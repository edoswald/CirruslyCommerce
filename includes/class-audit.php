<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit {
    public static function render_page() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die( 'No permission' );
        
        echo '<div class="wrap">'; 

        Cirrusly_Commerce_Core::render_global_header( 'Store Financial Audit' );

        // 1. Handle Cache & Refresh
        $cache_key = 'cw_audit_data';
        $cached_data = get_transient( $cache_key );
        
        if ( isset( $_GET['refresh_audit'] ) ) {
             delete_transient( $cache_key );
             $cached_data = false;
        }

        // 2. Get Config
        $core = new Cirrusly_Commerce_Core(); 
        $config = $core->get_global_config();
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $class_costs = json_decode( $config['class_costs_json'], true );
        
        $pay_pct = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;

        $get_rev = function($p) use ($revenue_tiers) { 
            if($revenue_tiers) {
                foreach($revenue_tiers as $t) if($p>=$t['min'] && $p<=$t['max']) return $t['charge']; 
            }
            return 0; 
        };

        // 3. Build Data
        if ( false === $cached_data ) {
            $args = array( 'post_type' => array('product','product_variation'), 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' );
            $ids = get_posts($args);
            $data = array();
            
            foreach($ids as $pid) {
                $p = wc_get_product($pid); if(!$p) continue;
                $cost = (float)$p->get_meta('_cogs_total_value');
                $ship_cost = (float)$p->get_meta('_cw_est_shipping');
                
                if($ship_cost <= 0 && !$p->is_virtual()) {
                    $cid = $p->get_shipping_class_id();
                    $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
                    if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
                    elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
                }
                
                $price = (float)$p->get_price();
                $rev = $get_rev($price);
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
                if(!$p->is_virtual() && (float)$p->get_weight() <= 0) $alerts[] = '<span class="gmc-badge" style="background:#dba617;color:#000;">0 Weight</span>';
                
                $ship_pl = $rev - $ship_cost;
                
                $data[] = array(
                    'id' => $pid, 'name' => $p->get_name(), 'type' => $p->get_type(), 'parent_id' => $p->get_parent_id(),
                    'cost' => $total_cost, 'item_cost' => $cost, 'ship_cost' => $ship_cost, 'ship_charge' => $rev,
                    'ship_pl' => $ship_pl, 'price' => $price, 'net' => $net, 'margin' => $margin,
                    'alerts' => $alerts, 'is_in_stock' => $p->is_in_stock(), 'cats' => wp_get_post_terms($pid, 'product_cat', array('fields'=>'slugs'))
                );
            }
            set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
            $cached_data = $data;
        }

        // Stats
        $total_skus = count($cached_data);
        $loss_count = 0; $alert_count = 0; $low_margin_count = 0;
        foreach($cached_data as $row) {
            if($row['net'] < 0) $loss_count++;
            if(!empty($row['alerts'])) $alert_count++;
            if($row['margin'] < 15) $low_margin_count++;
        }

        // Check PRO status
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        // --- Header Strip with PRO Buttons ---
        ?>
        <div class="cc-dash-grid" style="grid-template-columns: 1fr; margin-bottom: 20px;">
            <div class="cc-dash-card cc-full-width" style="border-top-color: #2271b1;">
                <div class="cc-stat-block">
                    <span class="cc-big-num"><?php echo esc_html( $total_skus ); ?></span>
                    <span class="cc-label">Audited SKUs</span>
                </div>
                <div class="cc-stat-block">
                    <span class="cc-big-num" style="color:#d63638;"><?php echo esc_html( $loss_count ); ?></span>
                    <span class="cc-label">Loss Makers</span>
                </div>
                
                <!-- PRO Upsell Area -->
                <div style="flex:2; display:flex; gap:10px; justify-content:center; align-items:center; border-left:1px solid #eee; padding-left:20px;">
                    <div class="<?php echo esc_attr($pro_class); ?>">
                        <button class="button button-secondary" <?php echo esc_attr($disabled_attr); ?>>
                            <span class="dashicons dashicons-download"></span> Export CSV
                        </button>
                    </div>
                    <div class="<?php echo esc_attr($pro_class); ?>">
                        <button class="button button-secondary" <?php echo esc_attr($disabled_attr); ?>>
                            <span class="dashicons dashicons-upload"></span> Bulk Import COGS
                        </button>
                    </div>
                    <?php if(!$is_pro): ?>
                    <a href="<?php echo esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ); ?>" class="cc-upgrade-btn button-small" style="font-size:11px;">
                        <span class="dashicons dashicons-lock" style="font-size:14px; line-height:1.5;"></span> Unlock Pro Tools
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        // Filter Logic
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
        $slice = array_slice($filtered_data, ($paged-1)*$per_page, $per_page);

        $allowed_form_tags = array(
            'select' => array('name' => true, 'id' => true, 'class' => true),
            'option' => array('value' => true, 'selected' => true),
        );

        echo '<div class="cc-settings-card">
            <div class="cc-card-header">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-filter"></span>
                    <h3 style="margin:0; font-size:14px; text-transform:uppercase; color:#646970;">Filter & Search</h3>
                </div>
                <div class="cc-ver-badge"><strong>'.esc_html($total).'</strong> Issues Found</div>
            </div>
            <div class="cc-card-body">
                <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="cirrusly-audit">
                    <input type="text" name="s" value="'.esc_attr($search).'" placeholder="Search products...">
                    <select name="margin">
                        <option value="5" '.selected($f_margin,5,false).'>Margin < 5%</option>
                        <option value="15" '.selected($f_margin,15,false).'>Margin < 15%</option>
                        <option value="25" '.selected($f_margin,25,false).'>Margin < 25%</option>
                        <option value="100" '.selected($f_margin,100,false).'>Show All (No Filter)</option>
                    </select> 
                    '.wp_kses( wc_product_dropdown_categories(array('option_none_text'=>'All Categories','name'=>'cat','selected'=>$f_cat,'value_field'=>'slug','echo'=>0)), $allowed_form_tags ).'
                    <label style="margin-left:5px;"><input type="checkbox" name="hide_oos" value="1" '.checked($f_oos,true,false).'> Hide OOS</label>
                    <button class="button button-primary">Filter</button>
                    <a href="?page=cirrusly-audit&refresh_audit=1" class="button" title="Refresh Data from DB">Refresh Data</a>
                </form>
            </div>
        </div>';
        
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
                
                echo '<tr>
                    <td>'.esc_html($row['id']).'</td>
                    <td><a href="'.esc_url(get_edit_post_link($row['id'])).'">'.wp_kses_post($name_html).'</a></td>
                    <td>'.wp_kses_post(wc_price($row['cost'])).' <small style="color:#999;display:block;">(Item '.wp_kses_post(wc_price($row['item_cost'])).' + Ship '.wp_kses_post(wc_price($row['ship_cost'])).')</small></td>
                    <td>'.wp_kses_post(wc_price($row['price'])).'</td>
                    <td style="'.esc_attr($ship_style).'">'.wp_kses_post(wc_price($row['ship_pl'])).'</td>
                    <td style="'.esc_attr($net_style).'">'.wp_kses_post(wc_price($row['net'])).'</td>
                    <td>'.esc_html(number_format($row['margin'],1)).'%</td>
                    <td>'.wp_kses_post(implode(' ',$row['alerts'])).'</td>
                    <td><a href="'.esc_url(get_edit_post_link($row['id'])).'" target="_blank" class="button button-small">Edit</a></td>
                </tr>';
            }
        }
        echo '</tbody></table>';

        $pages = ceil($total/$per_page);
        if($pages>1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">'.esc_html($total).' items</span>';
            for($i=1; $i<=$pages; $i++) {
                if($i==1 || $i==$pages || abs($i-$paged)<2) {
                    $cls = $i==$paged ? 'current' : '';
                    echo '<a class="button '.esc_attr($cls).'" href="'.esc_url(add_query_arg('paged',$i)).'">'.esc_html($i).'</a> ';
                } elseif($i==2 || $i==$pages-1) echo '... ';
            }
            echo '</div></div>';
        }

        echo '</div>'; 
    }
}