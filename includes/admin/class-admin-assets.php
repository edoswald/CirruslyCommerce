<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Admin_Assets {

    /**
     * Enqueue and localize admin styles, scripts, and UI helper code.
     */
    public function enqueue( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $is_plugin_page = strpos( $page, 'cirrusly-' ) !== false;
        $is_product_page = 'post.php' === $hook || 'post-new.php' === $hook;

        if ( ! $is_plugin_page && ! $is_product_page ) {
            return;
        }

        wp_enqueue_media(); 
        
        // Base Admin CSS
        wp_register_style( 'cirrusly-admin-css', CIRRUSLY_COMMERCE_URL . 'assets/css/admin.css', array(), CIRRUSLY_COMMERCE_VERSION );
        wp_enqueue_style( 'cirrusly-admin-css' );

        // Base Admin JS (Restored: Loads external file instead of inline)
        wp_register_script( 'cirrusly-admin-base-js', CIRRUSLY_COMMERCE_URL . 'assets/js/admin.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
        wp_enqueue_script( 'cirrusly-admin-base-js' );

        // Audit JS Logic
        if ( $page === 'cirrusly-audit' ) {
            wp_enqueue_script( 'cirrusly-audit-js', CIRRUSLY_COMMERCE_URL . 'assets/js/audit.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
            
            // Renamed localized object to match new prefix
            wp_localize_script( 'cirrusly-audit-js', 'cirrusly_audit_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'cirrusly_audit_save' )
            ));
        }

        // Pricing JS Logic
        if ( $is_product_page ) {
            wp_enqueue_script( 'cirrusly-pricing-js', CIRRUSLY_COMMERCE_URL . 'assets/js/pricing.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
            
            $config = get_option( 'cirrusly_shipping_config', array() );
            $defaults = array(
                'revenue_tiers_json' => json_encode(array(
                    array( 'min' => 0, 'max' => 10.00, 'charge' => 3.99 ),
                    array( 'min' => 10.01, 'max' => 20.00, 'charge' => 4.99 ),
                    array( 'min' => 60.00, 'max' => 99999, 'charge' => 0.00 ),
                )),
                'matrix_rules_json' => json_encode(array(
                    'economy'   => array( 'key'=>'economy', 'label' => 'Eco', 'cost_mult' => 1.0 ),
                    'standard'  => array( 'key'=>'standard', 'label' => 'Std', 'cost_mult' => 1.4 ),
                )),
                'class_costs_json' => json_encode(array('default' => 10.00)),
                'payment_pct' => 2.9, 'payment_flat' => 0.30,
                'profile_mode' => 'single', 'profile_split' => 100
            );
            $config = wp_parse_args( $config, $defaults );
            
            $js_config = array(
                'revenue_tiers' => json_decode( $config['revenue_tiers_json'] ),
                'matrix_rules'  => json_decode( $config['matrix_rules_json'] ),
                'classes'       => array(),
                'payment_pct'   => isset($config['payment_pct']) ? (float)$config['payment_pct'] : 2.9,
                'payment_flat'  => isset($config['payment_flat']) ? (float)$config['payment_flat'] : 0.30,
                'profile_mode'  => isset($config['profile_mode']) ? $config['profile_mode'] : 'single',
                'payment_pct_2' => isset($config['payment_pct_2']) ? (float)$config['payment_pct_2'] : 2.9,
                'payment_flat_2'=> isset($config['payment_flat_2']) ? (float)$config['payment_flat_2'] : 0.30,
                'profile_split' => isset($config['profile_split']) ? (float)$config['profile_split'] : 100,
            );
            
            $class_costs = json_decode( $config['class_costs_json'], true );
            if ( is_array( $class_costs ) ) {
                foreach( $class_costs as $slug => $cost ) {
                    $js_config['classes'][$slug] = array( 'cost' => (float)$cost, 'matrix' => true ); 
                }
            }
            
            $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
            $id_map = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) $id_map[ $term->term_id ] = $term->slug;
            }

            // Renamed localized object
            wp_localize_script( 'cirrusly-pricing-js', 'cirrusly_pricing_vars', array( 'ship_config' => $js_config, 'id_map' => $id_map ));
        }
    }
}