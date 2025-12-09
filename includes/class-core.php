<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Core {

    public function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
    }

    private function load_dependencies() {
        // Utilities (Always needed)
        require_once plugin_dir_path( __FILE__ ) . 'class-security.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-reports.php';
        if ( file_exists( plugin_dir_path( __FILE__ ) . 'class-gmc.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-gmc.php';
        }
        if ( file_exists( plugin_dir_path( __FILE__ ) . 'class-audit.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-audit.php';
        }

        // Admin-Specific Loading
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-admin-assets.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-settings-manager.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-dashboard-ui.php';
        }

        // Pro-Only Loading
        if ( self::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php';
        }
    }

    private function define_hooks() {
        if ( class_exists( 'Cirrusly_Commerce_Audit' ) ) {
            add_action('init', array('Cirrusly_Commerce_Audit', 'init'));
        }
        
        // Reports (Weekly Email)
        if ( class_exists( 'Cirrusly_Commerce_Reports' ) ) {
            Cirrusly_Commerce_Reports::init();
        }

        // 1. Initialize GMC Core (This registers the hooks ONCE)
        if ( class_exists( 'Cirrusly_Commerce_GMC' ) ) {
            $gmc = new Cirrusly_Commerce_GMC();
            $gmc->init();
        }

        // 2. Existing Admin Hooks
        if ( is_admin() ) {
            $settings = new Cirrusly_Commerce_Settings_Manager();
            $assets   = new Cirrusly_Commerce_Admin_Assets();
            $dash     = new Cirrusly_Commerce_Dashboard_UI();

            add_action( 'admin_menu', array( $settings, 'register_admin_menus' ) );
            add_action( 'admin_init', array( $settings, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );
            add_action( 'wp_dashboard_setup', array( $dash, 'register_widget' ) );
            add_action( 'admin_notices', array( $settings, 'render_onboarding_notice' ) );
        }

        add_action( 'wp_ajax_cc_audit_save', array( $this, 'handle_audit_inline_save' ) );
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'execute_scheduled_scan_router' ) );
        add_action( 'save_post_product', array( $this, 'clear_metrics_cache' ) );
        add_filter( 'pre_option_woocommerce_enable_cost_of_goods_sold', function() { return 'yes'; } );    }

    public function execute_scheduled_scan_router() {
        if ( class_exists('Cirrusly_Commerce_Google_API_Client') ) {
            Cirrusly_Commerce_Google_API_Client::execute_scheduled_scan();
        }
    }

    public function handle_audit_inline_save() {
        if ( ! current_user_can( 'edit_products' ) || ! check_ajax_referer( 'cc_audit_save', '_nonce', false ) ) {
            wp_send_json_error('Permission denied');
        }
        if ( ! self::cirrusly_is_pro() ) wp_send_json_error('Pro feature required');

        $pid = intval( $_POST['pid'] );
        $val = floatval( $_POST['value'] );
        $field = sanitize_text_field( $_POST['field'] );

        if ( $pid > 0 && in_array($field, array('_cogs_total_value', '_cw_est_shipping')) ) {
            update_post_meta( $pid, $field, $val );
            delete_transient( 'cw_audit_data' );
            wp_send_json_success();
        }
        wp_send_json_error('Invalid data');
    }

    public function clear_metrics_cache() {
        delete_transient( 'cirrusly_dashboard_metrics' );
    }

    public static function cirrusly_is_pro() {
        // 1. Secure Developer Override
        // FIX: Check if wp_get_current_user exists before calling current_user_can to prevent early load crash
        if ( defined('WP_DEBUG') && WP_DEBUG && function_exists('wp_get_current_user') && current_user_can('manage_options') ) {
            if ( isset( $_GET['cc_dev_mode'] ) ) {
                if ( $_GET['cc_dev_mode'] === 'pro' ) return true;
                if ( $_GET['cc_dev_mode'] === 'free' ) return false;
            }
        }

        // 2. Freemius Check
        if ( function_exists( 'cc_fs' ) ) {
             return cc_fs()->can_use_premium_code();
        }

        return false;
    }

    public static function cirrusly_is_pro_plus() {
        // 1. Dev Mode Override (useful for testing)
        if ( defined('WP_DEBUG') && WP_DEBUG && function_exists('wp_get_current_user') && current_user_can('manage_options') ) {
            if ( isset( $_GET['cc_dev_mode'] ) && $_GET['cc_dev_mode'] === 'pro_plus' ) return true;
        }

        // 2. Freemius Check
        if ( function_exists( 'cc_fs' ) ) {
            // Check if user is on 'proplus' plan (OR higher, if more tiers are added later).
            return cc_fs()->is_plan( 'proplus' );
        }

        return false;
    }

    public static function get_email_from_header() {
        $admin_email = get_option( 'admin_email' );
        $site_title  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        return array( 'From: ' . $site_title . ' <' . $admin_email . '>' );
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
            'payment_flat' => 0.30,
            'profile_mode' => 'single',
            'payment_pct_2' => 3.49,
            'payment_flat_2' => 0.49,
            'profile_split' => 100
        );
        return wp_parse_args( $saved, $defaults );
    }

    public static function render_global_header( $title ) {
        self::render_page_header( $title );
    }

    public static function render_page_header( $title ) {
        $is_pro = self::cirrusly_is_pro();

        echo '<h1 class="cc-page-title" style="margin-bottom:20px; display:flex; align-items:center;">';
        echo '<img src="' . esc_url( CIRRUSLY_COMMERCE_URL . 'assets/images/logo.svg' ) . '" style="height:50px; width:auto; margin-right:15px;" alt="Cirrusly Commerce">';
        echo esc_html( $title );
        echo '<div style="margin-left:auto; display:flex; align-items:center; gap:10px;">';
        
        if ( $is_pro ) {
            echo '<span class="cc-pro-version-badge">PRO</span>';
        }
        
        if ( class_exists( 'Cirrusly_Commerce_Help' ) ) {
            Cirrusly_Commerce_Help::render_button();
        }

        echo '<span class="cc-ver-badge" style="background:#f0f0f1;color:#646970;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">v' . esc_html( CIRRUSLY_COMMERCE_VERSION ) . '</span>';
        echo '</div></h1>';
        
        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        
        $nav_items = array(
            'cirrusly-commerce' => 'Dashboard',
            'cirrusly-gmc'      => 'Compliance Hub',
            'cirrusly-audit'    => 'Financial Audit',
            'cirrusly-settings' => 'Settings',
        );

        echo '<div class="cc-global-nav">';
        foreach ( $nav_items as $slug => $label ) {
            $active_class = ( $current_page === $slug ) ? 'cc-nav-active' : '';
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" class="' . esc_attr( $active_class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';
    }
}