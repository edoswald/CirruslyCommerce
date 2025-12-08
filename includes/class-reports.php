<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Reports {

    /**
     * Hooks the report generation to the scheduled action.
     */
    public static function init() {
        add_action( 'cirrusly_weekly_profit_report', array( __CLASS__, 'dispatch_weekly_report' ) );
    }

    /**
     * Dispatcher: Checks configuration and Pro status before loading the logic.
     */
    public static function dispatch_weekly_report() {
        // 1. Check if enabled
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        
        $general_email = !empty($scan_cfg['enable_email_report']) && $scan_cfg['enable_email_report'] === 'yes';
        $weekly_email  = !empty($scan_cfg['alert_weekly_report']) && $scan_cfg['alert_weekly_report'] === 'yes';

        // If neither is enabled, abort. 
        if ( ! $general_email && ! $weekly_email ) return;

        // 2. Pro Check
        if ( ! class_exists( 'Cirrusly_Commerce_Core' ) || ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
            return;
        }

        // 3. Load and Run Pro Logic
        // Following the pattern of dynamically loading Pro files only when needed
        $pro_file = plugin_dir_path( __FILE__ ) . 'pro/class-reports-pro.php';
        
        if ( file_exists( $pro_file ) ) {
            require_once $pro_file;
            Cirrusly_Commerce_Reports_Pro::generate_and_send( $scan_cfg );
        }
    }
}