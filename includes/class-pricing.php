<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Pricing {

    public function __construct() {
        add_action( 'woocommerce_product_options_pricing', array( $this, 'pe_render_simple_fields' ) );
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'pe_render_variable_fields' ), 10, 3 );
        add_action( 'woocommerce_process_product_meta', array( $this, 'pe_save_simple' ) );
        add_action( 'woocommerce_save_product_variation', array( $this, 'pe_save_variable' ), 10, 2 );
        
        // Admin Columns
        add_filter( 'manage_edit-product_columns', array( $this, 'add_margin_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_margin_column' ), 10, 2 );

        // Admin Notice for repeated sync failures
        add_action( 'admin_notices', array( $this, 'render_sync_error_notice' ) );

        // NEW: Register the asynchronous GMC Sync handler.
        add_action( 'cirrusly_commerce_gmc_sync', array( $this, 'handle_gmc_sync_event' ), 10, 1 );

        $this->init_frontend_msrp();
    }

    public function add_margin_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $title ) {
            $new_columns[$key] = $title;
            if ( $key === 'price' ) {
                $new_columns['cw_margin'] = 'Margin';
            }
        }
        return $new_columns;
    }

    public function render_margin_column( $column, $post_id ) {
        if ( 'cw_margin' !== $column ) return;
        
        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        // Simple calculation for "at a glance" view
        $price = (float) $product->get_price();
        // Use get_post_meta to avoid internal key notices
        $cost = (float) get_post_meta( $product->get_id(), '_cogs_total_value', true );

        if ( $price > 0 && $cost > 0 ) {
            $margin = (($price - $cost) / $price) * 100;
            $color = $margin < 15 ? '#d63638' : '#008a20';
            echo '<span style="font-weight:bold; color:' . esc_attr($color) . '">' . number_format( $margin, 0 ) . '%</span>';
        } elseif ( $cost <= 0 ) {
            echo '<span style="color:#999;">-</span>';
        }
    }

public function init_frontend_msrp() {
        $msrp_cfg = get_option( 'cirrusly_msrp_config', array() );
        
        if ( empty($msrp_cfg['enable_display']) || $msrp_cfg['enable_display'] !== 'yes' ) return;

        $pos_prod = isset($msrp_cfg['position_product']) ? $msrp_cfg['position_product'] : 'before_price';
        $pos_loop = isset($msrp_cfg['position_loop']) ? $msrp_cfg['position_loop'] : 'before_price';

        // Product Page Logic
        if ( 'inline' === $pos_prod ) {
            add_filter( 'woocommerce_get_price_html', array( $this, 'cw_render_msrp_inline' ), 100, 2 );
        } else {
            $hook = 'woocommerce_single_product_summary';
            $prio = 9; // Default: before_price
            
            switch ( $pos_prod ) {
                case 'before_title':       $prio = 4;  break;
                case 'after_price':        $prio = 11; break;
                case 'after_excerpt':      $prio = 21; break;
                case 'before_add_to_cart': $prio = 25; break;
                case 'after_add_to_cart':  $prio = 31; break;
                case 'after_meta':         $prio = 41; break;
                case 'before_price':       $prio = 9;  break; // Explicit default position
                default:
                    // Invalid value - log and use safe default
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( sprintf( 'Cirrusly Commerce: Invalid MSRP position "%s", using default', $pos_prod ) );
                    }
                    $prio = 9;
                    break;
            }

            add_action( $hook, array( $this, 'cw_render_msrp_block_hook' ), $prio );
        }

        // Loop/Catalog Logic
        if ( 'inline' === $pos_loop ) {
            if ( 'inline' !== $pos_prod ) { 
                add_filter( 'woocommerce_get_price_html', array( $this, 'cw_render_msrp_inline_loop_check' ), 100, 2 );
            }
        } else {
            $hook = 'woocommerce_after_shop_loop_item_title';
            $prio = 9; // Default: before_price
            if ( $pos_loop === 'after_price' ) $prio = 11;
            add_action( $hook, array( $this, 'cw_render_msrp_block_hook' ), $prio );
        }
    }

    public static function get_msrp_html( $product ) {
        if ( ! is_object($product) ) return '';

        $msrp_display = '';
        
        if ( $product->is_type( 'variable' ) ) {
            $msrp_vals = array();
            $children = $product->get_visible_children();
            if ( $children ) {
                foreach ( $children as $child_id ) {
                    $val = get_post_meta( $child_id, '_alg_msrp', true );
                    if ( $val && is_numeric($val) ) $msrp_vals[] = floatval( $val );
                }
            }
            if ( ! empty( $msrp_vals ) ) {
                $min_msrp = min( $msrp_vals );
                $max_msrp = max( $msrp_vals );
                $min_active = $product->get_variation_price( 'min', true );
                
                if ( $min_msrp > ($min_active + 0.001) ) {
                    $msrp_display = ($min_msrp == $max_msrp) ? wc_price($min_msrp) : wc_price($min_msrp) . ' – ' . wc_price($max_msrp);
                }
            }
        } 
        else {
            $val = get_post_meta( $product->get_id(), '_alg_msrp', true );
            $current_price = $product->get_price();
            if ( $val && is_numeric($val) && is_numeric($current_price) ) {
                if ( floatval($val) > (floatval($current_price) + 0.001) ) {
                    $msrp_display = wc_price( $val );
                }
            }
        }

        if ( $msrp_display ) {
            return '<div class="cw-msrp-container" style="color:#777;font-size:0.9em;margin-bottom:5px;line-height:1;">MSRP: <span class="cw-msrp-value" style="text-decoration:line-through;">' . $msrp_display . '</span></div>';
        }
        return '';
    }

    public function cw_render_msrp_block_hook() {
        global $product;
        echo wp_kses_post( self::get_msrp_html( $product ) );
    }

    public function cw_render_msrp_inline( $price_html, $product ) {
        $html = self::get_msrp_html( $product );
        if ( $html ) {
            $html = str_replace( 'div', 'span', $html );
            $html = str_replace( 'margin-bottom:5px', 'margin-right:5px', $html );
            return $html . $price_html;
        }
        return $price_html;
    }

    public function cw_render_msrp_inline_loop_check( $price_html, $product ) {
        if ( ! is_product() ) return $this->cw_render_msrp_inline( $price_html, $product );
        return $price_html;
    }

    /**
     * Render the admin UI fields for simple product pricing and the pricing toolbar.
     *
     * Outputs HTML inputs for Google minimum price, MAP, MSRP, base shipping, and sale timer end, populating each field from the product's post meta and then renders the pricing engine toolbar.
     *
     * The following meta keys are used to populate fields:
     * - _auto_pricing_min_price (Google Min)
     * - _cirrusly_map_price (MAP)
     * - _alg_msrp (MSRP)
     * - _cw_est_shipping (Base Ship)
     * - _cw_sale_end (Sale Timer End)
     */
    public function pe_render_simple_fields() {
        global $product_object;
        $ship = $product_object->get_meta( '_cw_est_shipping' );
        $map  = $product_object->get_meta( '_cirrusly_map_price' ); 
        $msrp = $product_object->get_meta( '_alg_msrp' ); 
        $min  = $product_object->get_meta( '_auto_pricing_min_price' );

        echo '<div class="options_group cw-cogs-group"><div class="cw-multi-row-simple four-cols">';
        
        woocommerce_wp_text_input( array( 
            'id' => '_auto_pricing_min_price', 
            'label' => 'Google Min ($) <span class="dashicons dashicons-info" title="The lowest price Google is allowed to discount this item to (Automated Discounts)."></span>', 
            'class' => 'wc_input_price short cw-min-input', 
            'value' => $min, 
            'data_type' => 'price', 
            'wrapper_class' => 'cw-flex-field'
        ));
        
        woocommerce_wp_text_input( array( 
            'id' => '_cirrusly_map_price', 
            'label' => 'MAP ($) <span class="dashicons dashicons-info" title="Minimum Advertised Price. For internal policy compliance."></span>', 
            'class' => 'wc_input_price short cw-map-input', 
            'value' => $map, 
            'data_type' => 'price', 
            'wrapper_class' => 'cw-flex-field'
        ));
        
        woocommerce_wp_text_input( array( 'id' => '_alg_msrp', 'label' => 'MSRP ($)', 'class' => 'wc_input_price short cw-msrp-input', 'value' => $msrp, 'data_type' => 'price', 'wrapper_class' => 'cw-flex-field' ));
        woocommerce_wp_text_input( array( 'id' => '_cw_est_shipping', 'label' => 'Base Ship ($)', 'class' => 'wc_input_price short cw-ship-input', 'value' => $ship, 'data_type' => 'price', 'description' => 'Auto-fills', 'wrapper_class' => 'cw-flex-field' ));
        $sale_end = $product_object->get_meta( '_cw_sale_end' );
        woocommerce_wp_text_input( array( 'id' => '_cw_sale_end', 'label' => 'Sale Timer End', 'placeholder' => 'YYYY-MM-DD HH:MM', 'class' => 'short cw-date-input', 'value' => $sale_end, 'wrapper_class' => 'cw-flex-field', 'description' => 'Enter date to show countdown.' ));
        echo '</div>';
        $this->pe_render_toolbar();
        echo '</div>';
    }

    public function pe_render_variable_fields( $loop, $variation_data, $variation ) {
        $ship = get_post_meta( $variation->ID, '_cw_est_shipping', true );
        $map  = get_post_meta( $variation->ID, '_cirrusly_map_price', true ); 
        $msrp = get_post_meta( $variation->ID, '_alg_msrp', true ); 
        $min  = get_post_meta( $variation->ID, '_auto_pricing_min_price', true );

        echo '<div class="cw-cogs-wrapper-var"><div class="cw-dual-row-variable four-cols">';
        
        woocommerce_wp_text_input( array( 
            'id' => "_auto_pricing_min_price[$loop]", 
            'label' => 'Google Min ($) <span class="dashicons dashicons-info" title="Lowest allowed price for Google Automated Discounts."></span>', 
            'class' => 'wc_input_price short cw-min-input', 
            'value' => $min, 
            'wrapper_class' => 'cw-flex-field' 
        ));
        
        woocommerce_wp_text_input( array( 'id' => "_cirrusly_map_price[$loop]", 'label' => 'MAP ($)', 'class' => 'wc_input_price short cw-map-input', 'value' => $map, 'wrapper_class' => 'cw-flex-field' ));
        woocommerce_wp_text_input( array( 'id' => "_alg_msrp[$loop]", 'label' => 'MSRP ($)', 'class' => 'wc_input_price short cw-msrp-input', 'value' => $msrp, 'wrapper_class' => 'cw-flex-field' ));
        woocommerce_wp_text_input( array( 'id' => "_cw_est_shipping[$loop]", 'label' => 'Base Ship ($)', 'class' => 'wc_input_price short cw-ship-input', 'value' => $ship, 'wrapper_class' => 'cw-flex-field' ));
        echo '</div>';
        $this->pe_render_toolbar();
        echo '</div>';
    }

    private function pe_render_toolbar() {
        ?>
        <div class="cw-tools-row" style="margin-top:10px;">
            <label>Pricing Engine</label>
            <span style="display:inline-block;">
                <select class="cw-tool-sale short" style="width:140px;margin:0;">
                    <option value="">Sale Strategy...</option>
                    <option value="msrp_05">5% Off MSRP</option>
                    <option value="msrp_10">10% Off MSRP</option>
                    <option value="msrp_15">15% Off MSRP</option>
                    <option value="msrp_20">20% Off MSRP</option>
                    <option value="msrp_25">25% Off MSRP</option>
                    <option value="msrp_30">30% Off MSRP</option>
                    <option value="msrp_40">40% Off MSRP</option>
                    <option value="reg_5">5% Off Reg</option>
                    <option value="reg_10">10% Off Reg</option>
                    <option value="reg_20">20% Off Reg</option>
                    <option value="clear" style="color:red;">X Clear Sale</option>
                </select>
                <select class="cw-sale-rounding short" style="width:80px;margin:0;">
                    <option value="99">.99</option>
                    <option value="50">.50</option>
                    <option value="nearest_5">Nearest 5/0</option>
                    <option value="exact">Exact</option>
                </select>
                <select class="cw-tool-reg short" style="width:180px;margin:0;">
                    <option value="">Reg Strategy...</option>
                    <optgroup label="From MSRP">
                        <option value="msrp_exact">Match MSRP</option>
                        <option value="msrp_sub_05">5% < MSRP</option>
                        <option value="msrp_sub_10">10% < MSRP</option>
                    </optgroup>
                    <optgroup label="From Cost">
                        <option value="margin_15">Target 15% Margin</option>
                        <option value="margin_20">Target 20% Margin</option>
                        <option value="margin_30">Target 30% Margin</option>
                    </optgroup>
                </select>
                <a href="#upgrade-to-pro" class="cc-upgrade-btn" style="padding:2px 6px; font-size:10px; margin-left:10px;" title="Unlock Global Pricing Rules & Psychological Pricing">
                    <span class="dashicons dashicons-lock" style="font-size:12px;vertical-align:middle;"></span> Unlock Automation
                </a>
            </span>
        </div>
        <div class="cw-profit-display" style="margin-left:160px; margin-top:5px; color:#555;">
             Profit: <strong><span class="cw-profit-val">--</span></strong> | Margin: <strong><span class="cw-margin-val">--</span></strong>
             <div class="cw-shipping-matrix"></div>
        </div>
        <?php
    }

    /**
     * Persist simple product pricing fields to post meta and schedule an asynchronous GMC update.
     *
     * Saves submitted pricing-related fields for a simple product into post meta:
     * `_cw_est_shipping`, `_cirrusly_map_price`, `_alg_msrp`, `_auto_pricing_min_price`, and `_cw_sale_end`.
     * After saving, schedules a background Google Merchant Center inventory/price update for the product.
     *
     * @param int $post_id The product post ID to save meta for.
     */
    public function pe_save_simple( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo Core Context
        if ( isset( $_POST['_cw_est_shipping'] ) ) update_post_meta( $post_id, '_cw_est_shipping', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cw_est_shipping'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cirrusly_map_price'] ) ) update_post_meta( $post_id, '_cirrusly_map_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cirrusly_map_price'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_alg_msrp'] ) ) update_post_meta( $post_id, '_alg_msrp', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_alg_msrp'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_auto_pricing_min_price'] ) ) update_post_meta( $post_id, '_auto_pricing_min_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_auto_pricing_min_price'] ) ) ) );
        if ( isset( $_POST['_cw_sale_end'] ) ) update_post_meta( $post_id, '_cw_sale_end', sanitize_text_field( wp_unslash( $_POST['_cw_sale_end'] ) ) );
        // REFACTORED: Schedule a background task for Google Sync to prevent slowing down bulk save operations.
        $this->schedule_gmc_sync( $post_id );
    }

    /**
     * Save pricing-related meta for a product variation and schedule an asynchronous GMC update.
     *
     * Reads variation-scoped pricing fields from the current POST (shipping estimate, MAP price,
     * MSRP, and auto-pricing minimum), sanitizes and formats them, updates the variation's post meta,
     * and then requests a push of the updated price/stock to Google Merchant Center asynchronously.
     *
     * @param int $vid Variation post ID to update.
     * @param int $i   Index of the variation in the submitted variation loop (used to read the POST arrays).
     */
    public function pe_save_variable( $vid, $i ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo Core Context
        if ( isset( $_POST['_cw_est_shipping'][$i] ) ) update_post_meta( $vid, '_cw_est_shipping', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cw_est_shipping'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cirrusly_map_price'][$i] ) ) update_post_meta( $vid, '_cirrusly_map_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cirrusly_map_price'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_alg_msrp'][$i] ) ) update_post_meta( $vid, '_alg_msrp', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_alg_msrp'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_auto_pricing_min_price'][$i] ) ) update_post_meta( $vid, '_auto_pricing_min_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_auto_pricing_min_price'][$i] ) ) ) );
        // REFACTORED: Schedule a background task for Google Sync.
        $this->schedule_gmc_sync( $vid );
    }

    /**
     * Helper to schedule an asynchronous Google Merchant Center inventory update.
     *
     * Uses wp_schedule_single_event to defer the API call, improving performance 
     * during product saves, especially bulk operations.
     *
     * @param int $product_id The WooCommerce product post ID to synchronize.
     * @return void
     */
    private function schedule_gmc_sync( $product_id ) {
        // Clear any existing pending events for this product to prevent duplicates 
        // if the product is saved multiple times in a short span (e.g., during bulk saves).
        wp_clear_scheduled_hook( 'cirrusly_commerce_gmc_sync', array( $product_id ) );
        
        // Schedule the event to run in 60 seconds to allow time for product meta to fully save.
        wp_schedule_single_event( time() + 60, 'cirrusly_commerce_gmc_sync', array( $product_id ) );
    }

    /**
     * Handler for the scheduled 'cirrusly_commerce_gmc_sync' event.
     *
     * @param int $product_id The WooCommerce product post ID to synchronize.
     * @return void
     */
    public function handle_gmc_sync_event( $product_id ) {
        $this->_gmc_api_worker( $product_id );
    }

    /**
     * Helper to log a global GMC sync API failure for admin notification.
     *
     * Stores the error message and time in a global option.
     *
     * @param string $message The error message to log.
     * @return void
     */
    private function log_global_sync_failure( $message ) {
        update_option( 'cirrusly_gmc_global_sync_error', array(
            'time'    => time(),
            'message' => $message,
        ), false );
        // Ensure the dismissal is cleared so the notice reappears.
        delete_transient( 'cirrusly_gmc_sync_notice_dismissed' );
    }

    /**
     * Helper to clear the global GMC sync API failure status.
     *
     * @return void
     */
    private function log_global_sync_success() {
        delete_option( 'cirrusly_gmc_global_sync_error' );
    }

    /**
     * Renders a dismissible admin notice if a global GMC sync failure is recorded.
     *
     * Only runs for users with manage_options capability and if the notice hasn't been dismissed.
     * @return void
     */
    public function render_sync_error_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( get_transient( 'cirrusly_gmc_sync_notice_dismissed' ) ) return;

        $error_data = get_option( 'cirrusly_gmc_global_sync_error' );

        if ( ! empty( $error_data ) && is_array( $error_data ) ) {
            $time_diff = human_time_diff( $error_data['time'], current_time( 'timestamp' ) );
            $message = sprintf( 
                '<strong>Cirrusly Commerce Warning:</strong> Google Merchant Center sync failed %s ago. This is often due to missing credentials or API connection issues. <a href="%s">Review GMC Hub Settings</a>. Last Error: <code>%s</code>',
                esc_html( $time_diff ),
                esc_url( admin_url( 'admin.php?page=cirrusly-gmc' ) ),
                esc_html( $error_data['message'] )
            );
            
            // Note: Full dismissal logic (handling the query param) must be implemented separately, 
            // but this provides the visible, dismissible notice structure.
            echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
        }
    }

    /**
     * Worker method to perform the Google Merchant Center inventory update.
     *
     * Migrated from the deprecated inventory->set() to use Google Content API v2.1's 
     * products->update (PATCH) method.
     *
     * @param int $product_id The WooCommerce product post ID to synchronize.
     * @return void
     */
    private function _gmc_api_worker( $product_id ) {
        // Ensure the necessary class for the Google Client is available (assuming it's loaded elsewhere, e.g., in class-gmc.php)
        if ( ! class_exists( 'Cirrusly_Commerce_GMC' ) ) {
            error_log( 'GMC Sync Failed: Cirrusly_Commerce_GMC class not found. Product ID: ' . $product_id );
            $this->log_global_sync_failure( 'Cirrusly_Commerce_GMC class not found.' );
            return;
        }

        $client = Cirrusly_Commerce_GMC::get_google_client();
        if ( is_wp_error( $client ) ) {
            $error_message = $client->get_error_message();
            error_log( 'GMC Sync Failed: GMC Client Error: ' . $error_message . ' Product ID: ' . $product_id );
            $this->log_global_sync_failure( 'GMC Client Error: ' . $error_message );
            return; // No connection = No sync
        }
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $merchant_id = get_option( 'cirrusly_gmc_merchant_id' );
        if ( empty( $merchant_id ) ) return; // Not configured

        // The following classes are assumed to be loaded via Composer/Autoloader with Google API Client.
        if ( ! class_exists( 'Google\Service\ShoppingContent' ) || ! class_exists( 'Google\Service\ShoppingContent\Product' ) ) {
            error_log( 'GMC Sync Failed: Google ShoppingContent classes not found. Product ID: ' . $product_id );
            $this->log_global_sync_failure( 'Google ShoppingContent classes not found.' );
            return;
        }

        $service = new Google\Service\ShoppingContent( $client );

        // Construct the product's offer ID (SKU or ID).
        $offer_id = $product->get_sku() ?: $product->get_id();
        
        try {
            // Build the minimal Product object for a PATCH request (update).
            $gmc_product = new Google\Service\ShoppingContent\Product();
            
            // Required identifying fields for a PATCH/Update.
            // These must match the identifiers used when the product was inserted/created in GMC.
            $gmc_product->setOfferId( (string) $offer_id );
            // Assuming 'en' and 'US' are the correct content language and target country.
            $gmc_product->setContentLanguage( 'en' ); 
            $gmc_product->setTargetCountry( 'US' );
            $gmc_product->setChannel( 'online' );
            
            // 1. Set Availability (Field to update)
            $gmc_product->setAvailability( $product->is_in_stock() ? 'in stock' : 'out of stock' );

            // 2. Set Price (Field to update)
            $price_obj = new Google\Service\ShoppingContent\Price();
            $price_obj->setValue( $product->get_price() );
            $price_obj->setCurrency( get_woocommerce_currency() );
            $gmc_product->setPrice( $price_obj );
            
            // The full product ID to update (e.g., online:en:US:offerId).
            $product_rest_id = sprintf( 'online:en:US:%s', $offer_id );

            // Call the products->update method (which performs a PATCH for partial updates).
            $service->products->update( 
                $merchant_id, 
                $product_rest_id, 
                $gmc_product
            );
            
            $this->log_global_sync_success();
            
        } catch ( Exception $e ) {
            error_log( 'GMC Sync Failed: ' . $e->getMessage() . ' Product ID: ' . $product_id );
            $this->log_global_sync_failure( 'API Exception: ' . $e->getMessage() );
        }
    }

}