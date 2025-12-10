<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Analytics_Pro {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_analytics_page' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
     * Enqueue Chart.js and the analytics UI styles for the Cirrusly analytics admin page.
     *
     * @param string $hook The current admin page hook name; assets are enqueued only when it contains 'cirrusly-analytics'.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'cirrusly-analytics' ) === false ) {
            return;
        }

        wp_enqueue_script( 'cc-chartjs', CIRRUSLY_COMMERCE_URL . 'assets/js/vendor/chart.umd.min.js', array(), '4.4.0', true );
        
        // 1. Inline CSS
        wp_enqueue_style( 'cc-analytics-styles', false );
        wp_add_inline_style( 'cc-analytics-styles', "
            .cc-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: #fff; padding: 10px 15px; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative; z-index: 100; }
            .cc-toolbar-left { font-size: 13px; color: #646970; }
            .cc-toolbar-right { display: flex; align-items: center; gap: 10px; position: relative; }
            .cc-status-trigger { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #50575e; background: #f6f7f7; padding: 5px 12px; border-radius: 20px; border: 1px solid #dcdcde; cursor: pointer; transition: all 0.2s; max-width: 300px; }
            .cc-status-trigger:hover { border-color: #2271b1; color: #2271b1; background: #fff; }
            .cc-status-trigger .dashicons { font-size: 14px; width: 14px; height: 14px; color: #8c8f94; }
            .cc-status-dropdown { display: none; position: absolute; top: 100%; right: 0; margin-top: 10px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-radius: 4px; padding: 15px; width: 280px; z-index: 999; }
            .cc-status-dropdown.is-open { display: block; }
            .cc-status-dropdown h4 { margin: 0 0 10px 0; font-size: 12px; text-transform: uppercase; color: #646970; border-bottom: 1px solid #eee; padding-bottom: 5px; }
            .cc-status-list { max-height: 300px; overflow-y: auto; margin-bottom: 10px; }
            .cc-status-item { display: block; margin-bottom: 6px; font-size: 13px; }
            .cc-status-item input { margin-top: 0; }
            .cc-analytics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
            .cc-metric-card { background: #fff; padding: 20px; border-radius: 0; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0,0,0,.04); position: relative; }
            .cc-metric-card h3 { margin: 0 0 10px 0; font-size: 13px; color: #646970; text-transform: uppercase; font-weight: 600; }
            .cc-metric-val { font-size: 28px; font-weight: 400; color: #1d2327; line-height: 1.2; }
            .cc-metric-sub { font-size: 12px; color: #646970; margin-top: 8px; }
            .cc-chart-section { background: #fff; padding: 20px; border: 1px solid #dcdcde; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 20px; }
            .cc-chart-header h2 { font-size: 1.3em; margin: 0; padding: 0 0 15px 0; font-weight: 600; color: #1d2327; }
            .cc-table-wrapper { background: #fff; border: 1px solid #c3c4c7; margin-bottom: 20px; }
            .cc-section-title { font-size: 1.1em; padding: 12px 15px; margin: 0; border-bottom: 1px solid #eaecf0; background: #fbfbfb; font-weight: 600; color: #1d2327; }
            @media (max-width: 960px) { .cc-analytics-grid { grid-template-columns: repeat(2, 1fr); } }
            @media (max-width: 600px) { .cc-analytics-grid { grid-template-columns: 1fr; } .cc-toolbar { flex-direction: column; align-items: flex-start; gap: 10px; } .cc-status-dropdown { right: auto; left: 0; width: 100%; box-sizing: border-box; } }
        " );

        // 2. Prepare Data for Inline Script
        // We need to fetch data here to pass it to the script
        $default_days = 90;
        $days = isset($_GET['period']) ? intval($_GET['period']) : $default_days;
        $days = max( 7, min( $days, 365 ) ); 
        
        $selected_statuses = array();
        if ( isset( $_GET['cc_statuses'] ) && is_array( $_GET['cc_statuses'] ) ) {
            $selected_statuses = array_map( 'sanitize_text_field', $_GET['cc_statuses'] );
        }

        $data = self::get_pnl_data( $days, $selected_statuses );
        $gmc_history = get_option( 'cirrusly_gmc_history', array() );

        $perf_history_json = wp_json_encode( $data['history'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $gmc_history_json  = wp_json_encode( $gmc_history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

        // 3. Inline Script
        $script = "
        document.addEventListener('DOMContentLoaded', function() {
            const trigger = document.getElementById('ccStatusTrigger');
            const dropdown = document.getElementById('ccStatusDropdown');
            if(trigger && dropdown){
                trigger.addEventListener('click', function(e){ e.stopPropagation(); dropdown.classList.toggle('is-open'); });
                document.addEventListener('click', function(e){ if(!dropdown.contains(e.target) && e.target !== trigger){ dropdown.classList.remove('is-open'); } });
            }
            const perfCtx = document.getElementById('performanceChart');
            if (perfCtx) {
                const perfData = {$perf_history_json};
                const dates = Object.keys(perfData);
                const sales = dates.map(d => perfData[d].revenue);
                const costs = dates.map(d => perfData[d].costs);
                const profit = dates.map(d => perfData[d].profit);
                new Chart(perfCtx, {
                    type: 'bar',
                    data: {
                        labels: dates,
                        datasets: [
                            { label: 'Net Profit', data: profit, type: 'line', borderColor: '#00a32a', borderWidth: 2, fill: false, tension: 0.3, order: 1 },
                            { label: 'Net Sales', data: sales, backgroundColor: '#2271b1', order: 2 },
                            { label: 'Total Costs', data: costs, backgroundColor: '#d63638', order: 3 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, grid: { borderDash: [2, 2] } }, x: { grid: { display: false } } } }
                });
            }
            const ctx = document.getElementById('gmcTrendChart');
            if (ctx) {
                const history = {$gmc_history_json};
                const labels = Object.keys(history).slice(-30);
                const criticalData = labels.map(d => history[d].critical || 0);
                const warningData = labels.map(d => history[d].warnings || 0);
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{ label: 'Disapproved', data: criticalData, borderColor: '#d63638', backgroundColor: 'rgba(214, 54, 56, 0.1)', fill: true, tension: 0.3 }, { label: 'Warnings', data: warningData, borderColor: '#dba617', borderDash: [5, 5], fill: false, tension: 0.3 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
                });
            }
        });";

        // Attach to Chart.js handle
        wp_add_inline_script( 'cc-chartjs', $script );
    }

    // ... [Keep render_analytics_view (REMOVE THE SCRIPT BLOCK AT THE BOTTOM), get_pnl_data, get_inventory_velocity, capture_daily_gmc_snapshot, calculate_single_order_fee] ...
    
    public function render_analytics_view() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cirrusly-commerce' ) );
        }

        // Logic to get $data, $velocity, $days, etc. must remain here for the HTML render
        // But the JSON preparation for JS has been moved to enqueue_assets
        $default_days = 90;
        $days = isset($_GET['period']) ? intval($_GET['period']) : $default_days;
        $days = max( 7, min( $days, 365 ) ); 

        $all_statuses = wc_get_order_statuses();
        $selected_statuses = array();
        if ( isset( $_GET['cc_statuses'] ) && is_array( $_GET['cc_statuses'] ) ) {
            $selected_statuses = array_map( 'sanitize_text_field', $_GET['cc_statuses'] );
        }

        if ( isset( $_GET['cc_refresh'] ) && check_admin_referer( 'cc_refresh_analytics' ) ) {
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cc_analytics_pnl_v4_%' OR option_name LIKE '_transient_timeout_cc_analytics_pnl_v4_%'" );
            wp_redirect( remove_query_arg( array( 'cc_refresh', '_wpnonce' ) ) );
            exit;
        }

        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            Cirrusly_Commerce_Core::render_page_header( 'Pro Plus Analytics' );
        }
        
        $data = self::get_pnl_data( $days, $selected_statuses );
        if ( empty( $selected_statuses ) ) {
            $selected_statuses = $data['statuses_used'];
        }
        $velocity = self::get_inventory_velocity();
        $refresh_url = wp_nonce_url( add_query_arg( array( 'cc_refresh' => '1' ) ), 'cc_refresh_analytics' );
        
        $readable_labels = array();
        foreach ( $selected_statuses as $slug ) {
            $lookup_slug = ( strpos( $slug, 'wc-' ) === 0 ) ? $slug : 'wc-' . $slug;
            if ( isset( $all_statuses[ $lookup_slug ] ) ) {
                $readable_labels[] = $all_statuses[ $lookup_slug ];
            } else {
                $readable_labels[] = ucwords( str_replace( array('wc-', '-'), array('', ' '), $slug ) );
            }
        }
        $status_text = implode( ', ', $readable_labels );

        ?>
        <div class="wrap cc-analytics-wrapper">
            <form method="get" action="" id="cc-analytics-form">
                <input type="hidden" name="page" value="cirrusly-analytics">
                <div class="cc-toolbar">
                    <div class="cc-toolbar-left">
                        Found <strong><?php echo intval($data['count']); ?></strong> orders in the last <?php echo intval($days); ?> days.
                    </div>
                    <div class="cc-toolbar-right">
                        <div style="position:relative;">
                            <div class="cc-status-trigger" id="ccStatusTrigger" title="<?php echo esc_attr($status_text); ?>">
                                <span class="dashicons dashicons-filter"></span>
                                Filters: <?php echo esc_html( substr($status_text, 0, 30) . (strlen($status_text)>30 ? '...' : '') ); ?>
                                <span class="dashicons dashicons-arrow-down-alt2" style="font-size:10px; width:10px; height:10px; margin-left:auto;"></span>
                            </div>
                            <div class="cc-status-dropdown" id="ccStatusDropdown">
                                <h4>Filter Statuses</h4>
                                <div class="cc-status-list">
                                    <?php foreach ( $all_statuses as $slug => $label ) : ?>
                                        <label class="cc-status-item">
                                            <input type="checkbox" name="cc_statuses[]" value="<?php echo esc_attr($slug); ?>" 
                                                <?php checked( in_array( $slug, $selected_statuses ) || in_array( str_replace('wc-','',$slug), $selected_statuses ) ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <button type="button" class="button button-small" onclick="document.querySelectorAll('.cc-status-list input').forEach(el=>el.checked=false)">Clear</button>
                                    <button type="submit" class="button button-primary button-small">Apply Filters</button>
                                </div>
                            </div>
                        </div>
                        <select name="period" id="cc_period_selector" onchange="document.getElementById('cc-analytics-form').submit()" style="vertical-align: top;">
                            <option value="7" <?php selected( $days, 7 ); ?>>Last 7 Days</option>
                            <option value="30" <?php selected( $days, 30 ); ?>>Last 30 Days</option>
                            <option value="90" <?php selected( $days, 90 ); ?>>Last 90 Days</option>
                            <option value="180" <?php selected( $days, 180 ); ?>>Last 6 Months</option>
                            <option value="365" <?php selected( $days, 365 ); ?>>Last Year</option>
                        </select>
                        <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" title="Force Refresh Data">
                            <span class="dashicons dashicons-update" style="margin-top:3px;"></span>
                        </a>
                    </div>
                </div>
            </form>

            <div class="cc-analytics-grid">
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

            <div class="cc-chart-section">
                <div class="cc-chart-header">
                    <h2>Performance Overview (Last <?php echo intval( $days ); ?> Days)</h2>
                </div>
                <div style="width: 100%; height: 350px;">
                    <canvas id="performanceChart"></canvas>
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
                    <thead><tr><th>Product</th><th>Current Stock</th><th>Avg Daily Sales</th><th>Est. Days Left</th><th>Action</th></tr></thead>
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
        <?php
    }

 /**
     * Compute profit-and-loss metrics and per-day history for a given date range and status filter.
     *
     * Computes aggregated totals (revenue, COGS, shipping, fees, refunds), derived metrics (total_costs,
     * net_profit, margin), a per-product leaderboard, and a daily history for the requested period.
     * Results are cached for one hour using a key derived from the days and status fingerprint.
     *
     * @param int   $days            Number of days to include (ending today). Defaults to 90.
     * @param array $custom_statuses Optional array of order status slugs (e.g., ['wc-completed']). If empty, a sensible default set is auto-discovered.
     * @return array An associative array with keys:
     *               - revenue (float): Total order revenue.
     *               - cogs (float): Total cost of goods sold.
     *               - shipping (float): Total estimated shipping costs.
     *               - fees (float): Total calculated fees.
     *               - refunds (float): Total refunded amounts.
     *               - total_costs (float): Sum of cogs, shipping, fees, and refunds.
     *               - net_profit (float): revenue minus total_costs.
     *               - margin (float): Net profit expressed as percentage of revenue (0 if revenue is 0).
     *               - count (int): Number of orders considered.
     *               - products (array): List of products aggregated with entries like ['id'=>int, 'name'=>string, 'qty'=>int, 'net'=>float], sorted by net descending.
     *               - history (array): Map of Y-m-d => ['revenue'=>float, 'costs'=>float, 'profit'=>float] for each day in the period.
     *               - method (string): Query method used (e.g., 'HPOS API' or 'Direct SQL').
     *               - statuses_used (array): The order statuses used to build the result.
     */
    private static function get_pnl_data( $days = 90, $custom_statuses = array() ) {
        // Initialize Dates
        $start_date_ymd = wp_date( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
        $end_date_ymd   = wp_date( 'Y-m-d', time() );
        
        // ---------------------------------------------------------
        // STATUS LOGIC
        // ---------------------------------------------------------
        $target_statuses = array();
        
        if ( ! empty( $custom_statuses ) ) {
            // Use user selection
            $target_statuses = $custom_statuses;
        } else {
            // Auto Discovery (Default)
            $all_statuses = array_keys( wc_get_order_statuses() );
            $excluded_statuses = array( 'wc-cancelled', 'wc-failed', 'wc-trash', 'wc-pending', 'wc-checkout-draft' );
            $target_statuses = array_diff( $all_statuses, $excluded_statuses );
            if ( empty( $target_statuses ) ) $target_statuses = array('wc-completed', 'wc-processing', 'wc-on-hold'); 
        }

        // Generate Cache Key based on days + status fingerprint
        $status_hash = md5( json_encode( $target_statuses ) );
        $cache_key   = 'cc_analytics_pnl_v4_' . $days . '_' . $status_hash; 
        
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $stats = array(
            'revenue' => 0, 'cogs' => 0, 'shipping'=> 0, 'fees' => 0, 'refunds' => 0, 'total_costs' => 0, 'net_profit' => 0, 'margin' => 0,
            'count'   => 0, 'products'=> array(), 'history' => array(), 
            'method' => 'Unknown', 'statuses_used' => $target_statuses
        );

        // Pre-fill history
        $period = new DatePeriod( new DateTime($start_date_ymd), new DateInterval('P1D'), (new DateTime($end_date_ymd))->modify('+1 day') );
        foreach ( $period as $dt ) { $stats['history'][ $dt->format( 'Y-m-d' ) ] = array( 'revenue' => 0, 'costs' => 0, 'profit' => 0 ); }

        $fee_config = get_option( 'cirrusly_shipping_config', array() );
        $order_ids = array();

        // ---------------------------------------------------------
        // QUERY STRATEGY: Check for HPOS vs Legacy
        // ---------------------------------------------------------
        $hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos_enabled ) {
            // HPOS PATH
            $stats['method'] = 'HPOS API';
            $page = 1;
            $per_page = 500;
            $order_ids = array();
            do {
                $query_args = array(
                'limit'        => $per_page,
                'page'         => $page,
                'status'       => $target_statuses,
                'date_created' => strtotime($start_date_ymd) . '...' . time(), 
                'type'         => 'shop_order',
                'return'       => 'ids',
                );
                $batch = wc_get_orders( $query_args );
                $order_ids = array_merge( $order_ids, $batch );
                $page++;
            } while ( count( $batch ) === $per_page );

        } else {
            // LEGACY PATH: Direct SQL
            $stats['method'] = 'Direct SQL';
            global $wpdb;
            
            // Ensure statuses have 'wc-' prefix for SQL if they are standard, or handle custom
            // Usually DB stores 'wc-completed', but input might be 'completed' if coming from certain places. 
            // wc_get_order_statuses() returns keys with 'wc-'. 
            // We ensure we query for what is likely in DB.
            
            $sql_statuses = array();
            foreach($target_statuses as $s) {
               // If it doesn't start with wc- and isn't a custom status that lacks it (rare), append. 
               // Actually safe to assume keys from wc_get_order_statuses() are correct DB values.
               $sql_statuses[] = $s; 
            }

            $status_placeholders = implode( ',', array_fill( 0, count( $sql_statuses ), '%s' ) );
            
            $sql = $wpdb->prepare( "
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'shop_order' 
                AND post_status IN ($status_placeholders) 
                AND post_date >= %s
            ", array_merge( $sql_statuses, array( $start_date_ymd . ' 00:00:00' ) ) );
            
            $order_ids = $wpdb->get_col( $sql );
        }

        // ---------------------------------------------------------
        // PROCESS ORDERS
        // ---------------------------------------------------------
        if ( ! empty( $order_ids ) ) {
            $stats['count'] = count( $order_ids );
            
            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) continue;

                $date_created = $order->get_date_created();
                if ( ! $date_created ) continue;
                $order_date = wp_date( 'Y-m-d', $date_created->getTimestamp() );
                
                // Safety check
                if ( ! isset( $stats['history'][ $order_date ] ) ) continue;

                $order_revenue = (float) $order->get_total();
                $order_refunds = (float) $order->get_total_refunded();
                $order_fees    = self::calculate_single_order_fee( $order_revenue, $fee_config );
                
                $order_cogs = 0;
                $order_ship = 0;

                foreach ( $order->get_items() as $item ) {
                    $product = $item->get_product();
                    if ( ! $product ) continue;

                    $qty = $item->get_quantity();
                    $line_total = (float) $item->get_total();
                    
                    // Cost Logic
                    $cogs_val = (float) $product->get_meta( '_cogs_total_value' );
                    $ship_val = (float) $product->get_meta( '_cw_est_shipping' );
                    
                    $cost_basis = ($cogs_val + $ship_val) * $qty;
                    $order_cogs += ($cogs_val * $qty);
                    $order_ship += ($ship_val * $qty);

                    // Leaderboard
                    $pid = $item->get_product_id();
                    if ( ! isset( $stats['products'][$pid] ) ) {
                        $stats['products'][$pid] = array( 'id' => $pid, 'name' => $product->get_name(), 'qty' => 0, 'net' => 0 );
                    }
                    $stats['products'][$pid]['qty'] += $qty;
                    $stats['products'][$pid]['net'] += ($line_total - $cost_basis);
                }

                // Aggregate
                $stats['revenue']  += $order_revenue;
                $stats['refunds']  += $order_refunds;
                $stats['fees']     += $order_fees;
                $stats['cogs']     += $order_cogs;
                $stats['shipping'] += $order_ship;

                // Daily History
                $daily_costs = $order_cogs + $order_ship + $order_fees + $order_refunds;
                $daily_profit = $order_revenue - $daily_costs;

                $stats['history'][ $order_date ]['revenue'] += $order_revenue;
                $stats['history'][ $order_date ]['costs']   += $daily_costs;
                $stats['history'][ $order_date ]['profit']  += $daily_profit;
            }
        }

        $stats['total_costs'] = $stats['cogs'] + $stats['shipping'] + $stats['fees'] + $stats['refunds'];
        $stats['net_profit']  = $stats['revenue'] - $stats['total_costs'];
        $stats['margin']      = $stats['revenue'] > 0 ? ( $stats['net_profit'] / $stats['revenue'] ) * 100 : 0;

        usort( $stats['products'], function($a, $b) { return $b['net'] <=> $a['net']; });
        
        // Cache result
        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /**
     * Identify inventory items at risk of stockout based on 30-day sales velocity.
     *
     * Computes average daily sales for each product over the last 30 days and returns
     * items whose estimated days remaining (stock / velocity) is less than 14.
     *
     * @return array[] An array of risky items sorted by ascending `days_left`. Each item is an associative array with keys:
     *                 - 'id' (int): Product ID.
     *                 - 'name' (string): Product name.
     *                 - 'stock' (float|int): Current stock quantity.
     *                 - 'velocity' (float): Average daily sales over the last 30 days.
     *                 - 'days_left' (float): Estimated days remaining at the current velocity.
     */
    private static function get_inventory_velocity() {
        // Use simpler logic for velocity too
        $sold_map = array();
        $date_from = strtotime( '-30 days' );
        
        $page = 1;
        $per_page = 500;
        $all_order_ids = array();
        
        do {
            $orders = wc_get_orders( array(
                'limit'        => $per_page,
                'page'         => $page,            'status'       => array( 'completed', 'processing' ), 
                'date_created' => '>=' . $date_from,
                'return'       => 'ids'
        ) );
            $all_order_ids = array_merge( $all_order_ids, $orders );
            $page++;
        } while ( count( $orders ) === $per_page );

        foreach ( $all_order_ids as $oid ) {
            $order = wc_get_order($oid);
            if (!$order) continue;
            foreach ( $order->get_items() as $item ) {
                $pid = $item->get_product_id();
                $qty = $item->get_quantity();
                $sold_map[ $pid ] = ( $sold_map[ $pid ] ?? 0 ) + $qty;
            }
        }

        $risky_items = array();
        foreach ( $sold_map as $pid => $qty_30 ) {
            $product = wc_get_product( $pid );
            if ( ! $product || ! $product->managing_stock() ) continue;

            $stock = $product->get_stock_quantity();
            if ( $stock <= 0 ) continue;

            $velocity = $qty_30 / 30;
            if ( $velocity <= 0 ) continue;

            $days_left = $stock / $velocity;
            if ( $days_left < 14 ) { 
                $risky_items[] = array(
                    'id' => $pid, 'name' => $product->get_name(), 'stock' => $stock, 'velocity' => $velocity, 'days_left' => $days_left
                );
            }
        }
        
        usort( $risky_items, function($a, $b) { return $a['days_left'] <=> $b['days_left']; });
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