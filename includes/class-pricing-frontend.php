<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_Frontend {

    public function __construct() {
        $this->init_frontend_msrp();
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
            $prio = 9;
            
            switch ( $pos_prod ) {
                case 'before_title':       $prio = 4;  break;
                case 'after_price':        $prio = 11; break;
                case 'after_excerpt':      $prio = 21; break;
                case 'before_add_to_cart': $prio = 25; break;
                case 'after_add_to_cart':  $prio = 31; break;
                case 'after_meta':         $prio = 41; break;
                case 'before_price':       $prio = 9;  break;
                default:                   $prio = 9;  break;
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
        } else {
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
}