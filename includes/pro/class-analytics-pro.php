<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Analytics_Pro {

    public function __construct() {
        // 1. Admin Menu
        add_action( 'admin_menu', array( $this, 'register_analytics_page' ), 20 );
        
        // 2. Assets (Chart.js)
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 3. GMC History Recorder (Hooks after the main daily scan)
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'capture_daily_gmc_snapshot' ), 20 );
    }

    /**
     * Register the submenu.
     */
    public function register_analytics_page() {
        add_submenu_page(
            'cirrusly-commerce',
            'Pro Plus Analytics',
            'Analytics',
            'manage_woocommerce',
            'cirrusly-analytics',
            array( $this, 'render_analytics_view' )
        );
    }

    /**
     * Load Chart.js for visualization.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'cirrusly-analytics' ) === false ) {
            return;
        }

        // Removed CDN to comply with Plugin Directory guidelines.
        // Ensure chart.umd.min.js is present in assets/js/vendor/
        wp_enqueue_script( 'cc-chartjs', CIRRUSLY_COMMERCE_URL . 'assets/js/vendor/chart.umd.min.js', array(), '4.4.0', true );
        
        // Inline CSS for the analytics dashboard
        wp_enqueue_style( 'cc-analytics-styles', false );
        wp_add_inline_style( 'cc-analytics-styles', "
            .cc-dashboard-split { display: grid; grid-template-columns: 280px 1fr; gap: 20px; margin-bottom: 20px; }
            .cc-metrics-col { display: flex; flex-direction: column; gap: 15px; }
            .cc-chart-col { background: #fff; padding: 20px; border-radius: 4px; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative; display: flex; flex-direction: column; }
            
            .cc-metric-card { background: #fff; padding: 20px; border-radius: 4px; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .cc-metric-card h3 { margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; }
            .cc-metric-val { font-size: 24px; font-weight: 600; color: #1d2327; }
            .cc-metric-sub { font-size: 12px; color: #646970; margin-top: 5px; }
            
            .cc-trend-up { color: #008a20; }
            .cc-trend-down { color: #d63638; }
            
            .cc-table-wrapper { background: #fff; border: 1px solid #c3c4c7; margin-bottom: 20px; }
            .cc-section-title { font-size: 1.2em; padding: 15px; margin: 0; border-bottom: 1px solid #eaecf0; background: #fbfbfb; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
            
            .cc-header-actions { float: right; margin-bottom: 10px; }

            /* Responsive adjustments */
            @media (max-width: 960px) {
                .cc-dashboard-split { grid-template-columns: 1fr; }
                .cc-metrics-col { display: grid; grid-template-columns: 1fr 1fr; }
            }
        " );
    }

    /**
     * Filter to add Integrity and Crossorigin attributes to Chart.js.
     * Deprecated for local files, keeping method stub to prevent fatal errors if hooked elsewhere, 
     * but returning tag unmodified.
     */
    public function add_chartjs_sri_attributes( $tag, $handle, $src ) { 
        return $tag;
    }

    /**
     * Main Render Method.
     */
    public function render_analytics_view() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cirrusly-commerce' ) );
        }

        // Default to last 30 days if no date range picker logic is added yet
        $days = isset($_GET['period']) ? intval($_GET['period']) : 30;
        $days = max( 1, min( $days, 365 ) ); // Clamp between 1 and 365 days;

        // Handle Refresh Action
        if ( isset( $_GET['cc_refresh'] ) && check_admin_referer( 'cc_refresh_analytics' ) ) {
            delete_transient( 'cc_analytics_pnl_' . $days );
            // Redirect to remove the query arg so refresh doesn't stick
            wp_redirect( remove_query_arg( array( 'cc_refresh', '_wpnonce' ) ) );
            exit;
        }

        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            Cirrusly_Commerce_Core::render_page_header( 'Pro Plus Analytics' );
        }
        
        $data = self::get_pnl_data( $days );
        $velocity = self::get_inventory_velocity();
        $gmc_history = get_option( 'cirrusly_gmc_history', array() );

        // Prepare History Data for JS
        $perf_history_json = wp_json_encode( $data['history'] );
        $gmc_history_json  = wp_json_encode( $gmc_history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        
        $refresh_url = wp_nonce_url( add_query_arg( 'cc_refresh', '1' ), 'cc_refresh_analytics' );
        ?>
        <div class="wrap cc-analytics-wrapper">
            
            <div class="cc-header-actions">
                <span style="color:#646970; margin-right: 10px;">Data cached for performance.</span>
                <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary">
                    <span class="dashicons dashicons-update" style="margin-top:3px;"></span> Refresh Stats
                </a>
            </div>
            <div style="clear:both;"></div>

            <div class="cc-dashboard-split">
                
                <div class="cc-metrics-col">
                    <div class="cc-metric-card" style="border-top: 3px solid #2271b1;">
                        <h3>Net Sales</h3>
                        <div class="cc-metric-val"><?php echo wp_kses_post( wc_price( $data['revenue'] ) ); ?></div>
                        <div class="cc-metric-sub"><?php echo esc_html( $data['count'] ); ?> Orders</div>
                    </div>
                    <div class="cc-metric-card" style="border-top: 3px solid #d63638;">
                        <h3>Total Costs</h3>
                        <div class="cc-metric-val"><?php echo wp_kses_post( wc_price( $data['total_costs'] ) ); ?></div>
                        <div class="cc-metric-sub">COGS + Ship + Fees</div>
                    </div>
                    <div class="cc-metric-card" style="border-top: 3px solid #00a32a;">
                        <h3>Net Profit</h3>
                        <div class="cc-metric-val" style="color:#00a32a;"><?php echo wp_kses_post( wc_price( $data['net_profit'] ) ); ?></div>
                        <div class="cc-metric-sub"><?php echo esc_html( number_format( $data['margin'], 1 ) ); ?>% Margin</div>
                    </div>
                    <div class="cc-metric-card" style="border-top: 3px solid #dba617;">
                        <h3>Projected Stockouts</h3>
                        <div class="cc-metric-val"><?php echo esc_html( count( $velocity ) ); ?></div>
                        <div class="cc-metric-sub">Next 14 Days</div>
                    </div>
                </div>

                <div class="cc-chart-col">
                    <div class="cc-section-title" style="border:none; background:transparent; padding: 0 0 15px 0;">
                        Performance Overview (<?php echo intval( $days ); ?> Days)
                    </div>
                    <div style="flex-grow: 1; min-height: 350px;">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>

            </div>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                
                <div class="cc-table-wrapper">
                    <div class="cc-section-title">GMC Health Trend (30 Days)</div>
                    <div style="padding: 20px; height: 300px;">
                        <canvas id="gmcTrendChart"></canvas>
                    </div>
                </div>

                <div class="cc-table-wrapper">
                    <div class="cc-section-title">Top Profitable Products</div>
                    <table class="wp-list-table widefat striped">
                        <thead><tr><th>Product</th><th style="text-align:right;">Net Profit</th></tr></thead>
                        <tbody>
                            <?php 
                            $top_products = array_slice( $data['products'], 0, 8 ); 
                            if ( empty( $top_products ) ) {
                                echo '<tr><td colspan="2">No data available.</td></tr>';
                            } else {
                                foreach ( $top_products as $p ) {
                                    echo '<tr>';
                                    echo '<td><a href="' . esc_url( get_edit_post_link($p['id']) ) . '">'. esc_html($p['name']) .'</a><br><small style="color:#888;">'. esc_html($p['qty']) .' sold</small></td>';
                                    echo '<td style="text-align:right; font-weight:bold; color:#00a32a;">' . wp_kses_post( wc_price($p['net']) ) . '</td>';
                                    echo '</tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

            </div>
            
            <?php if ( ! empty( $velocity ) ) : ?>
            <div class="cc-table-wrapper">
                <div class="cc-section-title" style="border-left: 4px solid #dba617;">⚠️ Inventory Risk: High Velocity Items</div>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Current Stock</th>
                            <th>Avg Daily Sales</th>
                            <th>Est. Days Left</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $velocity as $v ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( get_edit_post_link($v['id']) ); ?>"><?php echo esc_html( $v['name'] ); ?></a></td>
                                <td style="color:#d63638; font-weight:bold;"><?php echo esc_html( $v['stock'] ); ?></td>
                                <td><?php echo esc_html( number_format( $v['velocity'], 1 ) ); ?> / day</td>
                                <td><?php echo esc_html( round( $v['days_left'] ) ); ?> days</td>
                                <td><a href="<?php echo esc_url( get_edit_post_link($v['id']) ); ?>" class="button button-small">Restock</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            // --- 1. Performance Chart ---
            const perfCtx = document.getElementById('performanceChart');
            if (perfCtx) {
                const perfData = <?php echo $perf_history_json; ?>;
                const dates = Object.keys(perfData);
                const sales = dates.map(d => perfData[d].revenue);
                const costs = dates.map(d => perfData[d].costs);
                const profit = dates.map(d => perfData[d].profit);

                new Chart(perfCtx, {
                    type: 'bar',
                    data: {
                        labels: dates,
                        datasets: [
                            {
                                label: 'Net Profit',
                                data: profit,
                                type: 'line',
                                borderColor: '#00a32a',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.3,
                                order: 1
                            },
                            {
                                label: 'Net Sales',
                                data: sales,
                                backgroundColor: '#2271b1',
                                order: 2
                            },
                            {
                                label: 'Total Costs',
                                data: costs,
                                backgroundColor: '#d63638',
                                order: 3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed.y !== null) {
                                            label += new Intl.NumberFormat(undefined, { style: 'currency', currency: '<?php echo get_woocommerce_currency(); ?>' }).format(context.parsed.y);
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { borderDash: [2, 2] } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }

            // --- 2. GMC Trend Chart ---
            const ctx = document.getElementById('gmcTrendChart');
            if (ctx) {
                const history = <?php echo $gmc_history_json; ?>;
                const labels = [];
                const criticalData = [];
                const warningData = [];
                
                // Limit to last 30 entries
                const keys = Object.keys(history).slice(-30);
                
                keys.forEach(date => {
                    labels.push(date); // Date (m-d)
                    criticalData.push(history[date].critical || 0);
                    warningData.push(history[date].warnings || 0);
                });

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Disapproved Items',
                            data: criticalData,
                            borderColor: '#d63638',
                            backgroundColor: 'rgba(214, 54, 56, 0.1)',
                            fill: true,
                            tension: 0.3
                        }, {
                            label: 'Warnings',
                            data: warningData,
                            borderColor: '#dba617',
                            borderDash: [5, 5],
                            fill: false,
                            tension: 0.3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Engine: Calculate P&L for a period.
     */
    private static function get_pnl_data( $days = 30 ) {
        $after_date  = wp_date( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
        $before_date = wp_date( 'Y-m-d', time() );

        // Check cache first
        $cache_key = 'cc_analytics_pnl_' . $days;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
           return $cached;
        }

        // Initialize Stats
        $stats = array(
            'revenue' => 0,
            'cogs'    => 0,
            'shipping'=> 0,
            'fees'    => 0,
            'refunds' => 0,
            'count'   => 0,
            'products'=> array(), // For leaderboard
            'history' => array()  // Daily breakdown
        );

        // Fill history dates with 0s to ensure continuous chart
        $period_dates = new DatePeriod(
            new DateTime( $after_date ),
            new DateInterval( 'P1D' ),
            ( new DateTime( $before_date ) )->modify( '+1 day' )
        );
        foreach ( $period_dates as $dt ) {
            $stats['history'][ $dt->format( 'Y-m-d' ) ] = array( 'revenue' => 0, 'costs' => 0, 'profit' => 0 );
        }

        // Fetch Fee Config
        $fee_config = get_option( 'cirrusly_shipping_config', array() );

        // Loop using pagination to avoid memory/limit issues
        $page = 1;
        $max_pages = 1000; // Safety limit

        while( $page <= $max_pages ) {
            $orders = wc_get_orders( array(
                'limit'       => 250,
                'page'        => $page,
                'status'      => array( 'wc-completed', 'wc-processing' ),
                'date_after'  => $after_date,
                'date_before' => $before_date,
            ) );

            if ( empty( $orders ) ) break;

            $stats['count'] += count( $orders );

            foreach ( $orders as $order ) {
                $order_date = wp_date( 'Y-m-d', $order->get_date_created()->getTimestamp() );

                // Ensure key exists (in case order is outside initialized range for some reason)
                if ( ! isset( $stats['history'][ $order_date ] ) ) {
                    $stats['history'][ $order_date ] = array( 'revenue' => 0, 'costs' => 0, 'profit' => 0 );
                }

                $order_revenue = $order->get_total();
                $order_refunds = $order->get_total_refunded();
                
                // Fee Logic (Simplified from Reports Pro)
                $order_fees = self::calculate_single_order_fee( $order_revenue, $fee_config );
                $order_cogs = 0;
                $order_ship = 0;

                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    if ( ! $product ) continue;

                    $qty = $item->get_quantity();
                    $line_total = $item->get_total();
                    
                    // Retrieve stored COGS/Shipping or fall back to 0
                    $cogs_val = (float) $product->get_meta( '_cogs_total_value' );
                    $ship_val = (float) $product->get_meta( '_cw_est_shipping' );
                    
                    $cost_basis = ($cogs_val + $ship_val) * $qty;
                    
                    $order_cogs += ($cogs_val * $qty);
                    $order_ship += ($ship_val * $qty);

                    // Leaderboard Logic
                    $pid = $item->get_product_id();
                    if ( ! isset( $stats['products'][$pid] ) ) {
                        $stats['products'][$pid] = array(
                            'id' => $pid, 
                            'name' => $product->get_name(), 
                            'qty' => 0, 
                            'net' => 0 
                        );
                    }
                    
                    // Net Profit per product
                    $stats['products'][$pid]['qty'] += $qty;
                    $stats['products'][$pid]['net'] += ($line_total - $cost_basis);
                }

                // Global Stats
                $stats['revenue']  += $order_revenue;
                $stats['refunds']  += $order_refunds;
                $stats['fees']     += $order_fees;
                $stats['cogs']     += $order_cogs;
                $stats['shipping'] += $order_ship;

                // Daily History Stats
                $order_total_costs = $order_cogs + $order_ship + $order_fees + $order_refunds;
                $order_net_profit  = $order_revenue - $order_total_costs;

                $stats['history'][ $order_date ]['revenue'] += $order_revenue;
                $stats['history'][ $order_date ]['costs']   += $order_total_costs;
                $stats['history'][ $order_date ]['profit']  += $order_net_profit;
            }
            $page++;
        }

        $stats['total_costs'] = $stats['cogs'] + $stats['shipping'] + $stats['fees'] + $stats['refunds'];
        $stats['net_profit']  = $stats['revenue'] - $stats['total_costs'];
        $stats['margin']      = $stats['revenue'] > 0 ? ( $stats['net_profit'] / $stats['revenue'] ) * 100 : 0;

        // Sort Leaderboard
        usort( $stats['products'], function($a, $b) {
            return $b['net'] <=> $a['net'];
        });

        // Store result in transient to cache it
        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /**
     * Logic: Inventory Velocity.
     */
    private static function get_inventory_velocity() {
        // 1. Get Sales for last 30 days per product
        $sold_map = array();

    // Check if HPOS is enabled
    if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) 
         && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
        // HPOS-compatible approach: use wc_get_orders() and aggregate in PHP

            $page = 1;
            $max_pages = 1000; // Safety limit matching get_pnl_data

            while( $page <= $max_pages ) {
                $orders = wc_get_orders( array(
                    'limit'       => 250,
                    'page'        => $page,
                    'status'      => array( 'wc-completed', 'wc-processing' ),
                    // REPLACE 'date_created' => $date_query WITH:
                    'date_after'  => wp_date( 'Y-m-d', strtotime( '-30 days' ) ),
                ) );

                if ( empty( $orders ) ) {
                    break;
                }

                foreach ( $orders as $order ) {
                    foreach ( $order->get_items() as $item ) {
                        $pid = $item->get_product_id();
                        $qty = $item->get_quantity();
                        $sold_map[ $pid ] = ( $sold_map[ $pid ] ?? 0 ) + $qty;
                    }
                }
                $page++;
            }
        } else {

        // Lightweight query for speed
        global $wpdb;
        $order_items = $wpdb->get_results( $wpdb->prepare( "
            SELECT order_item_meta.meta_value as product_id, SUM( order_item_meta_2.meta_value ) as qty
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
            LEFT JOIN {$wpdb->posts} as posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND posts.post_status IN ('wc-completed', 'wc-processing')
            AND posts.post_date > %s
            AND order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta_2.meta_key = '_qty'
            GROUP BY product_id
        ", wp_date( 'Y-m-d', strtotime( '-30 days' ) ) ) );

        if ( is_null( $order_items ) || $wpdb->last_error ) {
            error_log( 'Cirrusly Analytics: Inventory velocity query failed - ' . $wpdb->last_error );
            return array(); // Return empty on error
        }

        foreach ( $order_items as $row ) {
            $sold_map[$row->product_id] = $row->qty;
        }
    }
    $risky_items = array();

    // 2. Compare against current stock
    foreach ( $sold_map as $pid => $qty_30 ) {
            $product = wc_get_product( $pid );
            if ( ! $product || ! $product->managing_stock() ) continue;

            $stock = $product->get_stock_quantity();
            if ( $stock <= 0 ) continue; // Already out

            $velocity = $qty_30 / 30; // Sales per day
            if ( $velocity <= 0 ) continue;

            $days_left = $stock / $velocity;

            if ( $days_left < 14 ) { // Alert if less than 2 weeks stock
                $risky_items[] = array(
                    'id'        => $pid,
                    'name'      => $product->get_name(),
                    'stock'     => $stock,
                    'velocity'  => $velocity,
                    'days_left' => $days_left
                );
            }
        }
        
    // Sort by urgency
    usort( $risky_items, function($a, $b) {
        return $a['days_left'] <=> $b['days_left'];
    });

    return $risky_items;
}


    /**
     * Hook: Capture daily GMC scan results for historical trending.
     * Hooks into 'cirrusly_gmc_daily_scan' at priority 20.
     */
    public function capture_daily_gmc_snapshot() {
        $scan_data = get_option( 'woo_gmc_scan_data', array() );
        
        if ( empty( $scan_data['results'] ) ) return;

        $critical = 0;
        $warnings = 0;

        foreach ( $scan_data['results'] as $res ) {
            if ( empty( $res['issues'] ) || ! is_array( $res['issues'] ) ) {
                continue;
            }

            foreach ( $res['issues'] as $issue ) {
                $itype = isset( $issue['type'] ) ? $issue['type'] : '';
                if ( $itype === 'critical' ) {
                    $critical++;
                } else {
                    $warnings++;
                }
            }
        }

        $history = get_option( 'cirrusly_gmc_history', array() );
        $today   = wp_date( 'Y-m-d' ); // e.g. "2025-10-10"

        $history[$today] = array(
            'critical' => $critical,
            'warnings' => $warnings,
            'ts'       => time()
        );

        // Keep only last 90 days to prevent bloat
        if ( count( $history ) > 90 ) {
            $history = array_slice( $history, -90, 90, true );
        }

        update_option( 'cirrusly_gmc_history', $history, false );
    }

    /**
     * Helper: Calculate fees (Simplified).
     */
    private static function calculate_single_order_fee( $total, $config ) {
        $pay_pct  = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        return ($total * $pay_pct) + $pay_flat;
    }
}