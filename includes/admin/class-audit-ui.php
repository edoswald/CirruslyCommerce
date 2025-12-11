<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit_UI {

    /**
     * Render the Store Financial Audit admin page.
     *
     * Processes input (filters, search, sorting, pagination), handles transient refresh and CSV import (delegated to Pro when applicable), enforces the 'edit_products' capability, and outputs the dashboard overview, filters toolbar, sortable/paginated products table, and Pro-only inline editing UI.
     */
    public static function render_page() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'No permission' );
        
        // Handle Import Submission (Delegated to Pro)
        if (
            isset( $_POST['cc_import_nonce'] )
            && wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['cc_import_nonce'] ) ),
                'cc_import_action'
            )
            && Cirrusly_Commerce_Core::cirrusly_is_pro()
            && class_exists( 'Cirrusly_Commerce_Audit_Pro' )
        ) {
            Cirrusly_Commerce_Audit_Pro::handle_import();
        }

        echo '<div class="wrap">'; 

        Cirrusly_Commerce_Core::render_global_header( 'Store Financial Audit' );
        settings_errors('cirrusly_audit');

        // 1. Handle Cache & Refresh
        $refresh = isset( $_GET['refresh_audit'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cc_refresh_audit' );
        if ( $refresh ) {
            delete_transient( 'cirrusly_audit_data' );
        }

        // 2. Get Data via Core Logic
        $cached_data = Cirrusly_Commerce_Audit::get_compiled_data( $refresh );

        // --- Calculate Audit Aggregates ---
        $total_skus = count($cached_data);
        $loss_count = 0;
        $alert_count = 0;
        $low_margin_count = 0;

        foreach($cached_data as $row) {
            if($row['net'] < 0) $loss_count++;
            if(!empty($row['alerts'])) $alert_count++;
            if($row['margin'] < 15) $low_margin_count++;
        }

        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        // 3. Process Filters & Pagination (Moved Up)
        $f_margin = isset($_GET['margin']) ? floatval($_GET['margin']) : 25;
        $f_cat = isset($_GET['cat']) ? sanitize_text_field(wp_unslash($_GET['cat'])) : '';
        $f_oos = isset($_GET['hide_oos']);
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'margin';
        $allowed_orderby = array('cost', 'price', 'ship_pl', 'net', 'margin');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'margin';
        }
        $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'asc';

        $filtered_data = array();
        foreach($cached_data as $row) {
            if($f_oos && !$row['is_in_stock']) continue;
            if($f_cat && !in_array($f_cat, $row['cats'])) continue;
            if($search && stripos($row['name'], $search) === false) continue;
            if ( $row['margin'] >= $f_margin && empty($row['alerts']) ) continue;
            
            $filtered_data[] = $row;
        }
        
        usort($filtered_data, function($a, $b) use ($orderby, $order) {
            if ($a[$orderby] == $b[$orderby]) return 0;
            if ($order === 'asc') return ($a[$orderby] < $b[$orderby]) ? -1 : 1;
            return ($a[$orderby] > $b[$orderby]) ? -1 : 1;
        });

        $total = count($filtered_data);
        $pages = ceil($total/$per_page);
        $slice = array_slice($filtered_data, ($paged-1)*$per_page, $per_page);

        // DASHBOARD GRID
        ?>
        <div class="cc-dashboard-overview" style="margin-bottom:20px;">
            <div class="cc-dash-grid" style="display:grid; grid-template-columns: repeat(4, 1fr); gap: 20px;">
                 <div class="cc-dash-card" style="border-top-color: #2271b1; text-align: center;">
                     <span class="cc-big-num"><?php echo esc_html( $total_skus ); ?></span>
                     <span class="cc-label">Audited SKUs</span>
                 </div>
                 <div class="cc-dash-card" style="border-top-color: #d63638; text-align: center;">
                     <span class="cc-big-num" style="color:#d63638;"><?php echo esc_html( $loss_count ); ?></span>
                     <span class="cc-label">Loss Makers (Net &lt; 0)</span>
                 </div>
                 <div class="cc-dash-card" style="border-top-color: #dba617; text-align: center;">
                     <span class="cc-big-num" style="color:#dba617;"><?php echo esc_html( $alert_count ); ?></span>
                     <span class="cc-label">Data Alerts</span>
                 </div>
                 <div class="cc-dash-card" style="border-top-color: #008a20; text-align: center;">
                     <span class="cc-big-num"><?php echo esc_html( $low_margin_count ); ?></span>
                     <span class="cc-label">Low Margin (&lt; 15%)</span>
                 </div>
                 <div style="grid-column: span 4; background:#f9f9f9; padding:10px; font-size:12px; color:#666; border-radius:4px; display:flex; justify-content:center; gap:30px; border:1px solid #ddd;">
                    <span><strong style="color:#2271b1;">Ship P/L:</strong> Shipping Charged - Estimated Cost</span>
                    <span><strong style="color:#2271b1;">Net Profit:</strong> Gross Profit - Payment Fees</span>
                    <span><strong style="color:#2271b1;">Margin:</strong> (Gross Profit / Price) * 100</span>
                 </div>
            </div>
        </div>

        <div class="cc-audit-toolbar" style="background:#fff; border:1px solid #c3c4c7; padding:15px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04);">
            
            <form method="get" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; flex:1;">
                <input type="hidden" name="page" value="cirrusly-audit">
                
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search product..." style="height:32px; min-width:200px;">
                
                <select name="margin" style="height:32px; vertical-align:top;">
                    <option value="5" <?php selected($f_margin,5); ?>>Margin < 5%</option>
                    <option value="15" <?php selected($f_margin,15); ?>>Margin < 15%</option>
                    <option value="25" <?php selected($f_margin,25); ?>>Margin < 25%</option>
                    <option value="100" <?php selected($f_margin,100); ?>>Show All</option>
                </select>

                <?php 
                $allowed_form_tags = array( 'select' => array('name' => true, 'id' => true, 'class' => true, 'style'=>true), 'option' => array('value' => true, 'selected' => true) );
                echo wp_kses( wc_product_dropdown_categories(array('option_none_text'=>'All Categories','name'=>'cat','selected'=>$f_cat,'value_field'=>'slug','echo'=>0, 'class'=>'', 'style'=>'height:32px; max-width:150px;')), $allowed_form_tags ); 
                ?>

                <label style="margin-left:5px; white-space:nowrap; background:#f0f0f1; padding:0 8px; border-radius:4px; border:1px solid #ccc; height:30px; line-height:28px; font-size:12px;">
                    <input type="checkbox" name="hide_oos" value="1" <?php checked($f_oos,true); ?>> Hide OOS
                </label>
                
                <button class="button button-primary" style="height:32px; line-height:30px;">Filter</button>
                <a href="<?php echo esc_url( wp_nonce_url( '?page=cirrusly-audit&refresh_audit=1', 'cc_refresh_audit' ) ); ?>" class="button" title="Refresh Data from Database" style="height:32px; line-height:30px;"><span class="dashicons dashicons-update" style="line-height:30px;"></span></a>
            </form>

            <div class="cc-toolbar-actions" style="display:flex; align-items:center; gap:8px; border-left:1px solid #ddd; padding-left:15px;">
                <?php if(!$is_pro): ?>
                     <a href="<?php echo esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ); ?>" title="Upgrade to Pro" style="color:#d63638; text-decoration:none; margin-right:5px; font-weight:bold;">
                        <span class="dashicons dashicons-lock"></span>
                     </a>
                <?php endif; ?>

                <?php if($is_pro): ?>
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg('action', 'export_csv'), 'cc_export_csv' ) ); ?>" class="button button-secondary" title="Export CSV">
                    <span class="dashicons dashicons-download"></span> Export
                </a>
                <?php else: ?>
                    <label class="button button-secondary" style="cursor:not-allowed; opacity:0.6;" title="Export CSV is available in Pro.">
                        <span class="dashicons dashicons-download"></span> Export
                    </label>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" style="margin:0;">
                     <?php wp_nonce_field('cc_import_action', 'cc_import_nonce'); ?>
                     <label class="button button-secondary" style="cursor:pointer;" title="Import Cost CSV" <?php echo esc_attr( $disabled_attr ); ?>>
                         <span class="dashicons dashicons-upload"></span> Import
                         <input type="file" name="csv_import" style="display:none;" onchange="this.form.submit()" <?php echo esc_attr( $disabled_attr ); ?>>
                     </label>
                </form>
            </div>
        </div>
        <?php

        // Pagination
        $pagination_html = '';
        if($pages>1) {
            $pagination_html .= '<div class="tablenav-pages"><span class="displaying-num">'.esc_html($total).' items</span>';
            $pagination_html .= '<span class="pagination-links">';
            for($i=1; $i<=$pages; $i++) {
                if($i==1 || $i==$pages || abs($i-$paged)<2) {
                    $cls = $i==$paged ? 'current' : '';
                    $pagination_html .= '<a class="button '.esc_attr($cls).'" href="'.esc_url(add_query_arg('paged',$i)).'">'.esc_html($i).'</a> ';
                } elseif($i==2 || $i==$pages-1) $pagination_html .= '<span class="tablenav-pages-navspan button disabled">...</span> ';
            }
            $pagination_html .= '</span></div>';
        }

        // Render Top Pagination
        if($pagination_html) {
             echo '<div class="tablenav top" style="margin-top:0;">' . wp_kses_post( $pagination_html ) . '</div>';
        }

        $sort_link = function($col, $label) use ($orderby, $order) {
            $new_order = ($orderby === $col && $order === 'asc') ? 'desc' : 'asc';
            $arrow = ($orderby === $col) ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
            return '<a href="'.esc_url(add_query_arg(array('orderby'=>$col, 'order'=>$new_order))).'" style="color:#333;text-decoration:none;font-weight:600;">'.esc_html($label).$arrow.'</a>';
        };

        echo '<table class="widefat fixed striped"><thead><tr>
            <th style="width:60px;">ID</th>
            <th>Product</th>
            <th>'.wp_kses_post($sort_link('cost', 'Total Cost')).'</th>
            <th>'.wp_kses_post($sort_link('price', 'Price')).'</th>
            <th>'.wp_kses_post($sort_link('ship_pl', 'Ship P/L')).'</th>
            <th>'.wp_kses_post($sort_link('net', 'Net Profit')).'</th>
            <th>'.wp_kses_post($sort_link('margin', 'Margin')).'</th>
            <th>Alerts</th>
            <th>Action</th>
        </tr></thead><tbody>';
        
        if ( empty($slice) ) {
            echo '<tr><td colspan="9" style="padding:20px; text-align:center;">No products found matching your criteria.</td></tr>';
        } else {
            foreach($slice as $row) {
                $name_html = esc_html($row['name']);
                if ( $row['type'] == 'variation' ) {
                    $parent = wc_get_product( $row['parent_id'] );
                    if($parent) {
                        $name_html = esc_html($parent->get_name()) . ' &rarr; <span style="color:#555;">' . esc_html(str_replace($parent->get_name().' - ', '', $row['name'])) . '</span>';
                    }
                }
                
                $net_style = $row['net'] < 0 ? 'color:#d63638;font-weight:bold;' : 'color:#008a20;font-weight:bold;';
                $ship_style = $row['ship_pl'] >= 0 ? 'color:#008a20;' : 'color:#d63638;';
                
                $cost_cell = wp_kses_post(wc_price($row['cost']));
                $ship_cell = wp_kses_post(wc_price($row['ship_pl']));
                
                if($is_pro) {
                     $cost_cell = '<span class="cc-inline-edit" data-pid="'.esc_attr($row['id']).'" data-field="_cogs_total_value" contenteditable="true" style="border-bottom:1px dashed #999; cursor:pointer;">'.number_format($row['item_cost'], 2).'</span> <small style="color:#999;">+ Ship '.number_format($row['ship_cost'], 2).'</small>';
                }

                echo '<tr>
                    <td>'.esc_html($row['id']).'</td>
                    <td><a href="'.esc_url(get_edit_post_link($row['id'])).'">'.wp_kses_post($name_html).'</a></td>
                    <td>'.wp_kses_post($cost_cell).'</td>
                    <td>'.wp_kses_post(wc_price($row['price'])).'</td>
                    <td style="'.esc_attr($ship_style).'">'.wp_kses_post($ship_cell).'</td>
                    <td class="col-net" style="'.esc_attr($net_style).'">'.wp_kses_post(wc_price($row['net'])).'</td>
                    <td class="col-margin">'.esc_html(number_format($row['margin'],1)).'%</td>
                    <td>'.wp_kses_post(implode(' ',$row['alerts'])).'</td>
                    <td><a href="'.esc_url(get_edit_post_link($row['id'])).'" target="_blank" class="button button-small">Edit</a></td>
                </tr>';
            }
        }
        echo '</tbody></table>';

        if($pagination_html) {
             echo '<div class="tablenav bottom">' . wp_kses_post( $pagination_html ) . '</div>';
        }

        // Inline script block removed.
        // It is now handled by assets/js/audit.js which is enqueued in Cirrusly_Commerce_Admin_Assets
        
        echo '</div>'; 
    }
}