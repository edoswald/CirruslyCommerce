<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit_UI {

    /**
     * Render the Store Financial Audit admin page and output its HTML interface.
     *
     * Renders dashboard metrics, filter controls, a sortable/paginated products table,
     * and a PRO tools card. Processes filter, search, sort, and pagination inputs;
     * may delete the audit transient when a refresh is requested; and delegates CSV
     * import handling to the Pro handler when a valid import nonce is submitted.
     * Verifies the current user has the 'edit_products' capability and terminates
     * with "No permission" if the check fails.
     */
    public static function render_page() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'No permission' );
        
        // Handle Import Submission (Delegated to Pro)
        if ( isset($_POST['cc_import_nonce']) && wp_verify_nonce($_POST['cc_import_nonce'], 'cc_import_action') ) {
            if ( class_exists( 'Cirrusly_Commerce_Audit_Pro' ) ) {
                Cirrusly_Commerce_Audit_Pro::handle_import();
            }
        }

        echo '<div class="wrap">'; 

        Cirrusly_Commerce_Core::render_global_header( 'Store Financial Audit' );
        settings_errors('cirrusly_audit');

        // 1. Handle Cache & Refresh
        $refresh = isset( $_GET['refresh_audit'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'cc_refresh_audit' );
        if ( $refresh ) delete_transient( 'cw_audit_data' );

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

        // DASHBOARD GRID
        ?>
        <div style="display:flex; gap:20px; align-items:flex-start;">
            <div class="cc-dash-grid" style="flex:1; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px;">
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
            </div>
            <div style="width:250px; background:#fff; border:1px solid #c3c4c7; padding:15px; font-size:12px; color:#555;">
                <strong>Dashboard Legend</strong>
                <ul style="margin:5px 0 0 15px; list-style:disc;">
                    <li><strong>Ship P/L:</strong> Shipping Charged - Shipping Cost. Positive is good.</li>
                    <li><strong>Net Profit:</strong> Gross Profit - Payment Fees.</li>
                    <li><strong>Margin:</strong> (Gross Profit / Price) * 100.</li>
                </ul>
            </div>
        </div>
        <?php

        // 3. Process Filters & Pagination
        $f_margin = isset($_GET['margin']) ? floatval($_GET['margin']) : 25;
        $f_cat = isset($_GET['cat']) ? sanitize_text_field(wp_unslash($_GET['cat'])) : '';
        $f_oos = isset($_GET['hide_oos']);
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'margin';
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

        $allowed_form_tags = array( 'select' => array('name' => true, 'id' => true, 'class' => true), 'option' => array('value' => true, 'selected' => true) );
        
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

        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <form method="get" style="display:inline-flex; gap:5px; align-items:center;">
                    <input type="hidden" name="page" value="cirrusly-audit">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search products...">
                    <select name="margin">
                        <option value="5" <?php selected($f_margin,5); ?>>Margin < 5%</option>
                        <option value="15" <?php selected($f_margin,15); ?>>Margin < 15%</option>
                        <option value="25" <?php selected($f_margin,25); ?>>Margin < 25%</option>
                        <option value="100" <?php selected($f_margin,100); ?>>Show All</option>
                    </select> 
                    <?php echo wp_kses( wc_product_dropdown_categories(array('option_none_text'=>'All Categories','name'=>'cat','selected'=>$f_cat,'value_field'=>'slug','echo'=>0)), $allowed_form_tags ); ?>
                    <label style="margin-left:5px;"><input type="checkbox" name="hide_oos" value="1" <?php checked($f_oos,true); ?>> Hide OOS</label>
                    <button class="button button-primary">Filter</button>
                    <a href="<?php echo esc_url( wp_nonce_url( '?page=cirrusly-audit&refresh_audit=1', 'cc_refresh_audit' ) ); ?>" class="button" title="Refresh Data from DB">Refresh Data</a>
                </form>
            </div>
            <?php echo wp_kses_post( $pagination_html ); ?>
        </div>
        
        <?php
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
                    <td>'.$cost_cell.'</td>
                    <td>'.wp_kses_post(wc_price($row['price'])).'</td>
                    <td style="'.esc_attr($ship_style).'">'.$ship_cell.'</td>
                    <td class="col-net" style="'.esc_attr($net_style).'">'.wp_kses_post(wc_price($row['net'])).'</td>
                    <td class="col-margin">'.esc_html(number_format($row['margin'],1)).'%</td>
                    <td>'.wp_kses_post(implode(' ',$row['alerts'])).'</td>
                    <td><a href="'.esc_url(get_edit_post_link($row['id'])).'" target="_blank" class="button button-small">Edit</a></td>
                </tr>';
            }
        }
        echo '</tbody></table>';

        echo '<div class="tablenav bottom">' . wp_kses_post( $pagination_html ) . '</div>';

        // NEW: PRO TOOLS CARD
        echo '<div class="cc-settings-card '.esc_attr($pro_class).'" style="margin-top:30px; border:1px solid #c3c4c7;">';
        if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn"><span class="dashicons dashicons-lock cc-lock-icon"></span> Unlock Bulk Data Tools</a></div>';
        
        echo '<div class="cc-card-header" style="background:#f8f9fa; border-bottom:1px solid #ddd;">
                <h3>Data Management <span class="cc-pro-badge">PRO</span></h3>
              </div>
              <div class="cc-card-body" style="display:flex; gap:20px; align-items:center;">
                 <div>
                    <h4>Export Data</h4>
                    <p>Download your full financial audit as a CSV file.</p>
                    <a href="'.esc_url( add_query_arg('action', 'export_csv') ).'" class="button button-secondary" '.esc_attr($disabled_attr).'>
                        <span class="dashicons dashicons-download"></span> Download CSV
                    </a>
                 </div>
                 <div style="border-left:1px solid #eee; padding-left:20px;">
                    <h4>Bulk Import COGS</h4>
                    <p>Update Cost of Goods and Pricing map via CSV.</p>
                    <form method="post" enctype="multipart/form-data">
                        '.wp_nonce_field('cc_import_action', 'cc_import_nonce', true, false).'
                        <label class="button button-secondary" style="cursor:pointer;">
                            <span class="dashicons dashicons-upload"></span> Upload CSV
                            <input type="file" name="csv_import" style="display:none;" onchange="this.form.submit()" '.esc_attr($disabled_attr).'>
                        </label>
                    </form>
                 </div>
              </div>';
        echo '</div>';

        if($is_pro) {
            ?>
            <script>
            jQuery(document).ready(function($){
                $('.cc-inline-edit').on('blur', function(){
                    var $el = $(this);
                    var $row = $el.closest('tr');
                    var pid = $el.data('pid');
                    var field = $el.data('field');
                    var val = $el.text();
                    $el.css('opacity', '0.5');

                    $.post(ajaxurl, {
                        action: 'cc_audit_save',
                        pid: pid,
                        field: field,
                        value: val,
                        _nonce: '<?php echo wp_create_nonce("cc_audit_save"); ?>'
                    }, function(res){
                        $el.css('opacity', '1');
                        if(res.success) {
                            $el.css('background-color', '#e7f6e7');
                            setTimeout(function(){ $el.css('background-color', 'transparent'); }, 1500);
                            if(res.data) {
                                if(res.data.net_html) $row.find('.col-net').html(res.data.net_html);
                                if(res.data.net_style) $row.find('.col-net').attr('style', res.data.net_style);
                                if(res.data.margin) $row.find('.col-margin').text(res.data.margin + '%');
                            }
                        } else {
                            $el.css('background-color', '#f8d7da');
                            alert('Save Failed: ' + (res.data || 'Unknown error'));
                        }
                    });
                });
                $('.cc-inline-edit').on('focus', function() {
                    var range = document.createRange();
                    range.selectNodeContents(this);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                });
            });
            </script>
            <?php
        }
        echo '</div>'; 
    }
}