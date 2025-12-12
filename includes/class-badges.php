<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Badges {

    /**
     * Initialize the badges subsystem: register frontend initialization hooks and load the Pro extension when available.
     *
     * Registers an action on the 'wp' hook to set up frontend badge hooks, and conditionally requires the Pro badges class file
     * if the Pro feature is active and the pro class file exists.
     */
    public function __construct() {
        add_action( 'wp', array( $this, 'init_frontend_hooks' ) );
        
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-badges-pro.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-badges-pro.php';
        }
    }
    /**
     * Register frontend WordPress hooks to render product badges and enqueue their assets when badges are enabled.
     *
     * Does nothing if badges are not enabled in the 'cirrusly_badge_config' option.
     */
    public function init_frontend_hooks() {
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        if ( empty($badge_cfg['enable_badges']) || $badge_cfg['enable_badges'] !== 'yes' ) return;

        add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_badges' ), 5 );
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_grid_payload' ), 99 );
        
        // Hook to standard enqueue script (not direct head printing)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

     /**
     * Attach badge styling and frontend badge-relocation script to the theme's base assets.
     *
     * Reads badge configuration to determine sizing, injects critical CSS to style custom badges
     * and hide default sale badges, and (on non-admin pages) injects a small script that moves
     * hidden badge payloads into product image wrappers and keeps them positioned when the grid changes.
     */
    public function enqueue_frontend_assets() {
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        $size = isset($badge_cfg['badge_size']) ? $badge_cfg['badge_size'] : 'medium';
        $font_size = '12px'; $padding = '4px 8px'; $width = '60px';
        if ( $size === 'small' ) { $font_size = '10px'; $padding = '2px 6px'; $width = '50px'; }
        if ( $size === 'large' ) { $font_size = '14px'; $padding = '6px 10px'; $width = '80px'; }
        
        $width_int = intval( $width );
        $single_width = ($width_int * 1.5) . 'px';

        $css = "html body .wc-block-components-sale-badge, html body .wc-block-grid__product-onsale, html body .wp-block-woocommerce-product-sale-badge, html body .onsale, html body span.onsale, html body .woocommerce-badges .badge-sale { display: none !important; visibility: hidden !important; opacity: 0 !important; z-index: -999 !important; }
        .cirrusly-badge-pill { background-color: #d63638; color: #fff; font-weight: bold; font-size: {$font_size}; text-transform: uppercase; padding: {$padding}; margin-bottom: 5px; display: inline-block; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); width: fit-content; line-height: 1.2; }
        .cirrusly-badge-pill.cirrusly-new { background-color: #2271b1; }
        .cirrusly-shop-badge-layer { position: absolute; bottom: 10px; left: 10px; z-index: 99; pointer-events: none; display: flex; flex-direction: column; align-items: flex-start; }
        .cirrusly-shop-badge-layer .cirrusly-badge-img { width: {$width} !important; height: auto; display: block; margin: 0; box-shadow: none !important; }
        .cirrusly-badge-container.cirrusly-single-page { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; width: fit-content; }
        .cirrusly-badge-container.cirrusly-single-page .cirrusly-badge-img { width: {$single_width} !important; height: auto; display: block; margin: 0; }
        .cirrusly-badge-container.cirrusly-single-page .cirrusly-badge-pill { margin-bottom: 0; font-size: 14px; padding: 6px 10px; }
        .cirrusly-has-tooltip { cursor: help; position: relative; }
        .cirrusly-has-tooltip:hover::after { content: attr(data-tooltip); position: absolute; bottom: 120%; left: 0; background-color: #333; color: #fff; font-size: 10px; font-weight: normal; text-transform: none; white-space: nowrap; padding: 5px 10px; border-radius: 4px; z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,0.3); pointer-events: none; }
        .cirrusly-badge-wrap { display: block; line-height: 0; width: fit-content; }";

        // Attach to the base frontend style handle
        wp_add_inline_style( 'cirrusly-frontend-base', $css );

        // 2. Frontend JS
        if ( ! is_admin() ) {
            $js = "document.addEventListener('DOMContentLoaded', function() {
                function moveBadges() {
                    var payloads = document.querySelectorAll('.cirrusly-badge-payload');
                    payloads.forEach(function(payload) {
                        var card = payload.closest('li.product, .wc-block-grid__product, .wp-block-post');
                        if (!card || card.closest('.woosb-products')) return;
                        var imgWrap = card.querySelector('.wc-block-grid__product-image, .woocommerce-loop-product__link, .wp-block-post-featured-image');
                        if ( imgWrap && ! imgWrap.querySelector('.cirrusly-shop-badge-layer') ) {
                            var layer = document.createElement('div');
                            layer.className = 'cirrusly-shop-badge-layer';
                            layer.innerHTML = payload.innerHTML;
                            imgWrap.style.position = 'relative';
                            imgWrap.appendChild(layer);
                            payload.remove(); 
                        }
                    });
                }
                var timeout;
                moveBadges();
                var observer = new MutationObserver(function(mutations) { 
                    clearTimeout(timeout);
                    timeout = setTimeout(moveBadges, 100);
                });
                var grid = document.querySelector('.products') || document.querySelector('.wc-block-grid') || document.body;
                if (grid) observer.observe(grid, { childList: true, subtree: true });
            });";

            // Attach to the base frontend script handle
            wp_add_inline_script( 'cirrusly-frontend-base', $js );
        }
    }

    /**
     * Render badge markup for the current product on single product pages.
     *
     * Echoes sanitized badge HTML wrapped in a `.cirrusly-badge-container.cirrusly-single-page`
     * element when a global `$product` is available and badges are present.
     */
    public function render_single_badges() {
        global $product;
        if ( ! $product ) return;
        $html = $this->get_badge_html( $product );
        if ( $html ) echo '<div class="cirrusly-badge-container cirrusly-single-page">' . wp_kses_post( $html ) . '</div>';
    }

    /**
     * Output a hidden container with badge HTML for the current product used in grid and list views.
     *
     * Uses the global $product; if no product or no badge HTML is available nothing is output.
     * When present, echoes a hidden <div class="cirrusly-badge-payload"> containing the badge HTML (sanitized for safe output).
     */
    public function render_grid_payload() {
        global $product;
        if ( ! $product ) return;
        $html = $this->get_badge_html( $product );
        if ( $html ) echo '<div class="cirrusly-badge-payload" style="display:none;">' . wp_kses_post( $html ) . '</div>';
    }

    /**
     * Builds HTML markup for all badges applicable to a given WooCommerce product.
     *
     * This includes sale percentage badges (calculated from MSRP or regular price),
     * a "New" badge for recently created products, custom image badges tied to
     * product tags, and additional "smart" badges provided by the Pro extension
     * when available.
     *
     * @param WC_Product|null $product The product to evaluate. If null or invalid, an empty string is returned.
     * @return string The concatenated HTML markup for all badges applicable to the product, or an empty string if none. 
     */    
    private function get_badge_html( $product ) {
        // [Existing logic unchanged]
        if ( ! $product ) return '';
        
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        $calc_from = isset($badge_cfg['calc_from']) ? $badge_cfg['calc_from'] : 'msrp';
        $new_days = isset($badge_cfg['new_days']) ? intval($badge_cfg['new_days']) : 30;
        $custom_badges = isset($badge_cfg['custom_badges_json']) ? json_decode($badge_cfg['custom_badges_json'], true) : array();

        $output = '';
        $min_threshold = 5; 

        // 1. SMART BADGES (Delegated to Pro Class)
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && class_exists( 'Cirrusly_Commerce_Badges_Pro' ) ) {
            $output .= Cirrusly_Commerce_Badges_Pro::get_smart_badges_html( $product, $badge_cfg );
        }

        // 2. SALE MATH (Free Feature)
        if ( $product->is_on_sale() ) {
            $percentage = 0;
            $prefix = 'Save ';
            $clean = function($v) { return (float) preg_replace('/[^0-9.]/', '', $v); };
            
            $msrp = get_post_meta( $product->get_id(), '_alg_msrp', true );
            if ( !$msrp && $product->is_type('variation') ) {
                $msrp = get_post_meta( $product->get_parent_id(), '_alg_msrp', true );
            }
            
            $reg_price = $clean($product->get_regular_price());
            $base = ($calc_from === 'msrp' && $clean($msrp)) ? $clean($msrp) : $reg_price;
            $sale = $clean($product->get_price());

            if ( $product->is_type('variable') ) {
                $children = $product->get_visible_children();
                $discounts = array();
                foreach ( $children as $child_id ) {
                    $var = wc_get_product($child_id);
                    if ( ! $var ) continue;
                    $v_reg = $clean($var->get_regular_price());
                    $v_sale = $clean($var->get_price());
                    $v_msrp = get_post_meta($child_id, '_alg_msrp', true) ?: get_post_meta($product->get_id(), '_alg_msrp', true);
                    $v_base = ($calc_from === 'msrp' && $clean($v_msrp)) ? $clean($v_msrp) : $v_reg;
                    if ( $v_base > $v_sale && $v_sale > 0 ) {
                        $discounts[] = round( ( ($v_base - $v_sale) / $v_base ) * 100 );
                    }
                }
                if ( ! empty($discounts) ) {
                    $max_p = max($discounts);
                    $min_p = min($discounts);
                    $percentage = $max_p;
                    if ( $min_p !== $max_p ) $prefix = 'Save up to ';
                }
            } elseif ( $base > 0 && $base > $sale ) {
                $percentage = round( ( ($base - $sale) / $base ) * 100 );
            }

            if ( $percentage >= $min_threshold ) {
                $source_text = ($calc_from === 'msrp') ? "MSRP" : "Regular Price";
                $tip = "Discounts calculated from " . $source_text;
                $output .= '<span class="cirrusly-badge-pill cirrusly-has-tooltip" data-tooltip="' . esc_attr($tip) . '">' . $prefix . $percentage . '%</span>';
            }
        }

        // 3. NEW BADGE (Free Feature)
        if ( $new_days > 0 ) {
            $created_date = $product->get_date_created();
            if ( $created_date ) {
                $diff = (time() - $created_date->getTimestamp()) / (60 * 60 * 24);
                if ( $diff <= $new_days ) {
                    $output .= '<span class="cirrusly-badge-pill cirrusly-new">New</span>';
                }
            }
        }

        // 4. CUSTOM BADGES (Free Feature)   
        if ( ! empty( $custom_badges ) && is_array( $custom_badges ) ) {
            foreach ( $custom_badges as $badge ) {
                if ( empty($badge['tag']) || empty($badge['url']) ) continue;
                if ( has_term( $badge['tag'], 'product_tag', $product->get_id() ) ) {
                    $width = !empty($badge['width']) ? intval($badge['width']) . 'px' : '60px';
                    $tooltip_attr = !empty($badge['tooltip']) ? ' class="cirrusly-badge-wrap cirrusly-has-tooltip" data-tooltip="' . esc_attr($badge['tooltip']) . '"' : ' class="cirrusly-badge-wrap"';
                    $output .= '<span' . $tooltip_attr . '>';
                    $output .= '<img src="' . esc_url($badge['url']) . '" style="width:' . esc_attr($width) . ' !important;" class="cirrusly-badge-img" />';
                    $output .= '</span>';
                }
            }
        }

        return $output;
    }
}