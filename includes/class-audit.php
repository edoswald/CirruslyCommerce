<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit {

    /**
     * Registers audit-related hooks used by the admin area.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Build and return per-product financial audit data used by the admin audit table and CSV export.
     *
     * When available, cached results are returned; setting $force_refresh to true rebuilds the data.
     *
     * Each returned item is an associative array with keys:
     * `id`, `name`, `type`, `parent_id`, `cost`, `item_cost`, `ship_cost`, `ship_charge`,
     * `ship_pl`, `price`, `net`, `margin`, `alerts`, `is_in_stock`, `cats`, `map`, `min_price`, `msrp`.
     *
     * @param bool $force_refresh If true, ignore cached data and regenerate the compiled audit data.
     * @return array[] Array of per-product audit records (one associative array per product).
     */
    public static function get_compiled_data( $force_refresh = false ) {
        $cache_key = 'cw_audit_data';
        $data = get_transient( $cache_key );
        
        if ( false === $data || $force_refresh ) {
            $core = new Cirrusly_Commerce_Core(); 
            $config = $core->get_global_config();
            $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
            $class_costs = json_decode( $config['class_costs_json'], true );
            
            // Payment Fee Logic
            $mode = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';
            $pay_pct = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
            $pay_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
            $pay_pct_2 = isset($config['payment_pct_2']) ? ($config['payment_pct_2'] / 100) : 0.0349;
            $pay_flat_2 = isset($config['payment_flat_2']) ? $config['payment_flat_2'] : 0.49;
            $split = isset($config['profile_split']) ? ($config['profile_split'] / 100) : 1.0;

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
                
                $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
                
                // --- Financials ---
                $cost = (float)$p->get_meta('_cogs_total_value');
                $ship_cost = (float)$p->get_meta('_cw_est_shipping');
                
                // --- Custom Pricing Fields ---
                $map = (float)$p->get_meta('_cirrusly_map_price');
                $min_price = (float)$p->get_meta('_auto_pricing_min_price');
                $msrp = (float)$p->get_meta('_alg_msrp');
                
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
                $rev = $is_shipping_exempt ? 0 : $get_rev($price);
                
                $total_inc = $price + $rev;
                $total_cost = $cost + $ship_cost;
                $margin = 0; $net = 0;
                
                if($price > 0 && $total_cost > 0) {
                    $gross = $total_inc - $total_cost;
                    $margin = ($gross/$price)*100;
                    
                    if ( $mode === 'multi' ) {
                        $fee1 = ($total_inc * $pay_pct) + $pay_flat;
                        $fee2 = ($total_inc * $pay_pct_2) + $pay_flat_2;
                        $fee = ($fee1 * $split) + ($fee2 * (1 - $split));
                    } else {
                        $fee = ($total_inc * $pay_pct) + $pay_flat;
                    }
                    $net = $gross - $fee;
                }
                
                $alerts = array();
                if($cost <= 0) $alerts[] = '<a href="'.esc_url(get_edit_post_link($pid)).'" target="_blank" class="gmc-badge" style="background:#d63638;color:#fff;text-decoration:none;">Add Cost</a>';
                
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
                    'cats' => wp_get_post_terms($pid, 'product_cat', array('fields'=>'slugs')),
                    // Export Data
                    'map' => $map,
                    'min_price' => $min_price,
                    'msrp' => $msrp
                );
            }
            set_transient( $cache_key, $data, 1 * HOUR_IN_SECONDS );
        }
        return $data;
    }

    /**
     * Compute key financial metrics for a single product ID for quick display or AJAX retrieval.
     *
     * Returns per-product values used by the audit UI: net profit, formatted net HTML and style,
     * margin (string) and numeric margin percent, formatted shipping profit/loss, and formatted total cost.
     *
     * @param int $pid The WooCommerce product ID to evaluate.
     * @return array|false An associative array with keys:
     *                     - 'net_val' (float): net profit value (price minus costs and fees),
     *                     - 'net_html' (string): formatted net profit using wc_price(),
     *                     - 'net_style' (string): inline CSS style for net display (color/weight),
     *                     - 'margin' (string): margin percent formatted to one decimal place,
     *                     - 'margin_val' (float): numeric margin percent,
     *                     - 'ship_pl_html' (string): formatted shipping profit/loss using wc_price(),
     *                     - 'cost_html' (string): formatted total cost (item + shipping) using wc_price().
     *                     Returns false if the product cannot be loaded.
     */
    public static function get_single_metric( $pid ) {
        // ... (Kept original logic for single metric AJAX if needed, 
        // usually this function isn't used for the bulk export/import flow) ...
        $p = wc_get_product($pid);
        if(!$p) return false;

        $core = new Cirrusly_Commerce_Core(); 
        $config = $core->get_global_config();
        
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $class_costs   = json_decode( $config['class_costs_json'], true );
        $mode = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';
        $pay_pct       = isset($config['payment_pct']) ? ($config['payment_pct'] / 100) : 0.029;
        $pay_flat      = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        $pay_pct_2     = isset($config['payment_pct_2']) ? ($config['payment_pct_2'] / 100) : 0.0349;
        $pay_flat_2    = isset($config['payment_flat_2']) ? $config['payment_flat_2'] : 0.49;
        $split         = isset($config['profile_split']) ? ($config['profile_split'] / 100) : 1.0;
        
        $is_shipping_exempt = $p->is_virtual() || $p->is_downloadable();
        
        $cost = (float)$p->get_meta('_cogs_total_value');
        $ship_cost = (float)$p->get_meta('_cw_est_shipping');
        
        if($ship_cost <= 0 && !$is_shipping_exempt) {
            $cid = $p->get_shipping_class_id();
            $slug = ($cid && ($t=get_term($cid,'product_shipping_class'))) ? $t->slug : 'default';
            if ( $class_costs && isset($class_costs[$slug]) ) { $ship_cost = $class_costs[$slug]; } 
            elseif ( $class_costs && isset($class_costs['default']) ) { $ship_cost = $class_costs['default']; } else { $ship_cost = 0; }
        } elseif ( $is_shipping_exempt ) {
            $ship_cost = 0;
        }

        $price = (float)$p->get_price();
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
           
            if ( $mode === 'multi' ) {
                $fee1 = ($total_inc * $pay_pct) + $pay_flat;
                $fee2 = ($total_inc * $pay_pct_2) + $pay_flat_2;
                $fee = ($fee1 * $split) + ($fee2 * (1 - $split));
            } else {
                $fee = ($total_inc * $pay_pct) + $pay_flat;
            }
            $net = $gross - $fee;
        }
        
        $ship_pl = $rev - $ship_cost;
        $net_style = $net < 0 ? 'color:#d63638;font-weight:bold;' : 'color:#008a20;font-weight:bold;';
        
        return array(
            'net_val' => $net,
            'net_html' => wc_price($net),
            'net_style' => $net_style,
            'margin' => number_format($margin, 1),
            'margin_val' => $margin,
            'ship_pl_html' => wc_price($ship_pl), 
            'cost_html' => wc_price($total_cost) 
        );
    }

    /**
     * Stream the compiled audit data as a CSV download when the audit admin page requests an export.
     *
     * This action checks for the admin page parameter `page=cirrusly-audit` and `action=export_csv`, verifies the current user can `edit_products` and that the site is Pro, then sends CSV headers and streams the compiled audit rows (ID, product name, type, cost, shipping cost, price, net profit, margin %, MAP, Google Min, MSRP) to php://output. Execution is terminated after the CSV is written. If the user lacks permission the method returns early; if not Pro, execution is halted via wp_die().
     */
    public static function handle_export() {
        if ( isset($_GET['page']) && $_GET['page'] === 'cirrusly-audit' && isset($_GET['action']) && $_GET['action'] === 'export_csv' ) {
            if ( ! current_user_can( 'edit_products' ) ) return;
            
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                wp_die('Export is a Pro feature.');
            }

            $data = self::get_compiled_data();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="store-audit-' . date('Y-m-d') . '.csv"');
            
            $fp = fopen('php://output', 'w');
            fputcsv($fp, array('ID', 'Product Name', 'Type', 'Cost (COGS)', 'Shipping Cost', 'Price', 'Net Profit', 'Margin %', 'MAP', 'Google Min', 'MSRP'));
            
            foreach ( $data as $row ) {
                fputcsv($fp, array(
                    $row['id'],
                    $row['name'],
                    $row['type'],
                    $row['item_cost'],
                    $row['ship_cost'],
                    $row['price'],
                    $row['net'],
                    number_format($row['margin'], 2) . '%',
                    $row['map'] > 0 ? $row['map'] : '',
                    $row['min_price'] > 0 ? $row['min_price'] : '',
                    $row['msrp'] > 0 ? $row['msrp'] : ''
                ));
            }
            fclose($fp);
            exit;
        }
    }

    /**
     * Import product COGS and extra pricing fields from an uploaded CSV file.
     *
     * Processes the uploaded file named "csv_import", using the CSV header row to map column names to indexes
     * (strips a UTF-8 BOM from the first header cell and ignores blank headers). Requires the user to have the
     * 'edit_products' capability and the plugin Pro version; on failure a settings error is registered.
     *
     * The CSV must include an "ID" column. For each row with a valid product ID, the method conditionally updates
     * post meta when the corresponding column is present and non-empty:
     *  - "Cost (COGS)" -> _cogs_total_value (formatted as a decimal)
     *  - "MAP"         -> _cirrusly_map_price
     *  - "Google Min"  -> _auto_pricing_min_price
     *  - "MSRP"        -> _alg_msrp
     *
     * Rows for missing or invalid product IDs are skipped. After processing the file the audit transient
     * 'cw_audit_data' is cleared and a settings message is added indicating how many products were updated.
     */
    public static function handle_import() {
        if ( isset($_FILES['csv_import']) && current_user_can('edit_products') ) {
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Import is a Pro feature.', 'error');
                return;
            }

            if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
                ini_set('auto_detect_line_endings', true);
            }

            $file = $_FILES['csv_import']['tmp_name'];
            if ( ! $file || ! is_readable( $file ) ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'Could not read uploaded CSV file.', 'error' );
                return;
            }
            $handle = fopen( $file, 'r' );
            if ( ! $handle ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'Failed to open uploaded CSV file.', 'error' );
                return;
            }
            
            // 1. Get Header Row & Map Indices
            $header = fgetcsv($handle);
            if ( ! $header ) {
                fclose($handle);
                add_settings_error( 'cirrusly_audit', 'import_fail', 'CSV file is empty or has no header row.', 'error' );

                return;
            }

             // Normalize headers (trim spaces) and strip possible UTF‑8 BOM from the first column.
             $map = array();
             foreach ( $header as $index => $col_name ) {
                 $col_name = (string) $col_name;

                 if ( 0 === $index ) {
                     // Remove UTF‑8 BOM if present.
                     $col_name = preg_replace( '/^\xEF\xBB\xBF/', '', $col_name );
                 }

                 $key = trim( $col_name );
                 if ( '' === $key ) {
                     continue; // Ignore blank header cells.
                 }

                 $map[ $key ] = $index;
             }
            // Normalize headers (trim spaces) and strip possible UTF‑8 BOM from the first column.
            $map = array();
            foreach ( $header as $index => $col_name ) {
                $col_name = (string) $col_name;

                if ( 0 === $index ) {
                    // Remove UTF‑8 BOM if present.
                    $col_name = preg_replace( '/^\xEF\xBB\xBF/', '', $col_name );
                }

                $key = trim( $col_name );
                if ( '' === $key ) {
                    continue; // Ignore blank header cells.
                }

                $map[ $key ] = $index;
            }

            // Ensure we at least have an ID column
            if ( ! isset($map['ID']) ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Invalid CSV: Missing "ID" column.', 'error');
                fclose($handle);
                return;
            }

            $count = 0;
            
            while (($row = fgetcsv($handle)) !== FALSE) {
                // Get Product ID
                $id_idx = $map['ID'];
                if ( empty($row[$id_idx]) ) continue;
                
                $pid = intval( $row[ $id_idx ] );
                if ( ! $pid ) {
                    continue;
                }
                if ( ! get_post( $pid ) ) {
                    continue; // Skip rows for non-existent IDs.
                }

                $updated = false;

                // Helper to update if column exists and value is not empty
                $update_field = function( $key, $meta_key, $is_price = true ) use ($row, $map, $pid, &$updated) {
                    if ( isset($map[$key]) && array_key_exists($map[$key], $row) && $row[$map[$key]] !== '' ) {
                        $val = $row[$map[$key]];
                        if ( $is_price ) $val = wc_format_decimal($val);
                        update_post_meta($pid, $meta_key, $val);
                        $updated = true;
                    }
                };

                // Update Fields based on Column Names
                $update_field('Cost (COGS)', '_cogs_total_value');
                $update_field('MAP', '_cirrusly_map_price');
                $update_field('Google Min', '_auto_pricing_min_price');
                $update_field('MSRP', '_alg_msrp');

                if ( $updated ) $count++;
            }
            fclose($handle);
            delete_transient( 'cw_audit_data' ); 
            add_settings_error('cirrusly_audit', 'import_success', "Updated data for $count products.", 'success');
        }
    }

/**
     * Render the Store Financial Audit admin page.
     */
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
                    <a href="?page=cirrusly-audit&refresh_audit=1" class="button" title="Refresh Data from DB">Refresh Data</a>
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

        // NEW: PRO TOOLS CARD (Moved to Bottom)
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