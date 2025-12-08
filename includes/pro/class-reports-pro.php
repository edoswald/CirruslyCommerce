<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Reports_Pro {

    /**
     * Orchestrates the report generation workflow.
     *
     * @param array $config The scan configuration array.
     */
    public static function generate_and_send( $config ) {
        // 1. Gather Data
        $report_data = self::get_weekly_data();
        if ( empty( $report_data['orders'] ) ) {
            return; // No data to report
        }

        // 2. Render HTML (View Separation)
        // We pass data to the view via a local variable
        ob_start();
        $data = $report_data; // Exposed to view as $data
        include plugin_dir_path( __FILE__ ) . 'views/html-email-report.php';
        $message = ob_get_clean();

        // 3. Send using Mailer Utility
        $to = !empty( $config['email_recipient'] ) ? $config['email_recipient'] : get_option('admin_email');
        $subject = 'Weekly Profit Report: ' . wc_price( $report_data['totals']['net_profit'] ) . ' Net';

        // Ensure Mailer is loaded (if not autoloaded)
        if ( ! class_exists( 'Cirrusly_Commerce_Mailer' ) ) {
            require_once dirname( plugin_dir_path( __FILE__ ) ) . '/class-mailer.php';
        }

        Cirrusly_Commerce_Mailer::send_html( $to, $subject, $message );
    }

    /**
     * Calculates financial data for the last 7 days.
     * Refactored from the original monolithic function.
     */
    private static function get_weekly_data() {
        $date_query = array(
            'after'     => wp_date('Y-m-d', strtotime('-7 days')),
            'before'    => wp_date('Y-m-d', current_time('timestamp')),
            'inclusive' => true,
        );
        
        $orders = wc_get_orders( array(
            'limit'        => -1,
            'status'       => array( 'wc-completed', 'wc-processing' ),
            'date_created' => $date_query,
        ) );

        if ( empty( $orders ) ) return array();

        $totals = array(
            'revenue'   => 0,
            'cogs'      => 0,
            'shipping'  => 0,
            'fees'      => 0,
            'net_profit'=> 0,
            'margin'    => 0,
            'count'     => count( $orders )
        );

        foreach ( $orders as $order ) {
            $totals['revenue'] += $order->get_total();
            
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product ) continue;
                
                $qty = $item->get_quantity();
                $cogs = (float) $product->get_meta( '_cogs_total_value' );
                $ship = (float) $product->get_meta( '_cw_est_shipping' );
                
                $totals['cogs'] += ( $cogs * $qty );
                $totals['shipping'] += ( $ship * $qty );
            }
        }

        $totals['fees']       = self::calculate_fees( $totals['revenue'], $totals['count'] );
        $totals['net_profit'] = $totals['revenue'] - $totals['cogs'] - $totals['shipping'] - $totals['fees'];
        $totals['margin']     = $totals['revenue'] > 0 ? ($totals['net_profit'] / $totals['revenue']) * 100 : 0;

        return array(
            'orders' => $orders,
            'totals' => $totals
        );
    }

    /**
     * Helper to calculate fees based on global config.
     */
    private static function calculate_fees( $revenue, $count ) {
        // Reuse the logic from original or Audit class
        // Ideally, this config retrieval could also be moved to a helper if used often
        if ( class_exists( 'Cirrusly_Commerce_Settings_Manager' ) ) {
            $config = Cirrusly_Commerce_Settings_Manager::get_global_config();
        } else {
             $config = get_option( 'cirrusly_shipping_config', array() );
        }

        $mode     = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';
        $pay_pct  = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;

        if ( $mode === 'multi' ) {
            $pay_pct_2  = isset($config['payment_pct_2']) ? ($config['payment_pct_2'] / 100) : 0.0349;
            $pay_flat_2 = isset($config['payment_flat_2']) ? $config['payment_flat_2'] : 0.49;
            $split      = isset($config['profile_split']) ? ($config['profile_split'] / 100) : 1.0;

            $fee1 = ($revenue * $pay_pct) + ($count * $pay_flat);
            $fee2 = ($revenue * $pay_pct_2) + ($count * $pay_flat_2);
            return ($fee1 * $split) + ($fee2 * (1 - $split));
        }

        return ($revenue * $pay_pct) + ($count * $pay_flat);
    }
}