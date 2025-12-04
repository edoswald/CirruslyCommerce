<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Reports {

    public static function init() {
        add_action( 'cirrusly_weekly_profit_report', array( __CLASS__, 'send_weekly_email' ) );
    }

    public static function send_weekly_email() {
        // 1. Check if enabled
        $scan_cfg = get_option( 'cirrusly_scan_config', array() );
        
        // We check both the specific 'weekly report' toggle (Pro) AND the general email toggle
        $general_email = !empty($scan_cfg['enable_email_report']) && $scan_cfg['enable_email_report'] === 'yes';
        $weekly_email  = !empty($scan_cfg['alert_weekly_report']) && $scan_cfg['alert_weekly_report'] === 'yes';

        // If neither is enabled, abort. 
        if ( ! $general_email && ! $weekly_email ) return;

        // 2. Pro Check (This is a Pro feature)
        if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) return;

        // 3. Gather Data (Last 7 Days)
        $date_query = array(
            'after'     => date('Y-m-d', strtotime('-7 days')),
            'before'    => date('Y-m-d', strtotime('now')),
            'inclusive' => true,
        );
        
        $orders = wc_get_orders( array(
            'limit'      => -1,
            'status'     => array( 'wc-completed', 'wc-processing' ),
            'date_created' => $date_query,
        ) );

        if ( empty( $orders ) ) return; // No orders, no report

        $total_revenue = 0;
        $total_cogs    = 0;
        $total_ship_cost = 0;
        $order_count   = count( $orders );

        // 4. Calculate Financials
        foreach ( $orders as $order ) {
            $total_revenue += $order->get_total();
            
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                if ( ! $product ) continue;
                
                $qty = $item->get_quantity();
                
                // Note: Ideally COGS should be snapshot on Order Item Meta.
                // Fallback to current Product Meta if historical not found.
                $cogs = (float) $product->get_meta( '_cogs_total_value' );
                $ship = (float) $product->get_meta( '_cw_est_shipping' );
                
                $total_cogs += ( $cogs * $qty );
                $total_ship_cost += ( $ship * $qty );
            }
        }

        // Get Payment Processor Fees from Settings
        $ship_config = get_option( 'cirrusly_shipping_config', array() );
        $pay_pct = isset($ship_config['payment_pct']) ? ($ship_config['payment_pct'] / 100) : 0.029;
        $pay_flat = isset($ship_config['payment_flat']) ? $ship_config['payment_flat'] : 0.30;
        
        $est_fees = ($total_revenue * $pay_pct) + ($order_count * $pay_flat);
        $net_profit = $total_revenue - $total_cogs - $total_ship_cost - $est_fees;
        $margin = $total_revenue > 0 ? ($net_profit / $total_revenue) * 100 : 0;

        // 5. Build Email
        $to = !empty($scan_cfg['email_recipient']) ? $scan_cfg['email_recipient'] : get_option('admin_email');
        $subject = 'Weekly Profit Report: ' . wc_price($net_profit) . ' Net';
        
        $message = '<h2>Weekly Store Performance</h2>';
        $message .= '<p>Here is your financial snapshot for the last 7 days.</p>';
        
        $message .= '<table cellpadding="10" cellspacing="0" style="width:100%; max-width:600px; border:1px solid #eee;">';
        
        $row_style = 'border-bottom:1px solid #eee;';
        
        $message .= '<tr><td style="'.$row_style.'"><strong>Orders</strong></td><td style="'.$row_style.'">' . $order_count . '</td></tr>';
        $message .= '<tr><td style="'.$row_style.'"><strong>Gross Revenue</strong></td><td style="'.$row_style.'">' . wc_price($total_revenue) . '</td></tr>';
        $message .= '<tr><td style="'.$row_style.'"><strong>COGS (Est)</strong></td><td style="'.$row_style.' color:#d63638;">- ' . wc_price($total_cogs) . '</td></tr>';
        $message .= '<tr><td style="'.$row_style.'"><strong>Shipping Costs (Est)</strong></td><td style="'.$row_style.' color:#d63638;">- ' . wc_price($total_ship_cost) . '</td></tr>';
        $message .= '<tr><td style="'.$row_style.'"><strong>Payment Fees (Est)</strong></td><td style="'.$row_style.' color:#d63638;">- ' . wc_price($est_fees) . '</td></tr>';
        
        $color = $net_profit > 0 ? '#008a20' : '#d63638';
        $message .= '<tr style="background:#f9f9f9; font-size:1.2em;"><td><strong>NET PROFIT</strong></td><td style="color:'.$color.'; font-weight:bold;">' . wc_price($net_profit) . '</td></tr>';
        $message .= '<tr><td><strong>Net Margin</strong></td><td>' . number_format($margin, 1) . '%</td></tr>';
        
        $message .= '</table>';
        $message .= '<p style="margin-top:20px; font-size:12px; color:#777;">*Calculated based on current product cost settings.</p>';

        add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );
        wp_mail( $to, $subject, $message );
        remove_filter( 'wp_mail_content_type', function() { return 'text/html'; } );
    }
}