<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_GMC_UI {

    /**
     * Initialize the Google Merchant Center admin UI by registering WordPress and WooCommerce admin hooks and filters.
     *
     * Registers column, column-rendering, product-settings, quick-edit, and admin-notice hooks used by the
     * Google Merchant Center integration UI.
     */
    public function __construct() {
        add_filter( 'manage_edit-product_columns', array( $this, 'add_gmc_admin_columns' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_gmc_admin_columns' ), 10, 2 );
        add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_gmc_product_settings' ) );
        add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'render_blocked_save_notice' ) );
    }

    /**
     * Render the Google Merchant Center admin hub page with tabbed navigation.
     *
     * Displays the hub header, a three-tab navigation (Health Check, Promotion Manager, Site Content),
     * and delegates rendering to the corresponding view for the currently selected tab.
     */
    public function render_gmc_hub_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'scan';
        ?>
        <div class="wrap">
            <?php Cirrusly_Commerce_Core::render_global_header( 'Compliance Hub' ); ?>
            
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
     * Render the Health Check UI for Google Merchant Center and present scan results and automation rules.
     *
     * Renders a diagnostic scan form, displays saved scan results from the `woo_gmc_scan_data` option,
     * and shows the Automation & Workflow Rules panel which stores settings in the `cirrusly_scan_config`
     * option (via WordPress options API). When the scan form is submitted with a valid nonce, the method
     * runs the scan logic, updates `woo_gmc_scan_data` with a timestamp and results, and outputs a success notice.
     */
    private function render_scan_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        $scan_cfg = get_option('cirrusly_scan_config', array());
        $block_save = isset($scan_cfg['block_on_critical']) ? 'checked' : '';
        $auto_strip = isset($scan_cfg['auto_strip_banned']) ? 'checked' : '';

        // CORE 1: Manual Helper
        echo '<div class="cc-manual-helper"><h4>Health Check</h4><p>Scans product data for critical Google Merchant Center issues.</p></div>';
        
        // CORE 2: Scan Button
        echo '<div style="background:#fff; padding:20px; border-bottom:1px solid #ccc;"><form method="post">';
        wp_nonce_field( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' );
        echo '<input type="hidden" name="run_gmc_scan" value="1">';
        submit_button('Run Diagnostics Scan', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_gmc_scan'] ) && check_admin_referer( 'cirrusly_gmc_scan', 'cc_gmc_scan_nonce' ) ) {
            // Unwrap new result structure
            $scan_result = Cirrusly_Commerce_GMC::run_gmc_scan_logic();
            $results     = isset( $scan_result['results'] ) ? $scan_result['results'] : array();
            
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
                    $tooltip = isset($i['reason']) ? $i['reason'] : $i['msg'];
                    $issues .= '<span class="gmc-badge" style="background:'.esc_attr($color).'; color:#fff; cursor:help;" title="'.esc_attr($tooltip).'">'.esc_html($i['msg']).'</span> ';
                }
                
                $actions = '<a href="'.esc_url(get_edit_post_link($p->get_id())).'" class="button button-small">Edit</a> ';
                if ( strpos( $issues, 'Missing GTIN' ) !== false ) {
                    $url = wp_nonce_url( admin_url( 'admin-post.php?action=cc_mark_custom&pid=' . $p->get_id() ), 'cc_mark_custom_' . $p->get_id() );
                    $actions .= '<a href="'.esc_url($url).'" class="button button-small">Mark Custom</a>';
                }

                echo '<tr><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td>'.$issues.'</td><td>'.$actions.'</td></tr>';
            }
            echo '</tbody></table>';
        }

        // PRO 3: Automation & Workflow Rules (Corrected Title)
        echo '<div class="'.esc_attr($pro_class).'" style="background:#f0f6fc; padding:15px; border:1px solid #c3c4c7; margin-top:20px; position:relative;">';
            if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn">Upgrade to Automate</a></div>';
            
            echo '<form method="post" action="options.php">';
            settings_fields('cirrusly_general_group'); 
            
            // UPDATED HEADER
            echo '<strong>Automation & Workflow Rules <span class="cc-pro-badge">PRO</span></strong><br>
            <label><input type="checkbox" name="cirrusly_scan_config[block_on_critical]" value="yes" '.$block_save.' '.esc_attr($disabled_attr).'> Block Save on Critical Error</label>
            <label style="margin-left:10px;"><input type="checkbox" name="cirrusly_scan_config[auto_strip_banned]" value="yes" '.$auto_strip.' '.esc_attr($disabled_attr).'> Auto-strip Banned Words</label>';
            
            // This hook injects the Automated Discounts UI
            do_action( 'cirrusly_commerce_scan_settings_ui' );

            echo '<br><br>
            <button type="submit" class="button button-small" '.esc_attr($disabled_attr).'>Save Rules</button>
            </form>';
        echo '</div>';
    }
    
    /**
     * Render the Promotions tab UI for managing and submitting Google Merchant Center promotions.
     */
    private function render_promotions_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cc-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        // --- 1. PRO: REMOTE PROMOTIONS DASHBOARD (Restored to Top) ---
        echo '<div class="cc-settings-card '.esc_attr($pro_class).'" style="margin-bottom:20px;">';
        if(!$is_pro) echo '<div class="cc-pro-overlay"><a href="'.esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ).'" class="cc-upgrade-btn"><span class="dashicons dashicons-lock cc-lock-icon"></span> Unlock Live Feed</a></div>';
        
        echo '<div class="cc-card-header" style="background:#f8f9fa; border-bottom:1px solid #ddd; padding:15px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">Live Google Promotions <span class="cc-pro-badge">PRO</span></h3>
                <button type="button" class="button button-secondary" id="cc_load_promos" '.esc_attr($disabled_attr).'><span class="dashicons dashicons-update"></span> Sync from Google</button>
              </div>';
        
        echo '<div class="cc-card-body" style="padding:0;">
                <table class="wp-list-table widefat fixed striped" id="cc-gmc-promos-table" style="border:0; box-shadow:none;">
                    <thead><tr><th>ID</th><th>Title</th><th>Effective Dates</th><th>Status</th><th>Type</th><th>Actions</th></tr></thead>
                    <tbody><tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center; color:#666;">Loading active promotions...</td></tr></tbody>
                </table>
              </div>';
        echo '</div>';

        // --- 2. CORE: GENERATOR / EDITOR ---
        echo '<div class="cc-manual-helper"><h4>Promotion Feed Generator</h4><p>Create or update a promotion entry for Google Merchant Center. Fill in the details, generate the code, and paste it into your Google Sheet feed.</p></div>';
        ?>
        <div class="cc-promo-generator" id="cc_promo_form_container">
            <h3 style="margin-top:0;" id="cc_form_title">Create Promotion Entry</h3>
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
                        Directly push this promotion to your linked Merchant Center account.
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
            // --- GENERATE CODE (Manual) ---
            $('#pg_generate').click(function(){
                var id = $('#pg_id').val(), app = $('#pg_app').val(), type = $('#pg_type').val();
                var title = $('#pg_title').val(), dates = $('#pg_dates').val(), code = $('#pg_code').val();
                var str = id + ',' + app + ',' + type + ',' + title + ',' + dates + ',ONLINE,' + dates + ',' + (type==='GENERIC_CODE' ? code : '');
                $('#pg_output').text(str);
                $('#pg_result_area').fadeIn();
            });

            // --- API: LIST PROMOTIONS (PRO) - Auto & Cached ---
            var loadPromotions = function( forceRefresh ) {
                var $btn = $('#cc_load_promos');
                var $table = $('#cc-gmc-promos-table tbody');
                
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:spin 2s linear infinite;"></span> Syncing...');
                if( forceRefresh ) $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center;">Refreshing data...</td></tr>');
                
                $.post(ajaxurl, {
                    action: 'cc_list_promos_gmc',
                    security: '<?php echo wp_create_nonce("cc_promo_api_list"); ?>',
                    force_refresh: forceRefresh ? 1 : 0
                }, function(res) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync from Google');
                    if(res.success) {
                        $table.empty();
                        if(res.data.length === 0) {
                            $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center;">No promotions found in Merchant Center.</td></tr>');
                            return;
                        }
                        // Render Rows
            function ccEscapeHtml(str) {

                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            $.each(res.data, function(i, p){
                var statusColor = '#777';
                            if(p.status === 'active') statusColor = '#008a20';
                            if(p.status === 'rejected') statusColor = '#d63638';
                            if(p.status === 'expired') statusColor = '#999';
                            
                            // Handle unknown statuses gracefully in UI
                           var displayStatus = p.status.toUpperCase();
                           if(p.status.indexOf('(') > 0) statusColor = '#dba617';

                var row = '<tr>' +
                    '<td><strong>' + ccEscapeHtml(p.id) + '</strong></td>' +
                    '<td>' + ccEscapeHtml(p.title) + '</td>' +
                    '<td>' + ccEscapeHtml(p.dates) + '</td>' +
                    '<td><span class="gmc-badge" style="background:'+statusColor+';color:#fff;">'+ccEscapeHtml(displayStatus)+'</span></td>' +
                    '<td>' + ccEscapeHtml(p.type) + (p.code ? ': <code>'+ccEscapeHtml(p.code)+'</code>' : '') + '</td>' +
                                '<td><button type="button" class="button button-small cc-edit-promo" ' +
                        'data-id="'+ccEscapeHtml(p.id)+'" data-title="'+ccEscapeHtml(p.title)+'" data-dates="'+ccEscapeHtml(p.dates)+'" data-app="'+ccEscapeHtml(p.app)+'" data-type="'+ccEscapeHtml(p.type)+'" data-code="'+ccEscapeHtml(p.code || '')+'">Edit</button></td>' +
                                '</tr>';
                            $table.append(row);
                        });
                    } else {
                        // Only alert if manual click, otherwise just show empty or error in row
                        if(forceRefresh) alert('Error: ' + (res.data || 'Failed to fetch data.'));
                        $table.html('<tr class="cc-empty-row"><td colspan="6" style="padding:20px; text-align:center; color:#d63638;">Error loading data.</td></tr>');
                    }
                });
            };

            // Auto-load on init (uses cache if available)
            if( $('#cc-gmc-promos-table').length > 0 ) {
                loadPromotions(false);
            }

            // Manual Sync Click (Forces Refresh)
            $('#cc_load_promos').click(function(){
                loadPromotions(true);
            });

            // --- UI: EDIT PROMOTION CLICK ---
            $(document).on('click', '.cc-edit-promo', function(e){
                e.preventDefault();
                var d = $(this).data();
                
                // Populate Form
                $('#pg_id').val(d.id); 
                $('#pg_title').val(d.title);
                $('#pg_dates').val(d.dates);
                $('#pg_app').val(d.app).trigger('change');
                $('#pg_type').val(d.type).trigger('change');
                $('#pg_code').val(d.code);
                
                // Scroll to Form
                $('html, body').animate({
                    scrollTop: $("#cc_promo_form_container").offset().top - 50
                }, 500);
                
                // Flash Form
                $('#cc_promo_form_container').css('border', '2px solid #2271b1').animate({borderWidth: 0}, 1500, function(){ $(this).css('border',''); });
                $('#cc_form_title').text('Edit Promotion: ' + d.id);
            });

            // --- API: SUBMIT (Create/Update) ---
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
                        app: $('#pg_app').val(),
                        type: $('#pg_type').val(),
                        code: $('#pg_code').val()
                    }
                }, function(response) {
                    if(response.success) {
                        alert('Success! Promotion pushed to Google Merchant Center.');
                        // Force refresh list to show new promo
                        loadPromotions(true);
                    } else {
                        alert('Error: ' + (response.data || 'Could not connect to API.'));
                    }
                    $btn.prop('disabled', false).html(originalText);
                });
            });
        });
        </script>
        <?php

        // --- 3. CORE: LOCAL ASSIGNMENTS TABLE ---
        global $wpdb;
        // ... (Bulk Action Logic) ...
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

        echo '<br><hr><h3>Local Product Assignments</h3><p class="description">Products in your WooCommerce store tagged with a Promotion ID.</p>';
        if(empty($promo_stats)) echo '<p>No promotions assigned locally.</p>';
        else {
            echo '<table class="wp-list-table widefat fixed striped" style="max-width:600px;"><thead><tr><th>ID</th><th>Products Assigned</th><th>Action</th></tr></thead><tbody>';
            foreach($promo_stats as $stat) {
                echo '<tr><td><strong>'.esc_html($stat->promo_id).'</strong></td><td>'.esc_html($stat->count).'</td><td><a href="?page=cirrusly-gmc&tab=promotions&view_promo='.urlencode($stat->promo_id).'" class="button button-small">View Products</a></td></tr>';
            }
            echo '</tbody></table>';
        }

        if ( $filter_promo ) {
            // FIXED: Added Pagination for better performance with large product lists
            $paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
            $per_page = 20;

            $args = array( 
                'post_type'      => 'product', 
                'posts_per_page' => $per_page, 
                'paged'          => $paged,
                'meta_key'       => '_gmc_promotion_id', 
                'meta_value'     => $filter_promo 
            );

            $query = new WP_Query( $args );
            $products = $query->posts;
            $total_pages = $query->max_num_pages;

            echo '<hr><h3>Managing: '.esc_html($filter_promo).'</h3>';
            echo '<form method="post">';
            wp_nonce_field( 'cirrusly_promo_bulk', 'cc_promo_nonce' );
            echo '<div style="background:#e5e5e5; padding:10px; margin-bottom:10px;">With Selected: <input type="text" name="gmc_new_promo_id" placeholder="New ID"> <button type="submit" name="gmc_promo_bulk_action" value="update" class="button">Move</button> <button type="submit" name="gmc_promo_bulk_action" value="remove" class="button">Remove</button></div>';
            
            // Pagination Top
            if ( $total_pages > 1 ) {
                $page_links = paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;'
                ) );
                echo '<div class="tablenav top"><div class="tablenav-pages" style="float:right; margin:5px 0;">' . $page_links . '</div><div class="clear"></div></div>';
            }

            echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th class="check-column"><input type="checkbox" id="cb-all-promo"></th><th>Name</th><th>Action</th></tr></thead><tbody>';
            
            if ( $products ) {
                foreach($products as $pObj) { 
                    $p=wc_get_product($pObj->ID); 
                    if(!$p) continue;
                    echo '<tr><th><input type="checkbox" name="gmc_promo_products[]" value="'.esc_attr($p->get_id()).'"></th><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'" class="button button-small">Edit</a></td></tr>'; 
                }
            } else {
                 echo '<tr><td colspan="3">No products found.</td></tr>';
            }

            echo '</tbody></table>';
            
            // Pagination Bottom
            if ( $total_pages > 1 ) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages" style="float:right; margin:5px 0;">' . $page_links . '</div><div class="clear"></div></div>';
            }

            echo '</form><script>jQuery("#cb-all-promo").change(function(){jQuery("input[name=\'gmc_promo_products[]\']").prop("checked",this.checked);});</script>';
            
            wp_reset_postdata();
        }
    }

    /**
     * Render the Site Content Audit admin view for scanning local content and checking Google account status.
     *
     * Renders a UI that (1) checks for required policy pages on the site, (2) provides a restricted-terms scan with controls to run and display scan results, and (3) shows Google Merchant Center account-level issues for Pro users.
     */
    private function render_content_scan_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        
        // --- 1. CORE: LOCAL SCAN EXPLANATION ---
        echo '<div class="cc-manual-helper">
            <h4>Site Content Audit (Local)</h4>
            <p>This tool scans your site pages and product descriptions to ensure compliance with Google Merchant Center policies.</p>
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

        // --- 2. CORE: RESTRICTED TERMS SCAN ---
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

        // --- 3. PRO: GOOGLE ACCOUNT STATUS (Moved to Bottom) ---
        echo '<div class="cc-settings-card ' . ( $is_pro ? '' : 'cc-pro-feature' ) . '" style="margin-bottom:20px; border:1px solid #c3c4c7; padding:0;">';
        
        if ( ! $is_pro ) {
            echo '<div class="cc-pro-overlay"><a href="' . esc_url( function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#' ) . '" class="cc-upgrade-btn"><span class="dashicons dashicons-lock cc-lock-icon"></span> Check Account Bans</a></div>';
        }

        echo '<div class="cc-card-header" style="background:#f8f9fa; border-bottom:1px solid #ddd; padding:15px;">
                <h3 style="margin:0;">Google Account Status <span class="cc-pro-badge">PRO</span></h3>
              </div>';
        
        echo '<div class="cc-card-body" style="padding:15px;">';
        
        if ( $is_pro ) {
            $account_status = $this->fetch_google_account_issues();
            
            // ERROR HANDLING
            if ( is_wp_error( $account_status ) ) {
                echo '<div class="notice notice-error inline" style="margin:0;"><p><strong>Connection Failed:</strong> ' . esc_html( $account_status->get_error_message() ) . '</p></div>';
            } 
            // SUCCESS
            elseif ( $account_status ) {
                $issues = $account_status->getAccountLevelIssues();
                if ( empty( $issues ) ) {
                    echo '<div class="notice notice-success inline" style="margin:0;"><p><strong>Account Healthy:</strong> No account-level policy issues detected.</p></div>';
                } else {
                    echo '<div class="notice notice-error inline" style="margin:0;"><p><strong>Attention Needed:</strong></p><ul style="list-style:disc; margin-left:20px;">';
                    foreach ( $issues as $issue ) {
                        echo '<li><strong>' . esc_html( $issue->getTitle() ) . ':</strong> ' . esc_html( $issue->getDetail() ) . '</li>';
                    }
                    echo '</ul></div>';
                }
            }
        } else {
            echo '<p>View real-time suspension status and policy violations directly from Google.</p>';
        }
        echo '</div></div>';
    }

    /**
     * Add a "GMC Data" column to the product list table.
     *
     * @param array $columns Associative array of existing list table columns (column_key => label).
     * @return array The modified columns array including the `gmc_status` key labeled "GMC Data".
     */
    public function add_gmc_admin_columns( $columns ) {
        $columns['gmc_status'] = __( 'Google Merchant Center Data', 'cirrusly-commerce' );
        return $columns;
    }

        /**
     * Renders the "GMC Data" cell for a product in the posts list when the column is `gmc_status`.
     *
     * Outputs visible badges and a hidden data element that expose per-product Google Merchant Center
     * attributes (custom product flag, promotion ID, and custom label) for use by admin UI and quick-edit.
     *
     * @param string $column  The current column key being rendered.
     * @param int    $post_id The post (product) ID for which the column is being rendered.
     */
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

    /**
     * Renders the Google Merchant Center attributes meta box on the product edit screen.
     *
     * Outputs controls for marking a product as "Custom Product? (No GTIN/Barcode)",
     * entering a Promotion ID, and setting Custom Label 0. The "Custom Product" checkbox
     * reflects the product's `_gla_identifier_exists` post meta.
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
    
    /**
     * Render the quick-edit UI controls for Google Merchant Center data in the products list.
     *
     * This outputs the inline quick-edit fieldset when the column being rendered is
     * `gmc_status` and the current post type is `product`.
     *
     * @param string $column_name The column key being rendered in the list table.
     * @param string $post_type   The current post type context.
     */
    public function render_quick_edit_box( $column_name, $post_type ) {
        if ( 'gmc_status' !== $column_name || 'product' !== $post_type ) return;
        ?>
        <fieldset class="inline-edit-col-right inline-edit-gmc">
            <div class="inline-edit-col">
                <h4>Google Merchant Center Data</h4>
                <label class="alignleft"><input type="checkbox" name="gmc_is_custom_product" value="yes"><span class="checkbox-title">Custom Product? (No GTIN)</span></label>
            </div>
        </fieldset>
        <?php
    }
    
    /**
     * Show an admin error notice when a user-specific blocked-save transient exists.
     *
     * Checks that the current user can edit products; if a transient named
     * `cc_gmc_blocked_save_{user_id}` is present, displays its message as an
     * error notice in the admin and removes the transient afterward.
     */
    public function render_blocked_save_notice() {
        if ( ! current_user_can( 'edit_products' ) ) return;
        $msg = get_transient( 'cc_gmc_blocked_save_' . get_current_user_id() );
        if ( $msg ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Cirrusly Commerce Alert:</strong> ' . esc_html( $msg ) . '</p></div>';
            delete_transient( 'cc_gmc_blocked_save_' . get_current_user_id() );
        }
    }

    /**
     * Scans site content (pages and products) for restricted terms defined in Google Merchant Center core.
     * * @return array List of content pieces with found restricted terms.
     */
    private function execute_content_scan_logic() {
        $issues = array();
        $terms_map = Cirrusly_Commerce_GMC::get_monitored_terms();
        
        // Flatten terms for searching
        $all_terms = array();
        foreach ( $terms_map as $category => $list ) {
            foreach ( $list as $word => $meta ) {
                $all_terms[$word] = $meta;
            }
        }

        // Scan Pages
        $pages = get_pages();
        foreach ( $pages as $page ) {
            $found_in_page = array();
            $content = $page->post_title . ' ' . $page->post_content;
            
            foreach ( $all_terms as $word => $meta ) {
                if ( stripos( $content, $word ) !== false ) {
                    $found_in_page[] = array(
                        'word'     => $word,
                        'severity' => $meta['severity'],
                        'reason'   => $meta['reason']
                    );
                }
            }

            if ( ! empty( $found_in_page ) ) {
                $issues[] = array(
                    'id'    => $page->ID,
                    'type'  => 'page',
                    'title' => $page->post_title,
                    'terms' => $found_in_page
                );
            }
        }

        // Scan Products
        $products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => -1 ) );
        foreach ( $products as $prod ) {
            $found_in_prod = array();
            $content = $prod->post_title . ' ' . $prod->post_content;

            foreach ( $all_terms as $word => $meta ) {
                if ( stripos( $content, $word ) !== false ) {
                    $found_in_prod[] = array(
                        'word'     => $word,
                        'severity' => $meta['severity'],
                        'reason'   => $meta['reason']
                    );
                }
            }

            if ( ! empty( $found_in_prod ) ) {
                $issues[] = array(
                    'id'    => $prod->ID,
                    'type'  => 'product',
                    'title' => $prod->post_title,
                    'terms' => $found_in_prod
                );
            }
        }

        return $issues;
    }

    /**
     * Fetches account-level issues from Google Merchant Center via the Pro client.
     * * @return Google_Service_ShoppingContent_AccountStatus|WP_Error|null
     */
    private function fetch_google_account_issues() {
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && class_exists( 'Cirrusly_Commerce_GMC_Pro' ) ) {
            return Cirrusly_Commerce_GMC_Pro::fetch_google_account_issues();
        }
        return new WP_Error( 'not_pro', 'Pro version required.' );
    }
}