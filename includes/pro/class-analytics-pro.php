<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Analytics_Pro {

    /**
     * Register admin hooks required for the analytics feature.
     */
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
     * Enqueue Chart.js and the analytics UI styles.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'cirrusly-analytics' ) === false ) {
            return;
        }

        wp_enqueue_script( 'cirrusly-chartjs', CIRRUSLY_COMMERCE_URL . 'assets/js/vendor/chart.umd.min.js', array(), '4.4.0', true );
        
        wp_enqueue_style( 'cirrusly-analytics-styles', false );
        wp_add_inline_style( 'cirrusly-analytics-styles', "
            .cirrusly-analytics-wrapper { max-width: 100%; box-sizing: border-box; }
            
            /* Enhanced Filter Bar */
            .cirrusly-analytics-controls {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 15px 20px;
                margin-bottom: 25px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 20px;
            }
            .cirrusly-control-group { display: flex; align-items: center; gap: 15px; }
            .cirrusly-control-label { 
                font-size: 11px; 
                font-weight: 700; 
                color: #646970; 
                text-transform: uppercase; 
                letter-spacing: 0.5px;
            }

            /* Beta Badge in Toolbar */
            .cirrusly-beta-tag {
                display: inline-block;
                background: #f0f6fc;
                color: #0c5460;
                border: 1px solid #cff4fc;
                font-size: 10px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 4px;
                text-transform: uppercase;
                margin-right: 10px;
            }

            /* Modern Select */
            .cirrusly-select-wrapper { position: relative; }
            .cirrusly-modern-select {
                appearance: none;
                background: #f6f7f7 url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2220%22%20height%3D%2220%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cpath%20d%3D%22M5%206l5%205%205-5%202%201-7%207-7-7%202-1z%22%20fill%3D%22%23555%22%2F%3E%3C%2Fsvg%3E') no-repeat right 8px top 55%;
                background-size: 16px 16px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 0 30px 0 10px;
                height: 34px;
                line-height: 34px;
                font-size: 13px;
                color: #3c434a;
                cursor: pointer;
                transition: border-color 0.2s;
                min-width: 150px;
            }
            .cirrusly-modern-select:hover { border-color: #2271b1; }
            .cirrusly-modern-select:focus { border-color: #2271b1; outline: 1px solid #2271b1; box-shadow: 0 0 0 1px #2271b1; }

            /* Modern Pills */
            .cirrusly-status-pills { display: flex; gap: 8px; flex-wrap: wrap; }
            .cirrusly-status-pill {
                display: inline-flex;
                align-items: center;
                padding: 6px 14px;
                border-radius: 18px;
                font-size: 12px;
                font-weight: 500;
                cursor: pointer;
                border: 1px solid #dcdcde;
                background: #fff;
                color: #50575e;
                transition: all 0.2s ease;
                user-select: none;
                box-shadow: 0 1px 1px rgba(0,0,0,0.02);
            }
            .cirrusly-status-pill:hover { 
                border-color: #2271b1; 
                color: #2271b1; 
                transform: translateY(-1px);
            }
            .cirrusly-status-pill.is-active {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
                box-shadow: 0 2px 4px rgba(34, 113, 177, 0.2);
            }
            .cirrusly-status-pill input {
                position: absolute;
                opacity: 0;
                width: 1px;
                height: 1px;
                margin: 0;
                pointer-events: none;
            }

            /* KPI Grid - Strict Layout */
            .cirrusly-dash-grid { 
                display: grid; 
                gap: 20px;
                margin-bottom: 25px;
                box-sizing: border-box;
                width: 100%;
            }
            .cirrusly-dash-grid.four-cols { 
                /* minmax(0, 1fr) prevents content blowout */
                grid-template-columns: repeat(4, minmax(0, 1fr)); 
            }

            /* Adjusted Card Styles */
            .cirrusly-dash-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-top-width: 4px;
                padding: 20px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                border-radius: 3px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                min-height: 100px;
            }
            .cirrusly-stat-big { 
                font-size: 26px; 
                font-weight: 600; 
                margin: 10px 0; 
                color: #1d2327;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .cirrusly-card-head { 
                font-size: 11px; 
                text-transform: uppercase; 
                color: #646970; 
                font-weight: 600; 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
            }

            /* Chart Cards */
            .cirrusly-chart-card { background: #fff; border: 1px solid #c3c4c7; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); border-radius: 3px; }
            .cirrusly-chart-header { padding: 15px 20px; background: #fff; border-bottom: 1px solid #f0f0f1; }
            .cirrusly-chart-header h2 { font-size: 14px; margin: 0; font-weight: 600; color: #1d2327; }
            .cirrusly-chart-body { padding: 20px; position: relative; }

            /* Responsive */
            @media (max-width: 1200px) { 
                .cirrusly-dash-grid.four-cols { grid-template-columns: repeat(2, 1fr); } 
            }
            @media (max-width: 782px) { 
                .cirrusly-dash-grid.four-cols { grid-template-columns: 1fr; } 
                .cirrusly-analytics-controls { flex-direction: column; align-items: stretch; gap: 15px; }
                .cirrusly-control-group { flex-wrap: wrap; }
            }
        " );
    }

    /**
     * Renders the Pro Plus Analytics admin page.
     */
    public function render_analytics_view() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cirrusly-commerce' ) );
        }

        $params = self::get_filter_params();
        $days = $params['days'];
        $selected_statuses = $params['statuses'];

        $all_statuses = wc_get_order_statuses();

        // Handle Force Refresh
        if ( isset( $_GET['cirrusly_refresh'] ) && check_admin_referer( 'cirrusly_refresh_analytics' ) ) {
            update_option( 'cirrusly_analytics_cache_version', time(), false );
            wp_safe_redirect( remove_query_arg( array( 'cirrusly_refresh', '_wpnonce' ) ) );
            exit;
        }

        // Standard Header (Reverted)
        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            Cirrusly_Commerce_Core::render_page_header( 'Pro Plus Analytics' );
        } else {
            echo '<div class="wrap"><h1>Analytics</h1>';
        }
        
        // Data Retrieval
        $data = self::get_pnl_data( $days, $selected_statuses );
        if ( empty( $selected_statuses ) ) {
            $selected_statuses = $data['statuses_used'];
        }
        $velocity = self::get_inventory_velocity();
        $refresh_url = wp_nonce_url( add_query_arg( array( 'cirrusly_refresh' => '1' ) ), 'cirrusly_refresh_analytics' );
        
        // JS Data
        $gmc_history = get_option( 'cirrusly_gmc_history', array() );
        $perf_history_json = wp_json_encode( $data['history'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        $gmc_history_json  = wp_json_encode( $gmc_history, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        
        $cost_breakdown = array(
            'COGS'     => $data['cogs'],
            'Shipping' => $data['shipping'],
            'Fees'     => $data['fees'],
            'Refunds'  => $data['refunds']
        );
        $cost_json = wp_json_encode( array_values($cost_breakdown) );
        $cost_labels_json = wp_json_encode( array_keys($cost_breakdown) );

        $script = "
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Performance Chart
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
                            { label: 'Net Profit', data: profit, type: 'line', borderColor: '#00a32a', borderWidth: 2, pointRadius: 1, fill: false, tension: 0.1, order: 1 },
                            { label: 'Net Sales', data: sales, backgroundColor: '#2271b1', barPercentage: 0.6, order: 2 },
                            { label: 'Costs', data: costs, backgroundColor: '#d63638', barPercentage: 0.6, order: 3 }
                        ]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        interaction: { mode: 'index', intersect: false }, 
                        plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 6 } } }, 
                        scales: { y: { beginAtZero: true, grid: { borderDash: [2, 2] } }, x: { grid: { display: false } } } 
                    }
                });
            }

            // 2. Cost Breakdown (Doughnut)
            const costCtx = document.getElementById('costBreakdownChart');
            if (costCtx) {
                new Chart(costCtx, {
                    type: 'doughnut',
                    data: {
                        labels: {$cost_labels_json},
                        datasets: [{
                            data: {$cost_json},
                            backgroundColor: ['#d63638', '#dba617', '#a7aaad', '#1d2327'],
                            borderWidth: 0
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10 } } }, cutout: '65%' }
                });
            }

            // 3. Refunds Trend (Small Line)
            const refCtx = document.getElementById('refundsTrendChart');
            if (refCtx) {
                const h = {$perf_history_json};
                const dates = Object.keys(h);
                const refunds = dates.map(d => h[d].refunds || 0);
                new Chart(refCtx, {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: [{ label: 'Refunds', data: refunds, borderColor: '#646970', borderWidth: 1.5, fill: true, backgroundColor: 'rgba(100,105,112,0.1)', tension: 0.3, pointRadius: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { display: false }, grid: { display: false } }, x: { display: false } } }
                });
            }

            // 4. GMC Trend
            const gmcCtx = document.getElementById('gmcTrendChart');
            if (gmcCtx) {
                const history = {$gmc_history_json};
                const labels = Object.keys(history).slice(-30);
                const criticalData = labels.map(d => history[d].critical || 0);
                new Chart(gmcCtx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{ label: 'Issues', data: criticalData, borderColor: '#d63638', backgroundColor: 'rgba(214, 54, 56, 0.05)', fill: true, tension: 0.3, pointRadius: 2 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
                });
            }

            // Status Pill Logic
            const formEl = document.getElementById('cirrusly-analytics-form');
            document.querySelectorAll('.cirrusly-status-pill input[type=\"checkbox\"]').forEach(cb => {
                cb.addEventListener('change', function() {
                    const pill = this.closest('.cirrusly-status-pill');
                    if (pill) pill.classList.toggle('is-active', this.checked);
                    if (formEl) formEl.submit();
                });
            });
        });";

        wp_add_inline_script( 'cirrusly-chartjs', $script );
        ?>
        
        <div class="wrap cirrusly-analytics-wrapper">

            <form method="get" action="" id="cirrusly-analytics-form">
                <input type="hidden" name="page" value="cirrusly-analytics">
                
                <div class="cirrusly-analytics-controls">
                    <div style="flex: 1;">
                        <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">
                            <span class="cirrusly-beta-tag">Beta</span>
                            
                            <div class="cirrusly-select-wrapper">
                                <select name="period" class="cirrusly-modern-select" onchange="this.form.submit()">
                                    <option value="7" <?php selected( $days, 7 ); ?>>Last 7 Days</option>
                                    <option value="30" <?php selected( $days, 30 ); ?>>Last 30 Days</option>
                                    <option value="90" <?php selected( $days, 90 ); ?>>Last 90 Days</option>
                                    <option value="180" <?php selected( $days, 180 ); ?>>Last 6 Months</option>
                                    <option value="365" <?php selected( $days, 365 ); ?>>Last Year</option>
                                </select>
                            </div>
                            
                            <span style="font-size:12px; color:#a7aaad;">Analyzing <strong><?php echo intval($data['count']); ?></strong> orders</span>
                        </div>

                        <div class="cirrusly-control-group">
                            <span class="cirrusly-control-label">Status:</span>
                            <div class="cirrusly-status-pills">
                                <?php foreach ( $all_statuses as $slug => $label ) : 
                                    $is_active = in_array( $slug, $selected_statuses ) || in_array( str_replace('wc-','',$slug), $selected_statuses );
                                ?>
                                    <label class="cirrusly-status-pill <?php echo $is_active ? 'is-active' : ''; ?>">
                                        <input type="checkbox" name="cirrusly_statuses[]" value="<?php echo esc_attr($slug); ?>" <?php checked( $is_active ); ?>>
                                        <?php echo esc_html( str_replace('wc-', '', $slug) ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div style="align-self: flex-start;">
                        <a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" title="Re-fetch data from database">
                            <span class="dashicons dashicons-update" style="line-height: 26px;"></span> Refresh Data
                        </a>
                    </div>
                </div>
            </form>

            <div class="cirrusly-dash-grid four-cols">
                
                <div class="cirrusly-dash-card" style="border-top-color: #2271b1;">
                    <div class="cirrusly-card-head"><span>Net Sales</span> <span class="dashicons dashicons-chart-bar"></span></div>
                    <div class="cirrusly-stat-big"><?php echo wp_kses_post( wc_price( $data['revenue'] ) ); ?></div>
                    <div class="cirrusly-card-head" style="font-weight:normal;">Gross Revenue</div>
                </div>

                <div class="cirrusly-dash-card" style="border-top-color: #d63638;">
                    <div class="cirrusly-card-head"><span>Total Costs</span> <span class="dashicons dashicons-cart"></span></div>
                    <div class="cirrusly-stat-big" style="color:#d63638;"><?php echo wp_kses_post( wc_price( $data['total_costs'] ) ); ?></div>
                    <div class="cirrusly-card-head" style="font-weight:normal;">All Expenses</div>
                </div>

                <div class="cirrusly-dash-card" style="border-top-color: #00a32a;">
                    <div class="cirrusly-card-head"><span>Net Profit</span> <span class="dashicons dashicons-money-alt"></span></div>
                    <div class="cirrusly-stat-big" style="color:#00a32a;"><?php echo wp_kses_post( wc_price( $data['net_profit'] ) ); ?></div>
                    <div class="cirrusly-card-head" style="font-weight:normal;"><?php echo esc_html( number_format( $data['margin'], 1 ) ); ?>% Margin</div>
                </div>

                <div class="cirrusly-dash-card" style="border-top-color: #646970;">
                    <div class="cirrusly-card-head"><span>Returns</span> <span class="dashicons dashicons-undo"></span></div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="cirrusly-stat-big" style="color:#646970; font-size:20px;"><?php echo wp_kses_post( wc_price( $data['refunds'] ) ); ?></div>
                        <div style="width: 70px; height: 35px;"><canvas id="refundsTrendChart"></canvas></div>
                    </div>
                    <div class="cirrusly-card-head" style="font-weight:normal;">Refund Volume</div>
                </div>

            </div>

            <div class="cirrusly-chart-card">
                <div class="cirrusly-chart-header">
                    <h2>Performance Overview</h2>
                </div>
                <div class="cirrusly-chart-body">
                    <div style="width: 100%; height: 320px;">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                
                <div class="cirrusly-chart-card">
                    <div class="cirrusly-chart-header">
                        <h2>Cost Breakdown</h2>
                    </div>
                    <div class="cirrusly-chart-body">
                        <div style="height: 220px;">
                            <canvas id="costBreakdownChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="cirrusly-chart-card">
                    <div class="cirrusly-chart-header">
                        <h2>GMC Disapprovals</h2>
                    </div>
                    <div class="cirrusly-chart-body">
                        <div style="height: 220px;">
                            <canvas id="gmcTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="cirrusly-chart-card">
                    <div class="cirrusly-chart-header">
                        <h2>Top Winners</h2>
                    </div>
                    <div style="padding:0;">
                        <table class="wp-list-table widefat striped" style="border:none; box-shadow:none;">
                            <tbody>
                                <?php 
                                $top_products = array_slice( $data['products'], 0, 5 ); 
                                if ( empty( $top_products ) ) {
                                    echo '<tr><td style="padding:15px; color:#777;">No data.</td></tr>';
                                } else {
                                    foreach ( $top_products as $p ) {
                                        echo '<tr>';
                                        echo '<td style="padding: 12px 15px;"><a href="' . esc_url( get_edit_post_link($p['id']) ) . '" style="font-weight:600; text-decoration:none; color:#1d2327;">'. esc_html( mb_strimwidth($p['name'], 0, 25, '...') ) .'</a></td>';
                                        echo '<td style="text-align:right; font-weight:bold; color:#00a32a; padding: 12px 15px;">' . wp_kses_post( wc_price($p['net']) ) . '</td>';
                                        echo '</tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <?php if ( ! empty( $velocity ) ) : ?>
            <div class="cirrusly-chart-card" style="border-left: 4px solid #dba617;">
                <div class="cirrusly-chart-header" style="background: #fff8e5;">
                    <h2 style="color:#dba617;">⚠️ Inventory Risk (Stockout < 14 Days)</h2>
                </div>
                <table class="wp-list-table widefat striped" style="border:none; box-shadow:none;">
                    <thead><tr><th style="padding-left:15px;">Product</th><th>Stock</th><th>Velocity</th><th>Days Left</th><th style="text-align:right; padding-right:15px;">Action</th></tr></thead>
                    <tbody>
                        <?php foreach ( array_slice($velocity, 0, 5) as $v ) : ?>
                            <tr>
                                <td style="padding-left:15px;"><a href="<?php echo esc_url( get_edit_post_link($v['id']) ); ?>" style="font-weight:600; color:#1d2327; text-decoration:none;"><?php echo esc_html( $v['name'] ); ?></a></td>
                                <td style="color:#d63638; font-weight:bold;"><?php echo esc_html( $v['stock'] ); ?></td>
                                <td><?php echo esc_html( number_format( $v['velocity'], 1 ) ); ?>/day</td>
                                <td><?php echo esc_html( round( $v['days_left'] ) ); ?></td>
                                <td style="text-align:right; padding-right:15px;"><a href="<?php echo esc_url( get_edit_post_link($v['id']) ); ?>" class="button button-small">Restock</a></td>
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
     * Helper: Extract filter params.
     */
    private static function get_filter_params() {
        $default_days = 90;
        $days = isset($_GET['period']) ? intval($_GET['period']) : $default_days;
        $days = max( 7, min( $days, 365 ) ); 

        $selected_statuses = array();
        if ( isset( $_GET['cirrusly_statuses'] ) && is_array( $_GET['cirrusly_statuses'] ) ) {
            $selected_statuses = array_map( 'sanitize_text_field', wp_unslash( $_GET['cirrusly_statuses'] ) );
        }

        return array( 'days' => $days, 'statuses' => $selected_statuses );
    }

    /**
     * Compute PnL metrics.
     */
    private static function get_pnl_data( $days = 90, $custom_statuses = array() ) {
        $start_date_ymd = wp_date( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
        $end_date_ymd   = wp_date( 'Y-m-d', time() );
        
        $target_statuses = array();
        if ( ! empty( $custom_statuses ) ) {
            $target_statuses = $custom_statuses;
        } else {
            $all_statuses = array_keys( wc_get_order_statuses() );
            $excluded_statuses = array( 'wc-cancelled', 'wc-failed', 'wc-trash', 'wc-pending', 'wc-checkout-draft' );
            $target_statuses = array_diff( $all_statuses, $excluded_statuses );
            if ( empty( $target_statuses ) ) $target_statuses = array('wc-completed', 'wc-processing', 'wc-on-hold'); 
        }

        $status_hash = md5( json_encode( $target_statuses ) );
        $version     = get_option( 'cirrusly_analytics_cache_version', '1' );
        $cache_key   = 'cirrusly_analytics_pnl_v6_' . $days . '_' . $status_hash . '_' . $version;
        
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) { return $cached; }

        $stats = array(
            'revenue' => 0, 'cogs' => 0, 'shipping'=> 0, 'fees' => 0, 'refunds' => 0, 'total_costs' => 0, 'net_profit' => 0, 'margin' => 0,
            'count'   => 0, 'products'=> array(), 'history' => array(), 
            'method' => 'Unknown', 'statuses_used' => $target_statuses
        );

        $period = new DatePeriod( new DateTime($start_date_ymd), new DateInterval('P1D'), (new DateTime($end_date_ymd))->modify('+1 day') );
        foreach ( $period as $dt ) { 
            $stats['history'][ $dt->format( 'Y-m-d' ) ] = array( 'revenue' => 0, 'costs' => 0, 'profit' => 0, 'refunds' => 0 ); 
        }

        $fee_config = get_option( 'cirrusly_shipping_config', array() );
        
        // Query Logic (HPOS vs Legacy)
        $hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $order_ids = array();

        if ( $hpos_enabled ) {
            $page = 1;
            do {
                $batch = wc_get_orders( array(
                    'limit'        => 500,
                    'page'         => $page,
                    'status'       => $target_statuses,
                    'date_created' => strtotime($start_date_ymd) . '...' . time(), 
                    'type'         => 'shop_order',
                    'return'       => 'ids',
                ) );
                $order_ids = array_merge( $order_ids, $batch );
                $page++;
            } while ( count( $batch ) === 500 );
        } else {
            global $wpdb;
            $sql_statuses = array_map( 'esc_sql', $target_statuses );
            $status_str = "'" . implode( "','", $sql_statuses ) . "'";
            $order_ids = $wpdb->get_col( $wpdb->prepare( "
                SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'shop_order' 
                AND post_status IN ($status_str) 
                AND post_date >= %s
            ", $start_date_ymd . ' 00:00:00' ) );
        }

        // Process
        if ( ! empty( $order_ids ) ) {
            $stats['count'] = count( $order_ids );
            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) continue;

                $date_created = $order->get_date_created();
                if ( ! $date_created ) continue;
                $order_date = wp_date( 'Y-m-d', $date_created->getTimestamp() );
                
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
                    
                    $cogs_val = (float) $product->get_meta( '_cogs_total_value' );
                    $ship_val = (float) $product->get_meta( '_cirrusly_est_shipping' );
                    
                    $cost_basis = ($cogs_val + $ship_val) * $qty;
                    $order_cogs += ($cogs_val * $qty);
                    $order_ship += ($ship_val * $qty);

                    $pid = $item->get_product_id();
                    if ( ! isset( $stats['products'][$pid] ) ) {
                        $stats['products'][$pid] = array( 'id' => $pid, 'name' => $product->get_name(), 'qty' => 0, 'net' => 0 );
                    }
                    $stats['products'][$pid]['qty'] += $qty;
                    $stats['products'][$pid]['net'] += ( (float)$item->get_total() - $cost_basis );
                }

                $stats['revenue']  += $order_revenue;
                $stats['refunds']  += $order_refunds;
                $stats['fees']     += $order_fees;
                $stats['cogs']     += $order_cogs;
                $stats['shipping'] += $order_ship;

                $daily_costs = $order_cogs + $order_ship + $order_fees + $order_refunds;
                $daily_profit = $order_revenue - $daily_costs;

                $stats['history'][ $order_date ]['revenue'] += $order_revenue;
                $stats['history'][ $order_date ]['costs']   += $daily_costs;
                $stats['history'][ $order_date ]['profit']  += $daily_profit;
                $stats['history'][ $order_date ]['refunds'] += $order_refunds;
            }
        }

        $stats['total_costs'] = $stats['cogs'] + $stats['shipping'] + $stats['fees'] + $stats['refunds'];
        $stats['net_profit']  = $stats['revenue'] - $stats['total_costs'];
        $stats['margin']      = $stats['revenue'] > 0 ? ( $stats['net_profit'] / $stats['revenue'] ) * 100 : 0;

        usort( $stats['products'], function($a, $b) { return $b['net'] <=> $a['net']; });
        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );
        return $stats;
    }

    private static function get_inventory_velocity() {
        $sold_map = array();
        $date_from = strtotime( '-30 days' );
        
        $page = 1;
        $per_page = 250; 
        do {
            $orders = wc_get_orders( array(
                'limit' => $per_page, 'page' => $page, 'status' => array( 'completed', 'processing' ), 'date_created' => '>=' . $date_from, 'return' => 'ids'
            ) );
            foreach ( $orders as $oid ) {
                $order = wc_get_order($oid);
                if (!$order) continue;
                foreach ( $order->get_items() as $item ) {
                    $pid = $item->get_product_id();
                    $sold_map[ $pid ] = ( $sold_map[ $pid ] ?? 0 ) + $item->get_quantity();
                }
            }
            $page++;
        } while ( count( $orders ) === $per_page );

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
                $risky_items[] = array( 'id' => $pid, 'name' => $product->get_name(), 'stock' => $stock, 'velocity' => $velocity, 'days_left' => $days_left );
            }
        }
        usort( $risky_items, function($a, $b) { return $a['days_left'] <=> $b['days_left']; });
        return $risky_items;
    }

    public function capture_daily_gmc_snapshot() {
        $scan_data = get_option( 'cirrusly_gmc_scan_data', array() );
        if ( empty( $scan_data['results'] ) ) return;

        $critical = 0; $warnings = 0;
        foreach ( $scan_data['results'] as $res ) {
            if ( empty( $res['issues'] ) ) continue;
            foreach ( $res['issues'] as $issue ) {
                if ( ($issue['type'] ?? '') === 'critical' ) $critical++; else $warnings++;
            }
        }

        $history = get_option( 'cirrusly_gmc_history', array() );
        $today   = wp_date( 'Y-m-d' );
        $history[$today] = array( 'critical' => $critical, 'warnings' => $warnings, 'ts' => time() );
        if ( count( $history ) > 90 ) $history = array_slice( $history, -90, 90, true );
        update_option( 'cirrusly_gmc_history', $history, false );
    }

    private static function calculate_single_order_fee( $total, $config ) {
        $pay_pct  = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        return ($total * $pay_pct) + $pay_flat;
    }
}