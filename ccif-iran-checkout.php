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
        // Modify checkout fields
        add_filter( 'woocommerce_checkout_fields', [ $this, 'modify_checkout_fields' ] );

        // Hook into checkout fields to manage them
        add_filter( 'woocommerce_checkout_fields', [ $this, 'move_order_notes_field' ] );

        // Modify field arguments, e.g., to remove '(optional)' text
        add_filter( 'woocommerce_form_field_args', [ $this, 'remove_optional_text' ], 10, 3 );

        // Save custom fields to order meta
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_custom_fields_to_order_meta' ], 10, 2 );

        // Save custom fields to user meta
        add_action( 'woocommerce_checkout_update_user_meta', [ $this, 'save_custom_fields_to_user_meta' ], 10, 2 );

        // Pre-populate custom fields from user meta
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'get_custom_field_value_from_user_meta' ], 10, 2 );

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Custom layout hooks
        add_action( 'woocommerce_before_checkout_billing_form', [ $this, 'output_layout_wrapper_start' ], 5 );
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'output_layout_wrapper_end' ], 20 );

        // Inject boxes based on field priorities
        add_action( 'woocommerce_checkout_billing', [ $this, 'output_invoice_box_start' ], 0 );
        add_action( 'woocommerce_checkout_billing', [ $this, 'output_person_info_box_start' ], 9 );
        add_action( 'woocommerce_checkout_billing', [ $this, 'output_address_info_box_start' ], 40 );
        add_action( 'woocommerce_checkout_billing', [ $this, 'close_final_box' ], 999 );

    }

    public function save_custom_fields_to_order_meta( $order, $data ) {
        $this->log_message( 'Attempting to save custom fields for order ID: ' . $order->get_id() );
        foreach ( $this->custom_billing_fields as $field_key ) {
            if ( isset( $data[ $field_key ] ) && ! empty( $data[ $field_key ] ) ) {
                $value = sanitize_text_field( $data[ $field_key ] );
                $order->update_meta_data( '_' . $field_key, $value );
                $this->log_message( "Saved meta for order {$order->get_id()}: { '{$field_key}' : '{$value}' }" );
            }
        }
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

    public function modify_checkout_fields( $fields ) {
        $this->log_message( 'Modifying checkout fields.' );
        $iran_data = $this->load_iran_data();

        // --- Custom Fields Definition ---
        $custom_fields = [
            'billing_invoice_request' => ['type' => 'checkbox', 'label' => 'درخواست صدور فاکتور رسمی', 'class' => ['form-row-wide', 'ccif-invoice-request-field'], 'priority' => 1],
            'billing_person_type'     => ['type' => 'select', 'label' => 'نوع شخص', 'class' => ['form-row-wide', 'ccif-person-field'], 'options' => ['' => 'انتخاب کنید', 'real' => 'حقیقی', 'legal' => 'حقوقی'], 'priority' => 10],

            // Real Person Fields
            'billing_national_code'   => ['label' => 'کد ملی', 'class' => ['form-row-wide', 'ccif-person-field', 'ccif-real-person-field'], 'placeholder' => '۱۰ رقم بدون خط تیره', 'priority' => 23],

            // Legal Person Fields
            'billing_company_name'    => ['label' => 'نام شرکت', 'class' => ['form-row-first', 'ccif-person-field', 'ccif-legal-person-field'], 'priority' => 31],
            'billing_economic_code'   => ['label' => 'شناسه ملی/اقتصادی', 'class' => ['form-row-last', 'ccif-person-field', 'ccif-legal-person-field'], 'priority' => 32],
            'billing_agent_first_name' => ['label' => 'نام نماینده', 'class' => ['form-row-first', 'ccif-person-field', 'ccif-legal-person-field'], 'priority' => 33],
            'billing_agent_last_name' => ['label' => 'نام خانوادگی نماینده', 'class' => ['form-row-last', 'ccif-person-field', 'ccif-legal-person-field'], 'priority' => 34],
        ];

        // Merge our custom fields into the billing group
        $fields['billing'] = array_merge($fields['billing'], $custom_fields);

        // --- Modify Standard Fields ---
        // Real person fields
        $fields['billing']['billing_first_name']['class'] = ['form-row-first', 'ccif-person-field', 'ccif-real-person-field'];
        $fields['billing']['billing_first_name']['priority'] = 21;
        $fields['billing']['billing_last_name']['class'] = ['form-row-last', 'ccif-person-field', 'ccif-real-person-field'];
        $fields['billing']['billing_last_name']['priority'] = 22;

        // Address fields
        $fields['billing']['billing_state']['class'] = ['form-row-first', 'ccif-address-field'];
        $fields['billing']['billing_state']['options'] = [ '' => 'انتخاب کنید' ] + $iran_data['states'];
        $fields['billing']['billing_state']['priority'] = 41;

        $fields['billing']['billing_city']['class'] = ['form-row-last', 'ccif-address-field'];
        $fields['billing']['billing_city']['options'] = [ '' => 'ابتدا استان را انتخاب کنید' ];
        $fields['billing']['billing_city']['priority'] = 42;

        $fields['billing']['billing_address_1']['label'] = 'آدرس دقیق';
        $fields['billing']['billing_address_1']['placeholder'] = 'خیابان، کوچه، پلاک، واحد';
        $fields['billing']['billing_address_1']['class'] = ['form-row-wide', 'ccif-address-field'];
        $fields['billing']['billing_address_1']['priority'] = 51;

        $fields['billing']['billing_postcode']['class'] = ['form-row-first', 'ccif-address-field'];
        $fields['billing']['billing_postcode']['priority'] = 61;

        $fields['billing']['billing_phone']['class'] = ['form-row-last', 'ccif-address-field'];
        $fields['billing']['billing_phone']['priority'] = 62;

        // --- Unset Unwanted Fields ---
        unset($fields['billing']['billing_company']); // Use our own company name field
        unset($fields['billing']['billing_address_2']); // Not needed

        // --- Reorder All Billing Fields ---
        uasort($fields['billing'], 'wc_checkout_fields_uasort_comparison');

        return $fields;
    }

    public function enqueue_assets() {
        if ( ! is_checkout() ) return;
        wp_enqueue_script( 'ccif-checkout-js', plugin_dir_url( __FILE__ ) . 'assets/js/ccif-checkout.js', ['jquery'], '6.0', true );
        wp_localize_script( 'ccif-checkout-js', 'ccifData', [ 'cities' => $this->load_iran_data()['cities'] ] );
        wp_enqueue_style( 'ccif-checkout-css', plugin_dir_url( __FILE__ ) . 'assets/css/ccif-checkout.css', [], '6.0' );
    }

    // --- Custom Layout Functions ---
    public function output_layout_wrapper_start() {
        echo '<div class="ccif-checkout-form">';
    }

    public function output_invoice_box_start() {
        echo '<div class="ccif-box invoice-request-box">';
    }

    public function output_person_info_box_start() {
        echo '</div>'; // Close invoice-request-box
        echo '<div class="ccif-box person-info-box"><h2 class="ccif-person-info-header">اطلاعات خریدار</h2>';
    }

    public function output_address_info_box_start() {
        echo '</div>'; // Close person-info-box
        echo '<div class="ccif-box address-info-box"><h2 class="ccif-address-info-header">اطلاعات ارسال</h2>';
    }

    public function close_final_box() {
        echo '</div>'; // Close address-info-box
    }

    public function output_layout_wrapper_end() {
        // Note: The order notes box is not included in this refactor to simplify things,
        // as it belongs to the 'order' fields group, not 'billing'.
        // The final div for the main wrapper is closed here.
        echo '</div>'; // Close ccif-checkout-form
    }


    private function log_message( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $logger->debug( $message, [ 'source' => 'ccif-iran-checkout' ] );
            }
        }
    }
}

new CCIF_Iran_Checkout_Rebuild();
