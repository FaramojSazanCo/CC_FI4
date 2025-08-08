<?php
/**
 * Plugin Name: CCIF Iran Checkout (Fresh Rebuild)
 * Description: A plugin to customize the WooCommerce checkout form for Iran.
 * Version: 6.0
 * Author: Your Name
 * Text Domain: ccif-iran-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CCIF_Iran_Checkout_Rebuild {

    private $order_notes_field = [];
    private $custom_billing_fields = [
        'billing_person_type',
        'billing_national_code',
        'billing_company_name',
        'billing_economic_code',
        'billing_agent_first_name',
        'billing_agent_last_name',
        'billing_invoice_request'
    ];

    public function __construct() {
        // Override the default billing form rendering with our custom layout
        add_action( 'woocommerce_before_checkout_billing_form', [ $this, 'output_custom_billing_form_start' ], 5 );
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'output_custom_billing_form_end' ] );

        // Remove the default billing form
        add_filter('woocommerce_checkout_billing', '__return_false');

        // Hook into checkout fields to manage them
        add_filter( 'woocommerce_checkout_fields', [ $this, 'move_order_notes_field' ] );

        // Modify field arguments, e.g., to remove '(optional)' text
        add_filter( 'woocommerce_form_field_args', [ $this, 'remove_optional_text' ], 10, 3 );

        // Save custom fields to user meta
        add_action( 'woocommerce_checkout_update_user_meta', [ $this, 'save_custom_fields_to_user_meta' ], 10, 2 );

        // Pre-populate custom fields from user meta
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'get_custom_field_value_from_user_meta' ], 10, 2 );

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function save_custom_fields_to_user_meta( $customer_id, $posted_data ) {
        if ( ! $customer_id ) {
            return; // Only for logged-in users
        }

        foreach ( $this->custom_billing_fields as $field_key ) {
            if ( isset( $posted_data[ $field_key ] ) ) {
                $value = sanitize_text_field( $posted_data[ $field_key ] );
                update_user_meta( $customer_id, $field_key, $value );
            }
        }
    }

    public function get_custom_field_value_from_user_meta( $value, $key ) {
        // If there's already a value (e.g., from a failed submission), don't override it.
        if ( ! empty( $value ) ) {
            return $value;
        }

        // Check if the user is logged in and the key is one of our custom fields.
        if ( is_user_logged_in() && in_array( $key, $this->custom_billing_fields ) ) {
            $saved_value = get_user_meta( get_current_user_id(), $key, true );
            if ( $saved_value ) {
                return $saved_value;
            }
        }

        return $value;
    }

    public function move_order_notes_field( $fields ) {
        if ( isset( $fields['order'] ) && isset( $fields['order']['order_comments'] ) ) {
            $this->order_notes_field = $fields['order']['order_comments'];
            unset( $fields['order']['order_comments'] );
        }
        return $fields;
    }

    public function remove_optional_text( $args, $key, $value ) {
        // This function removes the "(optional)" text from the labels of non-required fields.
        if ( ! $args['required'] && isset($args['label']) ) {
            // This regex is more flexible. It looks for an optional whitespace or &nbsp;
            // followed by the <span class="optional">...</span> tag and removes it.
            $args['label'] = preg_replace( '/(\s|&nbsp;)?<span class="optional">.*?<\/span>/i', '', $args['label'] );
        }
        return $args;
    }

    private function normalize_persian_string($string) {
        // Replace common Arabic characters with Persian equivalents for better matching.
        $string = str_replace(['ي', 'ك', 'آ'], ['ی', 'ک', 'ا'], $string);
        // Remove non-breaking spaces and trim whitespace from the beginning and end.
        $string = trim(str_replace('&nbsp;', ' ', $string));
        return $string;
    }

    private function load_iran_data() {
        // Ensure WooCommerce is active to avoid fatal errors.
        if ( ! class_exists('WooCommerce') ) {
            return ['states' => [], 'cities' => []];
        }

        // Get the official list of states from WooCommerce for Iran.
        // This returns an array like: [ 'TEH' => 'تهران', 'KHZ' => 'خوزستان', ... ]
        $wc_states = WC()->countries->get_states('IR');

        if (empty($wc_states)) {
            return ['states' => [], 'cities' => []];
        }

        // Load the city data from our custom JSON file.
        $json_file = plugin_dir_path(__FILE__) . 'assets/data/iran_data-2.json';
        if (!file_exists($json_file)) {
            // If the city file is missing, still return the official states for the dropdown.
            return ['states' => $wc_states, 'cities' => []];
        }
        $custom_data = json_decode(file_get_contents($json_file), true);

        // Create a reverse map of normalized state names to state codes for robust lookup.
        // e.g., [ 'تهران' => 'TEH', 'خوزستان' => 'KHZ', ... ]
        $normalized_name_to_code_map = [];
        foreach ($wc_states as $code => $name) {
            $normalized_name_to_code_map[$this->normalize_persian_string($name)] = $code;
        }

        $cities = [];
        if (is_array($custom_data)) {
            foreach ($custom_data as $province) {
                if (isset($province['name']) && isset($province['cities'])) {
                    $normalized_province_name = $this->normalize_persian_string($province['name']);
                    // Find the official WooCommerce code for the current province name.
                    if (isset($normalized_name_to_code_map[$normalized_province_name])) {
                        $state_code = $normalized_name_to_code_map[$normalized_province_name];
                        // Use the official state code as the key for the cities array.
                        $cities[$state_code] = $province['cities'];
                    }
                }
            }
        }

        // Return the official WC states for the dropdown and the cities keyed by official codes.
        return ['states' => $wc_states, 'cities' => $cities];
    }

    public function get_all_checkout_fields() {
        $iran_data = $this->load_iran_data();
        $fields = [];

        // --- Define All Fields ---
        $fields['billing_invoice_request'] = ['type' => 'checkbox', 'label' => 'درخواست صدور فاکتور رسمی', 'class' => ['form-row-wide', 'ccif-invoice-request-field']];

        $fields['billing_person_type'] = ['type' => 'select', 'label' => 'نوع شخص', 'class' => ['form-row-wide', 'ccif-person-field'], 'options' => ['' => 'انتخاب کنید', 'real' => 'حقیقی', 'legal' => 'حقوقی']];

        $fields['billing_first_name'] = ['label' => 'نام', 'class' => ['form-row-first', 'ccif-person-field', 'ccif-real-person-field']];
        $fields['billing_last_name'] = ['label' => 'نام خانوادگی', 'class' => ['form-row-last', 'ccif-person-field', 'ccif-real-person-field']];
        $fields['billing_national_code'] = ['label' => 'کد ملی', 'class' => ['form-row-wide', 'ccif-person-field', 'ccif-real-person-field'], 'placeholder' => '۱۰ رقم بدون خط تیره'];

        $fields['billing_company_name'] = ['label' => 'نام شرکت', 'class' => ['form-row-first', 'ccif-person-field', 'ccif-legal-person-field']];
        $fields['billing_economic_code'] = ['label' => 'شناسه ملی/اقتصادی', 'class' => ['form-row-last', 'ccif-person-field', 'ccif-legal-person-field']];
        $fields['billing_agent_first_name'] = ['label' => 'نام نماینده', 'class' => ['form-row-first', 'ccif-person-field', 'ccif-legal-person-field']];
        $fields['billing_agent_last_name'] = ['label' => 'نام خانوادگی نماینده', 'class' => ['form-row-last', 'ccif-person-field', 'ccif-legal-person-field']];

        $fields['billing_state'] = ['type' => 'select', 'label' => 'استان', 'required' => true, 'class' => ['form-row-first', 'ccif-address-field'], 'options' => [ '' => 'انتخاب کنید' ] + $iran_data['states']];
        $fields['billing_city'] = ['type' => 'select', 'label' => 'شهر', 'required' => true, 'class' => ['form-row-last', 'ccif-address-field'], 'options' => [ '' => 'ابتدا استان را انتخاب کنید' ]];
        $fields['billing_address_1'] = ['label' => 'آدرس دقیق', 'required' => true, 'placeholder' => 'خیابان، کوچه، پلاک، واحد', 'class' => ['form-row-wide', 'ccif-address-field']];
        $fields['billing_postcode'] = ['label' => 'کد پستی', 'required' => true, 'type' => 'tel', 'class' => ['form-row-first', 'ccif-address-field']];
        $fields['billing_phone'] = ['label' => 'شماره تماس', 'required' => true, 'type' => 'tel', 'class' => ['form-row-last', 'ccif-address-field']];

        return $fields;
    }

    public function output_custom_billing_form_start($checkout) {
        $all_fields = $this->get_all_checkout_fields();

        echo '<div class="ccif-checkout-form">';

        // Box 1: Invoice Request
        echo '<div class="ccif-box invoice-request-box">';
        woocommerce_form_field('billing_invoice_request', $all_fields['billing_invoice_request'], $checkout->get_value('billing_invoice_request'));
        echo '<p class="ccif-hint">در صورت نیاز به فاکتور رسمی، این گزینه را انتخاب و تمام اطلاعات خریدار را به دقت وارد نمایید. در غیر این صورت، تنها تکمیل اطلاعات ارسال کافی است.</p>';
        echo '</div>';

        // Box 2: Person/Company Info
        echo '<div class="ccif-box person-info-box"><h2 class="ccif-person-info-header">اطلاعات خریدار</h2>';
        woocommerce_form_field('billing_person_type', $all_fields['billing_person_type'], $checkout->get_value('billing_person_type'));

        echo '<div class="ccif-real-person-fields-wrapper">';
        woocommerce_form_field('billing_first_name', $all_fields['billing_first_name'], $checkout->get_value('billing_first_name'));
        woocommerce_form_field('billing_last_name', $all_fields['billing_last_name'], $checkout->get_value('billing_last_name'));
        woocommerce_form_field('billing_national_code', $all_fields['billing_national_code'], $checkout->get_value('billing_national_code'));
        echo '</div>';

        echo '<div class="ccif-legal-person-fields-wrapper">';
        woocommerce_form_field('billing_company_name', $all_fields['billing_company_name'], $checkout->get_value('billing_company_name'));
        woocommerce_form_field('billing_economic_code', $all_fields['billing_economic_code'], $checkout->get_value('billing_economic_code'));
        woocommerce_form_field('billing_agent_first_name', $all_fields['billing_agent_first_name'], $checkout->get_value('billing_agent_first_name'));
        woocommerce_form_field('billing_agent_last_name', $all_fields['billing_agent_last_name'], $checkout->get_value('billing_agent_last_name'));
        echo '</div>';
        echo '</div>';

        // Box 3: Address Info
        echo '<div class="ccif-box address-info-box"><h2 class="ccif-address-info-header">اطلاعات ارسال</h2>';
        woocommerce_form_field('billing_state', $all_fields['billing_state'], $checkout->get_value('billing_state'));
        woocommerce_form_field('billing_city', $all_fields['billing_city'], $checkout->get_value('billing_city'));
        woocommerce_form_field('billing_address_1', $all_fields['billing_address_1'], $checkout->get_value('billing_address_1'));
        woocommerce_form_field('billing_postcode', $all_fields['billing_postcode'], $checkout->get_value('billing_postcode'));
        woocommerce_form_field('billing_phone', $all_fields['billing_phone'], $checkout->get_value('billing_phone'));
        echo '</div>';

        // Box 4: Order Notes
        if ( ! empty( $this->order_notes_field ) ) {
            echo '<div class="ccif-box order-notes-box"><h2 class="ccif-order-notes-header">توضیحات تکمیلی</h2>';
            woocommerce_form_field( 'order_comments', $this->order_notes_field, $checkout->get_value( 'order_comments' ) );
            echo '</div>';
        }
    }

    public function output_custom_billing_form_end() {
        echo '</div>'; // Close .ccif-checkout-form
    }

    public function enqueue_assets() {
        if ( ! is_checkout() ) return;
        wp_enqueue_script( 'ccif-checkout-js', plugin_dir_url( __FILE__ ) . 'assets/js/ccif-checkout.js', ['jquery'], '6.0', true );
        wp_localize_script( 'ccif-checkout-js', 'ccifData', [ 'cities' => $this->load_iran_data()['cities'] ] );
        wp_enqueue_style( 'ccif-checkout-css', plugin_dir_url( __FILE__ ) . 'assets/css/ccif-checkout.css', [], '6.0' );
    }
}

new CCIF_Iran_Checkout_Rebuild();
