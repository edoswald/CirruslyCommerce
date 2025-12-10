<?php
/**
 * Plugin Name: Cirrusly Commerce
 * Description: All-in-one suite: GMC Assistant, Promotion Manager, Pricing Engine, and Store Financial Audit that doesn't cost an arm and a leg.
 * Version: 1.4
 * Author: Cirrusly Weather
 * Author URI: https://cirruslyweather.com
 * Text Domain: cirrusly-commerce
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * @fs_premium_only /includes/pro/, /assets/js/pro/, /assets/css/pro/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ... [Keep existing Composer Autoloader and Constants] ...
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

define( 'CIRRUSLY_COMMERCE_VERSION', '1.4' );
define( 'CIRRUSLY_COMMERCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CIRRUSLY_COMMERCE_URL', plugin_dir_url( __FILE__ ) );


if ( ! function_exists( 'cirrusly_fs' ) ) {
    /**
     * Provide access to the plugin's initialized Freemius SDK instance.
     *
     * Initializes the Freemius SDK on first invocation and returns the shared instance.
     *
     * @return object The Freemius SDK instance used by the plugin.
     */
    function cirrusly_fs() {
        global $cirrusly_fs;

        if ( ! isset( $cirrusly_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $cirrusly_fs = fs_dynamic_init( array(
                'id'                  => '22048',
                'slug'                => 'cirrusly-commerce',
                'type'                => 'plugin',
                'public_key'          => 'pk_34dc77b4bc7764037f0e348daac4a',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // Automatically removed in the free version. If you're not using the
                // auto-generated free version, delete this line before uploading to wp.org.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => false,
                ),
                'menu'                => array(
                    'slug'           => 'cirrusly-settings',
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $cirrusly_fs;
    }

    // Init Freemius.
    cirrusly_fs();
    // Signal that SDK was initiated.
    do_action( 'cirrusly_fs_loaded' );
}

if ( ! class_exists( 'Cirrusly_Commerce_Core' ) ) {
	include_once dirname( __FILE__ ) . '/includes/class-core.php';
}

class Cirrusly_Commerce_Main {

    private static $instance = null;

    /**
     * Retrieve the singleton instance of the main plugin class.
     *
     * @return self The shared Cirrusly_Commerce_Main instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstraps the plugin: loads module files, instantiates components, and registers hooks.
     *
     * Loads standard modules from the includes directory, conditionally loads pro and admin
     * modules when available, creates instances of plugin components (including optional pro
     * modules), initializes the help subsystem, and registers activation/deactivation hooks
     * along with the cron schedule and plugin action links filters.
     */
    public function __construct() {
        $includes_path = plugin_dir_path( __FILE__ ) . 'includes/';

        // 1. Load Standard Modules
        require_once $includes_path . 'class-frontend-assets.php'; // NEW
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
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro_plus() ) {
            if ( file_exists( $includes_path . 'pro/class-automated-discounts.php' ) ) {
                require_once $includes_path . 'pro/class-automated-discounts.php';
            }
            if ( file_exists( $includes_path . 'pro/class-analytics-pro.php' ) ) {
                require_once $includes_path . 'pro/class-analytics-pro.php';
            }
        }

        // 2.5 Load Admin Setup Wizard
        if ( is_admin() ) {
            if ( file_exists( $includes_path . 'admin/class-setup-wizard.php' ) ) {
                require_once $includes_path . 'admin/class-setup-wizard.php';
            }
        }

        // 3. Initialize Modules
        new Cirrusly_Commerce_Frontend_Assets(); // NEW
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
        
        if ( class_exists( 'Cirrusly_Commerce_Automated_Discounts' ) ) {
            new Cirrusly_Commerce_Automated_Discounts();
        }
        if ( class_exists( 'Cirrusly_Commerce_Analytics_Pro' ) ) {
            new Cirrusly_Commerce_Analytics_Pro();
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
     * @param array $schedules Associative array of cron schedules keyed by schedule name.
     * @return array The schedules array, guaranteed to include a 'weekly' schedule with a 7-day interval and label "Once Weekly".
     */
    public function add_weekly_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'cirrusly-commerce' )
            );
        }
        return $schedules;
    }

    /**
     * Perform plugin activation tasks: schedule cron events, migrate legacy options, and set an activation redirect transient.
     *
     * Ensures a daily 'cirrusly_gmc_daily_scan' and a weekly 'cirrusly_weekly_profit_report' cron event are scheduled.
     * Migrates the legacy 'woocommerce_enable_cost_of_goods_sold' option into 'cirrusly_enable_cost_of_goods_sold' when present,
     * or sets 'cirrusly_enable_cost_of_goods_sold' to 'yes' when no value exists. Copies a legacy merchant ID from
     * 'cirrusly_gmc_merchant_id' into the 'merchant_id_pro' key of the 'cirrusly_scan_config' option if needed.
     * Finally, sets a short-lived 'cirrusly_activation_redirect' transient to trigger a post-activation redirect.
     */
    public function activate() {
        if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
        }
        if ( ! wp_next_scheduled( 'cirrusly_weekly_profit_report' ) ) {
            wp_schedule_event( time(), 'weekly', 'cirrusly_weekly_profit_report' );
        }
        
        $old_value = get_option( 'woocommerce_enable_cost_of_goods_sold', null );
        $new_value = get_option( 'cirrusly_enable_cost_of_goods_sold', null );

        if ( null !== $old_value && null === $new_value ) {
            update_option( 'cirrusly_enable_cost_of_goods_sold', $old_value );
            delete_option( 'woocommerce_enable_cost_of_goods_sold' );
        } elseif ( null === $new_value ) {
            update_option( 'cirrusly_enable_cost_of_goods_sold', 'yes' );
        }

        $legacy_id = get_option( 'cirrusly_gmc_merchant_id' );
        $scan_config = get_option( 'cirrusly_scan_config', array() );
        
        if ( ! empty( $legacy_id ) && empty( $scan_config['merchant_id_pro'] ) ) {
            $scan_config['merchant_id_pro'] = $legacy_id;
            update_option( 'cirrusly_scan_config', $scan_config );
        }
        set_transient( 'cirrusly_activation_redirect', true, 60 );
    }

    /**
     * Removes the plugin's scheduled background tasks.
     *
     * Clears any scheduled 'cirrusly_gmc_daily_scan' and 'cirrusly_weekly_profit_report' cron hooks so those events will no longer run.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
        wp_clear_scheduled_hook( 'cirrusly_weekly_profit_report' ); 
    }

    /**
     * Add plugin action links for the Cirrusly settings and (optionally) a Go Pro upgrade.
     *
     * Prepends a "Settings" link to the provided plugin action links and appends a prominent
     * "Go Pro" upgrade link when the Freemius SDK is available and the current user is not paying.
     *
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links including the Settings link and, if applicable, Go Pro.
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
Cirrusly_Commerce_Main::instance();