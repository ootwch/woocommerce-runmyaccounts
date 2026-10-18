jQuery(document).ready(function(){

    jQuery('.select2').select2();

    show_guest_input();

    shipping_text();

    set_trigger();

    calculate_next_collective_invoice_date();

    jQuery("#rma-create-guest-customer").click( function( event ) {

        show_guest_input();

    });

    jQuery("a#flush-table").click( function( event ) {

        jQuery( "a#flush-table" ).css( { "pointer-events":"none", "color":"lightgrey" } );

        jQuery( "span.spinner" ).addClass("is-active")
            .css( { "float":"left" } );

        var data = {
            'action': 'rma_log_table',
            'db_action': 'flush'
        };

        // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
        jQuery.post( ajaxurl, data, function( response ) {
            jQuery( "span.spinner" ).css( { "float":"right" } )
                .removeClass("is-active");

            jQuery( "a#flush-table" ).css( { "pointer-events":"", "color":"#fff", "display":"none" } );

            jQuery( "tbody" ).css( { "display":"none" } );

            if (response) { alert( response ) }

        });

    });

    jQuery('select.invoice-trigger, select.payment-trigger, select.payment-trigger-exclude').on( 'change', function ( event ){

        set_trigger();

    });

    jQuery("#rma-shipping-id").on("select2:select", function () {
        shipping_text();
    });

});

function show_guest_input() {

    if (jQuery('#rma-create-guest-customer').is(":checked"))
    {
        jQuery("#rma-guest-customer-prefix").parent().parent().css("display", "");
        jQuery("select[name='wc_rma_settings[rma-guest-catch-all]']").parent().parent().css("display", "none");

    }
    else {
        jQuery("#rma-guest-customer-prefix").parent().parent().css("display", "none");
        jQuery("select[name='wc_rma_settings[rma-guest-catch-all]']").parent().parent().css("display", "");

    }

}

function set_trigger() {
    let invoiceTrigger ='';
    let paymentTrigger ='';
    let paymentTriggerExclude = '';

    invoiceTrigger        = jQuery('select.invoice-trigger').val();
    paymentTrigger        = jQuery('select.payment-trigger').val();
    paymentTriggerExclude = jQuery('select.payment-trigger-exclude').val();

    if( 'completed' === invoiceTrigger ) {

        jQuery(".payment-trigger option[value='immediately']").prop('disabled', true);

        if( 'immediately' === paymentTrigger ) {
            jQuery('select.payment-trigger').val('completed');
        }

    }

    if( 'immediately' === invoiceTrigger ) {

        jQuery(".payment-trigger option[value='immediately']").prop('disabled', false);

    }

    if( 'immediately' === paymentTrigger || '' === paymentTrigger) {

        jQuery(".invoice-trigger option[value='immediately']").prop('disabled', false);

    }

    if( '' === paymentTrigger) {
        jQuery("select[name='wc_rma_settings[rma-payment-trigger-exclude]']").parent().parent().css("display", "none");
        jQuery("fieldset[id='rma-payment-trigger-exclude-values']").parent().parent().css("display", "none");
    }
    else if( '' !== paymentTrigger ) {
        jQuery("select[name='wc_rma_settings[rma-payment-trigger-exclude]']").parent().parent().css("display", "");

        if( 'yes' === paymentTriggerExclude ) {
            jQuery("fieldset[id='rma-payment-trigger-exclude-values']").parent().parent().css("display", "");
        }
        else if ( 'no' === paymentTriggerExclude) {
            jQuery("fieldset[id='rma-payment-trigger-exclude-values']").parent().parent().css("display", "none");
        }
    }

}

function shipping_text() {

    let val = jQuery("#rma-shipping-id").val();

    if ( '' === val )
    {
        jQuery("#rma-shipping-text").parent().parent().css("display", "none");
    }
    else {
        jQuery("#rma-shipping-text").parent().parent().css("display", "");
    }

}

function calculate_next_collective_invoice_date() {
    let invoicePeriod ='';
    let invoiceWeekday ='';

    invoicePeriod  = jQuery('select.collective-invoice__period').val();
    invoiceWeekday = jQuery("input:radio[name ='wc_rma_settings_collective_invoice[collective_invoice_weekday]']:checked").val();

    if( null != invoiceWeekday ) {

        const data = {
            'action': 'next_invoice_date',
            'nonce': ( window.rmaWcAdmin || {} ).nextInvoiceDateNonce,
            'period': invoicePeriod,
            'weekday': invoiceWeekday
        };

        // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
        jQuery.post(ajaxurl, data, function ( response ) {

            if ( response && response.success ) {

                jQuery('p.next-invoice-date').text( response.data.date );

            } else if ( response && response.data && response.data.message ) {

                alert( response.data.message );

            }

        }).fail( function ( xhr ) {

            const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            if ( message ) {
                alert( message );
            }

        });
    }
}