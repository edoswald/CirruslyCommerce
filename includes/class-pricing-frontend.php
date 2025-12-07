<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Pricing_Frontend {

    /**
     * Initialize the frontend MSRP behavior for the plugin.
     *
     * Calls init_frontend_msrp() to register hooks and filters that control MSRP
     * display on product and catalog pages based on configuration.
     */
    public function __construct() {
        $this->init_frontend_msrp();
    }

    /**
     * Register frontend MSRP display hooks based on saved configuration.
     *
     * Reads the `cirrusly_msrp_config` option and, if MSRP display is enabled,
     * registers the appropriate WooCommerce filters or actions to render MSRP
     * values on product pages and in catalog/loop listings. Supports inline
     * insertion next to the price (via `woocommerce_get_price_html`) or block
     * placement using product and loop hooks (`woocommerce_single_product_summary`
     * and `woocommerce_after_shop_loop_item_title`) with positions mapped to
     * different hook priorities.
     */
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

    /**
     * Build an HTML fragment displaying a struck-through MSRP when the product's MSRP exceeds its active/current price.
     *
     * @param WC_Product|object $product The product to evaluate.
     * @return string HTML fragment with the formatted MSRP value (wrapped in a div.cw-msrp-container) if MSRP should be shown, or an empty string otherwise.
     */
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

    /**
     * Outputs the MSRP HTML for the current global product using safe HTML escaping.
     *
     * Retrieves MSRP markup for the global $product and echoes it after sanitizing with wp_kses_post.
     *
     * @return void
     */
    public function cw_render_msrp_block_hook() {
        global $product;
        echo wp_kses_post( self::get_msrp_html( $product ) );
    }

    /**
     * Prepends inline MSRP markup to a product price HTML when an MSRP is available.
     *
     * If an MSRP exists for the provided product, converts the MSRP container to an inline
     * element and adjusts spacing, then returns the MSRP HTML followed by the original price HTML.
     *
     * @param string $price_html The original price HTML.
     * @param WC_Product|object $product The product object to retrieve MSRP for.
     * @return string The combined HTML containing inline MSRP (if present) and the original price HTML.
     */
    public function cw_render_msrp_inline( $price_html, $product ) {
        $html = self::get_msrp_html( $product );
        if ( $html ) {
            $html = str_replace( 'div', 'span', $html );
            $html = str_replace( 'margin-bottom:5px', 'margin-right:5px', $html );
            return $html . $price_html;
        }
        return $price_html;
    }

    / **
     * Appends inline MSRP markup to the given price HTML when rendering product listings (non-single product pages).
     *
     * @param string           $price_html Existing formatted price HTML.
     * @param \WC_Product|null $product    The product object for which to render MSRP.
     * @return string The original or modified price HTML including inline MSRP when applicable.
     * /
    public function cw_render_msrp_inline_loop_check( $price_html, $product ) {
        if ( ! is_product() ) return $this->cw_render_msrp_inline( $price_html, $product );
        return $price_html;
    }
}