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
        
        // Audit Inline Save (AJAX)
        add_action( 'wp_ajax_cc_audit_save', array( $this, 'handle_audit_inline_save' ) );
        
        // Hide Upsells CSS Hook
        add_action( 'admin_head', array( $this, 'cirrusly_hide_upsells_css' ) );

        // Scheduled Scan Hook
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'execute_scheduled_scan' ) );
        
        add_action( 'save_post_product', array( $this, 'clear_metrics_cache' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'clear_metrics_cache' ) );
        
        // Force Enable Native COGS (Runtime)
        add_filter( 'pre_option_woocommerce_enable_cost_of_goods_sold', array( $this, 'force_enable_cogs' ) );

        // Onboarding Notice
        add_action( 'admin_notices', array( $this, 'render_onboarding_notice' ) );
        
        // Init Audit Class Logic
        add_action('init', array('Cirrusly_Commerce_Audit', 'init'));
    }

    public function handle_audit_inline_save() {
        // Security & Permission Check
        if ( ! current_user_can( 'edit_products' ) || ! check_ajax_referer( 'cc_audit_save', '_nonce', false ) ) {
            wp_send_json_error('Permission denied');
        }
        
        // PRO Check
        if ( ! self::cirrusly_is_pro() ) {
            wp_send_json_error('Pro feature required');
        }

        $pid = intval( $_POST['pid'] );
        $val = floatval( $_POST['value'] );
        $field = sanitize_text_field( $_POST['field'] );

        if ( $pid > 0 && in_array($field, array('_cogs_total_value', '_cw_est_shipping')) ) {
            update_post_meta( $pid, $field, $val );
            delete_transient( 'cw_audit_data' ); // Clear cache
            wp_send_json_success();
        }
        
        wp_send_json_error('Invalid data');
    }

    /**
     * Check if PRO features are active.
     * Relies strictly on Freemius license validation.
     */
    public static function cirrusly_is_pro() {
        // 1. Secure Developer Override
        if ( defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options') ) {
            if ( isset( $_GET['cc_dev_mode'] ) ) {
                if ( $_GET['cc_dev_mode'] === 'pro' ) return true;
                if ( $_GET['cc_dev_mode'] === 'free' ) return false;
            }
        }

        // 2. Freemius Check
        if ( function_exists( 'cc_fs' ) ) {
             $fs = cc_fs();
             if ( $fs && $fs->can_use_premium_code() ) {
                 return true;
             }
        }

        return false; 
    }

    public function cirrusly_hide_upsells_css() {
        // Only run on plugin pages
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'cirrusly-' ) === false ) return;

        $general = get_option( 'cirrusly_scan_config', array() ); // We store the toggle here for simplicity
        if ( ! empty( $general['hide_upsells'] ) && $general['hide_upsells'] === 'yes' ) {
            echo '<style>.cc-pro-feature { display: none !important; }</style>';
        }
    }

    public function force_enable_cogs() { return 'yes'; }
    public function clear_metrics_cache() { delete_transient( 'cirrusly_dashboard_metrics' ); }

    public function enqueue_assets( $hook ) {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $is_plugin_page = strpos( $page, 'cirrusly-' ) !== false;
        $is_product_page = 'post.php' === $hook || 'post-new.php' === $hook;

        if ( $is_plugin_page || $is_product_page ) {
            wp_enqueue_media(); 
            wp_enqueue_style( 'cirrusly-admin-css', CIRRUSLY_COMMERCE_URL . 'assets/css/admin.css', array(), CIRRUSLY_COMMERCE_VERSION );
            
            // Load Audit JS only on audit page
            if ( $page === 'cirrusly-audit' ) {
                wp_enqueue_script( 'cirrusly-audit-js', CIRRUSLY_COMMERCE_URL . 'assets/js/audit.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
                wp_localize_script( 'cirrusly-audit-js', 'cc_audit_vars', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'cc_audit_save' )
                ));
            }

            if ( $is_product_page ) {
                wp_enqueue_script( 'cirrusly-pricing-js', CIRRUSLY_COMMERCE_URL . 'assets/js/pricing.js', array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
                
                $config = $this->get_global_config();
                $js_config = array(
                    'revenue_tiers' => json_decode( $config['revenue_tiers_json'] ),
                    'matrix_rules'  => json_decode( $config['matrix_rules_json'] ),
                    'classes'       => array(),
                    'payment_pct'   => isset($config['payment_pct']) ? (float)$config['payment_pct'] : 2.9,
                    'payment_flat'  => isset($config['payment_flat']) ? (float)$config['payment_flat'] : 0.30
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
                
                // System Info Toggle
                $("#cc-sys-info-toggle").click(function(e){
                    e.preventDefault();
                    $("#cc-sys-info-panel").toggle();
                });
            });' );
        }
    }

    public static function render_page_header( $title ) {
        $mailto = 'mailto:help@cirruslyweather.com?subject=Support%20Request';
        $is_pro = self::cirrusly_is_pro(); // Check PRO status

        echo '<h1 class="cc-page-title" style="margin-bottom:20px; display:flex; align-items:center;">';
        echo '<img src="' . esc_url( CIRRUSLY_COMMERCE_URL . 'assets/images/logo.svg' ) . '" style="height:50px; width:auto; margin-right:15px;" alt="Cirrusly Commerce">';
        echo esc_html( $title );
        echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';
        
        // Pro Badge Logic
        if ( $is_pro ) {
            echo '<span class="cc-pro-version-badge">PRO</span>';
        }
        
        echo '<a href="#" id="cc-sys-info-toggle" class="button button-secondary" title="View System Info for Support">System Info</a>';
        echo '<a href="' . esc_attr( $mailto ) . '" class="button button-secondary">Get Support</a>'; 
        echo '<span class="cc-ver-badge" style="background:#f0f0f1;color:#646970;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">v' . esc_html( CIRRUSLY_COMMERCE_VERSION ) . '</span>';
        echo '</div></h1>';
        
        // Hidden System Info Panel
        echo '<div id="cc-sys-info-panel" style="display:none; background:#fff; border:1px solid #c3c4c7; padding:15px; margin-bottom:20px;">';
        echo '<h4>System Information <button type="button" class="button button-small" onclick="var copyText = document.getElementById(\'cc-sys-info-text\');copyText.select();document.execCommand(\'copy\');alert(\'Copied to clipboard!\');">Copy</button></h4>';
        echo '<textarea id="cc-sys-info-text" style="width:100%; height:150px; font-family:monospace; font-size:11px;" readonly>';
        self::render_system_info();
        echo '</textarea></div>';
        
        echo '<div class="cc-global-nav">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-commerce' ) ) . '">Dashboard</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-gmc' ) ) . '">GMC Hub</a>';
        // Updated Link Text
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-audit' ) ) . '">Financial Audit</a>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-settings' ) ) . '">Settings</a>';
        echo '</div>';
    }

    public static function render_system_info() {
        global $wp_version;
        echo "### System Info ###\n";
        echo "Site URL: " . site_url() . "\n";
        echo "WP Version: " . $wp_version . "\n";
        echo "WooCommerce: " . (class_exists('WooCommerce') ? WC()->version : 'Not Installed') . "\n";
        echo "Cirrusly Commerce: " . CIRRUSLY_COMMERCE_VERSION . "\n";
        echo "PHP Version: " . phpversion() . "\n";
        echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
        echo "Active Plugins:\n";
        $plugins = get_option('active_plugins');
        foreach($plugins as $p) { echo "- " . $p . "\n"; }
    }

    public static function render_global_header( $title ) {
        self::render_page_header( $title );
    }

    public function register_admin_menus() {
        add_menu_page( 'Cirrusly Commerce', 'Cirrusly Commerce', 'edit_products', 'cirrusly-commerce', array( $this, 'render_main_dashboard' ), 'dashicons-analytics', 56 );
        add_submenu_page( 'cirrusly-commerce', 'Dashboard', 'Dashboard', 'edit_products', 'cirrusly-commerce', array( $this, 'render_main_dashboard' ) );
        add_submenu_page( 'cirrusly-commerce', 'GMC Hub', 'GMC Hub', 'edit_products', 'cirrusly-gmc', array( 'Cirrusly_Commerce_GMC', 'render_page' ) );
        // Updated Menu Item Title
        add_submenu_page( 'cirrusly-commerce', 'Financial Audit', 'Financial Audit', 'edit_products', 'cirrusly-audit', array( 'Cirrusly_Commerce_Audit', 'render_page' ) );
        add_submenu_page( 'cirrusly-commerce', 'Settings', 'Settings', 'manage_options', 'cirrusly-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'cirrusly-commerce', 'User Manual', 'User Manual', 'edit_products', 'cirrusly-manual', array( 'Cirrusly_Commerce_Manual', 'render_page' ) );
    }

    public function register_settings() {
        // Consolidated Group: General
        register_setting( 'cirrusly_general_group', 'cirrusly_scan_config', array( 'sanitize_callback' => array( $this, 'handle_scan_schedule' ) ) );
        register_setting( 'cirrusly_general_group', 'cirrusly_msrp_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );
        register_setting( 'cirrusly_general_group', 'cirrusly_google_reviews_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );

        // Group: Profit Engine (Shipping)
        register_setting( 'cirrusly_shipping_group', 'cirrusly_shipping_config', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );

        // Group: Badges
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
        
        // Handle File Upload (Service Account JSON)
        if ( ! empty( $_FILES['cirrusly_service_account']['tmp_name'] ) ) {
            $file = $_FILES['cirrusly_service_account'];

            // 1. Enforce Max File Size (64KB Limit)
            if ( $file['size'] > 65536 ) { // 64 * 1024
                 add_settings_error( 'cirrusly_scan_config', 'size_error', 'File is too large. Max allowed size is 64KB.' );
                 return $this->sanitize_options_array( $input );
            }

            // 2. Verify MIME Type (Robust Check)
            $is_valid_mime = false;
            
            // Primary check using finfo
            if ( function_exists( 'finfo_open' ) ) {
                $finfo = finfo_open( FILEINFO_MIME_TYPE );
                $mime = finfo_file( $finfo, $file['tmp_name'] );
                finfo_close( $finfo );
                
                // Allow standard JSON mime types
                if ( in_array( $mime, array( 'application/json', 'text/plain', 'text/json' ), true ) ) {
                    $is_valid_mime = true;
                }
            } 
            // Fallback to WordPress check
            else {
                $wp_check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'json' => 'application/json' ) );
                if ( $wp_check['ext'] === 'json' ) {
                    $is_valid_mime = true;
                }
            }
            
            // Enforce extension match (case-insensitive)
            $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
            if ( $ext !== 'json' ) {
               $is_valid_mime = false;
            }

            if ( ! $is_valid_mime ) {
                add_settings_error( 'cirrusly_scan_config', 'mime_error', 'Invalid file type. Please upload a valid JSON file.' );
                return $this->sanitize_options_array( $input );
            }

            // 3. Safely Read & Validate JSON Structure
            $json_content = file_get_contents( $file['tmp_name'] );
            $data = json_decode( $json_content, true );

            // Check valid JSON syntax
            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
                add_settings_error( 'cirrusly_scan_config', 'json_error', 'File does not contain valid JSON content.' );
                return $this->sanitize_options_array( $input );
            }

            // Check for required Service Account keys
            $required_keys = array( 'type', 'project_id', 'private_key_id', 'private_key', 'client_email' );
            $missing_keys = array();
            
            foreach ( $required_keys as $key ) {
                if ( ! isset( $data[ $key ] ) ) {
                    $missing_keys[] = $key;
                }
            }

            if ( ! empty( $missing_keys ) ) {
                add_settings_error( 'cirrusly_scan_config', 'keys_missing', 'Invalid Service Account JSON. Missing required keys: ' . implode( ', ', $missing_keys ) );
                return $this->sanitize_options_array( $input );
            }

            // 4. Sanitize & Store (Only on Success)
            update_option( 'cirrusly_service_account_json', $json_content, false ); // Securely store raw content without autoload
            
            $input['service_account_uploaded'] = 'yes';
            $input['service_account_name'] = sanitize_file_name( $file['name'] );

            add_settings_error( 'cirrusly_scan_config', 'upload_success', 'Service Account JSON uploaded and verified successfully.', 'updated' );
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
        
        // Sanitize new payment fields
        if ( isset( $input['payment_pct'] ) ) $input['payment_pct'] = floatval( $input['payment_pct'] );
        if ( isset( $input['payment_flat'] ) ) $input['payment_flat'] = floatval( $input['payment_flat'] );

        // Sanitize Smart Badge Checkboxes
        if ( isset( $input['smart_inventory'] ) ) $input['smart_inventory'] = 'yes';
        if ( isset( $input['smart_performance'] ) ) $input['smart_performance'] = 'yes';
        if ( isset( $input['smart_scheduler'] ) ) $input['smart_scheduler'] = 'yes';

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
            'class_costs_json' => json_encode(array('default' => 10.00)),
            'payment_pct' => 2.9,
            'payment_flat' => 0.30
        );
        return wp_parse_args( $saved, $defaults );
    }

    public function render_onboarding_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        
        // Check if config exists
        $config = get_option( 'cirrusly_shipping_config' );
        if ( ! $config || empty( $config['revenue_tiers_json'] ) ) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <strong>Welcome to Cirrusly Commerce!</strong><br>
                    To get accurate profit calculations, please set up your 
                    <a href="<?php echo esc_url( admin_url('admin.php?page=cirrusly-settings&tab=shipping') ); ?>">Shipping Revenue Tiers</a> 
                    and Payment Fees.
                </p>
            </div>
            <?php
        }
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
            $count_vars  = wp_count_posts('product_variation');
            $total_products = $count_posts->publish + $count_vars->publish;

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
        echo '<div class="wrap">'; // Move wrapper outside/above header for consistency
        self::render_page_header( 'Cirrusly Commerce Dashboard' );
        $m = self::get_dashboard_metrics();
        $is_pro = self::cirrusly_is_pro();
        ?>
        <div class="cc-intro-text" style="background:#fff; padding:15px; border:1px solid #ccd0d4; margin-bottom:20px; border-left:4px solid #2271b1;">
            <h3><?php esc_html_e( 'Welcome to Cirrusly Commerce', 'cirrusly-commerce' ); ?></h3>
            <p><?php esc_html_e( 'Your comprehensive suite for optimizing your WooCommerce store\'s financial health and Google Merchant Center compliance.', 'cirrusly-commerce' ); ?></p>
        </div>
        
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
                <div class="cc-stat-row" style="margin-top:15px; padding-top:10px; border-top:1px solid #f0f0f1;">
                    <span>Real-Time Sync</span>
                    <?php if($is_pro): ?>
                        <span class="gmc-badge" style="background:#008a20;color:#fff;">ACTIVE</span>
                    <?php else: ?>
                        <span class="gmc-badge" style="background:#ccc;color:#666;">INACTIVE (PRO)</span>
                    <?php endif; ?>
                </div>
                <div class="cc-actions"><a href="admin.php?page=cirrusly-gmc&tab=scan" class="button button-primary">Fix Issues</a></div>
            </div>

            <div class="cc-dash-card" style="border-top-color: #2271b1;">
                <div class="cc-card-head"><span>Store Integrity</span> <span class="dashicons dashicons-analytics"></span></div>
                <div class="cc-stat-row"><span>Products Missing Cost</span><span class="cc-stat-val <?php echo $m['missing_cost'] > 0 ? 'cc-val-bad' : 'cc-val-good'; ?>"><?php echo esc_html( $m['missing_cost'] ); ?></span></div>
                <div class="cc-stat-row">
                    <span>Automated Badging</span>
                    <?php if($is_pro): ?>
                         <span class="gmc-badge" style="background:#008a20;color:#fff;">ACTIVE</span>
                    <?php else: ?>
                         <span class="gmc-badge" style="background:#ccc;color:#666;">BASIC</span>
                    <?php endif; ?>
                </div>
                <div class="cc-actions"><a href="admin.php?page=cirrusly-audit" class="button button-secondary">Open Audit</a></div>
            </div>
            
            <div class="cc-dash-card" style="border-top-color: #00a32a;">
                <div class="cc-card-head"><span>Quick Links</span> <span class="dashicons dashicons-admin-links"></span></div>
                <div class="cc-stat-row"><a href="admin.php?page=cirrusly-gmc&tab=promotions">Promotions Manager</a></div>
                <div class="cc-stat-row"><a href="admin.php?page=cirrusly-settings">Plugin Settings</a></div>
                <div class="cc-stat-row"><a href="admin.php?page=cirrusly-manual">User Manual</a></div>
            </div>
        </div>
        </div><?php
    }

    public function render_settings_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        echo '<div class="wrap">';
        self::render_page_header( 'Settings' );
        
        echo '<nav class="nav-tab-wrapper">
                <a href="?page=cirrusly-settings&tab=general" class="nav-tab '.($tab=='general'?'nav-tab-active':'').'">General Settings</a>
                <a href="?page=cirrusly-settings&tab=shipping" class="nav-tab '.($tab=='shipping'?'nav-tab-active':'').'">Profit Engine</a>
                <a href="?page=cirrusly-settings&tab=badges" class="nav-tab '.($tab=='badges'?'nav-tab-active':'').'">Badge Manager</a>
              </nav>';
        
        echo '<br><form method="post" action="options.php" enctype="multipart/form-data">';
        
        if($tab==='badges'){ 
            settings_fields('cirrusly_badge_group'); 
            $this->render_badges_settings(); 
        } elseif($tab==='shipping') { 
            settings_fields('cirrusly_shipping_group'); 
            $this->render_profit_engine_settings(); 
        } else { 
            settings_fields('cirrusly_general_group'); 
            $this->render_general_settings(); 
        }
        
        submit_button(); 
        echo '</form></div>';
    }

    private function render_general_settings() {
        // Retrieve values
        $msrp = get_option( 'cirrusly_msrp_config', array() );
        $msrp_enable = isset($msrp['enable_display']) ? $msrp['enable_display'] : '';

        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        $gcr_enable = isset($gcr['enable_reviews']) ? $gcr['enable_reviews'] : '';
        $gcr_id = isset($gcr['merchant_id']) ? $gcr['merchant_id'] : '';

        $scan = get_option( 'cirrusly_scan_config', array() );
        $daily = isset($scan['enable_daily_scan']) ? $scan['enable_daily_scan'] : '';
        $hide_upsells = isset($scan['hide_upsells']) ? $scan['hide_upsells'] : '';
        
        // Pro field values
        $merchant_id_pro = isset($scan['merchant_id_pro']) ? $scan['merchant_id_pro'] : '';
        $alert_reports = isset($scan['alert_weekly_report']) ? $scan['alert_weekly_report'] : '';
        $alert_disapproval = isset($scan['alert_gmc_disapproval']) ? $scan['alert_gmc_disapproval'] : '';
        $uploaded_file = isset($scan['service_account_name']) ? $scan['service_account_name'] : '';

        $is_pro = self::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        echo '<div class="cc-settings-grid">';
        
        // Card: Integrations (Reviews)
        echo '<div class="cc-settings-card">
            <div class="cc-card-header">
                <h3>Integrations</h3>
                <span class="dashicons dashicons-google"></span>
            </div>
            <div class="cc-card-body">
                <table class="form-table cc-settings-table">
                    <tr>
                        <th scope="row">Google Customer Reviews</th>
                        <td><label><input type="checkbox" name="cirrusly_google_reviews_config[enable_reviews]" value="yes" '.checked('yes', $gcr_enable, false).'> Enable Survey on Thank You Page</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Merchant ID</th>
                        <td><input type="text" name="cirrusly_google_reviews_config[merchant_id]" value="'.esc_attr($gcr_id).'" placeholder="123456789"></td>
                    </tr>
                </table>
            </div>
        </div>';

        // Card: Automation
        echo '<div class="cc-settings-card">
            <div class="cc-card-header">
                <h3>Automation</h3>
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="cc-card-body">
                <p>Scheduled tasks run in the background to ensure your store data remains healthy.</p>
                <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                <label><input type="checkbox" name="cirrusly_scan_config[enable_daily_scan]" value="yes" '.checked('yes', $daily, false).'> <strong>Run Daily Health Scan</strong></label>
                <p class="description">Automatically checks for missing GTINs and prohibited terms every 24 hours.</p>
                
                <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
                <label><input type="checkbox" name="cirrusly_scan_config[hide_upsells]" value="yes" '.checked('yes', $hide_upsells, false).'> Hide Pro Features</label>
                <p class="description">Enable this to hide grayed-out Pro features from the interface.</p>
            </div>
        </div>';
        
        // Card: Frontend Display (MSRP)
        echo '<div class="cc-settings-card">
            <div class="cc-card-header">
                <h3>Frontend Display</h3>
                <span class="dashicons dashicons-store"></span>
            </div>
            <div class="cc-card-body">
                <table class="form-table cc-settings-table">
                    <tr>
                        <th scope="row">MSRP Price</th>
                        <td><label><input type="checkbox" name="cirrusly_msrp_config[enable_display]" value="yes" '.checked('yes', $msrp_enable, false).'> Show Strikethrough MSRP on Product Pages</label></td>
                    </tr>
                </table>
                <p class="description">For custom placement, use the <strong>MSRP Display</strong> block in the Gutenberg editor.</p>
            </div>
        </div>';

        // PRO: API Connection
        echo '<div class="cc-settings-card '.esc_attr($pro_class).'">';
        if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn"><span class="dashicons dashicons-lock cc-lock-icon"></span> Upgrade to Connect API</a></div>';
        echo '<div class="cc-card-header">
                <h3>Content API Connection <span class="cc-pro-badge">PRO</span></h3>
                <span class="dashicons dashicons-cloud"></span>
            </div>
            <div class="cc-card-body">
                <p>Connect directly to Google Merchant Center for real-time price & stock syncing.</p>
                <table class="form-table cc-settings-table">
                    <tr><th>Service Account JSON</th><td>
                    <input type="file" name="cirrusly_service_account" '.esc_attr($disabled_attr).'>
                    '.($uploaded_file ? '<br><small>Uploaded: '.esc_html($uploaded_file).'</small>' : '').'
                    </td></tr>
                    <tr><th>Merchant ID</th><td><input type="text" name="cirrusly_scan_config[merchant_id_pro]" value="'.esc_attr($merchant_id_pro).'" '.esc_attr($disabled_attr).' placeholder="Locked"></td></tr>
                </table>
            </div>
        </div>';

        // PRO: Advanced Alerting
        echo '<div class="cc-settings-card '.esc_attr($pro_class).'">';
        if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn"><span class="dashicons dashicons-lock cc-lock-icon"></span> Unlock Alerts</a></div>';
        echo '<div class="cc-card-header">
                <h3>Advanced Alerts <span class="cc-pro-badge">PRO</span></h3>
                <span class="dashicons dashicons-email-alt"></span>
            </div>
            <div class="cc-card-body">
                <label><input type="checkbox" name="cirrusly_scan_config[alert_weekly_report]" value="yes" '.checked('yes', $alert_reports, false).' '.esc_attr($disabled_attr).'> Email me weekly Profit Reports</label><br>
                <label><input type="checkbox" name="cirrusly_scan_config[alert_gmc_disapproval]" value="yes" '.checked('yes', $alert_disapproval, false).' '.esc_attr($disabled_attr).'> Email me instantly on GMC Disapproval</label>
            </div>
        </div>';
        
        echo '</div>'; // End Grid
    }

    private function render_badges_settings() {
        $cfg = get_option( 'cirrusly_badge_config', array() );
        $enabled = isset($cfg['enable_badges']) ? $cfg['enable_badges'] : '';
        $size = isset($cfg['badge_size']) ? $cfg['badge_size'] : 'medium';
        $calc_from = isset($cfg['calc_from']) ? $cfg['calc_from'] : 'msrp';
        $new_days = isset($cfg['new_days']) ? $cfg['new_days'] : 30;
        
        // Smart Badges
        $smart_inv = isset($cfg['smart_inventory']) ? $cfg['smart_inventory'] : '';
        $smart_perf = isset($cfg['smart_performance']) ? $cfg['smart_performance'] : '';
        $smart_sched = isset($cfg['smart_scheduler']) ? $cfg['smart_scheduler'] : '';
        $sched_start = isset($cfg['scheduler_start']) ? $cfg['scheduler_start'] : '';
        $sched_end = isset($cfg['scheduler_end']) ? $cfg['scheduler_end'] : '';
        
        $custom_badges = isset($cfg['custom_badges_json']) ? json_decode($cfg['custom_badges_json'], true) : array();
        if(!is_array($custom_badges)) $custom_badges = array();

        $is_pro = self::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Badge Manager</h3><p>Automatically replace default WooCommerce sale badges.</p></div>';
        echo '<div class="cc-card-body"><table class="form-table cc-settings-table">
            <tr><th scope="row">Enable Module</th><td><label><input type="checkbox" name="cirrusly_badge_config[enable_badges]" value="yes" '.checked('yes', $enabled, false).'> Activate</label></td></tr>
            <tr><th scope="row">Badge Size</th><td><select name="cirrusly_badge_config[badge_size]"><option value="small" '.selected('small', $size, false).'>Small</option><option value="medium" '.selected('medium', $size, false).'>Medium</option><option value="large" '.selected('large', $size, false).'>Large</option></select></td></tr>
            <tr><th scope="row">Discount Base</th><td><select name="cirrusly_badge_config[calc_from]"><option value="msrp" '.selected('msrp', $calc_from, false).'>MSRP</option><option value="regular" '.selected('regular', $calc_from, false).'>Regular Price</option></select></td></tr>
            <tr><th scope="row">"New" Badge</th><td><input type="number" name="cirrusly_badge_config[new_days]" value="'.esc_attr($new_days).'" style="width:70px;"> days <span class="description">Products created within this many days get a "NEW" badge.</span></td></tr>
        </table>';

    // UPDATED: Smart Badges Section
    echo '<div class="'.esc_attr($pro_class).'" style="margin-top:20px; border:1px dashed #ccc; padding:15px;">';
    if(!$is_pro) echo '<div class="cc-pro-overlay" style="background:rgba(255,255,255,0.8);"><a href="#upgrade-to-pro" class="cc-upgrade-btn">Unlock Smart Badges</a></div>';
    
    echo '<h4>Smart Dynamic Badges <span class="cc-pro-badge">PRO</span></h4>
        <label><input type="checkbox" name="cirrusly_badge_config[smart_inventory]" value="yes" '.checked('yes', $smart_inv, false).' '.esc_attr($disabled_attr).'> <strong>Inventory:</strong> Show "Low Stock" badge when qty < 5</label><br>
        <label><input type="checkbox" name="cirrusly_badge_config[smart_performance]" value="yes" '.checked('yes', $smart_perf, false).' '.esc_attr($disabled_attr).'> <strong>Performance:</strong> Show "Best Seller" for top selling products</label><br>
        
        <div style="margin-top:10px;">
            <label><input type="checkbox" name="cirrusly_badge_config[smart_scheduler]" value="yes" '.checked('yes', $smart_sched, false).' '.esc_attr($disabled_attr).'> <strong>Scheduler:</strong> Show "Event" badge between dates:</label><br>
            <input type="date" name="cirrusly_badge_config[scheduler_start]" value="'.esc_attr($sched_start).'" '.esc_attr($disabled_attr).'> to 
            <input type="date" name="cirrusly_badge_config[scheduler_end]" value="'.esc_attr($sched_end).'" '.esc_attr($disabled_attr).'>
            <p class="description">Use this for store-wide events like "Black Friday".</p>
        </div>
    </div>';

        echo '<hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
        <h4>Custom Tag Badges</h4><p class="description">Show specific images when a product has a certain tag.</p>
        <table class="widefat striped cc-settings-table"><thead><tr><th>Tag Slug</th><th>Badge Image</th><th>Tooltip</th><th>Width</th><th></th></tr></thead><tbody id="cc-badge-rows">';
        if(!empty($custom_badges)) {
            foreach($custom_badges as $idx => $badge) {
                echo '<tr><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tag]" value="'.esc_attr($badge['tag']).'"></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][url]" class="regular-text" value="'.esc_attr($badge['url']).'"> <button type="button" class="button cc-upload-btn">Upload</button></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tooltip]" value="'.esc_attr($badge['tooltip']).'"></td><td><input type="number" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][width]" value="'.esc_attr($badge['width']).'" style="width:60px"> px</td><td><button type="button" class="button cc-remove-row"><span class="dashicons dashicons-trash"></span></button></td></tr>';
            }
        }
        echo '</tbody></table><button type="button" class="button" id="cc-add-badge-row" style="margin-top:10px;">+ Add Badge Rule</button></div></div>';
    }

    private function render_profit_engine_settings() {
        $config = $this->get_global_config();
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $matrix_rules = json_decode( $config['matrix_rules_json'], true );
        $class_costs = json_decode( $config['class_costs_json'], true );
        
        $payment_pct = isset($config['payment_pct']) ? $config['payment_pct'] : 2.9;
        $payment_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;

        if ( ! is_array( $revenue_tiers ) ) $revenue_tiers = array();

        $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
        $all_classes = array( 'default' => 'Default (No Class)' );
        if( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $all_classes[ $term->slug ] = $term->name; } }

        $is_pro = self::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        echo '<div class="cc-manual-helper"><h4>Profit Engine Configuration</h4><p>These settings drive the real-time margin calculations on your product edit pages. Accurate data here ensures you don\'t lose money on shipping.</p></div>';

        // Payment Processor Settings
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>Payment Processor Fees</h3><p>Accurate fee calculation.</p></div>
        <div class="cc-card-body">
            <table class="form-table cc-settings-table">
                <tr>
                    <th scope="row">Percent (%)</th>
                    <td><input type="number" step="0.1" name="cirrusly_shipping_config[payment_pct]" value="'.esc_attr($payment_pct).'"> % (e.g. Stripe is 2.9)</td>
                </tr>
                <tr>
                    <th scope="row">Flat Fee ($)</th>
                    <td><input type="number" step="0.01" name="cirrusly_shipping_config[payment_flat]" value="'.esc_attr($payment_flat).'"> $ (e.g. Stripe is 0.30)</td>
                </tr>
            </table>
            
            <div class="'.esc_attr($pro_class).'" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:15px;">
                <p><strong>Advanced Profiles <span class="cc-pro-badge">PRO</span></strong></p>
                <label><input type="radio" disabled checked> Single Profile</label><br>
                <label><input type="radio" '.esc_attr($disabled_attr).'> Multiple Gateways (Stripe + PayPal Mix)</label>
            </div>
        </div></div>';

        // 1. Revenue Tiers
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>1. Shipping Revenue</h3><p>How much do you charge the customer for shipping?</p></div>';
        echo '<div class="cc-card-body">
        <p class="description">Define tiers based on product price. (e.g., Items $0-$10 charge $3.99 shipping).</p>
        <table class="widefat striped cc-settings-table" style="max-width:100%;"><thead><tr><th>Min Price ($)</th><th>Max Price ($)</th><th>Charge Amount ($)</th><th></th></tr></thead><tbody id="cc-revenue-rows">';
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
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>2. Internal Shipping Cost</h3><p>How much does it cost YOU to ship this item?</p></div>';
        echo '<div class="cc-card-body">
        <p class="description">Set a base cost for each shipping class. This is deducted from your revenue to calculate margin.</p>
        <table class="widefat striped cc-settings-table" style="max-width:600px;"><thead><tr><th>Shipping Class</th><th>Your Cost ($)</th></tr></thead><tbody>';
        foreach ( $all_classes as $slug => $name ) {
            $val = isset( $class_costs[$slug] ) ? $class_costs[$slug] : 0.00;
            if ( $slug === 'default' && !isset( $class_costs['default'] ) ) $val = 10.00;
            echo '<tr><td><strong>'.esc_html($name).'</strong><br><small style="color:#888;">'.esc_html($slug).'</small></td>
                <td><input type="number" step="0.01" name="cirrusly_shipping_config[class_costs]['.esc_attr($slug).']" value="'.esc_attr($val).'"></td></tr>';
        }
        echo '</tbody></table></div></div>';

        // 3. Matrix Multipliers
        echo '<div class="cc-settings-card"><div class="cc-card-header"><h3>3. Scenario Matrix</h3><p>Model expensive shipping scenarios.</p></div>';
        echo '<div class="cc-card-body">
        <p class="description">Define scenarios (like Overnight Shipping) and their cost multiplier to see if you stay profitable.</p>
        <table class="widefat striped cc-settings-table" style="max-width:100%;"><thead><tr><th>Key</th><th>Label</th><th>Cost Multiplier</th><th></th></tr></thead><tbody id="cc-matrix-rows">';
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
