<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit {

    public static function init() {
        // Hook for export - must run before headers are sent
        add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Helper to get or generate audit data.
     * Used by both the Render View and the CSV Export.
     */
    public static function get_compiled_data( $force_refresh = false ) {
        $cache_key = 'cw_audit_data';
        $data = get_transient( $cache_key );
        
        if ( false === $data || $force_refresh ) {
            $core = new Cirrusly_Commerce_Core(); 
            $config = $core->get_global_config();
            $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
            $class_costs = json_decode( $config['class_costs_json'], true );
            
            $pay_pct = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
            $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;

            // Helper: Get Ship Revenue (Closure)
            $get_rev = function($p_price) use ($revenue_tiers) { 
                if($revenue_tiers) {
                    foreach($revenue_tiers as $t) if($p_price>=$t['min'] && $p_price<=$t['max']) return $t['charge']; 
                }
                return 0; 
            };

            $args = array( 'post_type' => array('product','product_variation'), 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' );
            $ids = get_posts($args);
            $data = array();
            
            foreach($ids as $pid) {
                $p = wc_get_product($pid); if(!$p) continue;
                
                // --- LOGIC FIX: Downloadable/Virtual are Shipping Exempt ---
                $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
                
                $cost = (float)$p->get_meta('_cogs_total_value');
                $ship_cost = (float)$p->get_meta('_cw_est_shipping');
                
                // Fallback Ship Cost Logic
                if($ship_cost <= 0 && !$is_shipping_exempt) {
                    $cid = $p->get_shipping_class_id();
                    $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
                    if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
                    elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
                } elseif ( $is_shipping_exempt ) {
                    $ship_cost = 0;
                }
                
                $price = (float)$p->get_price();
                
                // Rev Logic: Exempt products get 0 shipping revenue
                $rev = $is_shipping_exempt ? 0 : $get_rev($price);
                
                $total_inc = $price + $rev;
                $total_cost = $cost + $ship_cost;
                $margin = 0; $net = 0;
                
                if($price > 0 && $total_cost > 0) {
                    $gross = $total_inc - $total_cost;
                    $margin = ($gross/$price)*100;
                    $fee = ($total_inc * $pay_pct) + $pay_flat;
                    $net = $gross - $fee;
                }
                
                $alerts = array();
                if($cost <= 0) $alerts[] = '<a href="'.esc_url(get_edit_post_link($pid)).'" target="_blank" class="gmc-badge" style="background:#d63638;color:#fff;text-decoration:none;">Add Cost</a>';
                
                // Logic Fix: Only alert on weight if shippable
                if( !$is_shipping_exempt && (float)$p->get_weight() <= 0) $alerts[] = '<span class="gmc-badge" style="background:#dba617;color:#000;">0 Weight</span>';
                
                $ship_pl = $rev - $ship_cost;
                
                $data[] = array(
                    'id' => $pid,
                    'name' => $p->get_name(),
                    'type' => $p->get_type(),
                    'parent_id' => $p->get_parent_id(),
                    'cost' => $total_cost,
                    'item_cost' => $cost,
                    'ship_cost' => $ship_cost,
                    'ship_charge' => $rev,
                    'ship_pl' => $ship_pl,
                    'price' => $price,
                    'net' => $net,
                    'margin' => $margin,
                    'alerts' => $alerts,
                    'is_in_stock' => $p->is_in_stock(),
                    'cats' => wp_get_post_terms($pid, 'product_cat', array('fields'=>'slugs'))
                );
            }
            set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
        }
        return $data;
    }

    /**
     * Calculate metrics for a single product. 
     * Used by AJAX handler in Core to return real-time updates.
     */
    public static function get_single_metric( $pid ) {
        // Force refresh just this item's calculation logic essentially
        // We reuse the logic by running a partial routine similar to get_compiled_data
        // but simpler for performance.
        
        $p = wc_get_product($pid);
        if(!$p) return false;

        $core = new Cirrusly_Commerce_Core(); 
        $config = $core->get_global_config();
        
        // Configs
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $class_costs   = json_decode( $config['class_costs_json'], true );
        $pay_pct       = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat      = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        
        $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
        
        $cost = (float)$p->get_meta('_cogs_total_value');
        $ship_cost = (float)$p->get_meta('_cw_est_shipping');
        
        // Fallback Ship Cost
        if($ship_cost <= 0 && !$is_shipping_exempt) {
            $cid = $p->get_shipping_class_id();
            $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
            if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
            elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
        } elseif ( $is_shipping_exempt ) {
            $ship_cost = 0;
        }

        $price = (float)$p->get_price();
        
        // Get Revenue
        $rev = 0;
        if ( !$is_shipping_exempt && $revenue_tiers ) {
            foreach($revenue_tiers as $t) if($price>=$t['min'] && $price<=$t['max']) { $rev = $t['charge']; break; }
        }

        $total_inc = $price + $rev;
        $total_cost = $cost + $ship_cost;
        $margin = 0; $net = 0;
        
        if($price > 0 && $total_cost > 0) {
            $gross = $total_inc - $total_cost;
            $margin = ($gross/$price)*100;
            $fee = ($total_inc * $pay_pct) + $pay_flat;
            $net = $gross - $fee;
        }
        
        $ship_pl = $rev - $ship_cost;

        // Formatted HTML for JS return
        $net_style = $net < 0 ? 'color:#d63638;font-weight:bold;' : 'color:#008a20;font-weight:bold;';
        $ship_style = $ship_pl >= 0 ? 'color:#008a20;' : 'color:#d63638;';
        
        return array(
            'net_val' => $net,
            'net_html' => wc_price($net),
            'net_style' => $net_style,
            'margin' => number_format($margin, 1),
            'margin_val' => $margin,
            'ship_pl_html' => wc_price($ship_pl), // if needed
            'cost_html' => wc_price($total_cost) // if needed
        );
    }

    public static function handle_export() {
        if ( isset($_GET['page']) && $_GET['page'] === 'cirrusly-audit' && isset($_GET['action']) && $_GET['action'] === 'export_csv' ) {
            if ( ! current_user_can( 'edit_products' ) ) return;
            
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                wp_die('Export is a Pro feature.');
            }

            // Fix: Generate data if transient is missing (don't redirect)
            $data = self::get_compiled_data();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="store-audit-' . date('Y-m-d') . '.csv"');
            
            $fp = fopen('php://output', 'w');
            fputcsv($fp, array('ID', 'Product Name', 'Type', 'Cost (COGS)', 'Shipping Cost', 'Price', 'Net Profit', 'Margin %'));
            
            foreach ( $data as $row ) {
                fputcsv($fp, array(
                    $row['id'],
                    $row['name'],
                    $row['type'],
                    $row['item_cost'],
                    $row['ship_cost'],
                    $row['price'],
                    $row['net'],
                    number_format($row['margin'], 2) . '%'
                ));
            }
            fclose($fp);
            exit;
        }
    }

    public static function handle_import() {
        if ( isset($_FILES['csv_import']) && current_user_can('edit_products') ) {
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Import is a Pro feature.', 'error');
                return;
            }

            // Fix: Handle Mac line endings in CSVs
            ini_set('auto_detect_line_endings', true);

            $file = $_FILES['csv_import']['tmp_name'];
            $handle = fopen($file, "r");
            $count = 0;
            
            // Skip header
            fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== FALSE) {
                // Check if row has data
                if ( empty($row[0]) ) continue;
                
                $pid = intval($row[0]);
                // Fix: Ensure we are grabbing valid data. 
                // Export format: ID(0), Name(1), Type(2), Cost(3)
                $cost = isset($row[3]) ? floatval($row[3]) : 0;
                
                if ( $pid && $cost > 0 ) {
                    update_post_meta($pid, '_cogs_total_value', $cost);
                    $count++;
                }
            }
            fclose($handle);
            delete_transient( 'cw_audit_data' ); // Clear cache to show new data
            add_settings_error('cirrusly_audit', 'import_success', "Imported costs for $count products.", 'success');
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'No permission' );
        
        // Handle Import Submission
        if ( isset($_POST['cc_import_nonce']) && wp_verify_nonce($_POST['cc_import_nonce'], 'cc_import_action') ) {
            self::handle_import();
        }

        echo '<div class="wrap">'; 

        Cirrusly_Commerce_Core::render_global_header( 'Store Financial Audit' );
        settings_errors('cirrusly_audit');

        // 1. Handle Cache & Refresh
        $refresh = isset( $_GET['refresh_audit'] );
        if ( $refresh ) delete_transient( 'cw_audit_data' );

        // 2. Get Data via Helper
        $cached_data = self::get_compiled_data( $refresh );

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

        // Check PRO status
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        // --- Render Audit Header Strip ---
        ?>
        <div class="cc-dash-grid" style="grid-template-columns: 1fr; margin-bottom: 20px;">
            <div class="cc-dash-card cc-full-width" style="border-top-color: #2271b1;">
                <div class="cc-stat-block">
                    <span class="cc-big-num"><?php echo esc_html( $total_skus ); ?></span>
                    <span class="cc-label">Audited SKUs</span>
                </div>
                <div class="cc-stat-block">
                    <span class="cc-big-num" style="color:#d63638;"><?php echo esc_html( $loss_count ); ?></span>
                    <span class="cc-label">Loss Makers (Net &lt; 0)</span>
                </div>
                <div class="cc-stat-block">
                    <span class="cc-big-num" style="color:#dba617;"><?php echo esc_html( $alert_count ); ?></span>
                    <span class="cc-label">Data Alerts</span>
                </div>
                <div class="cc-stat-block">
                    <span class="cc-big-num"><?php echo esc_html( $low_margin_count ); ?></span>
                    <span class="cc-label">Low Margin (&lt; 15%)</span>
                </div>
                
                <div style="flex:2; display:flex; gap:10px; justify-content:center; align-items:center; border-left:1px solid #eee; padding-left:20px; position:relative;">
                    
                    <div class="<?php echo esc_attr($pro_class); ?>">
                        <a href="<?php echo esc_url( add_query_arg('action', 'export_csv') ); ?>" class="button button-secondary" <?php echo esc_attr($disabled_attr); ?>>
                            <span class="dashicons dashicons-download"></span> Export CSV
                        </a>
                    </div>
                    
                    <div class="<?php echo esc_attr($pro_class); ?>">
                        <form method="post" enctype="multipart/form-data" style="display:inline;">
                            <?php wp_nonce_field('cc_import_action', 'cc_import_nonce'); ?>
                            <label class="button button-secondary" style="cursor:pointer;">
                                <span class="dashicons dashicons-upload"></span> Bulk Import COGS
                                <input type="file" name="csv_import" style="display:none;" onchange="this.form.submit()" <?php echo esc_attr($disabled_attr); ?>>
                            </label>
                        </form>
                    </div>

                    <?php if(!$is_pro): ?>
                    <div class="cc-pro-overlay">
                        <a href="<?php echo esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ); ?>" class="cc-upgrade-btn button-small" style="font-size:11px;">
                            <span class="dashicons dashicons-lock" style="font-size:14px; line-height:1.5;"></span> Unlock Pro Tools
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        // 3. Process Filters & Pagination
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $f_margin = isset($_GET['margin']) ? floatval($_GET['margin']) : 25;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $f_cat = isset($_GET['cat']) ? sanitize_text_field(wp_unslash($_GET['cat'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $f_oos = isset($_GET['hide_oos']);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'margin';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'asc';

        $filtered_data = array();
        foreach($cached_data as $row) {
            if($f_oos && !$row['is_in_stock']) continue;
            if($f_cat && !in_array($f_cat, $row['cats'])) continue;
            if($search && stripos($row['name'], $search) === false) continue;
            // Filter Logic: Show if margin is LOW (problematic) OR has alerts
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

        // 4. Render View
        $allowed_form_tags = array( 'select' => array('name' => true, 'id' => true, 'class' => true), 'option' => array('value' => true, 'selected' => true) );
        
        // Helper to generate pagination HTML
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

        // Updated Filter Bar styling
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
                    <a href="?page=cirrusly-audit&refresh_audit=1" class="button" title="Refresh Data from DB">Refresh Data</a>
                </form>
            </div>
            <?php echo $pagination_html; ?>
        </div>
        
        <?php
        // Helper for Sort Links
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
                
                // Inline Edit Logic (Pro Only)
                $cost_cell = wp_kses_post(wc_price($row['cost']));
                $ship_cell = wp_kses_post(wc_price($row['ship_pl']));
                
                if($is_pro) {
                     $cost_cell = '<span class="cc-inline-edit" data-pid="'.esc_attr($row['id']).'" data-field="_cogs_total_value" contenteditable="true" style="border-bottom:1px dashed #999; cursor:pointer;">'.number_format($row['item_cost'], 2).'</span> <small style="color:#999;">+ Ship '.number_format($row['ship_cost'], 2).'</small>';
                }

                // Add classes (col-net, col-margin) for AJAX targeting
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

        // Pagination Bottom
        echo '<div class="tablenav bottom">'.$pagination_html.'</div>';

        // Inline Edit Script (Pro)
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
                    
                    // Show loading state (opacity)
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
                            // 1. Visual Confirmation (Green Flash)
                            $el.css('background-color', '#e7f6e7');
                            setTimeout(function(){ $el.css('background-color', 'transparent'); }, 1500);

                            // 2. Update Row Data (if returned)
                            if(res.data) {
                                if(res.data.net_html) $row.find('.col-net').html(res.data.net_html);
                                if(res.data.net_style) $row.find('.col-net').attr('style', res.data.net_style);
                                if(res.data.margin) $row.find('.col-margin').text(res.data.margin + '%');
                            }
                        } else {
                            // Error Flash
                            $el.css('background-color', '#f8d7da');
                            alert('Save Failed: ' + (res.data || 'Unknown error'));
                        }
                    });
                });
                
                // UX: Select text on click
                $('.cc-inline-edit').on('focus', function() {
                    document.execCommand('selectAll',false,null);
                });
            });
            </script>
            <?php
        }

        echo '</div>'; 
    }
}