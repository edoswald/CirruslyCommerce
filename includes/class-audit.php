<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit {

    /**
     * Registers audit hooks and loads audit sub-modules.
     *
     * When running in the admin area, loads the audit admin UI class. If the Pro
     * edition is active and the Pro audit file exists, loads the Pro audit module
     * and calls its initializer.
     */
    public static function init() {
        // 1. Load UI (Admin Only)
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-audit-ui.php';
        }

        // 2. Load Pro Features (Export/Import)
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-audit-pro.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-audit-pro.php';
            Cirrusly_Commerce_Audit_Pro::init();
        }
    }

    /**
     * Delegates rendering of the audit page to the audit UI if the UI class is available.
     */
    public static function render_page() {
        if ( class_exists( 'Cirrusly_Commerce_Audit_UI' ) ) {
            Cirrusly_Commerce_Audit_UI::render_page();
        }
    }

    /**
     * Build per-product financial audit data used by the admin table and CSV export.
     *
     * Reads cached results from the transient `cirrusly_audit_data` and, if absent or when
     * `$force_refresh` is true, recomputes per-product financial metrics (costs,
     * shipping, revenue tiers, fees, margin, net, alerts, categories and pricing
     * metadata) and caches the result for 1 hour.
     *
     * @param bool $force_refresh If true, bypass cached data and recompute results.
     * @return array[] Array of associative arrays, one per product, with the following keys:
     * - id: product ID
     * - name: product name
     * - type: product type
     * - parent_id: parent product ID (for variations)
     * - cost: total cost (item cost + shipping cost)
     * - item_cost: item cost only
     * - ship_cost: estimated shipping cost
     * - ship_charge: shipping charge / revenue applied
     * - ship_pl: shipping profit/loss (ship_charge - ship_cost)
     * - price: product price
     * - net: net profit after fees
     * - margin: gross margin percentage
     * - alerts: array of HTML badge strings for issues (e.g., missing cost/weight)
     * - is_in_stock: boolean stock status
     * - cats: array of product category slugs
     * - map: MAP price meta
     * - min_price: minimum auto-pricing value
     * - msrp: MSRP meta
     */
    public static function get_compiled_data( $force_refresh = false ) {
        $cache_key = 'cirrusly_audit_data';
        $data = get_transient( $cache_key );
        
        // MIGRATION: Check for old transient and migrate if found
        if ( false === $data ) {
            $old_data = get_transient( 'cw_audit_data' );
            if ( false !== $old_data ) {
                $data = $old_data;
                set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
                delete_transient( 'cw_audit_data' );
            }
        }
        
        if ( false === $data || $force_refresh ) {
            // FIXED: Replaced unsafe/missing Core method call with direct option retrieval.
            $config = get_option( 'cirrusly_scan_config', array() );
            
            // Added isset checks to prevent undefined index warnings
            $revenue_tiers = isset($config['revenue_tiers_json']) ? json_decode( $config['revenue_tiers_json'], true ) : array();
            $class_costs = isset($config['class_costs_json']) ? json_decode( $config['class_costs_json'], true ) : array();
            
            // Payment Fee Logic
            $mode = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';
            $pay_pct = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
            $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
            $pay_pct_2 = isset($config['payment_pct_2']) ? ($config['payment_pct_2'] / 100) : 0.0349;
            $pay_flat_2 = isset($config['payment_flat_2']) ? $config['payment_flat_2'] : 0.49;
            $split = isset($config['profile_split']) ? ($config['profile_split'] / 100) : 1.0;

            $get_rev = function($p_price) use ($revenue_tiers) { 
                if($revenue_tiers) {
                    foreach($revenue_tiers as $t) if($p_price>=$t['min'] && $p_price<=$t['max']) return $t['charge']; 
                }
                return 0; 
            };

            $args = array( 'post_type' => array('product','product_variation'), 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' );
            $ids = get_posts($args);
            $data = array();
            
            foreach($ids as $pid) {
                $p = wc_get_product($pid); if(!$p) continue;
                
                $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
                
                // --- Financials ---
                $cost = (float) get_post_meta( $p->get_id(), '_cogs_total_value', true );
                $ship_cost = (float)$p->get_meta('_cw_est_shipping');
                
                // --- Custom Pricing Fields ---
                $map = (float)$p->get_meta('_cirrusly_map_price');
                $min_price = (float)$p->get_meta('_auto_pricing_min_price');
                $msrp = (float)$p->get_meta('_alg_msrp');
                
                // Fallback Ship Cost Logic
                if($ship_cost <= 0 && !$is_shipping_exempt) {
                    $cid = $p->get_shipping_class_id();
                    $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
                    if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
                    elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
                } elseif ( $is_shipping_exempt ) {
                    $ship_cost = 0;
                }
                
                $price = (float)$p->get_price();
                $rev = $is_shipping_exempt ? 0 : $get_rev($price);
                
                $total_inc = $price + $rev;
                $total_cost = $cost + $ship_cost;
                $margin = 0; $net = 0;
                
                if($price > 0 && $total_cost > 0) {
                    $gross = $total_inc - $total_cost;
                    $margin = ($gross/$price)*100;
                    
                    if ( $mode === 'multi' ) {
                        $fee1 = ($total_inc * $pay_pct) + $pay_flat;
                        $fee2 = ($total_inc * $pay_pct_2) + $pay_flat_2;
                        $fee = ($fee1 * $split) + ($fee2 * (1 - $split));
                    } else {
                        $fee = ($total_inc * $pay_pct) + $pay_flat;
                    }
                    $net = $gross - $fee;
                }
                
                $alerts = array();
                if($cost <= 0) $alerts[] = '<a href="'.esc_url(get_edit_post_link($pid)).'" target="_blank" class="gmc-badge" style="background:#d63638;color:#fff;text-decoration:none;">Add Cost</a>';
                
                if( !$is_shipping_exempt && (float)$p->get_weight() <= 0) $alerts[] = '<span class="gmc-badge" style="background:#dba617;color:#000;">0 Weight</span>';
                
                $ship_pl = $rev - $ship_cost;
                
                $data[] = array(
                    'id' => $pid,
                    'name' => $p->get_name(),
                    'type' => $p->get_type(),
                    'parent_id' => $p->get_parent_id(),
                    'cost' => $total_cost,
                    'item_cost' => $cost,
                    'ship_cost' => $ship_cost,
                    'ship_charge' => $rev,
                    'ship_pl' => $ship_pl,
                    'price' => $price,
                    'net' => $net,
                    'margin' => $margin,
                    'alerts' => $alerts,
                    'is_in_stock' => $p->is_in_stock(),
                    'cats' => wp_get_post_terms($pid, 'product_cat', array('fields'=>'slugs')),
                    'map' => $map,
                    'min_price' => $min_price,
                    'msrp' => $msrp
                );
            }
            set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
        }
        return $data;
    }

    /**
     * Compute core financial metrics for a single product.
     *
     * @param int $pid The product ID to evaluate.
     * @return array|false An associative array of computed metrics, or `false` if the product does not exist.
     *
     * Returned array keys:
     * - `net_val` (float): Net profit value after costs and fees.
     * - `net_html` (string): Formatted net value using `wc_price`.
     * - `net_style` (string): CSS style to apply to the net value (red for negative, green for positive).
     * - `margin` (string): Margin percentage formatted to one decimal place.
     * - `margin_val` (float): Margin percentage numeric value.
     * - `ship_pl_html` (string): Formatted shipping profit/loss (revenue minus shipping cost) using `wc_price`.
     * - `cost_html` (string): Formatted total cost (item cost plus shipping) using `wc_price`.
     */
    public static function get_single_metric( $pid ) {
        $p = wc_get_product($pid);
        if(!$p) return false;

        // FIXED: Replaced unsafe/missing Core method call with direct option retrieval.
        $config = get_option( 'cirrusly_scan_config', array() );
        
        $revenue_tiers = isset($config['revenue_tiers_json']) ? json_decode( $config['revenue_tiers_json'], true ) : array();
        $class_costs   = isset($config['class_costs_json']) ? json_decode( $config['class_costs_json'], true ) : array();
        
        $mode = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';
        $pay_pct       = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat      = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        $pay_pct_2     = isset($config['payment_pct_2']) ? ($config['payment_pct_2'] / 100) : 0.0349;
        $pay_flat_2    = isset($config['payment_flat_2']) ? $config['payment_flat_2'] : 0.49;
        $split         = isset($config['profile_split']) ? ($config['profile_split'] / 100) : 1.0;
        
        $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
        
        $cost = (float) get_post_meta( $p->get_id(), '_cogs_total_value', true );
        $ship_cost = (float)$p->get_meta('_cw_est_shipping');
        
        if($ship_cost <= 0 && !$is_shipping_exempt) {
            $cid = $p->get_shipping_class_id();
            $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
            if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
            elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
        } elseif ( $is_shipping_exempt ) {
            $ship_cost = 0;
        }

        $price = (float)$p->get_price();
        $rev = 0;
        if ( !$is_shipping_exempt && $revenue_tiers ) {
            foreach($revenue_tiers as $t) if($price>=$t['min'] && $price<=$t['max']) { $rev = $t['charge']; break; }
        }

        $total_inc = $price + $rev;
        $total_cost = $cost + $ship_cost;
        $margin = 0; $net = 0;
        
        if($price > 0 && $total_cost > 0) {
            $gross = $total_inc - $total_cost;
            $margin = ($gross/$price)*100;
           
            if ( $mode === 'multi' ) {
                $fee1 = ($total_inc * $pay_pct) + $pay_flat;
                $fee2 = ($total_inc * $pay_pct_2) + $pay_flat_2;
                $fee = ($fee1 * $split) + ($fee2 * (1 - $split));
            } else {
                $fee = ($total_inc * $pay_pct) + $pay_flat;
            }
            $net = $gross - $fee;
        }
        
        $ship_pl = $rev - $ship_cost;
        $net_style = $net < 0 ? 'color:#d63638;font-weight:bold;' : 'color:#008a20;font-weight:bold;';
        
        return array(
            'net_val' => $net,
            'net_html' => wc_price($net),
            'net_style' => $net_style,
            'margin' => number_format($margin, 1),
            'margin_val' => $margin,
            'ship_pl_html' => wc_price($ship_pl), 
            'cost_html' => wc_price($total_cost) 
        );
    }
}