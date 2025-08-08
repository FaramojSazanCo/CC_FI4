jQuery(function($) {
    'use strict';

    // Ensure ccifData and cities exist to prevent errors
    if (typeof ccifData === 'undefined' || !ccifData.cities) {
        console.error('CCIF Iran Checkout: City data is not available.');
        return;
    }
    var cities = ccifData.cities;

    /**
     * Toggles the visibility of fields for Real vs. Legal persons.
     */
    function togglePersonFields() {
        var personType = $('#billing_person_type').val();
        var $realPersonWrapper = $('.ccif-real-person-fields-wrapper');
        var $legalPersonWrapper = $('.ccif-legal-person-fields-wrapper');

        if (personType === 'real') {
            $realPersonWrapper.show();
            $legalPersonWrapper.hide();
        } else if (personType === 'legal') {
            $legalPersonWrapper.show();
            $realPersonWrapper.hide();
        } else {
            $realPersonWrapper.hide();
            $legalPersonWrapper.hide();
        }
    }

    /**
     * Updates the 'required' status of fields based on the invoice checkbox.
     */
    function updateRequiredStatus() {
        var isInvoiceRequested = $('#billing_invoice_request').is(':checked');

        // Target all fields within the person/company box
        $('.person-info-box .form-row').each(function() {
            var $wrapper = $(this);
            var $input = $wrapper.find('input, select');

            // Set the required property on the input/select element
            $input.prop('required', isInvoiceRequested);

            // Toggle a class on the wrapper for CSS styling of the indicator
            $wrapper.toggleClass('ccif-is-required', isInvoiceRequested);
        });

        // Trigger the WooCommerce event to update its validation state
        $(document.body).trigger('update_checkout');
    }

    /**
     * Populates the city dropdown based on the selected state.
     */
    function populateCities() {
        var state = $('#billing_state').val();
        var $cityField = $('#billing_city');

        // Remember the current value if it exists
        var currentCity = $cityField.val();

        $cityField.empty().append('<option value="">' + 'ابتدا استان را انتخاب کنید' + '</option>');

        if (state && cities[state]) {
            $.each(cities[state], function(index, cityName) {
                // Create new option, select it if it matches the remembered value
                $cityField.append($('<option>', {
                    value: cityName,
                    text: cityName,
                    selected: cityName === currentCity
                }));
            });
        }
    }

    // --- Event Handlers ---
    $('body').on('change', '#billing_person_type', togglePersonFields);
    $('body').on('change', '#billing_invoice_request', updateRequiredStatus);
    $('body').on('change', '#billing_state', populateCities);

    // --- Initial Execution on Page Load ---
    togglePersonFields();
    updateRequiredStatus();

    // Populate cities on load if a state is already selected (e.g., on form validation error)
    // Also, trigger it on updated_checkout which is fired by WooCommerce after state field changes.
    $(document.body).on('updated_checkout', function() {
        // A small delay can help ensure our script runs after WooCommerce has finished its own updates.
        setTimeout(function() {
            if ($('#billing_state').val() && $('#billing_city').children().length <= 1) {
                populateCities();
            }
        }, 100);
    });

    // Initial population for page loads where state is already set.
    if ($('#billing_state').val()) {
        populateCities();
    }
});
