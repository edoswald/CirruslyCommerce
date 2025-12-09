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

        // Load Chart.js from CDN (Lightweight and standard)
        wp_enqueue_script( 'cc-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.0', true );
        
        // Inline CSS for the analytics dashboard
        wp_add_inline_style( 'admin-bar', "
            .cc-analytics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
            .cc-metric-card { background: #fff; padding: 20px; border-radius: 4px; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .cc-metric-card h3 { margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; }
            .cc-metric-val { font-size: 24px; font-weight: 600; color: #1d2327; }
            .cc-metric-sub { font-size: 12px; color: #646970; margin-top: 5px; }
            .cc-trend-up { color: #008a20; }
            .cc-trend-down { color: #d63638; }
            .cc-table-wrapper { background: #fff; border: 1px solid #c3c4c7; margin-bottom: 20px; }
            .cc-section-title { font-size: 1.2em; padding: 15px; margin: 0; border-bottom: 1px solid #eaecf0; background: #fbfbfb; font-weight: 600; }
        " );
    }

    /**
     * Main Render Method.
     */
    public function render_analytics_view() {
        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            Cirrusly_Commerce_Core::render_page_header( 'Pro Plus Analytics' );
        }

        // Default to last 30 days if no date range picker logic is added yet
        $days = isset($_GET['period']) ? intval($_GET['period']) : 30;
        $data = self::get_pnl_data( $days );
        $velocity = self::get_inventory_velocity();
        $gmc_history = get_option( 'cirrusly_gmc_history', array() );

        ?>
        <div class="wrap cc-analytics-wrapper">
            
            <div class="cc-analytics-grid">
                <div class="cc-metric-card" style="border-top: 3px solid #2271b1;">
                    <h3>Net Sales</h3>
                    <div class="cc-metric-val"><?php echo wc_price( $data['revenue'] ); ?></div>
                    <div class="cc-metric-sub"><?php echo esc_html( $data['count'] ); ?> Orders</div>
                </div>
                <div class="cc-metric-card" style="border-top: 3px solid #d63638;">
                    <h3>Total Costs</h3>
                    <div class="cc-metric-val"><?php echo wc_price( $data['total_costs'] ); ?></div>
                    <div class="cc-metric-sub">COGS + Ship + Fees</div>
                </div>
                <div class="cc-metric-card" style="border-top: 3px solid #00a32a;">
                    <h3>Net Profit</h3>
                    <div class="cc-metric-val" style="color:#00a32a;"><?php echo wc_price( $data['net_profit'] ); ?></div>
                    <div class="cc-metric-sub"><?php echo number_format( $data['margin'], 1 ); ?>% Margin</div>
                </div>
                <div class="cc-metric-card" style="border-top: 3px solid #dba617;">
                    <h3>Projected Stockouts</h3>
                    <div class="cc-metric-val"><?php echo count( $velocity ); ?></div>
                    <div class="cc-metric-sub">Next 14 Days</div>
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
                                    echo '<td><a href="' . get_edit_post_link($p['id']) . '">'. esc_html($p['name']) .'</a><br><small style="color:#888;">'. $p['qty'] .' sold</small></td>';
                                    echo '<td style="text-align:right; font-weight:bold; color:#00a32a;">' . wc_price($p['net']) . '</td>';
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
                                <td><a href="<?php echo get_edit_post_link($v['id']); ?>"><?php echo esc_html( $v['name'] ); ?></a></td>
                                <td style="color:#d63638; font-weight:bold;"><?php echo esc_html( $v['stock'] ); ?></td>
                                <td><?php echo number_format( $v['velocity'], 1 ); ?> / day</td>
                                <td><?php echo round( $v['days_left'] ); ?> days</td>
                                <td><a href="<?php echo get_edit_post_link($v['id']); ?>" class="button button-small">Restock</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('gmcTrendChart');
            if (!ctx) return;

            // Prepare Data from PHP
            const history = <?php echo json_encode( $gmc_history ); ?>;
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
        });
        </script>
        <?php
    }

    /**
     * Engine: Calculate P&L for a period.
     */
    private static function get_pnl_data( $days = 30 ) {
        $date_query = array(
            'after'     => wp_date( 'Y-m-d', strtotime( '-' . $days . ' days' ) ),
            'before'    => wp_date( 'Y-m-d', time() ),
            'inclusive' => true,
        );

        $orders = wc_get_orders( array(
            'limit'        => -1,
            'status'       => array( 'wc-completed', 'wc-processing' ),
            'date_created' => $date_query,
        ) );

        $stats = array(
            'revenue' => 0,
            'cogs'    => 0,
            'shipping'=> 0,
            'fees'    => 0,
            'refunds' => 0,
            'count'   => count( $orders ),
            'products'=> array() // For leaderboard
        );

        // Fetch Fee Config
        $fee_config = get_option( 'cirrusly_shipping_config', array() );
        
        foreach ( $orders as $order ) {
            $stats['revenue'] += $order->get_total();
            $stats['refunds'] += $order->get_total_refunded();
            
            // Fee Logic (Simplified from Reports Pro)
            $stats['fees'] += self::calculate_single_order_fee( $order->get_total(), $fee_config );

            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product ) continue;

                $qty = $item->get_quantity();
                $line_total = $item->get_total();
                
                // Retrieve stored COGS/Shipping or fall back to 0
                $cogs_val = (float) $product->get_meta( '_cogs_total_value' );
                $ship_val = (float) $product->get_meta( '_cw_est_shipping' );
                
                $cost_basis = ($cogs_val + $ship_val) * $qty;
                
                $stats['cogs']     += ($cogs_val * $qty);
                $stats['shipping'] += ($ship_val * $qty);

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
                
                // Net Profit per product (approximate, excluding global fees/refunds for simplicity in leaderboard)
                $stats['products'][$pid]['qty'] += $qty;
                $stats['products'][$pid]['net'] += ($line_total - $cost_basis);
            }
        }

        $stats['total_costs'] = $stats['cogs'] + $stats['shipping'] + $stats['fees'] + $stats['refunds'];
        $stats['net_profit']  = $stats['revenue'] - $stats['total_costs'];
        $stats['margin']      = $stats['revenue'] > 0 ? ( $stats['net_profit'] / $stats['revenue'] ) * 100 : 0;

        // Sort Leaderboard
        usort( $stats['products'], function($a, $b) {
            return $b['net'] <=> $a['net'];
        });

        return $stats;
    }

    /**
     * Logic: Inventory Velocity.
     */
    private static function get_inventory_velocity() {
        // 1. Get Sales for last 30 days per product
        $sold_map = array();
        
        // Lightweight query for speed
        global $wpdb;
        $order_items = $wpdb->get_results( "
            SELECT order_item_meta.meta_value as product_id, SUM( order_item_meta_2.meta_value ) as qty
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta_2 ON order_items.order_item_id = order_item_meta_2.order_item_id
            LEFT JOIN {$wpdb->posts} as posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND posts.post_status IN ('wc-completed', 'wc-processing')
            AND posts.post_date > '" . date('Y-m-d', strtotime('-30 days')) . "'
            AND order_items.order_item_type = 'line_item'
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta_2.meta_key = '_qty'
            GROUP BY product_id
        " );

        foreach ( $order_items as $row ) {
            $sold_map[$row->product_id] = $row->qty;
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
            foreach ( $res['issues'] as $issue ) {
                if ( $issue['type'] === 'critical' ) $critical++;
                else $warnings++;
            }
        }

        $history = get_option( 'cirrusly_gmc_history', array() );
        $today   = date( 'M j' ); // e.g. "Oct 10"

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