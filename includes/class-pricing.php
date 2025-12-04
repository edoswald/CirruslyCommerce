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
        $cost = (float) $product->get_meta('_cogs_total_value');

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

        if ( 'inline' === $pos_prod ) {
            add_filter( 'woocommerce_get_price_html', array( $this, 'cw_render_msrp_inline' ), 100, 2 );
        } else {
            $hook = 'woocommerce_single_product_summary';
            $prio = 9; 
            if ( $pos_prod === 'before_title' ) $prio = 4;
            if ( $pos_prod === 'after_price' ) $prio = 11;
            if ( $pos_prod === 'before_add_to_cart' ) $prio = 25;
            add_action( $hook, array( $this, 'cw_render_msrp_block_hook' ), $prio );
        }

        if ( 'inline' === $pos_loop ) {
            if ( 'inline' !== $pos_prod ) { 
                add_filter( 'woocommerce_get_price_html', array( $this, 'cw_render_msrp_inline_loop_check' ), 100, 2 );
            }
        } else {
            $hook = 'woocommerce_after_shop_loop_item_title';
            $prio = 9;
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
        woocommerce_wp_text_input( array( 'id' => '_cw_sale_end', 'label' => 'Sale Timer End', 'placeholder' => 'YYYY-MM-DD HH:MM', 'class' => 'short cw-date-input', 'wrapper_class' => 'cw-flex-field','description' => 'Enter date to show countdown.' ));
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
                <!-- PRO Upsell Link -->
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
        // NEW: Trigger Real-Time Google Sync
        $this->push_update_to_gmc( $post_id );
    }

    public function pe_save_variable( $vid, $i ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo Core Context
        if ( isset( $_POST['_cw_est_shipping'][$i] ) ) update_post_meta( $vid, '_cw_est_shipping', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cw_est_shipping'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cirrusly_map_price'][$i] ) ) update_post_meta( $vid, '_cirrusly_map_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cirrusly_map_price'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_alg_msrp'][$i] ) ) update_post_meta( $vid, '_alg_msrp', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_alg_msrp'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_auto_pricing_min_price'][$i] ) ) update_post_meta( $vid, '_auto_pricing_min_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_auto_pricing_min_price'][$i] ) ) ) );
        // NEW: Trigger Real-Time Google Sync   
        $this->push_update_to_gmc( $vid );
    }

    /**
     * Pushes price/stock updates to Google immediately.
     */
    private function push_update_to_gmc( $product_id ) {
        $client = Cirrusly_Commerce_GMC::get_google_client();
        if ( is_wp_error( $client ) ) return; // No connection = No sync

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $merchant_id = get_option( 'cirrusly_gmc_merchant_id' );
        $service = new Google\Service\ShoppingContent( $client );

        // Construct GMC ID (online:en:US:ID)
        // Note: You might want to make 'US' and 'en' configurable settings later
        $gmc_id = sprintf( 'online:en:US:%s', $product->get_sku() ?: $product->get_id() );

        try {
            $inventory = new Google\Service\ShoppingContent\ProductInventory();
            $inventory->setAvailability( $product->is_in_stock() ? 'in stock' : 'out of stock' );
            
            $price = new Google\Service\ShoppingContent\Price();
            $price->setValue( $product->get_price() );
            $price->setCurrency( get_woocommerce_currency() );
            $inventory->setPrice( $price );

            $service->inventory->set( $merchant_id, $gmc_id, $inventory );
        } catch ( Exception $e ) {
            error_log( 'GMC Sync Failed: ' . $e->getMessage() );
        }
    }

}