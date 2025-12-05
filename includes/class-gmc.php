<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC {

    /**
     * Initialize GMC integration by registering WordPress and WooCommerce hooks and filters.
     *
     * Registers all admin UI, saving, quick-edit, bulk-edit, compliance, auto-strip, AJAX and admin action handlers
     * required for Google Merchant Center related features (product meta UI, admin columns, quick edit behavior,
     * promo submission endpoint, content/product scanning hooks, and save-time compliance enforcement).
     */
    public function __construct() {
        add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_gmc_product_settings' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
        add_filter( 'manage_edit-product_columns', array( $this, 'add_gmc_admin_columns' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_gmc_admin_columns' ), 10, 2 );
        add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'admin_footer', array( $this, 'render_quick_edit_script' ) );

        // Handle "Mark as Custom" action
        add_action( 'admin_post_cc_mark_custom', array( $this, 'handle_mark_custom' ) );
        
        // Block Save on Critical Error (Pro Feature)
        add_action( 'save_post_product', array( $this, 'check_compliance_on_save' ), 10, 3 );
        
        // NEW: Auto-strip Banned Words (Pro Feature)
        add_filter( 'wp_insert_post_data', array( $this, 'handle_auto_strip_on_save' ), 10, 2 );

        // NEW: AJAX for Promo Submit
        add_action( 'wp_ajax_cc_submit_promo_to_gmc', array( $this, 'handle_promo_api_submit' ) );
    }

    /**
     * Instantiate this class and display the Google Merchant Center hub page in the admin.
     */
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

    /**
     * Provide the set of monitored terms grouped by category for content and product scans.
     *
     * Each category maps monitored term strings to a metadata array describing why the term is flagged.
     *
     * @return array Associative array where keys are category names (e.g., 'promotional', 'medical') and values are
     *               arrays mapping term => metadata. Metadata arrays contain:
     *               - 'severity' (string): severity label such as 'Critical', 'High', or 'Medium'.
     *               - 'scope' (string): where to check the term, e.g., 'title' or 'all'.
     *               - 'reason' (string): human-readable explanation for flagging the term.
     */
    private function get_monitored_terms() {
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

    /**
     * Renders the Site Content Audit UI and handles running and displaying content scan results.
     *
     * Outputs the "Required Policies" checklist and the "Restricted Terms Scan" form; when the scan form is submitted
     * and verified, runs the content scan, persists results to the `cirrusly_content_scan_data` option, and displays
     * the last scan's findings with per-item flagged terms and edit links.
     */
    private function render_content_scan_view() {
        // UPDATED: Description
        echo '<div class="cc-manual-helper">
            <h4>Site Content Audit</h4>
            <p>This tool scans your site pages and product descriptions to ensure compliance with Google Merchant Center policies. It checks for:</p>
            <ul style="list-style:disc; margin-left:20px; font-size:12px;">
                <li><strong>Required Policies:</strong> Verifies the existence of Refund, Privacy, and Terms of Service pages.</li>
                <li><strong>Restricted Terms:</strong> Detects medical claims or prohibited promotional text that may cause account suspension.</li>
            </ul>
        </div>';
        
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
            $title = $post->post_title;
            $content = wp_strip_all_tags($post->post_content); 
            
            foreach ($monitored as $category => $terms) {
                foreach ($terms as $word => $rules) {
                    $pattern = '/\b' . preg_quote($word, '/') . '\b/i'; // Word Boundary Regex
                    $found = false;

                    if ( $rules['scope'] === 'title' ) {
                        if ( preg_match($pattern, $title) ) $found = true;
                    } else {
                        if ( preg_match($pattern, $title . ' ' . $content) ) $found = true;
                    }

                    if ( $found ) {
                        $found_terms[] = array('word' => $word, 'reason' => $rules['reason'], 'severity' => $rules['severity']);
                    }
                }
            }

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

    /**
     * Render the Promotions tab UI for managing and submitting Google Merchant Center promotions.
     *
     * Outputs a Promotion Feed Generator form (fields for promotion ID, title, dates, applicability,
     * offer type and optional generic code) with client-side code to generate a single-line feed entry
     * and an AJAX "One-Click Submit to Google" action that is visually gated for PRO users.
     *
     * Also displays an Active Promotions table (cached in a transient) and, when a promotion is selected,
     * a management view listing products assigned to that promotion with bulk actions to move or remove
     * the promotion from selected products. Handles processing of the promo bulk-action POST request
     * (with nonce verification), updates product meta, clears the promo stats transient and displays a
     * success notice.
     *
     * The AJAX submit button uses a server nonce (`cc_promo_api_submit`) and is disabled for non-PRO users;
     * client-side validation ensures Promotion ID and Title are provided before sending.
     */
    private function render_promotions_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        echo '<div class="cc-manual-helper"><h4>Promotion Feed Generator</h4><p>Create a valid promotion entry for Google Merchant Center. Fill in the details, generate the code, and paste it into your Google Sheet feed.</p></div>';
        ?>
        <div class="cc-promo-generator">
            <h3 style="margin-top:0;">1. Create Promotion Entry</h3>
            <div class="cc-promo-grid">
                <div>
                    <label for="pg_id">Promotion ID <span class="dashicons dashicons-info" title="Unique ID"></span></label>
                    <input type="text" id="pg_id" placeholder="SUMMER_SALE">
                    <label for="pg_title">Long Title <span class="dashicons dashicons-info" title="Customer-facing title"></span></label>
                    <input type="text" id="pg_title" placeholder="20% Off Summer Items">
                    <label for="pg_dates">Dates <span class="dashicons dashicons-info" title="Format: YYYY-MM-DD/YYYY-MM-DD"></span></label>
                    <input type="text" id="pg_dates" placeholder="2025-06-01/2025-06-30">
                </div>
                <div>
                    <label for="pg_app">Product Applicability</label>
                    <select id="pg_app"><option value="SPECIFIC_PRODUCTS">Specific Products</option><option value="ALL_PRODUCTS">All Products</option></select>
                    <label for="pg_type">Offer Type</label>
                    <select id="pg_type"><option value="NO_CODE">No Code Needed</option><option value="GENERIC_CODE">Generic Code</option></select>
                    <label for="pg_code">Generic Code</label>
                    <input type="text" id="pg_code" placeholder="SAVE20">
                </div>
            </div>
            
            <div style="margin-top:15px; display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="button button-primary" id="pg_generate">Generate Code</button>
                
                <div class="<?php echo esc_attr($pro_class); ?>" style="display:flex; align-items:center; gap:10px;">
                    <span class="description" style="font-style:italic; font-size:12px;">
                        Directly push this promotion to your linked Merchant Center account. <br>Requires API connection in Settings.
                    </span>
                    <div style="position:relative;">
                        <?php if(!$is_pro): ?>
                        <div class="cc-pro-overlay">
                            <a href="<?php echo esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ); ?>" class="cc-upgrade-btn">
                               <span class="dashicons dashicons-lock cc-lock-icon"></span> Upgrade
                            </a>
                        </div>
                        <?php endif; ?>
                        <button type="button" class="button button-secondary" id="pg_api_submit" <?php echo esc_attr($disabled_attr); ?>>
                            <span class="dashicons dashicons-cloud-upload"></span> One-Click Submit to Google
                        </button>
                        <span class="cc-pro-badge">PRO</span>
                    </div>
                </div>
            </div>

            <div id="pg_result_area" style="display:none; margin-top:15px;">
                <span class="cc-copy-hint">Copy and paste this line into your Google Sheet:</span>
                <div id="pg_output" class="cc-generated-code"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            $('#pg_generate').click(function(){
                var id = $('#pg_id').val(), app = $('#pg_app').val(), type = $('#pg_type').val();
                var title = $('#pg_title').val(), dates = $('#pg_dates').val(), code = $('#pg_code').val();
                var str = id + ',' + app + ',' + type + ',' + title + ',' + dates + ',ONLINE,' + dates + ',' + (type==='GENERIC_CODE' ? code : '');
                $('#pg_output').text(str);
                $('#pg_result_area').fadeIn();
            });

            // Handle One-Click Submit
            $('#pg_api_submit').click(function(){
                var $btn = $(this);
                var originalText = $btn.html();
                
                // Basic Validation
                if( !$('#pg_id').val() || !$('#pg_title').val() ) {
                    alert('Please fill in Promotion ID and Title first.');
                    return;
                }

                $btn.prop('disabled', true).text('Sending...');

                $.post(ajaxurl, {
                    action: 'cc_submit_promo_to_gmc',
                    security: '<?php echo wp_create_nonce("cc_promo_api_submit"); ?>',
                    data: {
                        id: $('#pg_id').val(),
                        title: $('#pg_title').val(),
                        dates: $('#pg_dates').val(),
                        code: $('#pg_code').val()
                    }
                }, function(response) {
                    if(response.success) {
                        alert('Success! Promotion pushed to Google Merchant Center.');
                    } else {
                        alert('Error: ' + (response.data || 'Could not connect to API.'));
                    }
                    $btn.prop('disabled', false).html(originalText);
                });
            });
        });
        </script>
        <?php

        // ... (Active Promotions Table) ...
        global $wpdb;
        if ( isset( $_POST['gmc_promo_bulk_action'] ) && ! empty( $_POST['gmc_promo_products'] ) && check_admin_referer( 'cirrusly_promo_bulk', 'cc_promo_nonce' ) ) {
            $new_promo_id = isset($_POST['gmc_new_promo_id']) ? sanitize_text_field( wp_unslash( $_POST['gmc_new_promo_id'] ) ) : '';
            $action = sanitize_text_field( wp_unslash( $_POST['gmc_promo_bulk_action'] ) );
            $promo_products = isset($_POST['gmc_promo_products']) && is_array($_POST['gmc_promo_products']) ? array_map('intval', $_POST['gmc_promo_products']) : array();

            $count = 0;
            foreach ( $promo_products as $pid ) {
                if ( 'update' === $action ) update_post_meta( $pid, '_gmc_promotion_id', $new_promo_id );
                elseif ( 'remove' === $action ) delete_post_meta( $pid, '_gmc_promotion_id' );
                $count++;
            }
            delete_transient( 'cirrusly_active_promos_stats' );
            echo '<div class="notice notice-success inline"><p>Success! Updated ' . esc_html($count) . ' products.</p></div>';
        }

        $promo_stats = get_transient( 'cirrusly_active_promos_stats' );
        if ( false === $promo_stats ) {
            $promo_stats = $wpdb->get_results( "SELECT meta_value as promo_id, count(post_id) as count FROM {$wpdb->postmeta} WHERE meta_key = '_gmc_promotion_id' AND meta_value != '' GROUP BY meta_value ORDER BY count DESC" );
            set_transient( 'cirrusly_active_promos_stats', $promo_stats, 1 * HOUR_IN_SECONDS );
        }
        
        $filter_promo = isset( $_GET['view_promo'] ) ? sanitize_text_field( wp_unslash( $_GET['view_promo'] ) ) : '';

        echo '<h3>Active Promotions</h3>';
        if(empty($promo_stats)) echo '<p>No promotions found.</p>';
        else {
            echo '<table class="wp-list-table widefat fixed striped" style="max-width:600px;"><thead><tr><th>ID</th><th>Products Assigned</th><th>Action</th></tr></thead><tbody>';
            foreach($promo_stats as $stat) {
                echo '<tr><td><strong>'.esc_html($stat->promo_id).'</strong></td><td>'.esc_html($stat->count).'</td><td><a href="?page=cirrusly-gmc&tab=promotions&view_promo='.urlencode($stat->promo_id).'" class="button button-small">View Products</a></td></tr>';
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

    /**
     * Render the Health Check admin UI for Google Merchant Center integration.
     *
     * Displays Pro-gated automated compliance controls (block save on critical errors,
     * auto-strip banned words), a manual Diagnostics Scan form, and the latest scan results.
     * If a diagnostics scan is submitted with a valid nonce, runs the scan logic and stores
     * results in the `woo_gmc_scan_data` option. Scan results include per-product issues
     * with Edit and a "Mark Custom" action for products flagged with a missing GTIN.
     */
    private function render_scan_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        $scan_cfg = get_option('cirrusly_scan_config', array());
        $block_save = isset($scan_cfg['block_on_critical']) ? 'checked' : '';
        $auto_strip = isset($scan_cfg['auto_strip_banned']) ? 'checked' : '';

        echo '<div class="cc-manual-helper"><h4>Health Check</h4><p>Scans product data for critical GMC issues.</p></div>';
        
        // PRO: Auto-Fix Upsell
        echo '<div class="'.esc_attr($pro_class).'" style="background:#f0f6fc; padding:15px; border:1px solid #c3c4c7; margin-bottom:20px; position:relative;">';
            if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn">Upgrade to Automate</a></div>';
            
            echo '<form method="post" action="options.php">';
            settings_fields('cirrusly_general_group'); 
            echo '<strong>Automated Compliance <span class="cc-pro-badge">PRO</span></strong><br>
            <label><input type="checkbox" name="cirrusly_scan_config[block_on_critical]" value="yes" '.$block_save.' '.esc_attr($disabled_attr).'> Block Save on Critical Error</label>
            <label style="margin-left:10px;"><input type="checkbox" name="cirrusly_scan_config[auto_strip_banned]" value="yes" '.$auto_strip.' '.esc_attr($disabled_attr).'> Auto-strip Banned Words</label>';
            
            // NEW: Fire hook to render the Automated Discounts checkbox here
            do_action( 'cirrusly_commerce_scan_settings_ui' );

            echo '<br><br>
            <button type="submit" class="button button-small" '.esc_attr($disabled_attr).'>Save Rules</button>
            </form>';
        echo '</div>';



        // Scan Button
        echo '<div style="background:#fff; padding:20px; border-bottom:1px solid #ccc;"><form method="post">';
        wp_nonce_field( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' );
        echo '<input type="hidden" name="run_gmc_scan" value="1">';
        submit_button('Run Diagnostics Scan', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_gmc_scan'] ) && check_admin_referer( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' ) ) {
            $results = $this->run_gmc_scan_logic();
            update_option( 'woo_gmc_scan_data', array( 'timestamp' => current_time( 'timestamp' ), 'results' => $results ), false );
            echo '<div class="notice notice-success inline"><p>Scan Complete.</p></div>';
        }
        
        $scan_data = get_option( 'woo_gmc_scan_data' );
        if ( ! empty( $scan_data ) && !empty($scan_data['results']) ) {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Product</th><th>Issues</th><th>Action</th></tr></thead><tbody>';
            foreach($scan_data['results'] as $r) {
                $p=wc_get_product($r['product_id']); if(!$p) continue;
                $issues = ''; 
                foreach($r['issues'] as $i) {
                    $color = ($i['type'] === 'critical') ? '#d63638' : '#dba617';
                    // UPDATED: Tooltip implementation
                    $tooltip = isset($i['reason']) ? $i['reason'] : $i['msg'];
                    $issues .= '<span class="gmc-badge" style="background:'.esc_attr($color).'; color:#fff; cursor:help;" title="'.esc_attr($tooltip).'">'.esc_html($i['msg']).'</span> ';
                }
                
                // NEW: Mark as Custom Action
                $actions = '<a href="'.esc_url(get_edit_post_link($p->get_id())).'" class="button button-small">Edit</a> ';
                if ( strpos( $issues, 'Missing GTIN' ) !== false ) {
                    $url = wp_nonce_url( admin_url( 'admin-post.php?action=cc_mark_custom&pid=' . $p->get_id() ), 'cc_mark_custom_' . $p->get_id() );
                    $actions .= '<a href="'.esc_url($url).'" class="button button-small">Mark Custom</a>';
                }

                echo '<tr><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td>'.$issues.'</td><td>'.$actions.'</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    /**
     * Scan published products for GMC-related issues such as missing GTINs and monitored banned or promotional terms.
     *
     * Iterates all published products, flags products that are not marked as custom but lack a GTIN-like meta, and detects monitored medical (critical) and promotional (warning) terms in product titles. Returns an array of detected issues grouped by product ID.
     *
     * @return array[] Each element is an associative array with keys:
     *               - 'product_id' (int): The product post ID.
     *               - 'issues' (array[]): List of issue objects, each with:
     *                   - 'type' (string): 'critical' or 'warning'.
     *                   - 'msg' (string): Short message describing the issue (e.g., 'Missing GTIN', 'Restricted: cure').
     *                   - 'reason' (string): Explanation for why the term or issue was flagged.
     */
    public function run_gmc_scan_logic() {
        $issues_found = array();
        $args = array( 'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish' );
        $products = get_posts( $args );
        $monitored = $this->get_monitored_terms();

        foreach ( $products as $post ) {
            $p_issues = array();
            $pid = $post->ID;
            $title = $post->post_title;
            
            // 1. Check GTIN
            // If '_gla_identifier_exists' is NOT 'no' (default is yes), the product claims to have an identifier.
            // We verify if one exists (Assuming standard woo attributes or common fields like _gtin, _ean, or just SKU if strictly managed)
            // For this logic, we check if marked as custom.
            $is_custom = get_post_meta( $pid, '_gla_identifier_exists', true );
            
            // Note: In render_gmc_product_settings, checkbox 'gmc_is_custom_product' value is 'yes' if meta is 'no'. 
            // So if meta is 'no', it IS custom (No GTIN required). 
            // If meta is 'yes' or empty, it requires GTIN.
            if ( 'no' !== $is_custom ) {
                // Here we check for a hypothetical GTIN field. 
                // Since this plugin doesn't seem to add its own GTIN field, we'll check a common one or rely on the user to mark custom.
                // For demonstration, we'll flag it if we can't find a GTIN-like meta.
                $gtin = get_post_meta( $pid, '_gtin', true ); 
                if ( empty( $gtin ) ) {
                    // Fallback check: maybe it's using SKU as GTIN? Unlikely but possible.
                    // $sku = get_post_meta( $pid, '_sku', true );
                    $p_issues[] = array( 'type' => 'critical', 'msg' => 'Missing GTIN', 'reason' => 'Product is not marked as Custom (Identifier Exists) but lacks a GTIN.' );
                }
            }

            // 2. Check Banned Words in Title (using word boundaries)
            foreach ( $monitored['medical'] as $word => $rules ) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
                if ( preg_match( $pattern, $title ) ) {
                    $p_issues[] = array( 'type' => 'critical', 'msg' => 'Restricted: ' . $word, 'reason' => $rules['reason'] );
                }
            }
            foreach ( $monitored['promotional'] as $word => $rules ) {
                $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
                if ( $rules['scope'] === 'title' && preg_match( $pattern, $title ) ) {
                    $p_issues[] = array( 'type' => 'warning', 'msg' => 'Promo: ' . $word, 'reason' => $rules['reason'] );
                }
            }

            if ( ! empty( $p_issues ) ) {
                $issues_found[] = array( 'product_id' => $pid, 'issues' => $p_issues );
            }
        }
        return $issues_found;
    }

    /**
     * Marks a product as a custom product (no GTIN) and redirects back to the GMC scan tab.
     *
     * Verifies the current user can edit products and validates the admin nonce for the given
     * product ID taken from $_GET['pid']; sets the post meta '_gla_identifier_exists' to 'no'
     * for that product, then redirects to the Cirrusly GMC scan page and terminates execution.
     */
    public function handle_mark_custom() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die('No permission');
        $pid = intval( $_GET['pid'] );
        check_admin_referer( 'cc_mark_custom_' . $pid );
        
        update_post_meta( $pid, '_gla_identifier_exists', 'no' );
        wp_redirect( admin_url('admin.php?page=cirrusly-gmc&tab=scan&msg=custom_marked') );
        exit;
    }

    /****
     * Prevent publishing of products that contain critical (medical) terms when the block-on-critical option is enabled.
     *
     * When enabled in cirrusly_scan_config['block_on_critical'], this method checks a product's title for monitored
     * medical terms and, if any are found, forces the product into draft status to prevent it from being published.
     * The check is skipped during autosaves and for non-product post types.
     *
     * @param int     $post_id The ID of the post being saved.
     * @param WP_Post $post    The post object being saved.
     * @param bool    $update  Whether this is an existing post being updated (true) or a new post (false).
     */
    public function check_compliance_on_save( $post_id, $post, $update ) {
        // Only run if option enabled
        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['block_on_critical']) || $scan_cfg['block_on_critical'] !== 'yes' ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== 'product' ) return;

        // Quick check for banned words
        $monitored = $this->get_monitored_terms();
        foreach($monitored['medical'] as $word => $data) {
             if ( stripos($post->post_title, $word) !== false ) {
                 // We can't easily stop the save process in WP without throwing a die() or removing hooks, 
                 // which is bad UX. Instead, we force post_status back to draft to prevent publishing.
                 $post->post_status = 'draft';
                 wp_update_post( $post );
             }
        }
    }

    /**
     * Removes configured banned medical terms from a product's title and, when allowed, its content before saving.
     *
     * Iterates the monitored medical term list and strips whole-word, case-insensitive matches from post_title.
     * If a term's scope is "all", the term is also removed from post_content. Multiple whitespace is collapsed in the title after removals.
     *
     * @param array $data   The post data being saved (will be returned, possibly modified).
     * @param array $postarr The original post array passed to wp_insert_post.
     * @return array The potentially modified post data with banned medical terms removed where applicable.
     */
    public function handle_auto_strip_on_save( $data, $postarr ) {
        // Check if enabled
        $scan_cfg = get_option('cirrusly_scan_config', array());
        if ( empty($scan_cfg['auto_strip_banned']) || $scan_cfg['auto_strip_banned'] !== 'yes' ) return $data;
        
        // Only strictly monitored post types
        if ( $data['post_type'] !== 'product' ) return $data;

        $monitored = $this->get_monitored_terms();
        
        // Loop through banned terms (Medical/Critical only usually for stripping)
        foreach ( $monitored['medical'] as $word => $rules ) {
            // Case-insensitive replacement
            $pattern = '/\b' . preg_quote($word, '/') . '\b/i';
            
            // Strip from Title
            if ( preg_match( $pattern, $data['post_title'] ) ) {
                $data['post_title'] = preg_replace( $pattern, '', $data['post_title'] );
                // Clean up double spaces created by removal
                $data['post_title'] = trim( preg_replace('/\s+/', ' ', $data['post_title']) );
            }
            
            // Strip from Content (if scope allows)
            if ( $rules['scope'] === 'all' && preg_match( $pattern, $data['post_content'] ) ) {
                $data['post_content'] = preg_replace( $pattern, '', $data['post_content'] );
            }
        }
        
        return $data;
    }
    
    /**
     * Renders the Google Merchant Center attributes meta box controls on the product edit screen.
     *
     * Outputs form fields for marking a product as custom (no GTIN), for a Promotion ID, and for Custom Label 0.
     *
     * @return void
     */
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

    /**
     * Injects a small inline script on the Products list page that synchronizes the Quick Edit form's GMC fields
     * with the product's hidden GMC metadata in the row.
     *
     * The script runs only on the admin products listing and updates the "Custom Product" checkbox in Quick Edit
     * to reflect the row's stored GMC custom flag.
     */
    public function render_quick_edit_script() {
        global $pagenow; if('edit.php'!==$pagenow || 'product'!==get_post_type()) return;
        ?>
        <script>jQuery(function($){ var $wp_inline_edit = inlineEditPost.edit; inlineEditPost.edit = function( id ) { $wp_inline_edit.apply( this, arguments ); var pid = typeof(id)=='object'?parseInt(this.getId(id)):parseInt(id); if(pid>0){ var $row=$('#post-'+pid), $h=$row.find('.gmc-hidden-data'), $e=$('#edit-'+pid); $e.find('input[name="gmc_is_custom_product"]').prop('checked', 'yes'===$h.data('custom')); } }; });</script>
        <?php
    }

    /**
 * Create and return a configured Google API client for Google Shopping Content.
 *
 * Attempts to build a Google\Client using the service account JSON stored in
 * the `cirrusly_gmc_service_account_json` option and configures scopes for
 * the Google Shopping Content API.
 *
 * @return Google\Client|WP_Error Configured Google\Client on success, or a WP_Error with one of the following codes:
 *                                - 'missing_lib' if the Google PHP client library is not available.
 *                                - 'missing_creds' if the service account JSON option is empty.
 *                                - 'invalid_json' if the stored JSON cannot be decoded.
 *                                - 'auth_failed' if client configuration or initialization fails (message included).
 */
public static function get_google_client() {
    $json_key = get_option( 'cirrusly_gmc_service_account_json' ); 
    
    // Safety: Check if Composer loaded the class
    if ( ! class_exists( 'Google\Client' ) ) {
        return new WP_Error( 'missing_lib', 'Google Library not loaded. Run composer install.' );
    }

    if ( empty( $json_key ) ) {
        return new WP_Error( 'missing_creds', 'Missing Service Account JSON.' );
    }

    try {
        $client = new Google\Client();
        $client->setApplicationName( 'Cirrusly Commerce' );
        $auth_config = json_decode( $json_key, true );
        if ( null === $auth_config ) {
            return new WP_Error( 'invalid_json', 'Service Account JSON is malformed.' );
        }
        $client->setAuthConfig( $auth_config );
        $client->setScopes([
            'https://www.googleapis.com/auth/content',
        ]);
        
        return $client;
    } catch ( Exception $e ) {
        return new WP_Error( 'auth_failed', 'Auth Error: ' . $e->getMessage() );
    }
}

/**
 * Handles an AJAX request to submit a Promotion to the Google Shopping Content API.
 *
 * Validates the AJAX nonce and PRO entitlement, obtains a configured Google client and the
 * merchant ID, builds a Promotion object from POSTed data (at minimum `id` and `title`),
 * and submits it to the Merchant Center via the ShoppingContent service. Responds with
 * JSON success on successful submission or JSON error messages for validation, configuration,
 * client, or API failures.
 */
public function handle_promo_api_submit() {
    check_ajax_referer( 'cc_promo_api_submit', 'security' );
    
    if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
        wp_send_json_error( 'Pro version required for API access.' );
    }

    $client = self::get_google_client();
    if ( is_wp_error( $client ) ) wp_send_json_error( $client->get_error_message() );

    $merchant_id = get_option( 'cirrusly_gmc_merchant_id' );
    if ( empty( $merchant_id ) ) {
        wp_send_json_error( 'Merchant ID not configured.' );
    }

    // Extract POST data
    $data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
    $data = is_array( $data ) ? $data : array();
    $id    = isset( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '';
    $title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

    if ( '' === $id || '' === $title ) {
        wp_send_json_error( 'Promotion ID and Title are required.' );
    }
    
    // Initialize the Service
    $service = new Google\Service\ShoppingContent( $client );

    try {
        // Create Promotion Object
        $promo = new Google\Service\ShoppingContent\Promotion();
        $promo->setPromotionId( $id );
        $promo->setLongTitle( $title );
        $promo->setContentLanguage( 'en' ); // Should ideally be dynamic
        $promo->setTargetCountry( 'US' );   // Should ideally be dynamic
        $promo->setRedemptionChannel( array( 'ONLINE' ) );

        // 1. Parse Dates (Format: YYYY-MM-DD/YYYY-MM-DD) received from JS
        $dates_raw = isset( $data['dates'] ) ? sanitize_text_field( $data['dates'] ) : '';
        if ( ! empty( $dates_raw ) && strpos( $dates_raw, '/' ) !== false ) {
            list( $start_str, $end_str ) = explode( '/', $dates_raw );
            // Validate date format
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_str ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_str ) ) {
              wp_send_json_error( 'Invalid date format. Expected YYYY-MM-DD/YYYY-MM-DD.' );
            }
            $period = new Google\Service\ShoppingContent\PromotionPromotionStatusDateRange();
            // Google expects ISO 8601 (e.g. 2025-06-01T00:00:00Z)
            $period->setDateRange( $start_str . 'T00:00:00Z/' . $end_str . 'T23:59:59Z' );
            $promo->setPromotionEffectiveTimePeriod( $period );
        }

        // 2. Product Applicability
        $app_val = isset( $data['app'] ) ? sanitize_text_field( $data['app'] ) : 'ALL_PRODUCTS';
        $promo->setProductApplicability( $app_val );

        // 3. Offer Type & Generic Code
        $type_val = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'NO_CODE';
        $promo->setOfferType( $type_val );
        
        if ( 'GENERIC_CODE' === $type_val && ! empty( $data['code'] ) ) {
            $promo->setGenericRedemptionCode( sanitize_text_field( $data['code'] ) );
        }

        // Send to Google
        $service->promotions->create( $merchant_id, $promo );

        wp_send_json_success( 'Promotion submitted successfully!' );
    } catch ( Exception $e ) {
        wp_send_json_error( 'Google Error: ' . $e->getMessage() );
    }
}

   
}