<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Dashboard_UI {

    /**
     * Registers the Cirrusly Commerce Overview dashboard widget for users with product edit permissions.
     *
     * Adds a WordPress dashboard widget titled "Cirrusly Commerce Overview" and hooks it to the widget renderer when the current user has the 'edit_products' capability.
     */
    public function register_widget() {
        if ( current_user_can( 'edit_products' ) ) {
            wp_add_dashboard_widget( 'cirrusly_commerce_overview', 'Cirrusly Commerce Overview', array( $this, 'render_wp_dashboard_widget' ) );
        }
    }

    /**
     * Renders the Cirrusly Commerce main admin dashboard page.
     *
     * Outputs the dashboard UI including the Store Pulse (last 7 days), catalog and margin summary,
     * Google Merchant Center status, store integrity metrics, and quick links. The displayed data
     * reflect current dashboard metrics and respect PRO feature gating.
     */
    public function render_main_dashboard() {
        echo '<div class="wrap">'; 
        echo '<h1>' . esc_html__( 'Cirrusly Commerce Dashboard', 'cirrusly-commerce' ) . '</h1>';
        $m = self::get_dashboard_metrics();
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        ?>
        
        <div class="cc-intro-text" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px; border-left:4px solid #2271b1;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h3 style="margin:0 0 5px 0;"><?php esc_html_e( 'Store Pulse (Last 7 Days)', 'cirrusly-commerce' ); ?></h3>
                    <p style="margin:0;"><?php esc_html_e( 'Snapshot of recent performance.', 'cirrusly-commerce' ); ?></p>
                </div>
                <div style="text-align:right;">
                     <span style="font-size:24px; font-weight:bold; color:#008a20;"><?php echo wc_price( $m['weekly_revenue'] ); ?></span><br>
                     <span style="font-size:12px; color:#555;"><?php echo esc_html( $m['weekly_orders'] ); ?> orders</span>
                </div>
            </div>
        </div>
        
        <div class="cc-dash-grid" style="grid-template-columns: repeat(2, 1fr);">
            
            <div class="cc-dash-card" style="border-top-color: #2271b1;">
                <div class="cc-card-head"><span>Catalog Snapshot</span> <span class="dashicons dashicons-products"></span></div>
                <div class="cc-stat-block" style="border:none; text-align:left; display:flex; gap:20px;">
                    <div><span class="cc-big-num"><?php echo esc_html( $m['total_products'] ); ?></span><span class="cc-label">Products</span></div>
                    <div><span class="cc-big-num" style="color: #d63638;"><?php echo esc_html( $m['on_sale_count'] ); ?></span><span class="cc-label">On Sale</span></div>
                    <div><span class="cc-big-num" style="color: #dba617;"><?php echo esc_html( $m['low_stock_count'] ); ?></span><span class="cc-label">Low Stock</span></div>
                </div>
            </div>
            
            <div class="cc-dash-card" style="border-top-color: #00a32a;">
                <div class="cc-card-head"><span>Profit Engine</span> <span class="dashicons dashicons-money"></span></div>
                <div class="cc-stat-row"><span>Avg Margin (Est.)</span><span class="cc-stat-val" style="color:#00a32a;"><?php echo esc_html( $m['avg_margin'] ); ?>%</span></div>
                <div class="cc-stat-row">
                    <span>Unprofitable Products</span>
                    <span class="cc-stat-val <?php echo $m['loss_makers'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>">
                        <?php echo esc_html( $m['loss_makers'] ); ?>
                    </span>
                </div>
                <div class="cc-stat-row" style="border-bottom:none;">
                    <span>Missing Cost Data</span>
                    <span class="cc-stat-val <?php echo $m['missing_cost'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>"><?php echo esc_html( $m['missing_cost'] ); ?></span>
                </div>
                <div class="cc-actions"><a href="admin.php?page=cirrusly-audit" class="button button-secondary">Audit Financials</a></div>
            </div>

            <div class="cc-dash-card" style="border-top-color: #d63638;">
                <div class="cc-card-head"><span>GMC Health</span> <span class="dashicons dashicons-google"></span></div>
                
                <div class="cc-stat-row">
                    <span>Critical Issues</span>
                    <span class="cc-stat-val <?php echo $m['gmc_critical'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>"><?php echo esc_html( $m['gmc_critical'] ); ?></span>
                </div>
                
                <div class="cc-stat-row">
                    <span>Warnings</span>
                    <span class="cc-stat-val" style="<?php echo $m['gmc_warnings'] > 0 ? 'color:#dba617;' : 'color:#008a20;'; ?>">
                        <?php echo esc_html( $m['gmc_warnings'] ); ?>
                    </span>
                </div>
                
                <div class="cc-stat-row">
                    <span>Content Policy</span>
                    <?php if ( $m['content_issues'] > 0 ): ?>
                        <span class="cc-stat-val cc-val-bad"><?php echo esc_html( $m['content_issues'] ); ?> Issues</span>
                    <?php else: ?>
                        <span class="cc-stat-val cc-val-good">Pass</span>
                    <?php endif; ?>
                </div>

                <div class="cc-stat-row" style="margin-top:15px; padding-top:10px; border-top:1px solid #f0f0f1; border-bottom:none;">
                    <span>Sync Status</span>
                    <?php if($is_pro): ?>
                        <span class="gmc-badge" style="background:#008a20;color:#fff;">ACTIVE</span>
                    <?php else: ?>
                        <span class="gmc-badge" style="background:#ccc;color:#666;">INACTIVE (PRO)</span>
                    <?php endif; ?>
                </div>
                <div class="cc-actions">
                    <a href="admin.php?page=cirrusly-gmc&tab=scan" class="button button-primary">Fix Issues</a>
                </div>
            </div>
            
            <div class="cc-dash-card" style="border-top-color: #646970;">
                <div class="cc-card-head"><span>Quick Links</span> <span class="dashicons dashicons-admin-links"></span></div>
                <div class="cc-stat-row"><a href="admin.php?page=cirrusly-gmc&tab=promotions">Promotions Manager</a></div>
                <div class="cc-stat-row"><a href="admin.php?page=cirrusly-settings">Plugin Settings</a></div>
                <div class="cc-stat-row" style="border-bottom:none;"><a href="admin.php?page=cirrusly-manual">User Manual</a></div>
            </div>
        </div>
        </div><?php
    }

    /**
     * Render the Cirrusly Commerce overview widget in the WordPress dashboard.
     *
     * Displays a compact overview including last 7 days revenue and orders, average margin,
     * Google Merchant Center health, loss makers, missing cost count, and a button linking
     * to the full Cirrusly Commerce dashboard.
     */
    public function render_wp_dashboard_widget() {
        $m = self::get_dashboard_metrics();
        
        echo '<div class="cc-widget-container" style="display:flex; flex-direction:column; gap:15px;">';
        
        // 1. Revenue Pulse (Mini)
        echo '<div style="border-bottom:1px solid #eee; padding-bottom:10px; display:flex; justify-content:space-between; align-items:end;">';
            echo '<div><span style="color:#777; font-size:11px; text-transform:uppercase;">Last 7 Days</span><br><span style="font-size:20px; font-weight:600; color:#008a20;">' . wc_price($m['weekly_revenue']) . '</span></div>';
            echo '<div style="text-align:right;"><span style="font-size:12px; color:#555;">' . esc_html( $m['weekly_orders'] ) . ' Orders</span><br><span style="font-size:12px; color:' . ($m['avg_margin'] < 15 ? '#d63638' : '#008a20') . ';">' . esc_html( $m['avg_margin'] ) . '% Margin</span></div>';
        echo '</div>';

        // 2. Health Grid
        echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:5px;">';
            
            // GMC Health
            $gmc_color = $m['gmc_critical'] > 0 ? '#d63638' : '#008a20';
            $gmc_label = $m['gmc_critical'] > 0 ? $m['gmc_critical'] . ' Critical' : 'Healthy';
            echo '<div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">';
                echo '<span class="dashicons dashicons-google" style="color:#555;"></span> <strong style="display:block; color:'. $gmc_color .';">' . $gmc_label . '</strong><span style="font-size:10px; color:#777;">GMC</span>';
            echo '</div>';

            // Loss Makers
            $loss_color = $m['loss_makers'] > 0 ? '#d63638' : '#777';
            echo '<div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">';
                echo '<span class="dashicons dashicons-warning" style="color:#555;"></span> <strong style="display:block; color:'. $loss_color .';">' . $m['loss_makers'] . '</strong><span style="font-size:10px; color:#777;">Loss Makers</span>';
            echo '</div>';

            // Missing Cost
            $cost_color = $m['missing_cost'] > 0 ? '#d63638' : '#777';
            echo '<div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">';
                echo '<span class="dashicons dashicons-clipboard" style="color:#555;"></span> <strong style="display:block; color:'. $cost_color .';">' . $m['missing_cost'] . '</strong><span style="font-size:10px; color:#777;">No Cost</span>';
            echo '</div>';
            
        echo '</div>';

        // 3. Pro Action / Footer
        echo '<div style="text-align:center; margin-top:5px;">';
            echo '<a class="button button-primary" style="width:100%; text-align:center;" href="' . esc_url( admin_url( 'admin.php?page=cirrusly-commerce' ) ) . '">Open Full Dashboard</a>';
        echo '</div>';
        
        echo '</div>';
    }

    /**
     * Collects and returns cached store metrics used by the admin dashboard.
     *
     * If cached metrics are missing or incomplete, computes metrics (GMC scan counts,
     * content issues, catalog and cost stats, margin and loss-maker estimates,
     * 7-day revenue/orders pulse, and low-stock count) and stores the result in a
     * transient for one hour.
     *
     * @return array{
     * gmc_critical:int,    // Number of critical Google Merchant Center issues.
     * gmc_warnings:int,    // Number of non-critical Google Merchant Center warnings.
     * content_issues:int,  // Count of content scan issues.
     * missing_cost:int,    // Count of products/variations missing cost data.
     * loss_makers:int,     // Estimated count of products with negative net (loss makers).
     * total_products:int,  // Total published products and product variations.
     * on_sale_count:int,   // Number of products currently on sale.
     * avg_margin:float,    // Average margin percentage across sampled products (rounded to 1 decimal).
     * weekly_revenue:float,// Total revenue from completed/processing orders in the last 7 days.
     * weekly_orders:int,   // Number of completed/processing orders in the last 7 days.
     * low_stock_count:int  // Count of published products/variations with stock > 0 and <= low-stock threshold.
     * }
     */
    public static function get_dashboard_metrics() {
        $metrics = get_transient( 'cirrusly_dashboard_metrics' );
        
        // Ensure new keys exist if served from old cache
        if ( false === $metrics || ! isset($metrics['low_stock_count']) ) {
            global $wpdb;
            
            // 1. GMC Scan Data
            $scan_data = get_option( 'woo_gmc_scan_data' );
            $gmc_critical = 0; $gmc_warnings = 0;
            if ( is_array( $scan_data ) && ! empty( $scan_data['results'] ) && is_array( $scan_data['results'] ) ) {
                foreach( $scan_data['results'] as $res ) {
                    if ( empty( $res['issues'] ) || ! is_array( $res['issues'] ) ) {
                        continue;
                    }
                    foreach( $res['issues'] as $i ) {
                        if ( ! isset( $i['type'] ) ) continue;
                        if( $i['type'] === 'critical' ) $gmc_critical++; else $gmc_warnings++;
                    }
                }
            }
            
            // 2. Content Scan Data
            $content_data = get_option( 'cirrusly_content_scan_data' );
            $content_issues = 0;
            if ( is_array( $content_data ) && ! empty( $content_data['issues'] ) && is_array( $content_data['issues'] ) ) {
                $content_issues = count( $content_data['issues'] );
            }

            // 3. Catalog & Cost Stats
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $missing_cost = $wpdb->get_var("SELECT count(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_cogs_total_value') WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = 0)");
            
            $count_posts = wp_count_posts('product');
            $count_vars  = wp_count_posts('product_variation');
            $total_products = (int) $count_posts->publish + (int) $count_vars->publish;

            $on_sale_ids = wc_get_product_ids_on_sale();
            $on_sale_count = is_array( $on_sale_ids ) ? count( $on_sale_ids ) : 0;
            
            // 4. Margin & Loss Makers
            $audit_data = get_transient( 'cw_audit_data' );
            $loss_makers = 0;
            $total_margin = 0; 
            $margin_count = 0;

            if ( is_array($audit_data) ) {
                foreach($audit_data as $row) {
                    if ( isset( $row['net'] ) && (float) $row['net'] < 0 ) $loss_makers++;
                    if ( isset($row['margin']) ) { $total_margin += (float) $row['margin']; $margin_count++; }
                }
            } else {
                // Fallback lightweight query
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $margin_query = $wpdb->get_results("SELECT pm.meta_value as cost, p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_cogs_total_value' AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 200");
                
                if ( is_array( $margin_query ) ) {
                    foreach($margin_query as $row) {
                        $product = wc_get_product($row->ID);
                        if($product) {
                            $price = (float)$product->get_price(); 
                            $cost = isset( $row->cost ) ? (float)$row->cost : 0;
                            if($price > 0 && $cost > 0) { 
                                $margin = (($price - $cost) / $price) * 100; $total_margin += $margin; $margin_count++;
                                if ( $price < $cost ) $loss_makers++; // Rough Estimate in fallback
                            }
                        }
                    }
                }
            }
            $avg_margin = $margin_count > 0 ? round($total_margin / $margin_count, 1) : 0;

            // 5. Weekly Revenue Pulse
            $weekly_revenue = 0;
            $weekly_orders = 0;
            
            // Lightweight 7-day lookback
            // Fix: Use string comparison instead of array for compatibility with HPOS
            $orders = wc_get_orders( array(
                'limit'        => 1000, // Cap to prevent memory issues on high-volume stores
                'status'       => array('wc-completed', 'wc-processing'),
                'date_created' => '>=' . wp_date('Y-m-d', strtotime('-7 days')), 
                'return'       => 'ids',
                'no_found_rows'=> true // Optimization
            ) );
            
            if ( ! empty($orders) && is_array( $orders ) ) {
                $weekly_orders = count($orders);
                foreach($orders as $oid) {
                    $o = wc_get_order($oid);
                    if($o) $weekly_revenue += (float) $o->get_total();
                }
            }

            // 6. Low Stock (New)
            $low_stock_threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $low_stock_count = $wpdb->get_var( $wpdb->prepare( "
                SELECT count(p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
                INNER JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id AND pm_manage.meta_key = '_manage_stock' AND pm_manage.meta_value = 'yes'
                LEFT JOIN {$wpdb->postmeta} pm_low ON p.ID = pm_low.post_id AND pm_low.meta_key = '_low_stock_amount'
                WHERE p.post_type IN ('product', 'product_variation') 
                AND p.post_status = 'publish' 
                AND CAST(pm_stock.meta_value AS SIGNED) > 0
                AND CAST(pm_stock.meta_value AS SIGNED) <= COALESCE( NULLIF(pm_low.meta_value, ''), %d )
            ", $low_stock_threshold ) );

            $metrics = array(
                'gmc_critical'   => $gmc_critical,
                'gmc_warnings'   => $gmc_warnings,
                'content_issues' => $content_issues,
                'missing_cost'   => (int) $missing_cost,
                'loss_makers'    => $loss_makers,
                'total_products' => $total_products,
                'on_sale_count'  => $on_sale_count,
                'avg_margin'     => $avg_margin,
                'weekly_revenue' => $weekly_revenue,
                'weekly_orders'  => $weekly_orders,
                'low_stock_count'=> (int) $low_stock_count
            );
            set_transient( 'cirrusly_dashboard_metrics', $metrics, 1 * HOUR_IN_SECONDS );
        }
        return $metrics;
    }
}
?>