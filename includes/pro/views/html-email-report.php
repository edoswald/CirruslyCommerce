<?php 
/**
 * View for the Weekly Profit Report Email
 * @var array $data Contains 'orders' (array) and 'totals' (array)
 */
defined( 'ABSPATH' ) || exit;

$t = wp_parse_args( $data['totals'], array(
    'count'      => 0,
    'revenue'    => 0,
    'cogs'       => 0,
    'shipping'   => 0,
    'fees'       => 0,
    'net_profit' => 0,
    'margin'     => 0,
) );$row_style = 'border-bottom:1px solid #eee; padding: 10px;';
$net_color = $t['net_profit'] > 0 ? '#008a20' : '#d63638';
?>
<h2>Weekly Store Performance</h2>
<p>Here is your financial snapshot for the last 7 days.</p>

<table cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; border:1px solid #eee; font-family: sans-serif;">
    <tr>
        <td style="<?php echo $row_style; ?>"><strong>Orders</strong></td>
        <td style="<?php echo $row_style; ?>"><?php echo intval( $t['count'] ); ?></td>
    </tr>
    <tr>
        <td style="<?php echo $row_style; ?>"><strong>Gross Revenue</strong></td>
        <td style="<?php echo $row_style; ?>"><?php echo wc_price( $t['revenue'] ); ?></td>
    </tr>
    <tr>
        <td style="<?php echo $row_style; ?>"><strong>COGS (Est)</strong></td>
        <td style="<?php echo $row_style; ?> color:#d63638;">- <?php echo wc_price( $t['cogs'] ); ?></td>
    </tr>
    <tr>
        <td style="<?php echo $row_style; ?>"><strong>Shipping Costs (Est)</strong></td>
        <td style="<?php echo $row_style; ?> color:#d63638;">- <?php echo wc_price( $t['shipping'] ); ?></td>
    </tr>
    <tr>
        <td style="<?php echo $row_style; ?>"><strong>Payment Fees (Est)</strong></td>
        <td style="<?php echo $row_style; ?> color:#d63638;">- <?php echo wc_price( $t['fees'] ); ?></td>
    </tr>
    
    <tr style="background:#f9f9f9; font-size:1.2em;">
        <td style="padding: 10px;"><strong>NET PROFIT</strong></td>
        <td style="padding: 10px; color:<?php echo $net_color; ?>; font-weight:bold;">
            <?php echo wc_price( $t['net_profit'] ); ?>
        </td>
    </tr>
    <tr>
        <td style="padding: 10px;"><strong>Net Margin</strong></td>
        <td style="padding: 10px;"><?php echo number_format( $t['margin'], 1 ); ?>%</td>
    </tr>
</table>

<p style="margin-top:20px; font-size:12px; color:#777;">*Calculated based on current product cost settings.</p>