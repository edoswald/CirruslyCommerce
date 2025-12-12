<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Dashboard_UI {

    /**
     * Registers the "Cirrusly Commerce Overview" dashboard widget.
     */
    public function register_widget() {
        if ( current_user_can( 'edit_products' ) ) {
            wp_add_dashboard_widget( 'cirrusly_commerce_overview', 'Cirrusly Commerce Overview', array( $this, 'render_wp_dashboard_widget' ) );
        }
    }

    /**
     * Collects and caches store metrics used by the admin dashboard.
     * @return array Metrics data.
     */
    public static function get_dashboard_metrics() {
        $metrics = get_transient( 'cirrusly_dashboard_metrics' );
        
        if ( false === $metrics || ! isset($metrics['low_stock_count']) ) {
            global $wpdb;
            
            // 1. GMC Scan Data
            $scan_data = get_option( 'woo_gmc_scan_data' );
            $gmc_critical = 0; $gmc_warnings = 0;
            if ( ! empty( $scan_data['results'] ) ) {
                foreach( $scan_data['results'] as $res ) {
                    foreach( $res['issues'] as $i ) {
                        if( $i['type'] === 'critical' ) $gmc_critical++; else $gmc_warnings++;
                    }
                }
            }
            
            // 2. Content Scan Data
            $content_data = get_option( 'cirrusly_content_scan_data' );
            $content_issues = 0;
            if ( ! empty( $content_data['issues'] ) ) {
                $content_issues = count( $content_data['issues'] );
            }

            // 3. Catalog & Cost Stats
            $missing_cost = $wpdb->get_var("SELECT count(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_cogs_total_value') WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = 0)");
            
            $count_posts = wp_count_posts('product');
            $count_vars  = wp_count_posts('product_variation');
            $total_products = $count_posts->publish + $count_vars->publish;

            $on_sale_ids = wc_get_product_ids_on_sale();
            $on_sale_count = count( $on_sale_ids );
            
            // 4. Margin & Loss Makers
            $audit_data = get_transient( 'cw_audit_data' );
            $loss_makers = 0;
            $total_margin = 0; 
            $margin_count = 0;

            if ( is_array( $audit_data ) ) {
                foreach ( $audit_data as $row ) {
                    if ( isset( $row['net'] ) && (float) $row['net'] < 0 ) {
                        $loss_makers++;
                    }
                    if ( isset( $row['margin'] ) ) {
                        $total_margin += (float) $row['margin'];
                        $margin_count++;
                    }
                }
            } else {
                // Fallback lightweight query
                $margin_query = $wpdb->get_results("SELECT pm.meta_value as cost, p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_cogs_total_value' AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 200");
                
                foreach($margin_query as $row) {
                    $product = wc_get_product($row->ID);
                    if($product) {
                        $price = (float)$product->get_price(); $cost = (float)$row->cost;
                        if($price > 0 && $cost > 0) { 
                            $margin = (($price - $cost) / $price) * 100; $total_margin += $margin; $margin_count++;
                            if ( $price < $cost ) $loss_makers++;
                        }
                    }
                }
            }
            $avg_margin = $margin_count > 0 ? round($total_margin / $margin_count, 1) : 0;

            // 5. Weekly Revenue Pulse
            $weekly_revenue = 0;
            $weekly_orders = 0;
            $orders = wc_get_orders( array(
                'limit'        => 1000,
                'status'       => array('wc-completed', 'wc-processing'),
                'date_created' => '>=' . wp_date('Y-m-d', strtotime('-7 days')), 
                'return'       => 'ids'
            ) );
            
            if ( ! empty($orders) ) {
                $weekly_orders = count($orders);
                foreach($orders as $oid) {
                    $o = wc_get_order($oid);
                    if($o) $weekly_revenue += $o->get_total();
                }
            }

            // 6. Low Stock
            $low_stock_threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
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
                'missing_cost'   => $missing_cost,
                'loss_makers'    => $loss_makers,
                'total_products' => $total_products,
                'on_sale_count'  => $on_sale_count,
                'avg_margin'     => $avg_margin,
                'weekly_revenue' => $weekly_revenue,
                'weekly_orders'  => $weekly_orders,
                'low_stock_count'=> $low_stock_count
            );
            set_transient( 'cirrusly_dashboard_metrics', $metrics, 1 * HOUR_IN_SECONDS );
        }
        return $metrics;
    }

    /**
     * Renders the Main Dashboard Page.
     * UPDATED: Now static to resolve fatal error.
     */
    public static function render_main_dashboard() {
        echo '<div class="wrap">'; 
        if ( class_exists('Cirrusly_Commerce_Core') ) {
            Cirrusly_Commerce_Core::render_global_header( 'Cirrusly Commerce Dashboard' );
        }
        
        $m = self::get_dashboard_metrics();
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        ?>
        
        <div class="cirrusly-intro-text" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px; border-left:4px solid #2271b1;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h3 style="margin:0 0 5px 0;"><?php esc_html_e( 'Store Pulse (Last 7 Days)', 'cirrusly-commerce' ); ?></h3>
                    <p style="margin:0;"><?php esc_html_e( 'Snapshot of recent performance.', 'cirrusly-commerce' ); ?></p>
                </div>
                <div style="text-align:right;">
                     <span style="font-size:24px; font-weight:bold; color:#008a20;"><?php echo wp_kses_post( wc_price( $m['weekly_revenue'] ) ); ?></span><br>
                     <span style="font-size:12px; color:#555;"><?php echo esc_html( $m['weekly_orders'] ); ?> orders</span>
                </div>
            </div>
        </div>
        
        <div class="cirrusly-dash-grid" style="grid-template-columns: repeat(2, 1fr);">
            
            <div class="cirrusly-dash-card" style="border-top-color: #2271b1;">
                <div class="cirrusly-card-head"><span>Catalog Snapshot</span> <span class="dashicons dashicons-products"></span></div>
                <div class="cirrusly-stat-block" style="border:none; text-align:left; display:flex; gap:20px;">
                    <div><span class="cirrusly-big-num"><?php echo esc_html( $m['total_products'] ); ?></span><span class="cirrusly-label">Products</span></div>
                    <div><span class="cirrusly-big-num" style="color: #d63638;"><?php echo esc_html( $m['on_sale_count'] ); ?></span><span class="cirrusly-label">On Sale</span></div>
                    <div><span class="cirrusly-big-num" style="color: #dba617;"><?php echo esc_html( $m['low_stock_count'] ); ?></span><span class="cirrusly-label">Low Stock</span></div>
                </div>
            </div>
            
            <div class="cirrusly-dash-card" style="border-top-color: #00a32a;">
                <div class="cirrusly-card-head"><span>Profit Engine</span> <span class="dashicons dashicons-money"></span></div>
                <div class="cirrusly-stat-row"><span>Avg Margin (Est.)</span><span class="cirrusly-stat-val" style="color:#00a32a;"><?php echo esc_html( $m['avg_margin'] ); ?>%</span></div>
                <div class="cirrusly-stat-row">
                    <span>Unprofitable Products</span>
                    <span class="cirrusly-stat-val <?php echo $m['loss_makers'] > 0 ? 'cirrusly-val-bad' : 'cirrusly-val-good'; ?>">
                        <?php echo esc_html( $m['loss_makers'] ); ?>
                    </span>
                </div>
                <div class="cirrusly-stat-row" style="border-bottom:none;">
                    <span>Missing Cost Data</span>
                    <span class="cirrusly-stat-val <?php echo $m['missing_cost'] > 0 ? 'cirrusly-val-bad' : 'cirrusly-val-good'; ?>"><?php echo esc_html( $m['missing_cost'] ); ?></span>
                </div>
                <div class="cirrusly-actions"><a href="admin.php?page=cirrusly-audit" class="button button-secondary">Audit Financials</a></div>
            </div>

            <div class="cirrusly-dash-card" style="border-top-color: #d63638;">
                <div class="cirrusly-card-head"><span>GMC Health</span> <span class="dashicons dashicons-google"></span></div>
                <div class="cirrusly-stat-row"><span>Critical Issues</span><span class="cirrusly-stat-val <?php echo $m['gmc_critical'] > 0 ? 'cirrusly-val-bad' : 'cirrusly-val-good'; ?>"><?php echo esc_html( $m['gmc_critical'] ); ?></span></div>
                <div class="cirrusly-stat-row"><span>Warnings</span><span class="cirrusly-stat-val" style="<?php echo $m['gmc_warnings'] > 0 ? 'color:#dba617;' : 'color:#008a20;'; ?>"><?php echo esc_html( $m['gmc_warnings'] ); ?></span></div>
                <div class="cirrusly-stat-row"><span>Content Policy</span><?php echo wp_kses_post( ( $m['content_issues'] > 0 ) ? '<span class="cirrusly-stat-val cirrusly-val-bad">' . esc_html( $m['content_issues'] ) . ' Issues</span>' : '<span class="cirrusly-stat-val cirrusly-val-good">Pass</span>' ); ?></div>
                <div class="cirrusly-stat-row" style="margin-top:15px; padding-top:10px; border-top:1px solid #f0f0f1; border-bottom:none;">
                    <span>Sync Status</span>
                    <?php if($is_pro): ?><span class="gmc-badge" style="background:#008a20;color:#fff;">ACTIVE</span><?php else: ?><span class="gmc-badge" style="background:#ccc;color:#666;">INACTIVE (PRO)</span><?php endif; ?>
                </div>
                <div class="cirrusly-actions"><a href="admin.php?page=cirrusly-gmc&tab=scan" class="button button-primary">Fix Issues</a></div>
            </div>
            
            <div class="cirrusly-dash-card" style="border-top-color: #646970;">
                <div class="cirrusly-card-head"><span>Quick Links</span> <span class="dashicons dashicons-admin-links"></span></div>
                <div class="cirrusly-stat-row"><a href="admin.php?page=cirrusly-gmc&tab=promotions">Promotions Manager</a></div>
                <div class="cirrusly-stat-row"><a href="admin.php?page=cirrusly-settings">Plugin Settings</a></div>
                <div class="cirrusly-stat-row" style="border-bottom:none;"><a href="admin.php?page=cirrusly-manual">User Manual</a></div>
            </div>
        </div>
        </div><?php
    }

    /**
     * Renders the WP Dashboard Widget.
     */
    public function render_wp_dashboard_widget() {
        $m = self::get_dashboard_metrics();
        ?>
        <div class="cirrusly-widget-container" style="display:flex; flex-direction:column; gap:15px;">
            <div style="border-bottom:1px solid #eee; padding-bottom:10px; display:flex; justify-content:space-between; align-items:end;">
                <div><span style="color:#777; font-size:11px; text-transform:uppercase;">Last 7 Days</span><br><span style="font-size:20px; font-weight:600; color:#008a20;"><?php echo wp_kses_post( wc_price($m['weekly_revenue']) ); ?></span></div>
                <div style="text-align:right;"><span style="font-size:12px; color:#555;"><?php echo esc_html($m['weekly_orders']); ?> Orders</span><br><span style="font-size:12px; color:<?php echo esc_attr( ($m['avg_margin'] < 15 ? '#d63638' : '#008a20') ); ?>;"><?php echo esc_html($m['avg_margin']); ?>% Margin</span></div>
            </div>
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:5px;">
                <div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">
                    <span class="dashicons dashicons-google" style="color:#555;"></span> <strong style="display:block; color:<?php echo esc_attr( $m['gmc_critical'] > 0 ? '#d63638' : '#008a20' ); ?>;"><?php echo $m['gmc_critical'] > 0 ? esc_html($m['gmc_critical']) . ' Critical' : 'Healthy'; ?></strong><span style="font-size:10px; color:#777;">GMC</span>
                </div>
                <div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">
                    <span class="dashicons dashicons-warning" style="color:#555;"></span> <strong style="display:block; color:<?php echo esc_attr( $m['loss_makers'] > 0 ? '#d63638' : '#777' ); ?>;"><?php echo esc_html($m['loss_makers']); ?></strong><span style="font-size:10px; color:#777;">Loss Makers</span>
                </div>
                <div style="background:#f9f9f9; padding:10px; border-radius:4px; text-align:center;">
                    <span class="dashicons dashicons-clipboard" style="color:#555;"></span> <strong style="display:block; color:<?php echo esc_attr( $m['missing_cost'] > 0 ? '#d63638' : '#777' ); ?>;"><?php echo esc_html($m['missing_cost']); ?></strong><span style="font-size:10px; color:#777;">No Cost</span>
                </div>
            </div>
            <div style="text-align:center; margin-top:5px;">
                <a class="button button-primary" style="width:100%; text-align:center;" href="<?php echo esc_url( admin_url( 'admin.php?page=cirrusly-commerce' ) ); ?>">Open Full Dashboard</a>
            </div>
        </div>
        <?php
    }
}