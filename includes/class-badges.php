<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Badges {

    public function __construct() {
        add_action( 'wp', array( $this, 'init_frontend_hooks' ) );
    }

    public function init_frontend_hooks() {
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        if ( empty($badge_cfg['enable_badges']) || $badge_cfg['enable_badges'] !== 'yes' ) return;

        add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_badges' ), 5 );
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_grid_payload' ), 99 );
        add_action( 'wp_footer', array( $this, 'render_badge_script' ), 100 );
        add_action( 'wp_head', array( $this, 'print_critical_css' ) );
    }

    public function print_critical_css() {
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        $size = isset($badge_cfg['badge_size']) ? $badge_cfg['badge_size'] : 'medium';
        
        $font_size = '12px'; $padding = '4px 8px'; $width = '60px';
        if ( $size === 'small' ) { $font_size = '10px'; $padding = '2px 6px'; $width = '50px'; }
        if ( $size === 'large' ) { $font_size = '14px'; $padding = '6px 10px'; $width = '80px'; }

        ?>
        <style>
        /* --- 1. NUCLEAR OPTION: HIDE DEFAULT BADGES --- */
        html body .wc-block-components-sale-badge, 
        html body .wc-block-grid__product-onsale,
        html body .wp-block-woocommerce-product-sale-badge,
        html body .onsale,
        html body span.onsale,
        html body .woocommerce-badges .badge-sale { 
            display: none !important; visibility: hidden !important; opacity: 0 !important; z-index: -999 !important;
        }
        /* --- 2. CUSTOM BADGE STYLES --- */
        .cw-badge-pill {
            background-color: #d63638; color: #fff; font-weight: bold; 
            font-size: <?php echo esc_attr($font_size); ?>;
            text-transform: uppercase; 
            padding: <?php echo esc_attr($padding); ?>; 
            margin-bottom: 5px; display: inline-block;
            border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); width: fit-content; line-height: 1.2;
        }
        .cw-badge-pill.cw-new { background-color: #2271b1; }
        
        .cw-shop-badge-layer {
            position: absolute; bottom: 10px; left: 10px; z-index: 99; pointer-events: none;
            display: flex; flex-direction: column; align-items: flex-start;
        }
        .cw-shop-badge-layer .cw-badge-img { 
            width: <?php echo esc_attr($width); ?> !important; 
            height: auto; display: block; margin: 0; box-shadow: none !important; 
        }
        
        .cw-badge-container.cw-single-page { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; width: fit-content; }
        .cw-badge-container.cw-single-page .cw-badge-img { 
            width: <?php echo esc_attr(intval($width) * 1.5) . 'px'; ?> !important; 
            height: auto; display: block; margin: 0; 
        }
        .cw-badge-container.cw-single-page .cw-badge-pill { margin-bottom: 0; font-size: 14px; padding: 6px 10px; }

        /* Tooltip */
        .cw-has-tooltip { cursor: help; position: relative; }
        .cw-has-tooltip:hover::after {
            content: attr(data-tooltip); position: absolute; bottom: 120%; left: 0;
            background-color: #333; color: #fff; font-size: 10px; font-weight: normal;
            text-transform: none; white-space: nowrap; padding: 5px 10px; border-radius: 4px;
            z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,0.3); pointer-events: none;
        }
        .cw-badge-wrap { display: block; line-height: 0; width: fit-content; }
        </style>
        <?php
    }

    public function render_single_badges() {
        global $product;
        if ( ! $product ) return;
        $html = $this->get_badge_html( $product );
        if ( $html ) echo '<div class="cw-badge-container cw-single-page">' . wp_kses_post( $html ) . '</div>';
    }

    public function render_grid_payload() {
        global $product;
        if ( ! $product ) return;
        $html = $this->get_badge_html( $product );
        if ( $html ) echo '<div class="cw-badge-payload" style="display:none;">' . wp_kses_post( $html ) . '</div>';
    }

    public function render_badge_script() {
        if ( is_admin() ) return;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            function moveBadges() {
                var payloads = document.querySelectorAll('.cw-badge-payload');
                payloads.forEach(function(payload) {
                    var card = payload.closest('li.product, .wc-block-grid__product, .wp-block-post');
                    if (!card || card.closest('.woosb-products')) return;
                    var imgWrap = card.querySelector('.wc-block-grid__product-image, .woocommerce-loop-product__link, .wp-block-post-featured-image');
                    if ( imgWrap && ! imgWrap.querySelector('.cw-shop-badge-layer') ) {
                        var layer = document.createElement('div');
                        layer.className = 'cw-shop-badge-layer';
                        layer.innerHTML = payload.innerHTML;
                        imgWrap.style.position = 'relative';
                        imgWrap.appendChild(layer);
                        payload.remove(); 
                    }
                });
            }
            moveBadges();
            var observer = new MutationObserver(function(mutations) { moveBadges(); });
            var grid = document.querySelector('.products') || document.querySelector('.wc-block-grid') || document.body;
            if (grid) observer.observe(grid, { childList: true, subtree: true });
        });
        </script>
        <?php
    }

    /**
     * Builds HTML for product badges based on site configuration and the product's state.
     *
     * Generates zero or more badge elements for:
     * - premium "SMART" badges (Inventory, Performance, Scheduler) when the site is PRO and corresponding features are enabled,
     * - sale-based discount badges calculated from MSRP or regular price,
     * - "New" arrival badges based on product age,
     * - custom tag-based image badges configured via JSON.
     *
     * @param \WC_Product|null $product The product to evaluate. If null or invalid, an empty string is returned.
     * @return string HTML containing the concatenated badge elements (may be an empty string).
     */
    private function get_badge_html( $product ) {
        if ( ! $product ) return '';
        
        $badge_cfg = get_option( 'cirrusly_badge_config', array() );
        $calc_from = isset($badge_cfg['calc_from']) ? $badge_cfg['calc_from'] : 'msrp';
        $new_days = isset($badge_cfg['new_days']) ? intval($badge_cfg['new_days']) : 30;
        $custom_badges = isset($badge_cfg['custom_badges_json']) ? json_decode($badge_cfg['custom_badges_json'], true) : array();

        $output = '';
        $min_threshold = 5; 

    /* --------------------------------------------------------- */ 
    /* fs_premium_only start                                     */
    /* --------------------------------------------------------- */
    
    // Only render SMART badges for PRO users
    $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
    
    // 1. SMART: INVENTORY (Low Stock)
    if ( $is_pro && ! empty($badge_cfg['smart_inventory']) && $badge_cfg['smart_inventory'] === 'yes' ) {
        if ( $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() < 5 ) {
            $output .= '<span class="cw-badge-pill" style="background-color:#dba617;">Low Stock</span>';
        }
    }

    // 2. SMART: PERFORMANCE (Best Seller)
    // Check if total sales > 50 (Simple threshold) or use transient for Top 10
    if ( $is_pro && ! empty($badge_cfg['smart_performance']) && $badge_cfg['smart_performance'] === 'yes' ) {
        // Optimization: Check simple sales count to avoid heavy queries on every load
        if ( $product->get_total_sales() > 50 ) {
            $output .= '<span class="cw-badge-pill" style="background-color:#00a32a;">Best Seller</span>';
        }
    }

    // 3. SMART: SCHEDULER (Date Range)
    if ( $is_pro && ! empty($badge_cfg['smart_scheduler']) && $badge_cfg['smart_scheduler'] === 'yes' ) {
        $start = !empty($badge_cfg['scheduler_start']) ? strtotime($badge_cfg['scheduler_start']) : 0;
        $end   = !empty($badge_cfg['scheduler_end']) ? strtotime($badge_cfg['scheduler_end']) : 0;
        $now   = current_time('timestamp');

        if ( $start && $end && $now >= $start && $now <= $end ) {
            $output .= '<span class="cw-badge-pill" style="background-color:#826eb4;">Event</span>';
        }

    // 4. SMART BADGE: SENTIMENT (Customer Love)
        // Check if Pro AND connected to Google NLP
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            // FIX: Assumes Cirrusly_Commerce_GMC::get_google_client() is available in the environment.
            $client = Cirrusly_Commerce_GMC::get_google_client();
            
            if ( ! is_wp_error( $client ) ) {
                $sentiment_badge = $this->get_sentiment_badge( $product, $client );
                if ( $sentiment_badge ) $output .= $sentiment_badge;
            }
        }
    } // Closing brace for the L205 IF statement (if it was intended)

    /* --------------------------------------------------------- */
    /* fs_premium_only end                                       */
    /* --------------------------------------------------------- */

        // 1. SALE MATH
        if ( $product->is_on_sale() ) {
            $percentage = 0;
            $prefix = 'Save ';
            
            $clean = function($v) { return (float) preg_replace('/[^0-9.]/', '', $v); };
            
            // Base Price Logic
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
                $output .= '<span class="cw-badge-pill cw-has-tooltip" data-tooltip="' . esc_attr($tip) . '">' . $prefix . $percentage . '%</span>';
            }
        }

        // 2. NEW ARRIVAL BADGE
        if ( $new_days > 0 ) {
            $created_date = $product->get_date_created();
            if ( $created_date ) {
                $created_ts = $created_date->getTimestamp();
                $diff = (time() - $created_ts) / (60 * 60 * 24); // Days
                if ( $diff <= $new_days ) {
                    $output .= '<span class="cw-badge-pill cw-new">New</span>';
                }
            }
        }

        // 3. CUSTOM TAG BADGES
        if ( ! empty( $custom_badges ) && is_array( $custom_badges ) ) {
            foreach ( $custom_badges as $badge ) {
                if ( empty($badge['tag']) || empty($badge['url']) ) continue;
                
                if ( has_term( $badge['tag'], 'product_tag', $product->get_id() ) ) {
                    $width = !empty($badge['width']) ? intval($badge['width']) . 'px' : '60px';
                    $tooltip_attr = !empty($badge['tooltip']) ? ' class="cw-badge-wrap cw-has-tooltip" data-tooltip="' . esc_attr($badge['tooltip']) . '"' : ' class="cw-badge-wrap"';
                    
                    $output .= '<span' . $tooltip_attr . '>';
                    $output .= '<img src="' . esc_url($badge['url']) . '" style="width:' . esc_attr($width) . ' !important;" class="cw-badge-img" />';
                    $output .= '</span>';
                }
            }
        }

        return $output;
    }

    /**
     * Analyze recent reviews using Google NLP to determine if "Customer Favorite"
     */
    private function get_sentiment_badge( $product, $client ) {
        // Simple caching to avoid API costs/latency on every page load
        $cache_key = 'cc_sentiment_' . $product->get_id();
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return $cached;

        $comments = get_comments( array( 'post_id' => $product->get_id(), 'number' => 5, 'status' => 'approve' ) );
        if ( empty( $comments ) ) {
            set_transient( $cache_key, '', DAY_IN_SECONDS );
            return '';
        }

        try {
            $service = new Google\Service\CloudNaturalLanguage( $client );
            $total_score = 0;
            $count = 0;

            foreach ( $comments as $comment ) {
                $doc = new Google\Service\CloudNaturalLanguage\Document();
                $doc->setContent( $comment->comment_content );
                $doc->setType( 'PLAIN_TEXT' );
                
                $request = new Google\Service\CloudNaturalLanguage\AnalyzeSentimentRequest();
                $request->setDocument($doc);

                $resp = $service->documents->analyzeSentiment( $request );
                $score = $resp->getDocumentSentiment()->getScore();
                
                $total_score += $score;
                $count++;
            }

            // If average sentiment is > 0.6 (Highly Positive)
            if ( $count > 0 && ($total_score / $count) > 0.6 ) {
                $html = '<span class="cw-badge-pill" style="background-color:#e0115f;">Customer Fave ❤️</span>';
                set_transient( $cache_key, $html, 7 * DAY_IN_SECONDS );
                return $html;
            }

        } catch ( Exception $e ) {
            // fail silently
        }
        
        set_transient( $cache_key, '', DAY_IN_SECONDS );
        return '';
    }
}