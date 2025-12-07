<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Admin_Assets {

    /**
     * Enqueue and localize admin scripts and styles.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueue( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $is_plugin_page = strpos( $page, 'cirrusly-' ) !== false;
        $is_product_page = 'post.php' === $hook || 'post-new.php' === $hook;

        // Only load assets on relevant pages to maintain performance
        if ( ! $is_plugin_page && ! $is_product_page ) {
            return;
        }

        // 1. Core Styles & Media
        wp_enqueue_media(); 
        wp_enqueue_style( 'cirrusly-admin-css', CIRRUSLY_COMMERCE_URL . 'assets/css/admin.css', array(), CIRRUSLY_COMMERCE_VERSION );
        
        // 2. Audit Page JS
        if ( $page === 'cirrusly-audit' ) {
            wp_enqueue_script( 'cirrusly-audit-js', CIRRUSLY_COMMERCE_URL . 'assets/js/audit.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
            wp_localize_script( 'cirrusly-audit-js', 'cc_audit_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'cc_audit_save' )
            ));
        }

        // 3. Product Pricing Engine JS
        if ( $is_product_page ) {
            wp_enqueue_script( 'cirrusly-pricing-js', CIRRUSLY_COMMERCE_URL . 'assets/js/pricing.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
            
            // Reconstruct config logic here to ensure JS has data
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
            
            // Pass configuration to JS for real-time margin calculation
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

            wp_localize_script( 'cirrusly-pricing-js', 'cw_vars', array( 'ship_config' => $js_config, 'id_map' => $id_map ));
        }
        
        // 4. UI Helper Scripts (Tab switching, dynamic rows)
        // Moved from inline string to a cleaner heredoc or simple string
        $js_ui_helpers = '
        jQuery(document).ready(function($){
            var frame; var $currentBtn;
            $(document).on("click", ".cc-upload-btn", function(e) {
                e.preventDefault(); $currentBtn = $(this);
                if ( frame ) { frame.open(); return; }
                frame = wp.media({ title: "Select Badge Image", button: { text: "Use this image" }, multiple: false });
                frame.on( "select", function() {
                    var attachment = frame.state().get("selection").first().toJSON();
                    $currentBtn.prev("input").val(attachment.url).trigger("change");
                });
                frame.open();
            });
            $(document).on("click", ".cc-remove-btn", function(e){ e.preventDefault(); $(this).siblings("input").val(""); });
            
            // Dynamic Rows (Settings Page)
            $("#cc-add-badge-row").click(function(){
                var idx = $("#cc-badge-rows tr").length + 1000;
                var row = "<tr><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][tag]\'></td><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][url]\' class=\'regular-text\'> <button type=\'button\' class=\'button cc-upload-btn\'>Upload</button></td><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][tooltip]\'></td><td><input type=\'number\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][width]\' value=\'60\'> px</td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-badge-rows").append(row);
            });
            $("#cc-add-revenue-row").click(function(){
                var idx = $("#cc-revenue-rows tr").length + 1000;
                var row = "<tr><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][min]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][max]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][charge]\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-revenue-rows").append(row);
            });
            $("#cc-add-matrix-row").click(function(){
                var idx = $("#cc-matrix-rows tr").length + 1000;
                var row = "<tr><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][key]\'></td><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][label]\'></td><td>x <input type=\'number\' step=\'0.1\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][cost_mult]\' value=\'1.0\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-matrix-rows").append(row);
            });
            $("#cc-add-countdown-row").click(function(){
                var idx = $("#cc-countdown-rows tr").length + 1000;
                var row = "<tr><td><input type=\'text\' name=\'cirrusly_countdown_rules["+idx+"][taxonomy]\'></td><td><input type=\'text\' name=\'cirrusly_countdown_rules["+idx+"][term]\'></td><td><input type=\'text\' name=\'cirrusly_countdown_rules["+idx+"][end]\'></td><td><input type=\'text\' name=\'cirrusly_countdown_rules["+idx+"][label]\'></td><td><select name=\'cirrusly_countdown_rules["+idx+"][align]\'><option value=\'left\'>Left</option><option value=\'right\'>Right</option><option value=\'center\'>Center</option></select></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-countdown-rows").append(row);
            });
            $(document).on("click", ".cc-remove-row", function(){ $(this).closest("tr").remove(); });
        });';
        
        wp_add_inline_script( 'common', $js_ui_helpers );
    }
}