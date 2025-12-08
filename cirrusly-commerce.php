<?php
/**
 * Plugin Name: Cirrusly Commerce
 * Description: All-in-one suite: GMC Assistant, Promotion Manager, Pricing Engine, and Store Financial Audit that doesn't cost an arm and a leg.
 * Version: 1.3
 * Author: Cirrusly Weather
 * Author URI: https://cirruslyweather.com
 * Text Domain: cirrusly-commerce
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -------------------------------------------------------------------------
// COMPOSER AUTOLOADER
// -------------------------------------------------------------------------
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

// Define Constants
define( 'CIRRUSLY_COMMERCE_VERSION', '1.3' );
define( 'CIRRUSLY_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_URL', plugin_dir_url( __FILE__ ) );

// -------------------------------------------------------------------------
// FREEMIUS INTEGRATION
// -------------------------------------------------------------------------
if ( ! function_exists( 'cc_fs' ) ) {
    /**
     * Initializes and returns the Freemius SDK instance for the plugin.
     *
     * @return object The Freemius SDK instance.
     */
    function cc_fs() {
        global $cc_fs;

        if ( ! isset( $cc_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $cc_fs = fs_dynamic_init( array(
                'id'                  => '22048',
                'slug'                => 'cirrusly-commerce',
                'type'                => 'plugin',
                'public_key'          => 'pk_34dc77b4bc7764037f0e348daac4a',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'cirrusly-commerce',
                    'support'        => false,
                ),
            ) );
        }

        return $cc_fs;
    }

    // Init Freemius.
    cc_fs();

    do_action( 'cc_fs_loaded' );
}

// Include the main core class first (defines Cirrusly_Commerce_Core)
if ( ! class_exists( 'Cirrusly_Commerce_Core' ) ) {
	include_once dirname( __FILE__ ) . '/includes/class-core.php';
}

/**
 * Main instance of Cirrusly_Commerce.
 */
class Cirrusly_Commerce_Main {

    private static $instance = null;

    /**
     * Retrieve the singleton instance of the main plugin class, creating it if one does not already exist.
     *
     * @return Cirrusly_Commerce_Main The shared instance of the main plugin class.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstraps and initializes plugin modules, registers lifecycle hooks, and adds plugin filters.
     *
     * Loads core and feature module files, conditionally loads the Pro-only automated discounts module,
     * instantiates module classes, initializes the help module, registers activation and deactivation hooks,
     * and adds filters for custom cron schedules and the plugin action links.
     */
    public function __construct() {
        $includes_path = plugin_dir_path( __FILE__ ) . 'includes/';

        // 1. Load Standard Modules (Controllers)
        // These files now act as lightweight controllers that decide 
        // whether to load Admin UI or Pro Logic.
        require_once $includes_path . 'class-gmc.php';
        require_once $includes_path . 'class-pricing.php';
        require_once $includes_path . 'class-audit.php';
        require_once $includes_path . 'class-reviews.php';
        require_once $includes_path . 'class-blocks.php';
        require_once $includes_path . 'class-compatibility.php';
        require_once $includes_path . 'class-badges.php';
        require_once $includes_path . 'class-manual.php';
        require_once $includes_path . 'class-countdown.php';
        require_once $includes_path . 'class-help.php';

        // 2. Load Pro-Only Modules
        // Automated Discounts was completely moved to the Pro directory.
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            if ( file_exists( $includes_path . 'pro/class-automated-discounts.php' ) ) {
                require_once $includes_path . 'pro/class-automated-discounts.php';
            }
        }

        // 3. Initialize Modules
        new Cirrusly_Commerce_Core();
        new Cirrusly_Commerce_GMC();
        new Cirrusly_Commerce_Pricing();
        new Cirrusly_Commerce_Audit();
        new Cirrusly_Commerce_Reviews();
        new Cirrusly_Commerce_Blocks();
        new Cirrusly_Commerce_Compatibility();
        new Cirrusly_Commerce_Badges();
        new Cirrusly_Commerce_Countdown();
        new Cirrusly_Commerce_Manual();

        
        // Only init Automated Discounts if the class was loaded (i.e., user is Pro)
        if ( class_exists( 'Cirrusly_Commerce_Automated_Discounts' ) ) {
            new Cirrusly_Commerce_Automated_Discounts();
        }
        
        Cirrusly_Commerce_Help::init();

        // 4. Register Hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        
        add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    /**
     * Ensure a 'weekly' cron schedule exists in the provided schedules array.
     *
     * @param array $schedules Associative array of existing cron schedules keyed by schedule name.
     * @return array The schedules array with a 'weekly' schedule (interval 604800 seconds, label "Once Weekly") ensured.
     */
    public function add_weekly_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 604800, // 7 days
                'display'  => __( 'Once Weekly', 'cirrusly-commerce' )
            );
        }
        return $schedules;
    }

    /**
     * Perform plugin activation tasks.
     *
     * Schedules a daily GMC scan and a weekly profit report (if not already scheduled),
     * enables WooCommerce "Cost of Goods Sold", and migrates a legacy Cirrusly merchant
     * ID into the scan configuration under `merchant_id_pro` when appropriate.
     */
    public function activate() {
        if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
        }
        if ( ! wp_next_scheduled( 'cirrusly_weekly_profit_report' ) ) {
            wp_schedule_event( time(), 'weekly', 'cirrusly_weekly_profit_report' );
        }
        
        update_option( 'woocommerce_enable_cost_of_goods_sold', 'yes' );

        // Migration: Legacy Merchant ID
        $legacy_id = get_option( 'cirrusly_gmc_merchant_id' );
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        
        if ( ! empty( $legacy_id ) && empty( $scan_config['merchant_id_pro'] ) ) {
            $scan_config['merchant_id_pro'] = $legacy_id;
            update_option( 'cirrusly_scan_config', $scan_config );
        }
    }

    /**
     * Remove plugin-related scheduled cron events.
     *
     * Clears any scheduled WordPress cron jobs for the daily GMC scan and the weekly profit report.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
        wp_clear_scheduled_hook( 'cirrusly_weekly_profit_report' ); 
    }

    /**
     * Add plugin action links: a Settings link and, when applicable, a prominent Go Pro upgrade link.
     *
     * @param array $links Existing action links for the plugin.
     * @return array The modified action links with the Settings link prepended and a Go Pro link added for non-paying Freemius accounts.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-settings' ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        
        if ( function_exists('cc_fs') && cc_fs() && cc_fs()->is_not_paying() ) {
            $links['go_pro'] = '<a href="' . cc_fs()->get_upgrade_url() . '" style="color:#d63638;font-weight:bold;">Go Pro</a>';
        }
        
        return $links;
    }
}

// Boot
Cirrusly_Commerce_Main::instance();