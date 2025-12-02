<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Reviews {
    public function __construct() {
        add_action( 'woocommerce_thankyou', array( $this, 'render_google_reviews_optin' ) );
    }

    public function render_google_reviews_optin( $order_id ) {
        if ( ! $order_id ) return;
        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        if ( empty($gcr['enable_reviews']) || $gcr['enable_reviews'] !== 'yes' ) return;
        
        $merchant_id = isset($gcr['merchant_id']) ? $gcr['merchant_id'] : '';
        if ( empty($merchant_id) ) return;

        wp_enqueue_script( 'google-platform', 'https://apis.google.com/js/platform.js?onload=renderOptIn', array(), null, true );
        wp_add_inline_script( 'google-platform', 'window.renderOptIn = function() { window.gapi.load(\'surveyoptin\', function() { window.gapi.surveyoptin.render({ "merchant_id": ' . esc_js( $merchant_id ) . ', "order_id": "' . esc_js( $order_id ) . '" }); }); }' );
    }
}