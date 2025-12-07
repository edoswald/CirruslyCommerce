<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_UI {

    public function __construct() {
        // Meta Boxes
        add_action( 'woocommerce_product_options_pricing', array( $this, 'pe_render_simple_fields' ) );
        add_action( 'woocommerce_variation_options_pricing', array( $this, 'pe_render_variable_fields' ), 10, 3 );
        
        // Saving
        add_action( 'woocommerce_process_product_meta', array( $this, 'pe_save_simple' ) );
        add_action( 'woocommerce_save_product_variation', array( $this, 'pe_save_variable' ), 10, 2 );
        
        // Admin Columns
        add_filter( 'manage_edit-product_columns', array( $this, 'add_margin_column' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_margin_column' ), 10, 2 );
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

        $price = (float) $product->get_price();
        $cost = (float) get_post_meta( $product->get_id(), '_cogs_total_value', true );

        if ( $price > 0 && $cost > 0 ) {
            $margin = (($price - $cost) / $price) * 100;
            $color = $margin < 15 ? '#d63638' : '#008a20';
            echo '<span style="font-weight:bold; color:' . esc_attr($color) . '">' . number_format( $margin, 0 ) . '%</span>';
        } elseif ( $cost <= 0 ) {
            echo '<span style="color:#999;">-</span>';
        }
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
            'label' => 'Google Min ($) <span class="dashicons dashicons-info" title="Automated Discounts Floor."></span>', 
            'class' => 'wc_input_price short cw-min-input', 
            'value' => $min, 
            'data_type' => 'price', 
            'wrapper_class' => 'cw-flex-field'
        ));
        
        woocommerce_wp_text_input( array( 'id' => '_cirrusly_map_price', 'label' => 'MAP ($)', 'class' => 'wc_input_price short cw-map-input', 'value' => $map, 'data_type' => 'price', 'wrapper_class' => 'cw-flex-field' ));
        woocommerce_wp_text_input( array( 'id' => '_alg_msrp', 'label' => 'MSRP ($)', 'class' => 'wc_input_price short cw-msrp-input', 'value' => $msrp, 'data_type' => 'price', 'wrapper_class' => 'cw-flex-field' ));
        woocommerce_wp_text_input( array( 'id' => '_cw_est_shipping', 'label' => 'Base Ship ($)', 'class' => 'wc_input_price short cw-ship-input', 'value' => $ship, 'data_type' => 'price', 'description' => 'Auto-fills', 'wrapper_class' => 'cw-flex-field' ));
        
        $sale_end = $product_object->get_meta( '_cw_sale_end' );
        woocommerce_wp_text_input( array( 'id' => '_cw_sale_end', 'label' => 'Sale Timer End', 'placeholder' => 'YYYY-MM-DD HH:MM', 'class' => 'short cw-date-input', 'value' => $sale_end, 'wrapper_class' => 'cw-flex-field' ));
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
        woocommerce_wp_text_input( array( 'id' => "_auto_pricing_min_price[$loop]", 'label' => 'Google Min ($)', 'class' => 'wc_input_price short cw-min-input', 'value' => $min, 'wrapper_class' => 'cw-flex-field' ));
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
                    </optgroup>
                    <optgroup label="From Cost">
                        <option value="margin_15">Target 15% Margin</option>
                        <option value="margin_20">Target 20% Margin</option>
                        <option value="margin_30">Target 30% Margin</option>
                    </optgroup>
                </select>
            </span>
        </div>
        <div class="cw-profit-display" style="margin-left:160px; margin-top:5px; color:#555;">
             Profit: <strong><span class="cw-profit-val">--</span></strong> | Margin: <strong><span class="cw-margin-val">--</span></strong>
             <div class="cw-shipping-matrix"></div>
        </div>
        <?php
    }

    public function pe_save_simple( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cw_est_shipping'] ) ) update_post_meta( $post_id, '_cw_est_shipping', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cw_est_shipping'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cirrusly_map_price'] ) ) update_post_meta( $post_id, '_cirrusly_map_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cirrusly_map_price'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_alg_msrp'] ) ) update_post_meta( $post_id, '_alg_msrp', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_alg_msrp'] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_auto_pricing_min_price'] ) ) update_post_meta( $post_id, '_auto_pricing_min_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_auto_pricing_min_price'] ) ) ) );
        
        if ( isset( $_POST['_cw_sale_end'] ) ) update_post_meta( $post_id, '_cw_sale_end', sanitize_text_field( wp_unslash( $_POST['_cw_sale_end'] ) ) );
        
        $this->schedule_gmc_sync( $post_id );
    }

    public function pe_save_variable( $vid, $i ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cw_est_shipping'][$i] ) ) update_post_meta( $vid, '_cw_est_shipping', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cw_est_shipping'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_cirrusly_map_price'][$i] ) ) update_post_meta( $vid, '_cirrusly_map_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cirrusly_map_price'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_alg_msrp'][$i] ) ) update_post_meta( $vid, '_alg_msrp', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_alg_msrp'][$i] ) ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_auto_pricing_min_price'][$i] ) ) update_post_meta( $vid, '_auto_pricing_min_price', wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_auto_pricing_min_price'][$i] ) ) ) );
        
        $this->schedule_gmc_sync( $vid );
    }

    private function schedule_gmc_sync( $product_id ) {
        // Ensure Pro is active before scheduling
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            wp_clear_scheduled_hook( 'cirrusly_commerce_gmc_sync', array( $product_id ) );
            wp_schedule_single_event( time() + 60, 'cirrusly_commerce_gmc_sync', array( $product_id ) );
        }
    }
}