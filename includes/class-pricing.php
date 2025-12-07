<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Pricing {

    public function __construct() {
        // 1. Load Frontend MSRP Logic
        if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-pricing-frontend.php';
            new Cirrusly_Commerce_Pricing_Frontend();
        }

        // 2. Load Admin UI (Meta Boxes, Saving, Columns)
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-pricing-ui.php';
            new Cirrusly_Commerce_Pricing_UI();
        }

        // 3. Load Pro Sync Worker (Background Process)
        // This handles the 'cirrusly_commerce_gmc_sync' cron event and error notices.
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-pricing-sync.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-pricing-sync.php';
            new Cirrusly_Commerce_Pricing_Sync();
        }
    }
}