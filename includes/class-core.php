<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Core {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        
        // Scheduled Scan Hook
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'execute_scheduled_scan' ) );
        
        add_action( 'save_post_product', array( $this, 'clear_metrics_cache' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'clear_metrics_cache' ) );
        
        // Force Enable Native COGS (Runtime)
        add_filter( 'pre_option_woocommerce_enable_cost_of_goods_sold', array( $this, 'force_enable_cogs' ) );
    }

    public function force_enable_cogs() { return 'yes'; }
    public function clear_metrics_cache() { delete_transient( 'cirrusly_dashboard_metrics' ); }

    public function enqueue_assets( $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $is_plugin_page = strpos( $page, 'cirrusly-' ) !== false;
        $is_product_page = 'post.php' === $hook || 'post-new.php' === $hook;

        if ( $is_plugin_page || $is_product_page ) {
            wp_enqueue_media(); 
            wp_enqueue_style( 'cirrusly-admin-css', CIRRUSLY_COMMERCE_URL . 'assets/css/admin.css', array(), CIRRUSLY_COMMERCE_VERSION );
            
            // Removed inline nav styles to allow admin.css to handle centering
            wp_add_inline_style( 'cirrusly-admin-css', '
                .cc-manual-helper { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 15px; margin-bottom: 20px; }
                .cc-manual-helper h4 { margin-top: 0; color: #1d2327; }
                .cc-manual-helper p { font-size: 13px; line-height: 1.5; margin-bottom: 0; }
                .cc-promo-generator { background:#fff; border:1px solid #c3c4c7; padding:20px; margin-bottom:20px; }
                .cc-promo-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
                .cc-promo-generator label { display:block; font-weight:600; margin-bottom:5px; font-size:12px; color:#50575e; }
                .cc-promo-generator input, .cc-promo-generator select { width:100%; margin-bottom:15px; border:1px solid #8c8f94; }
                .cc-generated-code { background:#f0f0f1; padding:15px; border:1px dashed #8c8f94; margin-top:15px; font-family:monospace; user-select:all; word-break:break-all; white-space: pre-wrap; }
                .cc-copy-hint { font-size:11px; color:#666; display:block; margin-bottom:5px; text-transform:uppercase; font-weight:bold; }
                .cc-policy-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:20px; }
                .cc-policy-item { background:#fff; padding:10px; border:1px solid #c3c4c7; display:flex; align-items:center; }
                .cc-policy-item .dashicons { margin-right:8px; font-size:20px; }
                .cc-policy-ok .dashicons { color:#008a20; }
                .cc-policy-fail .dashicons { color:#d63638; }
                .cc-settings-card { background:#fff; border:1px solid #c3c4c7; padding:0; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,0.04); }
                .cc-card-header { padding:15px 20px; border-bottom:1px solid #f0f0f1; background:#fcfcfc; }
                .cc-card-header h3 { margin:0; font-size:1.1em; }
                .cc-card-header p { margin:5px 0 0; color:#646970; font-size:13px; }
                .cc-card-body { padding:20px; }
                .cc-settings-table th { font-weight:600; color:#1d2327; }
                .cc-settings-table input[type="number"], .cc-settings-table input[type="text"] { width:100%; max-width:150px; }
            ' );

            if ( $is_product_page ) {
                wp_enqueue_script( 'cirrusly-pricing-js', CIRRUSLY_COMMERCE_URL . 'assets/js/pricing.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
                
                $config = $this->get_global_config();
                $js_config = array(
                    'revenue_tiers' => json_decode( $config['revenue_tiers_json'] ),
                    'matrix_rules'  => json_decode( $config['matrix_rules_json'] ),
                    'classes'       => array()
                );
                
                $class_costs = json_decode( $config['class_costs_json'], true );
                if ( is_array( $class_costs ) ) {
                    foreach( $class_costs as $slug => $cost ) {
                        $js_config['classes'][$slug] = array( 'cost' => (float)$cost, 'matrix' => true ); 
                    }
                }
                
                $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
                $id_map = array();
                if ( ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) $id_map[ $term->term_id ] = $term->slug;
                }

                wp_localize_script( 'cirrusly-pricing-js', 'cw_vars', array( 'ship_config' => $js_config, 'id_map' => $id_map ));
            }
            
            wp_add_inline_script( 'common', 'jQuery(document).ready(function($){
                var frame; var $currentBtn;
                $(document).on("click", ".cc-upload-btn", function(e) {
                    e.preventDefault(); $currentBtn = $(this);
                    if ( frame ) { frame.open(); return; }
                    frame = wp.media({ title: "Select Badge Image", button: { text: "Use this image" }, multiple: false });
                    frame.on( "select", function() {
                        var attachment = frame.state().get("selection").first().toJSON();
                        $currentBtn.prev("input").val(attachment.url).trigger("change");
                    });
                    frame.open();
                });
                $(document).on("click", ".cc-remove-btn", function(e){ e.preventDefault(); $(this).siblings("input").val(""); });
                $("#cc-add-badge-row").click(function(){
                    var idx = $("#cc-badge-rows tr").length + 1000;
                    var row = "<tr><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][tag]\'></td><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][url]\' class=\'regular-text\'> <button type=\'button\' class=\'button cc-upload-btn\'>Upload</button></td><td><input type=\'text\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][tooltip]\'></td><td><input type=\'number\' name=\'cirrusly_badge_config[custom_badges]["+idx+"][width]\' value=\'60\'> px</td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                    $("#cc-badge-rows").append(row);
                });
                $("#cc-add-revenue-row").click(function(){
                    var idx = $("#cc-revenue-rows tr").length + 1000;
                    var row = "<tr><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][min]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][max]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][charge]\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                    $("#cc-revenue-rows").append(row);
                });
                $("#cc-add-matrix-row").click(function(){
                    var idx = $("#cc-matrix-rows tr").length + 1000;
                    var row = "<tr><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][key]\'></td><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][label]\'></td><td>x <input type=\'number\' step=\'0.1\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][cost_mult]\' value=\'1.0\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                    $("#cc-matrix-rows").append(row);
                });
                $(document).on("click", ".cc-remove-row", function(){ $(this).closest("tr").remove(); });
            });' );
        }
    }

    public static function render_page_header( $title ) {
        $mailto = 'mailto:help@cirruslyweather.com?subject=Support%20Request';
        echo '<h1 style="margin-bottom:20px; display:flex; align-items:center;">';
        echo '<img src="' . esc_url( CIRRUSLY_COMMERCE_URL . 'assets/images/logo.svg' ) . '" style="height:50px; width:auto; margin-right:15px;" alt="Cirrusly Commerce">';
        echo esc_html( $title );
        echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';
        echo '<a href="' . esc_attr( $mailto ) . '" class="button button-secondary">Get Support</a>'; 
        echo '<span class="cc-ver-badge" style="background:#f0f0f1;color:#646970;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">v' . esc_html( CIRRUSLY_COMMERCE_VERSION ) . '</span>';
        echo '</div></h1>';
        
        echo '<div class="cc-global-nav">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-commerce' ) ) . '">Dashboard</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-gmc' ) ) . '">GMC Hub</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-audit' ) ) . '">Audit</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-settings' ) ) . '">Settings</a>';
        echo '</div>';
    }

    public static function render_global_header( $title ) {
        self::render_page_header( $title );
    }

    public function register_admin_menus() {
        add_menu_page( 'Cirrusly Commerce', 'Cirrusly Commerce', 'edit_products', 'cirrusly-commerce', array( $this, 'render_main_dashboard' ), 'dashicons-analytics', 56 );
        add_submenu_page( 'cirrusly-commerce', 'Dashboard', 'Dashboard', 'edit_products', 'cirrusly-commerce', array( $this, 'render_main_dashboard' ) );
        add_submenu_page( 'cirrusly-commerce', 'GMC Hub', 'GMC Hub', 'edit_products', 'cirrusly-gmc', array( 'Cirrusly_Commerce_GMC', 'render_page' ) );
        add_submenu_page( 'cirrusly-commerce', 'Store Audit', 'Store Audit', 'edit_products', 'cirrusly-audit', array( 'Cirrusly_Commerce_Audit', 'render_page' ) );
        add_submenu_page( 'cirrusly-commerce', 'Settings', 'Settings', 'manage_options', 'cirrusly-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'cirrusly-commerce', 'User Manual', 'User Manual', 'edit_products', 'cirrusly-manual', array( 'Cirrusly_Commerce_Manual', 'render_page' ) );
    }

    public function register_settings() {
        register_setting( 'cirrusly_shipping_group', 'cirrusly_shipping_config', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
        register_setting( 'cirrusly_reviews_group', 'cirrusly_google_reviews_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );
        register_setting( 'cirrusly_scan_group', 'cirrusly_scan_config', array( 'sanitize_callback' => array( $this, 'handle_scan_schedule' ) ) );
        register_setting( 'cirrusly_msrp_group', 'cirrusly_msrp_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );
        register_setting( 'cirrusly_badge_group', 'cirrusly_badge_config', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
    }

    public function sanitize_options_array( $input ) {
        $clean = array();
        if ( is_array( $input ) ) {
            foreach( $input as $key => $val ) {
                $clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $val );
            }
        }
        return $clean;
    }

    public function handle_scan_schedule( $input ) {
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
        if ( isset($input['enable_daily_scan']) && $input['enable_daily_scan'] === 'yes' ) {
            if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
                wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
            }
        }
        return $this->sanitize_options_array( $input );
    }

    public function sanitize_settings( $input ) {
        if ( isset( $input['revenue_tiers'] ) && is_array( $input['revenue_tiers'] ) ) {
            $clean_tiers = array();
            foreach ( $input['revenue_tiers'] as $tier ) {
                if ( isset($tier['min']) && is_numeric($tier['min']) ) {
                    $clean_tiers[] = array( 
                        'min' => floatval( $tier['min'] ), 
                        'max' => floatval( isset($tier['max']) ? $tier['max'] : 99999 ), 
                        'charge' => floatval( isset($tier['charge']) ? $tier['charge'] : 0 ),
                    );
                }
            }
            $input['revenue_tiers_json'] = json_encode( $clean_tiers );
            unset( $input['revenue_tiers'] );
        }
        if ( isset( $input['matrix_rules'] ) && is_array( $input['matrix_rules'] ) ) {
            $clean_matrix = array();
            foreach ( $input['matrix_rules'] as $idx => $rule ) {
                $key = isset($rule['key']) ? sanitize_title($rule['key']) : (is_string($idx) ? $idx : 'rule_'.$idx);
                if ( ! empty( $key ) && isset( $rule['label'] ) ) {
                    $clean_matrix[ $key ] = array( 'key' => $key, 'label' => sanitize_text_field( $rule['label'] ), 'cost_mult' => floatval( $rule['cost_mult'] ) );
                }
            }
            $input['matrix_rules_json'] = json_encode( $clean_matrix );
            unset( $input['matrix_rules'] );
        }
        if ( isset( $input['class_costs'] ) && is_array( $input['class_costs'] ) ) {
            $clean_costs = array();
            foreach ( $input['class_costs'] as $slug => $cost ) {
                if ( ! empty( $slug ) ) $clean_costs[ sanitize_text_field( $slug ) ] = floatval( $cost );
            }
            $input['class_costs_json'] = json_encode( $clean_costs );
            unset( $input['class_costs'] );
        }
        if ( isset( $input['custom_badges'] ) && is_array( $input['custom_badges'] ) ) {
            $clean_badges = array();
            foreach ( $input['custom_badges'] as $badge ) {
                if ( ! empty($badge['tag']) && ! empty($badge['url']) ) {
                    $clean_badges[] = array(
                        'tag' => sanitize_title( $badge['tag'] ),
                        'url' => esc_url_raw( $badge['url'] ),
                        'tooltip' => sanitize_text_field( $badge['tooltip'] ),
                        'width' => intval( $badge['width'] ) > 0 ? intval( $badge['width'] ) : 60
                    );
                }
            }
            $input['custom_badges_json'] = json_encode( $clean_badges );
            unset( $input['custom_badges'] );
        }
        return $input;
    }

    public function get_global_config() {
        $saved = get_option( 'cirrusly_shipping_config' );
        $defaults = array(
            'revenue_tiers_json' => json_encode(array(
                array( 'min' => 0,     'max' => 10.00, 'charge' => 3.99 ),
                array( 'min' => 10.01, 'max' => 20.00, 'charge' => 4.99 ),
                array( 'min' => 60.00, 'max' => 99999, 'charge' => 0.00 ),
            )),
            'matrix_rules_json' => json_encode(array(
                'economy'   => array( 'key'=>'economy',   'label' => 'Eco',      'cost_mult' => 1.0 ),
                'standard'  => array( 'key'=>'standard',  'label' => 'Std',      'cost_mult' => 1.4 ),
                'twoday'    => array( 'key'=>'twoday',    'label' => '2Day',     'cost_mult' => 2.5 ),
                'overnight' => array( 'key'=>'overnight', 'label' => 'Over',     'cost_mult' => 5.0 ),
            )),
            'class_costs_json' => json_encode(array('default' => 10.00))
        );
        return wp_parse_args( $saved, $defaults );
    }

    // --- Metrics Caching ---
    public static function get_dashboard_metrics() {
        $metrics = get_transient( 'cirrusly_dashboard_metrics' );
        if ( false === $metrics ) {
            global $wpdb;
            
            $scan_data = get_option( 'woo_gmc_scan_data' );
            $gmc_critical = 0; $gmc_warnings = 0;
            if ( ! empty( $scan_data['results'] ) ) {
                foreach( $scan_data['results'] as $res ) {
                    foreach( $res['issues'] as $i ) {
                        if( $i['type'] === 'critical' ) $gmc_critical++; else $gmc_warnings++;
                    }
                }
            }
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $missing_cost = $wpdb->get_var("SELECT count(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_cogs_total_value') WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish' AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = 0)");
            
            $count_posts = wp_count_posts('product');
            $total_products = $count_posts->publish;
            $on_sale_ids = wc_get_product_ids_on_sale();
            $on_sale_count = count( $on_sale_ids );
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $margin_query = $wpdb->get_results("SELECT pm.meta_value as cost, p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_cogs_total_value' AND p.post_type = 'product' AND p.post_status = 'publish' LIMIT 100");
            
            $total_margin = 0; $margin_count = 0;
            foreach($margin_query as $row) {
                $product = wc_get_product($row->ID);
                if($product) {
                    $price = (float)$product->get_price(); $cost = (float)$row->cost;
                    if($price > 0 && $cost > 0) { $margin = (($price - $cost) / $price) * 100; $total_margin += $margin; $margin_count++; }
                }
            }
            $avg_margin = $margin_count > 0 ? round($total_margin / $margin_count, 1) : 0;

            $metrics = array(
                'gmc_critical' => $gmc_critical,
                'gmc_warnings' => $gmc_warnings,
                'missing_cost' => $missing_cost,
                'total_products' => $total_products,
                'on_sale_count' => $on_sale_count,
                'avg_margin' => $avg_margin
            );
            set_transient( 'cirrusly_dashboard_metrics', $metrics, 1 * HOUR_IN_SECONDS );
        }
        return $metrics;
    }

    public function render_main_dashboard() {
        self::render_page_header( 'Cirrusly Commerce Dashboard' );
        $m = self::get_dashboard_metrics();
        ?>
        <div class="wrap">
            <div class="cc-intro-text" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px; border-left:4px solid #2271b1;">
                <h3><?php esc_html_e( 'Welcome to Cirrusly Commerce', 'cirrusly-commerce' ); ?></h3>
                <p><?php esc_html_e( 'Your comprehensive suite for optimizing your WooCommerce store\'s financial health and Google Merchant Center compliance.', 'cirrusly-commerce' ); ?></p>
            </div>
            <style>
                .cc-dash-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
                .cc-dash-card { background: #fff; border: 1px solid #ccd0d4; border-top-width: 4px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); position: relative; }
                .cc-full-width { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; border-top-color: #646970; }
                .cc-stat-block { text-align: center; flex: 1; border-right: 1px solid #eee; }
                .cc-stat-block:last-child { border-right: none; }
                .cc-big-num { font-size: 24px; font-weight: 700; color: #2c3338; display: block; }
                .cc-label { font-size: 11px; text-transform: uppercase; color: #646970; font-weight: 600; margin-top: 5px; display: block; }
                .cc-card-head { font-size: 14px; text-transform: uppercase; color: #646970; font-weight: 600; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
                .cc-stat-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
                .cc-stat-val { font-weight: 700; font-size: 16px; }
                .cc-val-bad { color: #d63638; } .cc-val-warn { color: #dba617; } .cc-val-good { color: #008a20; }
                .cc-actions { margin-top: 20px; text-align: right; }
            </style>
            <div class="cc-dash-grid">
                <div class="cc-dash-card cc-full-width">
                    <div class="cc-stat-block"><span class="cc-big-num"><?php echo esc_html( $m['total_products'] ); ?></span><span class="cc-label">Catalog Size</span></div>
                    <div class="cc-stat-block"><span class="cc-big-num" style="color: #d63638;"><?php echo esc_html( $m['on_sale_count'] ); ?></span><span class="cc-label">On Sale</span></div>
                    <div class="cc-stat-block"><span class="cc-big-num" style="color: #00a32a;"><?php echo esc_html( $m['avg_margin'] ); ?>%</span><span class="cc-label">Avg Margin (Est.)</span></div>
                    <div class="cc-stat-block"><span class="cc-big-num" style="color: #dba617;"><?php echo esc_html( $m['missing_cost'] ); ?></span><span class="cc-label">Missing Cost</span></div>
                </div>
                
                <div class="cc-dash-card" style="border-top-color: #d63638;">
                    <div class="cc-card-head"><span>Google Merchant Center</span> <span class="dashicons dashicons-google"></span></div>
                    <div class="cc-stat-row"><span>Critical Issues</span><span class="cc-stat-val <?php echo $m['gmc_critical'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>"><?php echo esc_html( $m['gmc_critical'] ); ?></span></div>
                    <div class="cc-stat-row"><span>Warnings</span><span class="cc-stat-val <?php echo $m['gmc_warnings'] > 0 ? 'cc-val-warn' : 'cc-val-good'; ?>"><?php echo esc_html( $m['gmc_warnings'] ); ?></span></div>
                    <div class="cc-actions"><a href="admin.php?page=cirrusly-gmc&tab=scan" class="button button-primary">Fix Issues</a></div>
                </div>

                <div class="cc-dash-card" style="border-top-color: #2271b1;">
                    <div class="cc-card-head"><span>Store Integrity</span> <span class="dashicons dashicons-analytics"></span></div>
                    <div class="cc-stat-row"><span>Products Missing Cost</span><span class="cc-stat-val <?php echo $m['missing_cost'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>"><?php echo esc_html( $m['missing_cost'] ); ?></span></div>
                    <div class="cc-actions"><a href="admin.php?page=cirrusly-audit" class="button button-secondary">Open Audit</a></div>
                </div>
                
                <div class="cc-dash-card" style="border-top-color: #00a32a;">
                    <div class="cc-card-head"><span>Quick Links</span> <span class="dashicons dashicons-admin-links"></span></div>
                    <div class="cc-stat-row"><a href="admin.php?page=cirrusly-gmc&tab=promotions">Promotions Manager</a></div>
                    <div class="cc-stat-row"><a href="admin.php?page=cirrusly-settings">Plugin Settings</a></div>
                    <div class="cc-stat-row"><a href="admin.php?page=cirrusly-manual">User Manual</a></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'shipping';
        echo '<div class="wrap">';
        self::render_page_header( 'Settings' );
        echo '<nav class="nav-tab-wrapper"><a href="?page=cirrusly-settings&tab=shipping" class="nav-tab '.($tab=='shipping'?'nav-tab-active':'').'">Shipping</a><a href="?page=cirrusly-settings&tab=reviews" class="nav-tab '.($tab=='reviews'?'nav-tab-active':'').'">Reviews</a><a href="?page=cirrusly-settings&tab=scans" class="nav-tab '.($tab=='scans'?'nav-tab-active':'').'">Scans</a><a href="?page=cirrusly-settings&tab=msrp" class="nav-tab '.($tab=='msrp'?'nav-tab-active':'').'">MSRP</a><a href="?page=cirrusly-settings&tab=badges" class="nav-tab '.($tab=='badges'?'nav-tab-active':'').'">Badges</a></nav>';
        echo '<br><form method="post" action="options.php">';
        
        if($tab==='msrp'){ settings_fields('cirrusly_msrp_group'); do_settings_sections('cirrusly_msrp_group'); $this->render_msrp_settings(); }
        elseif($tab==='reviews'){ settings_fields('cirrusly_reviews_group'); do_settings_sections('cirrusly_reviews_group'); $this->render_reviews_settings(); }
        elseif($tab==='scans'){ settings_fields('cirrusly_scan_group'); do_settings_sections('cirrusly_scan_group'); $this->render_scans_settings(); }
        elseif($tab==='badges'){ settings_fields('cirrusly_badge_group'); $this->render_badges_settings(); }
        else { settings_fields('cirrusly_shipping_group'); $this->render_shipping_settings(); }
        
        submit_button(); 
        echo '</form></div>';
    }

    private function render_badges_settings() {
        $cfg = get_option( 'cirrusly_badge_config', array() );
        $enabled = isset($cfg['enable_badges']) ? $cfg['enable_badges'] : '';
        $size = isset($cfg['badge_size']) ? $cfg['badge_size'] : 'medium';
        $calc_from = isset($cfg['calc_from']) ? $cfg['calc_from'] : 'msrp';
        $custom_badges = isset($cfg['custom_badges_json']) ? json_decode($cfg['custom_badges_json'], true) : array();
        if(!is_array($custom_badges)) $custom_badges = array();

        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Badge Manager</h3><p>Automatically replace default WooCommerce sale badges with smart, percentage-based badges.</p></div>';
        echo '<div class="cc-card-body"><table class="form-table cc-settings-table">
            <tr><th scope="row">Enable Module</th><td><label><input type="checkbox" name="cirrusly_badge_config[enable_badges]" value="yes" '.checked('yes', $enabled, false).'> Activate</label></td></tr>
            <tr><th scope="row">Badge Size</th><td><select name="cirrusly_badge_config[badge_size]"><option value="small" '.selected('small', $size, false).'>Small</option><option value="medium" '.selected('medium', $size, false).'>Medium</option><option value="large" '.selected('large', $size, false).'>Large</option></select></td></tr>
            <tr><th scope="row">Discount Base</th><td><select name="cirrusly_badge_config[calc_from]"><option value="msrp" '.selected('msrp', $calc_from, false).'>MSRP</option><option value="regular" '.selected('regular', $calc_from, false).'>Regular Price</option></select></td></tr>
        </table>
        <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
        <h4>Custom Tag Badges</h4><p class="description">Show specific images when a product has a certain tag.</p>
        <table class="widefat striped cc-settings-table"><thead><tr><th>Tag Slug</th><th>Badge Image</th><th>Tooltip</th><th>Width</th><th></th></tr></thead><tbody id="cc-badge-rows">';
        if(!empty($custom_badges)) {
            foreach($custom_badges as $idx => $badge) {
                echo '<tr><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tag]" value="'.esc_attr($badge['tag']).'"></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][url]" class="regular-text" value="'.esc_attr($badge['url']).'"> <button type="button" class="button cc-upload-btn">Upload</button></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tooltip]" value="'.esc_attr($badge['tooltip']).'"></td><td><input type="number" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][width]" value="'.esc_attr($badge['width']).'" style="width:60px"> px</td><td><button type="button" class="button cc-remove-row"><span class="dashicons dashicons-trash"></span></button></td></tr>';
            }
        }
        echo '</tbody></table><button type="button" class="button" id="cc-add-badge-row" style="margin-top:10px;">+ Add Badge Rule</button></div></div>';
    }

    private function render_msrp_settings() {
        $msrp = get_option( 'cirrusly_msrp_config', array() );
        $msrp_enable = isset($msrp['enable_display']) ? $msrp['enable_display'] : '';
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>MSRP Display</h3><p>Enable the strikethrough MSRP price on the frontend.</p></div>';
        echo '<div class="cc-card-body"><table class="form-table cc-settings-table"><tr><th scope="row">Enable Display</th><td><label><input type="checkbox" name="cirrusly_msrp_config[enable_display]" value="yes" '.checked('yes', $msrp_enable, false).'> Show MSRP on frontend</label></td></tr></table></div></div>';
    }

    private function render_reviews_settings() {
        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        $gcr_enable = isset($gcr['enable_reviews']) ? $gcr['enable_reviews'] : '';
        $gcr_id = isset($gcr['merchant_id']) ? $gcr['merchant_id'] : '';
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Google Customer Reviews</h3><p>Enables the survey popup. Requires Merchant Center ID.</p></div>';
        echo '<div class="cc-card-body"><table class="form-table cc-settings-table"><tr><th scope="row">Enable</th><td><input type="checkbox" name="cirrusly_google_reviews_config[enable_reviews]" value="yes" '.checked('yes', $gcr_enable, false).'></td></tr>';
        echo '<tr><th scope="row">Merchant ID</th><td><input type="text" name="cirrusly_google_reviews_config[merchant_id]" value="'.esc_attr($gcr_id).'"></td></tr></table></div></div>';
    }

    private function render_scans_settings() {
        $scan = get_option( 'cirrusly_scan_config', array() );
        $daily = isset($scan['enable_daily_scan']) ? $scan['enable_daily_scan'] : '';
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Scheduled Scans</h3><p>Run automated health checks daily.</p></div>';
        echo '<div class="cc-card-body"><table class="form-table cc-settings-table"><tr><th scope="row">Daily Scan</th><td><input type="checkbox" name="cirrusly_scan_config[enable_daily_scan]" value="yes" '.checked('yes', $daily, false).'> Enable</td></tr></table></div></div>';
    }

    private function render_shipping_settings() {
        $config = $this->get_global_config();
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $matrix_rules = json_decode( $config['matrix_rules_json'], true );
        $class_costs = json_decode( $config['class_costs_json'], true );
        if ( ! is_array( $revenue_tiers ) ) $revenue_tiers = array();

        $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
        $all_classes = array( 'default' => 'Default (No Class)' );
        if( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $all_classes[ $term->slug ] = $term->name; } }

        // 1. Revenue Tiers
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Shipping Revenue Tiers</h3><p>Define how much shipping revenue is collected based on product price.</p></div>';
        echo '<div class="cc-card-body"><table class="widefat striped cc-settings-table" style="max-width:100%;"><thead><tr><th>Min Price</th><th>Max Price</th><th>Charge</th><th></th></tr></thead><tbody id="cc-revenue-rows">';
        if( !empty($revenue_tiers) ) {
            foreach($revenue_tiers as $idx => $tier) {
                echo '<tr>
                    <td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][min]" value="'.esc_attr($tier['min']).'"></td>
                    <td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][max]" value="'.esc_attr($tier['max']).'"></td>
                    <td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][charge]" value="'.esc_attr($tier['charge']).'"></td>
                    <td><button type="button" class="button cc-remove-row"><span class="dashicons dashicons-trash"></span></button></td>
                </tr>';
            }
        }
        echo '</tbody></table><button type="button" class="button" id="cc-add-revenue-row" style="margin-top:10px;">+ Add Tier</button></div></div>';

        // 2. Class Costs
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Shipping Class Costs (Base Cost)</h3><p>Define your actual cost to ship specific classes of items.</p></div>';
        echo '<div class="cc-card-body"><table class="widefat striped cc-settings-table" style="max-width:600px;"><thead><tr><th>Shipping Class</th><th>Your Cost ($)</th></tr></thead><tbody>';
        foreach ( $all_classes as $slug => $name ) {
            $val = isset( $class_costs[$slug] ) ? $class_costs[$slug] : 0.00;
            if ( $slug === 'default' && !isset( $class_costs['default'] ) ) $val = 10.00;
            echo '<tr><td><strong>'.esc_html($name).'</strong><br><small style="color:#888;">'.esc_html($slug).'</small></td>
                <td><input type="number" step="0.01" name="cirrusly_shipping_config[class_costs]['.esc_attr($slug).']" value="'.esc_attr($val).'"></td></tr>';
        }
        echo '</tbody></table></div></div>';

        // 3. Matrix Multipliers
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Profitability Matrix Scenarios</h3><p>Define custom shipping scenarios (e.g. Rush) and their cost multiplier.</p></div>';
        echo '<div class="cc-card-body"><table class="widefat striped cc-settings-table" style="max-width:100%;"><thead><tr><th>Key</th><th>Label</th><th>Cost Multiplier</th><th></th></tr></thead><tbody id="cc-matrix-rows">';
        if($matrix_rules) {
            $idx = 0;
            foreach($matrix_rules as $k => $rule) {
                $keyVal = isset($rule['key']) ? $rule['key'] : 'rule_'.$idx;
                $labelVal = isset($rule['label']) ? $rule['label'] : '';
                $multVal = isset($rule['cost_mult']) ? $rule['cost_mult'] : 1.0;
                $idx++;
                echo '<tr>
                    <td><input type="text" name="cirrusly_shipping_config[matrix_rules]['.esc_attr($idx).'][key]" value="'.esc_attr($keyVal).'"></td>
                    <td><input type="text" name="cirrusly_shipping_config[matrix_rules]['.esc_attr($idx).'][label]" value="'.esc_attr($labelVal).'"></td>
                    <td>x <input type="number" step="0.1" name="cirrusly_shipping_config[matrix_rules]['.esc_attr($idx).'][cost_mult]" value="'.esc_attr($multVal).'"></td>
                    <td><button type="button" class="button cc-remove-row"><span class="dashicons dashicons-trash"></span></button></td>
                </tr>';
            }
        }
        echo '</tbody></table><button type="button" class="button" id="cc-add-matrix-row" style="margin-top:10px;">+ Add Scenario</button></div></div>';
        
        // JS
        echo '<script>jQuery(document).ready(function($){
            $("#cc-add-revenue-row").click(function(){
                var idx = $("#cc-revenue-rows tr").length + 1000;
                var row = "<tr><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][min]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][max]\'></td><td><input type=\'number\' step=\'0.01\' name=\'cirrusly_shipping_config[revenue_tiers]["+idx+"][charge]\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-revenue-rows").append(row);
            });
            $("#cc-add-matrix-row").click(function(){
                var idx = $("#cc-matrix-rows tr").length + 1000;
                var row = "<tr><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][key]\'></td><td><input type=\'text\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][label]\'></td><td>x <input type=\'number\' step=\'0.1\' name=\'cirrusly_shipping_config[matrix_rules]["+idx+"][cost_mult]\' value=\'1.0\'></td><td><button type=\'button\' class=\'button cc-remove-row\'><span class=\'dashicons dashicons-trash\'></span></button></td></tr>";
                $("#cc-matrix-rows").append(row);
            });
            $(document).on("click", ".cc-remove-row", function(){ $(this).closest("tr").remove(); });
        });</script>';
    }

    public function register_dashboard_widget() {
        if ( current_user_can( 'edit_products' ) ) {
            wp_add_dashboard_widget( 'cirrusly_commerce_overview', 'Cirrusly Commerce Overview', array( $this, 'render_wp_dashboard_widget' ) );
        }
    }

    public function render_wp_dashboard_widget() {
        echo '<div style="text-align:center; padding:10px;"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=cirrusly-commerce' ) ) . '">Open Dashboard</a></div>';
    }

    public function execute_scheduled_scan() {
        $scanner = new Cirrusly_Commerce_GMC();
        $results = $scanner->run_gmc_scan_logic();
        $scan_data = array( 'timestamp' => current_time( 'timestamp' ), 'results' => $results );
        update_option( 'woo_gmc_scan_data', $scan_data, false );
        
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        if ( !empty($scan_cfg['enable_email_report']) && $scan_cfg['enable_email_report'] === 'yes' ) {
            $to = !empty($scan_cfg['email_recipient']) ? $scan_cfg['email_recipient'] : get_option('admin_email');
            wp_mail( $to, 'Cirrusly Commerce: Daily Scan Report', 'Scan Completed. Issues: ' . count($results) );
        }
    }
}
}
