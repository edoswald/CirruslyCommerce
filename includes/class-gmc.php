<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC {

    public function __construct() {
        add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_gmc_product_settings' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
        add_filter( 'manage_edit-product_columns', array( $this, 'add_gmc_admin_columns' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_gmc_admin_columns' ), 10, 2 );
        add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'admin_footer', array( $this, 'render_quick_edit_script' ) );
    }

    public static function render_page() {
        $instance = new self();
        $instance->render_gmc_hub_page();
    }

    public function render_gmc_hub_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'scan';
        ?>
        <div class="wrap">
            <?php Cirrusly_Commerce_Core::render_global_header( 'GMC Hub' ); ?>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=cirrusly-gmc&tab=scan" class="nav-tab <?php echo 'scan'===$tab?'nav-tab-active':''; ?>">Health Check</a>
                <a href="?page=cirrusly-gmc&tab=promotions" class="nav-tab <?php echo 'promotions'===$tab?'nav-tab-active':''; ?>">Promotion Manager</a>
                <a href="?page=cirrusly-gmc&tab=content" class="nav-tab <?php echo 'content'===$tab?'nav-tab-active':''; ?>">Site Content</a>
            </nav>
            <br>
            <?php 
            if ( 'promotions' === $tab ) {
                 $this->render_promotions_view();
            } elseif ( 'content' === $tab ) {
                 $this->render_content_scan_view();
            } else {
                 $this->render_scan_view();
            }
            ?>
        </div>
        <?php
    }

    private function get_monitored_terms() {
        // Scope: 'title' (only check titles), 'all' (check title + content)
        return array(
            'promotional' => array(
                'free shipping' => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Allowed in descriptions but prohibited in titles.'),
                'sale'          => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Prohibited in titles. Use the "Sale Price" field instead.'),
                'buy one'       => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Promotional text. Use GMC Promotions feed for BOGO offers.'),
                'best price'    => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim. Google may flag this as "Misrepresentation".'),
                'cheapest'      => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim. Highly likely to cause "Misrepresentation" suspension.'),
            ),
            'medical' => array( 
                'cure'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim. Strictly prohibited for non-pharmacies.'),
                'heal'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim. Implies permanent fix.'),
                'virus'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim. Do not claim to prevent or treat viruses.'),
                'covid'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Sensitive event policy. Strictly regulated.'),
                'guaranteed'  => array('severity' => 'Medium',   'scope' => 'all', 'reason' => 'If you say "Guaranteed", you must have a clear money-back policy linked.')
            )
        );
    }

    private function render_content_scan_view() {
        echo '<div class="cc-manual-helper"><h4>Site Content Audit</h4><p>Google scans your site content for policy compliance. We check for key policies (Refunds, TOS) and restricted claims. <br><strong>Note:</strong> We now use smart detection to ignore words inside other words (e.g., "Secure" won\'t flag "Cure").</p></div>';
        
        // --- 1. REQUIRED POLICIES (Green Check / Red X) ---
        $all_pages = get_pages();
        $found_titles = array();
        foreach($all_pages as $p) $found_titles[] = strtolower($p->post_title);
        
        $required = array(
            'Refund/Return Policy' => array('refund', 'return'),
            'Terms of Service'     => array('terms', 'conditions', 'tos'),
            'Contact Page'         => array('contact', 'support'),
            'Privacy Policy'       => array('privacy')
        );

        echo '<h3 style="margin-top:0;">Required Policies</h3><div class="cc-policy-grid">';
        foreach($required as $label => $keywords) {
            $found = false;
            foreach($found_titles as $title) {
                foreach($keywords as $kw) {
                    if(strpos($title, $kw) !== false) { $found = true; break 2; }
                }
            }
            $cls = $found ? 'cc-policy-ok' : 'cc-policy-fail';
            $icon = $found ? 'dashicons-yes' : 'dashicons-no';
            echo '<div class="cc-policy-item '.esc_attr($cls).'"><span class="dashicons '.esc_attr($icon).'"></span> '.esc_html($label).'</div>';
        }
        echo '</div>';

        // --- 2. RESTRICTED TERMS SCAN ---
        echo '<div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-bottom:20px; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">Restricted Terms Scan</h3>
                <form method="post" style="margin:0;">';
        wp_nonce_field( 'cirrusly_content_scan', 'cc_content_scan_nonce' );
        echo '<input type="hidden" name="run_content_scan" value="1">';
        submit_button('Run New Scan', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_content_scan'] ) && check_admin_referer( 'cirrusly_content_scan', 'cc_content_scan_nonce' ) ) {
            $issues = $this->execute_content_scan_logic();
            update_option( 'cirrusly_content_scan_data', array('timestamp'=>time(), 'issues'=>$issues) );
            echo '<div class="notice notice-success inline" style="margin-top:10px;"><p>Scan Complete. Results saved.</p></div>';
        }

        $data = get_option( 'cirrusly_content_scan_data' );
        if ( !empty($data) && !empty($data['issues']) ) {
            echo '<p><strong>Last Scan:</strong> ' . esc_html( date_i18n( get_option('date_format').' '.get_option('time_format'), $data['timestamp'] ) ) . '</p>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Type</th><th>Title</th><th>Flagged Terms</th><th>Action</th></tr></thead><tbody>';
            foreach($data['issues'] as $issue) {
                echo '<tr>
                    <td>'.esc_html(ucfirst($issue['type'])).'</td>
                    <td><strong>'.esc_html($issue['title']).'</strong></td>
                    <td>';
                    foreach($issue['terms'] as $t) {
                        $color = ($t['severity'] == 'Critical') ? '#d63638' : '#dba617';
                        echo '<span class="gmc-badge" style="background:'.esc_attr($color).';color:#fff;cursor:help;" title="'.esc_attr($t['reason']).'">'.esc_html($t['word']).'</span> '; 
                    }
                    echo '</td>
                    <td><a href="'.esc_url(get_edit_post_link($issue['id'])).'" class="button button-small" target="_blank">Edit</a></td>
                </tr>';
            }
            echo '</tbody></table>';
        } elseif( !empty($data) ) {
            echo '<p style="margin-top:10px; color:#008a20;">✔ No restricted terms found in last scan.</p>';
        } else {
            echo '<p style="margin-top:10px;">No scan history found.</p>';
        }
        echo '</div>';
    }

    private function execute_content_scan_logic() {
        $monitored = $this->get_monitored_terms();
        $args = array('post_type' => array('post', 'page', 'product'), 'post_status' => 'publish', 'posts_per_page' => -1);
        $posts = get_posts($args);
        $issues = array();

        foreach($posts as $post) {
            $found_terms = array();
            
            // Text to Scan
            $title = $post->post_title;
            // Fix: Use wp_strip_all_tags instead of strip_tags
            $content = wp_strip_all_tags($post->post_content); 
            
            // 1. Term Scanning
            foreach ($monitored as $category => $terms) {
                foreach ($terms as $word => $rules) {
                    $pattern = '/\b' . preg_quote($word, '/') . '\b/i'; // Word Boundary Regex
                    $found = false;

                    if ( $rules['scope'] === 'title' ) {
                        // Strict mode: Only flag if in title
                        if ( preg_match($pattern, $title) ) $found = true;
                    } else {
                        // Global mode: Check title OR content
                        if ( preg_match($pattern, $title . ' ' . $content) ) $found = true;
                    }

                    if ( $found ) {
                        $found_terms[] = array(
                            'word' => $word,
                            'reason' => $rules['reason'],
                            'severity' => $rules['severity']
                        );
                    }
                }
            }

            // 2. Gimmick Scanning (Titles Only)
            if ( strlen($title) > 5 && ctype_upper(preg_replace('/[^a-zA-Z]/', '', $title)) ) {
                $found_terms[] = array('word' => 'ALL CAPS', 'reason' => 'Excessive capitalization in title.', 'severity' => 'Medium');
            }
            if ( preg_match('/[!]{2,}/', $title) ) {
                $found_terms[] = array('word' => '!!!', 'reason' => 'Excessive punctuation in title.', 'severity' => 'Medium');
            }

            if(!empty($found_terms)) {
                $issues[] = array('id'=>$post->ID, 'title'=>$post->post_title, 'type'=>$post->post_type, 'terms'=>$found_terms);
            }
        }
        return $issues;
    }

    private function render_promotions_view() {
        echo '<div class="cc-manual-helper"><h4>Promotion Feed Generator</h4><p>Create a valid promotion entry for Google Merchant Center. Fill in the details, generate the code, and paste it into your Google Sheet feed.</p></div>';
        ?>
        <div class="cc-promo-generator">
            <h3 style="margin-top:0;">1. Create Promotion Entry</h3>
            <div class="cc-promo-grid">
                <div>
                    <label for="pg_id">Promotion ID <span class="dashicons dashicons-info" title="Unique ID (e.g. SUMMER_SALE_2025). Must match the ID used in product data."></span></label>
                    <input type="text" id="pg_id" placeholder="SUMMER_SALE">
                    
                    <label for="pg_title">Long Title <span class="dashicons dashicons-info" title="Customer-facing title (e.g. '20% Off All Summer Gear')"></span></label>
                    <input type="text" id="pg_title" placeholder="20% Off Summer Items">
                    
                    <label for="pg_dates">Dates <span class="dashicons dashicons-info" title="Format: YYYY-MM-DD/YYYY-MM-DD (Start/End)"></span></label>
                    <input type="text" id="pg_dates" placeholder="2025-06-01/2025-06-30">
                </div>
                <div>
                    <label for="pg_app">Product Applicability <span class="dashicons dashicons-info" title="Specific: Applies only to products with matching Promo ID. All: Applies to entire store."></span></label>
                    <select id="pg_app">
                        <option value="SPECIFIC_PRODUCTS">Specific Products (Mapped in Plugin)</option>
                        <option value="ALL_PRODUCTS">All Products</option>
                    </select>
                    
                    <label for="pg_type">Offer Type <span class="dashicons dashicons-info" title="No Code: Automatic discount. Generic Code: Requires user to enter code."></span></label>
                    <select id="pg_type">
                        <option value="NO_CODE">No Code Needed</option>
                        <option value="GENERIC_CODE">Generic Code</option>
                    </select>
                    
                    <label for="pg_code">Generic Code <span class="dashicons dashicons-info" title="Required if Offer Type is Generic Code."></span></label>
                    <input type="text" id="pg_code" placeholder="SAVE20">
                </div>
            </div>
            
            <div style="margin-top:15px;">
                <button type="button" class="button button-primary" id="pg_generate">Generate Code</button>
            </div>

            <div id="pg_result_area" style="display:none; margin-top:15px;">
                <span class="cc-copy-hint">Copy and paste this line into your Google Sheet:</span>
                <div id="pg_output" class="cc-generated-code"></div>
                <p style="font-size:11px; color:#666; margin-top:5px;"><strong>Columns:</strong> promotion_id, product_applicability, offer_type, long_title, promotion_effective_dates, redemption_channel, promotion_display_dates, generic_redemption_code</p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#pg_generate').click(function(){
                var id = $('#pg_id').val();
                var app = $('#pg_app').val();
                var type = $('#pg_type').val();
                var title = $('#pg_title').val();
                var dates = $('#pg_dates').val();
                var code = $('#pg_code').val();
                
                var str = id + ',' + app + ',' + type + ',' + title + ',' + dates + ',ONLINE,' + dates + ',' + (type==='GENERIC_CODE' ? code : '');
                
                $('#pg_output').text(str);
                $('#pg_result_area').fadeIn();
            });
        });
        </script>
        <?php

        // --- ACTIVE PROMOTIONS TABLE ---
        global $wpdb;
        
        // Handle Form Submission
        if ( isset( $_POST['gmc_promo_bulk_action'] ) && ! empty( $_POST['gmc_promo_products'] ) && check_admin_referer( 'cirrusly_promo_bulk', 'cc_promo_nonce' ) ) {
            $new_promo_id = isset($_POST['gmc_new_promo_id']) ? sanitize_text_field( wp_unslash( $_POST['gmc_new_promo_id'] ) ) : '';
            $action = sanitize_text_field( wp_unslash( $_POST['gmc_promo_bulk_action'] ) );
            
            // Sanitize array
            $promo_products = isset($_POST['gmc_promo_products']) && is_array($_POST['gmc_promo_products']) 
                ? array_map('intval', $_POST['gmc_promo_products']) 
                : array();

            $count = 0;
            foreach ( $promo_products as $pid ) {
                if ( 'update' === $action ) update_post_meta( $pid, '_gmc_promotion_id', $new_promo_id );
                elseif ( 'remove' === $action ) delete_post_meta( $pid, '_gmc_promotion_id' );
                $count++;
            }
            delete_transient( 'cirrusly_active_promos_stats' ); // Clear cache
            echo '<div class="notice notice-success inline"><p>Success! Updated ' . esc_html($count) . ' products.</p></div>';
        }

        // Cached Stats Query
        $promo_stats = get_transient( 'cirrusly_active_promos_stats' );
        if ( false === $promo_stats ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $promo_stats = $wpdb->get_results( "SELECT meta_value as promo_id, count(post_id) as count FROM {$wpdb->postmeta} WHERE meta_key = '_gmc_promotion_id' AND meta_value != '' GROUP BY meta_value ORDER BY count DESC" );
            set_transient( 'cirrusly_active_promos_stats', $promo_stats, 1 * HOUR_IN_SECONDS );
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_promo = isset( $_GET['view_promo'] ) ? sanitize_text_field( wp_unslash( $_GET['view_promo'] ) ) : '';

        echo '<h3>Active Promotions</h3>';
        if(empty($promo_stats)) echo '<p>No promotions found.</p>';
        else {
            echo '<table class="wp-list-table widefat fixed striped" style="max-width:600px;"><thead><tr><th>ID</th><th>Products Assigned</th><th>Action</th></tr></thead><tbody>';
            foreach($promo_stats as $stat) {
                echo '<tr>
                    <td><strong>'.esc_html($stat->promo_id).'</strong></td>
                    <td>'.esc_html($stat->count).'</td>
                    <td><a href="?page=cirrusly-gmc&tab=promotions&view_promo='.urlencode($stat->promo_id).'" class="button button-small">View Products</a></td>
                </tr>';
            }
            echo '</tbody></table>';
        }

        if ( $filter_promo ) {
            $products = get_posts( array( 'post_type'=>'product', 'posts_per_page'=>-1, 'meta_key'=>'_gmc_promotion_id', 'meta_value'=>$filter_promo ) );
            echo '<hr><h3>Managing: '.esc_html($filter_promo).'</h3>';
            echo '<form method="post">';
            wp_nonce_field( 'cirrusly_promo_bulk', 'cc_promo_nonce' );
            echo '<div style="background:#e5e5e5; padding:10px; margin-bottom:10px;">With Selected: <input type="text" name="gmc_new_promo_id" placeholder="New ID"> <button type="submit" name="gmc_promo_bulk_action" value="update" class="button">Move</button> <button type="submit" name="gmc_promo_bulk_action" value="remove" class="button">Remove</button></div>';
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th class="check-column"><input type="checkbox" id="cb-all-promo"></th><th>Name</th><th>Action</th></tr></thead><tbody>';
            foreach($products as $pObj) { 
                $p=wc_get_product($pObj->ID); 
                echo '<tr><th><input type="checkbox" name="gmc_promo_products[]" value="'.esc_attr($p->get_id()).'"></th><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'" class="button button-small">Edit</a></td></tr>'; 
            }
            echo '</tbody></table></form><script>jQuery("#cb-all-promo").change(function(){jQuery("input[name=\'gmc_promo_products[]\']").prop("checked",this.checked);});</script>';
        }
    }

    private function render_scan_view() {
        echo '<div class="cc-manual-helper"><h4>Health Check</h4><p>Scans product data for critical GMC issues like missing GTINs or prohibited titles. Click "Edit Product" to fix issues, then run a new scan.</p></div>';
        echo '<div style="background:#fff; padding:20px; border-bottom:1px solid #ccc;"><form method="post">';
        wp_nonce_field( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' );
        echo '<input type="hidden" name="run_gmc_scan" value="1">';
        submit_button('Run Diagnostics Scan', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_gmc_scan'] ) && check_admin_referer( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' ) ) {
            $results = $this->run_gmc_scan_logic();
            $scan_data = array( 'timestamp' => current_time( 'timestamp' ), 'results' => $results );
            update_option( 'woo_gmc_scan_data', $scan_data, false );
            echo '<div class="notice notice-success inline"><p>Scan Complete. Results updated.</p></div>';
        }
        
        $scan_data = get_option( 'woo_gmc_scan_data' );
        if ( ! empty( $scan_data ) && isset( $scan_data['results'] ) && !empty($scan_data['results']) ) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Product</th><th>Issues</th><th>Action</th></tr></thead><tbody>';
            foreach($scan_data['results'] as $r) {
                $p=wc_get_product($r['product_id']); if(!$p) continue;
                $issues = ''; 
                foreach($r['issues'] as $i) {
                    $color = ($i['type'] === 'critical') ? '#d63638' : '#dba617';
                    $issues .= '<span class="gmc-badge" style="background:'.esc_attr($color).'; color:#fff; padding:3px 8px; border-radius:10px; font-size:11px; margin-right:5px;">'.esc_html($i['msg']).'</span> ';
                }
                echo '<tr><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td>'.wp_kses_post($issues).'</td><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'" class="button button-small">Edit</a></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No issues found or scan not run.</p>';
        }
    }

    public function run_gmc_scan_logic() {
        $products = wc_get_products( array( 'status'=>'publish', 'limit'=>-1 ) );
        $report = array();
        foreach ( $products as $product ) {
            $issues = array();
            $needs_gtin = true;
            $id_ex = get_post_meta($product->get_id(), '_gla_identifier_exists', true);
            if('no'===$id_ex) $needs_gtin = false;
            
            // Check GTIN
            if($needs_gtin) {
                $has_gtin = false;
                foreach(array('_gtin', '_global_unique_id', '_ean', '_upc') as $k) { 
                    if(get_post_meta($product->get_id(), $k, true)) $has_gtin = true; 
                }
                if(!$has_gtin) $issues[] = array('type'=>'critical', 'msg'=>'Missing GTIN');
            }

            // Expanded Checks: Description Length
            $desc_len = strlen(strip_tags($product->get_description()));
            if($desc_len < 30) $issues[] = array('type'=>'warning', 'msg'=>'Desc Too Short');
            if($desc_len > 5000) $issues[] = array('type'=>'warning', 'msg'=>'Desc Too Long');

            // Expanded Checks: Image Filename
            $img_id = $product->get_image_id();
            if($img_id) {
                $url = wp_get_attachment_url($img_id);
                $filename = basename($url);
                if(preg_match('/(watermark|logo|promo)/i', $filename)) {
                    $issues[] = array('type'=>'warning', 'msg'=>'Suspicious Image Name');
                }
            }
            
            if(!empty($issues)) $report[] = array('product_id'=>$product->get_id(), 'issues'=>$issues);
        }
        return $report;
    }

    public function render_gmc_product_settings() {
        global $post;
        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__( 'Google Merchant Center Attributes', 'cirrusly-commerce' ) . '</strong></p>';
        $current_val = get_post_meta( $post->ID, '_gla_identifier_exists', true );
        woocommerce_wp_checkbox( array( 'id' => 'gmc_is_custom_product', 'label' => 'Custom Product?', 'description' => 'No GTIN/Barcode', 'value' => ('no'===$current_val?'yes':'no'), 'cbvalue' => 'yes' ) );
        woocommerce_wp_text_input( array( 'id' => '_gmc_promotion_id', 'label' => 'Promotion ID' ) );
        woocommerce_wp_text_input( array( 'id' => '_gmc_custom_label_0', 'label' => 'Custom Label 0' ) );
        echo '</div>';
    }

    public function save_product_meta( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $val = isset( $_POST['gmc_is_custom_product'] ) ? 'no' : 'yes';
        update_post_meta( $post_id, '_gla_identifier_exists', $val );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_gmc_promotion_id'] ) ) update_post_meta( $post_id, '_gmc_promotion_id', sanitize_text_field( wp_unslash( $_POST['_gmc_promotion_id'] ) ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_gmc_custom_label_0'] ) ) update_post_meta( $post_id, '_gmc_custom_label_0', sanitize_text_field( wp_unslash( $_POST['_gmc_custom_label_0'] ) ) );
        
        // Clear cache on save
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    public function add_gmc_admin_columns( $columns ) {
        $columns['gmc_status'] = 'GMC Data';
        return $columns;
    }

    public function render_gmc_admin_columns( $column, $post_id ) {
        if ( 'gmc_status' !== $column ) return;
        $id_ex = get_post_meta( $post_id, '_gla_identifier_exists', true );
        $promo = get_post_meta( $post_id, '_gmc_promotion_id', true );
        $label = get_post_meta( $post_id, '_gmc_custom_label_0', true );
        
        if ( 'no' === $id_ex ) echo '<span class="gmc-badge gmc-badge-custom">Custom</span> ';
        if ( $promo ) echo '<span class="gmc-badge gmc-badge-promo" title="'.esc_attr($promo).'">Promo</span> ';
        if ( $label ) echo '<span class="gmc-badge gmc-badge-label" title="'.esc_attr($label).'">Label</span> ';
        
        echo '<span class="gmc-hidden-data" 
            data-custom="'.('no'===$id_ex?'yes':'no').'" 
            data-promo="'.esc_attr($promo).'" 
            data-label="'.esc_attr($label).'" 
            style="display:none;"></span>';
    }
    
    public function render_quick_edit_box( $column_name, $post_type ) {
        if ( 'gmc_status' !== $column_name || 'product' !== $post_type ) return;
        ?>
        <fieldset class="inline-edit-col-right inline-edit-gmc">
            <div class="inline-edit-col">
                <h4>GMC Data</h4>
                <label class="alignleft"><input type="checkbox" name="gmc_is_custom_product" value="yes"><span class="checkbox-title">Custom Product? (No GTIN)</span></label>
            </div>
        </fieldset>
        <?php
    }

    public function save_quick_bulk_edit( $product ) {
        $post_id = $product->get_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_REQUEST['gmc_is_custom_product'] ) ) update_post_meta( $post_id, '_gla_identifier_exists', 'no' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        elseif ( isset( $_REQUEST['woocommerce_quick_edit'] ) && ! isset( $_REQUEST['bulk_edit'] ) ) update_post_meta( $post_id, '_gla_identifier_exists', 'yes' );
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    public function render_quick_edit_script() {
        global $pagenow; if('edit.php'!==$pagenow || 'product'!==get_post_type()) return;
        ?>
        <script>jQuery(function($){ var $wp_inline_edit = inlineEditPost.edit; inlineEditPost.edit = function( id ) { $wp_inline_edit.apply( this, arguments ); var pid = typeof(id)=='object'?parseInt(this.getId(id)):parseInt(id); if(pid>0){ var $row=$('#post-'+pid), $h=$row.find('.gmc-hidden-data'), $e=$('#edit-'+pid); $e.find('input[name="gmc_is_custom_product"]').prop('checked', 'yes'===$h.data('custom')); } }; });</script>
        <?php
    }
}
