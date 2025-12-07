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

        // Admin-Specific Loading (Performance Win)
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-admin-assets.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-settings-manager.php';
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-dashboard-ui.php';
        }

        // Pro-Only Loading (Freemius Best Practice)
        // Only load heavy API clients if the user is actually Pro
        if ( self::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-google-api-client.php';
        }
    }

    private function define_hooks() {
        // Init other modules
        add_action('init', array('Cirrusly_Commerce_Audit', 'init'));
        
        // Reports (Weekly Email)
        if ( class_exists( 'Cirrusly_Commerce_Reports' ) ) {
            Cirrusly_Commerce_Reports::init();
        }

        // Admin Hooks
        if ( is_admin() ) {
            $settings = new Cirrusly_Commerce_Settings_Manager();
            $assets   = new Cirrusly_Commerce_Admin_Assets();
            $dash     = new Cirrusly_Commerce_Dashboard_UI();

            add_action( 'admin_menu', array( $settings, 'register_admin_menus' ) );
            add_action( 'admin_init', array( $settings, 'register_settings' ) );
            add_action( 'admin_enqueue_scripts', array( $assets, 'enqueue' ) );
            add_action( 'wp_dashboard_setup', array( $dash, 'register_widget' ) );
            add_action( 'admin_notices', array( $settings, 'render_onboarding_notice' ) );
            
            // Delegate Main Dashboard Page rendering to the UI class
            // Note: register_admin_menus in Settings Manager should point to $dash->render_main_dashboard()
        }

        // AJAX: Audit Save
        add_action( 'wp_ajax_cc_audit_save', array( $this, 'handle_audit_inline_save' ) );

        // Scheduled Scan (Delegated to Pro Class if exists, or basic handler)
        add_action( 'cirrusly_gmc_daily_scan', array( $this, 'execute_scheduled_scan_router' ) );

        // Runtime Logic
        add_action( 'save_post_product', array( $this, 'clear_metrics_cache' ) );
        add_filter( 'pre_option_woocommerce_enable_cost_of_goods_sold', '__return_yes' );
    }

    /**
     * Router to handle scans. If Pro class exists, use it.
     */
    public function execute_scheduled_scan_router() {
        if ( class_exists('Cirrusly_Commerce_Google_API_Client') ) {
            Cirrusly_Commerce_Google_API_Client::execute_scheduled_scan();
        }
    }

    public function handle_audit_inline_save() {
        // (Keep the existing AJAX logic here, or move to Audit class)
        // ... existing code ...
    }

    public function clear_metrics_cache() {
        delete_transient( 'cirrusly_dashboard_metrics' );
    }

    public static function cirrusly_is_pro() {
        // (Keep existing Freemius check logic)
        if ( defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options') && isset( $_GET['cc_dev_mode'] ) ) {
             return $_GET['cc_dev_mode'] === 'pro';
        }
        if ( function_exists( 'cc_fs' ) ) {
             return cc_fs()->can_use_premium_code();
        }
        return false;
    }
}
?>