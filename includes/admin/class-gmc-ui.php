<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_GMC_UI {

    /****
     * Initialize the Cirrusly Google Merchant Center admin UI by registering WordPress hooks and filters.
     *
     * Registers handlers for product list columns and rendering, product edit meta box UI, quick-edit controls,
     * admin notices related to blocked saves, and enqueues admin assets for the GMC admin screens.
     */
    public function __construct() {
        add_filter( 'manage_edit-product_columns', array( $this, 'add_gmc_admin_columns' ) );
        add_action( 'manage_product_posts_custom_column', array( $this, 'render_gmc_admin_columns' ), 10, 2 );
        add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render_gmc_product_settings' ) );
        add_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit_box' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'render_blocked_save_notice' ) );
        // New hook for enqueuing assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Restrict script enqueuing to the Cirrusly GMC admin page.
     *
     * Checks the current admin screen and exits immediately if the screen is not
     * the Cirrusly Google Merchant Center (cirrusly-gmc) admin page.
     */
    public function enqueue_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || false === strpos( $screen->id, '_page_cirrusly-gmc' ) ) {
            return;
        }

        // Only enqueue promotions JS if on the promotions tab
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'scan';
        if ( 'promotions' === $tab ) {
            $nonce_list   = wp_create_nonce( 'cirrusly_promo_api_list' );
            $nonce_submit = wp_create_nonce( 'cirrusly_promo_api_submit' );

            wp_enqueue_script(
                'cirrusly-admin-promotions',
                CIRRUSLY_COMMERCE_URL . 'assets/js/admin-promotions.js',
                array( 'jquery', 'cirrusly-admin-base-js' ),
                '1.0.0',
                true
            );

            wp_localize_script( 'cirrusly-admin-promotions', 'cirrusly_promo_data', array(
                'ajaxurl'      => admin_url( 'admin-ajax.php' ),
                'nonce_list'   => $nonce_list,
                'nonce_submit' => $nonce_submit,
            ) );
        }
    }

    /**
     * Render the Google Merchant Center hub page with tabbed navigation.
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
                <a href="?page=cirrusly-gmc&tab=scan" class="nav-tab <?php echo 'scan'===$tab? 'nav-tab-active' : ''; ?>">Health Check</a>
                <a href="?page=cirrusly-gmc&tab=promotions" class="nav-tab <?php echo 'promotions'===$tab? 'nav-tab-active' : ''; ?>">Promotion Manager</a>
                <a href="?page=cirrusly-gmc&tab=content" class="nav-tab <?php echo 'content'===$tab? 'nav-tab-active' : ''; ?>">Site Content</a>
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
     * Renders the Health Check admin UI for scanning the product catalog and managing scan-related automation rules.
     *
     * Displays a manual scan control, runs and persists a scan when the scan form is submitted, migrates legacy scan data if present, and shows scan results with per-product actions. Also renders the Automation & Workflow Rules panel (PRO-gated) that exposes settings for blocking saves on critical issues and auto-stripping banned words.
     */
    private function render_scan_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cirrusly-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        $scan_cfg = get_option('cirrusly_scan_config', array());
        $block_save = isset($scan_cfg['block_on_critical']) ? 'checked' : '';
        $auto_strip = isset($scan_cfg['auto_strip_banned']) ? 'checked' : '';

        // CORE 1: Manual Helper
        echo '<div class="cirrusly-manual-helper"><h4>Health Check</h4><p>This audit tool scans your WooCommerce product catalog for common issues that may lead to Google Merchant Center disapprovals or account suspensions. Use this tool to identify and fix potential problems before submitting your product feed to Google.</p></div>';
        
        // CORE 2: Scan Button
        echo '<div style="background:#fff; padding:20px; border-bottom:1px solid #ccc;"><form method="post">';
        wp_nonce_field( 'cirrusly_gmc_scan', 'cirrusly_gmc_scan_nonce' );
        echo '<input type="hidden" name="run_gmc_scan" value="1">';
        submit_button('Scan for Issues', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_gmc_scan'] ) && check_admin_referer( 'cirrusly_gmc_scan', 'cirrusly_gmc_scan_nonce' ) ) {
            // Unwrap new result structure
            $scan_result = Cirrusly_Commerce_GMC::run_gmc_scan_logic();
            $results     = isset( $scan_result['results'] ) ? $scan_result['results'] : array();
            
            update_option( 'cirrusly_gmc_scan_data', array( 'timestamp' => current_time( 'timestamp' ), 'results' => $results ), false );
            echo '<div class="notice notice-success inline"><p>Scan Completed.</p></div>';
        }
        
        // MIGRATION: Check for old scan data and migrate
        $scan_data = get_option( 'cirrusly_gmc_scan_data' );
        if ( false === $scan_data ) {
            $old_scan_data = get_option( 'woo_gmc_scan_data' );
            if ( false !== $old_scan_data ) {
                update_option( 'cirrusly_gmc_scan_data', $old_scan_data );
                delete_option( 'woo_gmc_scan_data' );
                $scan_data = $old_scan_data;
            }
        }

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
                    $url = wp_nonce_url( admin_url( 'admin-post.php?action=cirrusly_mark_custom&pid=' . $p->get_id() ), 'cirrusly_mark_custom_' . $p->get_id() );
                    $actions .= '<a href="'.esc_url($url).'" class="button button-small">Mark as Custom</a>';
                }

                echo '<tr><td><a href="'.esc_url(get_edit_post_link($p->get_id())).'">'.esc_html($p->get_name()).'</a></td><td>'.wp_kses_post($issues).'</td><td>'.wp_kses_post($actions).'</td></tr>';
            }
            echo '</tbody></table>';
        }

        // PRO 3: Automation & Workflow Rules (Corrected Title)
        echo '<div class="'.esc_attr($pro_class).'" style="background:#f0f6fc; padding:15px; border:1px solid #c3c4c7; margin-top:20px; position:relative;">';
            if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn">Upgrade to Automate</a></div>';
            
            echo '<form method="post" action="options.php">';
            settings_fields('cirrusly_general_group'); 
            
            // UPDATED HEADER
            echo '<strong>Automation & Workflow Rules <span class="cirrusly-pro-badge">PRO</span></strong><br>
            <label><input type="checkbox" name="cirrusly_scan_config[block_on_critical]" value="yes" '.esc_attr($block_save).' '.esc_attr($disabled_attr).'> Block Save on Critical Error</label>
            <label style="margin-left:10px;"><input type="checkbox" name="cirrusly_scan_config[auto_strip_banned]" value="yes" '.esc_attr($auto_strip).' '.esc_attr($disabled_attr).'> Auto-strip Banned Words</label>';
            
            // This hook injects the Automated Discounts UI
            do_action( 'cirrusly_commerce_scan_settings_ui' );

            echo '<br><br>
            <button type="submit" class="button button-small" '.esc_attr($disabled_attr).'>Save Rules</button>
            </form>';
        echo '</div>';
    }

     /**
     * Render the promotions management UI and handle local promotion assignment actions.
     *
     * Outputs the Live Google Promotions admin interface (promotions table, promotion generator,
     * and local product assignment list). When a POST with `gmc_promo_bulk_action` and a valid
     * `cirrusly_promo_bulk` nonce is present, performs bulk updates or removals of the
     * `_gmc_promotion_id` post meta for selected products and clears the promotions transient cache.
     *
     * Additional behaviors:
     * - Loads cached promotion statistics from the `cirrusly_active_promos_stats` transient and
     * regenerates it from postmeta when absent.
     * - Supports filtering by a single promotion ID via the `view_promo` query parameter and
     * displays a paginated product list when filtered.
     * - Outputs markup that is PRO-gated for certain actions (UI elements may be disabled when not PRO).
     *
     * @return void
     */
    private function render_promotions_view() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cirrusly-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        echo '<div class="cirrusly-settings-card '.esc_attr($pro_class).'" style="margin-bottom:20px;">';
        if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Unlock Live Feed</a></div>';
        
        echo '<div class="cirrusly-card-header" style="background:#f8f9fa; border-bottom:1px solid #ddd; padding:15px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">Live Google Promotions <span class="cirrusly-pro-badge">PRO</span></h3>
                <button type="button" class="button button-secondary" id="cirrusly_load_promos" '.esc_attr($disabled_attr).'><span class="dashicons dashicons-update"></span> Sync from Google</button>
              </div>';
        
        echo '<div class="cirrusly-card-body" style="padding:0;">
                <table class="wp-list-table widefat fixed striped" id="cirrusly-gmc-promos-table" style="border:0; box-shadow:none;">
                    <thead><tr><th>ID</th><th>Title</th><th>Effective Dates</th><th>Status</th><th>Type</th><th>Actions</th></tr></thead>
                    <tbody><tr class="cirrusly-empty-row"><td colspan="6" style="padding:20px; text-align:center; color:#666;">Loading active promotions...</td></tr></tbody>
                </table>
              </div>';
        echo '</div>';
        
        echo '<div class="cirrusly-manual-helper"><h4>Promotion Feed Generator</h4><p>Create or update a promotion entry for Google Merchant Center. Fill in the details, generate the code, and paste it into your Google Sheet feed.</p></div>';
        ?>
        <div class="cirrusly-promo-generator" id="cirrusly_promo_form_container">
            <h3 style="margin-top:0;" id="cirrusly_form_title">Create Promotion Entry</h3>
            <div class="cirrusly-promo-grid">
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
                        <div class="cirrusly-pro-overlay">
                            <a href="<?php echo esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ); ?>" class="cirrusly-upgrade-btn">
                               <span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Upgrade
                            </a>
                        </div>
                        <?php endif; ?>
                        <button type="button" class="button button-secondary" id="pg_api_submit" <?php echo esc_attr($disabled_attr); ?>>
                            <span class="dashicons dashicons-cloud-upload"></span> One-Click Submit to Google
                        </button>
                        <span class="cirrusly-pro-badge">PRO</span>
                    </div>
                </div>
            </div>

            <div id="pg_result_area" style="display:none; margin-top:15px;">
                <span class="cirrusly-copy-hint">Copy and paste this line into your Google Sheet:</span>
                <div id="pg_output" class="cirrusly-generated-code"></div>
            </div>
        </div>
        <?php
        global $wpdb;
        
        if ( isset( $_POST['gmc_promo_bulk_action'] ) && isset( $_POST['gmc_promo_products'] ) && check_admin_referer( 'cirrusly_promo_bulk', 'cirrusly_promo_nonce' ) ) {
            $new_promo_id = isset($_POST['gmc_new_promo_id']) ? sanitize_text_field( wp_unslash( $_POST['gmc_new_promo_id'] ) ) : '';
            $action = sanitize_text_field( wp_unslash( $_POST['gmc_promo_bulk_action'] ) );
            
            // Fix: Unslash and map for safety
            $promo_products_raw = wp_unslash( $_POST['gmc_promo_products'] );
            $promo_products = is_array($promo_products_raw) ? array_map('intval', $promo_products_raw) : array();

            if ( ! empty( $promo_products ) ) {
                $count = 0;
                foreach ( $promo_products as $pid ) {
                    if ( 'update' === $action ) update_post_meta( $pid, '_gmc_promotion_id', $new_promo_id );
                    elseif ( 'remove' === $action ) delete_post_meta( $pid, '_gmc_promotion_id' );
                    $count++;
                }
                delete_transient( 'cirrusly_active_promos_stats' );
                echo '<div class="notice notice-success inline"><p>Success! Updated ' . esc_html($count) . ' products.</p></div>';
            }
        }

        $promo_stats = get_transient( 'cirrusly_active_promos_stats' );
        if ( false === $promo_stats ) {
            // Note: This direct query is used for aggregation which is not supported by WP_Query. 
            // It is strictly wrapped in get_transient to ensure caching compliance.
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
            wp_nonce_field( 'cirrusly_promo_bulk', 'cirrusly_promo_nonce' );
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
                echo '<div class="tablenav top"><div class="tablenav-pages" style="float:right; margin:5px 0;">' . wp_kses_post( $page_links ) . '</div><div class="clear"></div></div>';
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
                echo '<div class="tablenav bottom"><div class="tablenav-pages" style="float:right; margin:5px 0;">' . wp_kses_post( $page_links ) . '</div><div class="clear"></div></div>';
            }
            echo '</form>';
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
        echo '<div class="cirrusly-manual-helper">
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

        echo '<h3 style="margin-top:0;">Required Policies</h3><div class="cirrusly-policy-grid">';
        foreach($required as $label => $keywords) {
            $found = false;
            foreach($found_titles as $title) {
                foreach($keywords as $kw) {
                    if(strpos($title, $kw) !== false) { $found = true; break 2; }
                }
            }
            $cls = $found ? 'cirrusly-policy-ok' : 'cirrusly-policy-fail';
            $icon = $found ? 'dashicons-yes' : 'dashicons-no';
            echo '<div class="cirrusly-policy-item '.esc_attr($cls).'"><span class="dashicons '.esc_attr($icon).'"></span> '.esc_html($label).'</div>';
        }
        echo '</div>';

        // --- 2. CORE: RESTRICTED TERMS SCAN ---
        echo '<div style="background:#fff; padding:20px; border:1px solid #c3c4c7; margin-bottom:20px; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">Restricted Terms Scan</h3>
                <form method="post" style="margin:0;">';
        wp_nonce_field( 'cirrusly_content_scan', 'cirrusly_content_scan_nonce' );
        echo '<input type="hidden" name="run_content_scan" value="1">';
        submit_button('Run New Scan', 'primary', 'run_scan', false);
        echo '</form></div>';

        if ( isset( $_POST['run_content_scan'] ) && check_admin_referer( 'cirrusly_content_scan', 'cirrusly_content_scan_nonce' ) ) {
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
        echo '<div class="cirrusly-settings-card ' . ( $is_pro ? '' : 'cirrusly-pro-feature' ) . '" style="margin-bottom:20px; border:1px solid #c3c4c7; padding:0;">';
        
        if ( ! $is_pro ) {
            echo '<div class="cirrusly-pro-overlay"><a href="' . esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ) . '" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Check Account Bans</a></div>';
        }

        echo '<div class="cirrusly-card-header" style="background:#f8f9fa; border-bottom:1px solid #ddd; padding:15px;">
                <h3 style="margin:0;">Google Account Status <span class="cirrusly-pro-badge">PRO</span></h3>
              </div>';
        
        echo '<div class="cirrusly-card-body" style="padding:15px;">';
        
        if ( $is_pro ) {
            $account_status = $this->fetch_google_account_issues();
            
            // ERROR HANDLING
            if ( is_wp_error( $account_status ) ) {
                echo '<div class="notice notice-error inline" style="margin:0;"><p><strong>Connection Failed:</strong> ' . esc_html( $account_status->get_error_message() ) . '</p></div>';
            } 
            // SUCCESS - Handle service worker JSON response
            elseif ( is_array( $account_status ) || is_object( $account_status ) ) {
                // Convert object to array if needed
                $status_data = is_array( $account_status ) ? $account_status : (array) $account_status;
                
                // Get issues array from response
                $issues = isset( $status_data['issues'] ) ? $status_data['issues'] : array();
                
                if ( empty( $issues ) ) {
                    echo '<div class="notice notice-success inline" style="margin:0;"><p><strong>Account Healthy:</strong> No account-level policy issues detected.</p></div>';
                } else {
                    echo '<div class="notice notice-error inline" style="margin:0;"><p><strong>Attention Needed:</strong></p><ul style="list-style:disc; margin-left:20px;">';
                    foreach ( $issues as $issue ) {
                        // Handle both array and object formats from service worker
                        $title = isset( $issue['title'] ) ? $issue['title'] : (isset( $issue->title ) ? $issue->title : 'Unknown Issue');
                        $detail = isset( $issue['detail'] ) ? $issue['detail'] : (isset( $issue->detail ) ? $issue->detail : 'No details provided');
                        echo '<li><strong>' . esc_html( $title ) . ':</strong> ' . esc_html( $detail ) . '</li>';
                    }
                    echo '</ul></div>';
                }
            } else {
                echo '<div class="notice notice-warning inline" style="margin:0;"><p><strong>Unexpected Response Format:</strong> Could not parse account status from service worker.</p></div>';
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
     * Render the "GMC Status" column content on the product list table.
     *
     * For each product ID, displays:
     * - GTIN status from post meta (`_gla_identifier_exists`)
     * - Custom product flag status
     * - MAP (Minimum Advertised Price) if configured
     * - Compliance badges (from audit scan)
     * - Quick-link to product edit page for GMC settings
     *
     * @param string $column Column key to render.
     * @param int    $post_id Product post ID.
     * @return void
     */
    public function render_gmc_admin_columns( $column, $post_id ) {
        if ( 'gmc_status' !== $column ) {
            return;
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            return;
        }

        $gtin = get_post_meta( $post_id, '_gla_identifier_exists', true );
        $custom = get_post_meta( $post_id, '_gmc_product_custom', true );
        $map = get_post_meta( $post_id, '_cirrusly_map_price', true );

        echo '<div style="margin-bottom:5px;">';
        
        echo $gtin ? '<span class="dashicons dashicons-yes-alt" style="color:#28a745;"></span> GTIN: Present' : '<span class="dashicons dashicons-no-alt" style="color:#dc3545;"></span> GTIN: Missing';

        echo '<br>';

        echo $custom ? '<span class="dashicons dashicons-yes-alt" style="color:#28a745;"></span> Custom Product' : '<span class="dashicons dashicons-info" style="color:#ffc107;"></span> Standard Product';

        if ( ! empty( $map ) ) {
            echo '<br><span style="font-size:12px; color:#666;"><strong>MAP:</strong> ' . esc_html( wc_price( $map ) ) . '</span>';
        }

        echo '<br><a href="' . esc_url( get_edit_post_link( $post_id ) ) . '#cirrusly-gmc-meta" class="button button-small" style="margin-top:5px;">Settings</a>';
        echo '</div>';
    }

    /**
     * Render the WooCommerce product data meta box for Cirrusly Commerce GMC settings.
     *
     * Allows setting per-product:
     * - GTIN type (UPC, EAN, ISBN, custom)
     * - GTIN value
     * - Custom product flag (hides product from feed if enabled)
     * - Minimum Advertised Price (MAP)
     * - Promotion ID assignment
     * - Flag for manual product exclusion from Google feeds
     */
    public function render_gmc_product_settings() {
        global $post;
        $product = wc_get_product( $post->ID );

        if ( ! $product ) {
            return;
        }

        // Get meta
        $gtin_type = get_post_meta( $post->ID, '_gtin_type', true ) ?: 'UPC';
        $gtin_value = get_post_meta( $post->ID, '_gtin_value', true ) ?: '';
        $gmc_custom = get_post_meta( $post->ID, '_gmc_product_custom', true ) ?: '';
        $map_price = get_post_meta( $post->ID, '_cirrusly_map_price', true ) ?: '';
        $promo_id = get_post_meta( $post->ID, '_gmc_promotion_id', true ) ?: '';
        $exclude = get_post_meta( $post->ID, '_gmc_product_exclude', true ) ?: '';

        // Render form
        echo '<div id="cirrusly-gmc-meta" class="cirrusly-product-meta">';

        woocommerce_wp_select( array(
            'id'      => '_gtin_type',
            'label'   => 'GTIN Type',
            'options' => array( 'UPC' => 'UPC', 'EAN' => 'EAN', 'ISBN' => 'ISBN', 'CUSTOM' => 'Custom' ),
            'value'   => $gtin_type,
            'desc_tip'=> true,
            'description' => 'Choose the type of GTIN/identifier for this product.',
        ) );

        woocommerce_wp_text_input( array(
            'id'      => '_gtin_value',
            'label'   => 'GTIN Value',
            'value'   => $gtin_value,
            'desc_tip'=> true,
            'description' => 'e.g., 1234567890123',
        ) );

        woocommerce_wp_checkbox( array(
            'id'      => '_gmc_product_custom',
            'label'   => 'Mark as Custom Product',
            'cbvalue' => 'yes',
            'value'   => $gmc_custom,
            'desc_tip'=> true,
            'description' => 'When enabled, this product will not be sent to Google feeds.',
        ) );

        woocommerce_wp_text_input( array(
            'id'      => '_cirrusly_map_price',
            'label'   => 'Minimum Advertised Price (MAP)',
            'value'   => $map_price,
            'type'    => 'number',
            'desc_tip'=> true,
            'description' => 'Override display price for MAP compliance.',
        ) );

        woocommerce_wp_text_input( array(
            'id'      => '_gmc_promotion_id',
            'label'   => 'Promotion ID',
            'value'   => $promo_id,
            'desc_tip'=> true,
            'description' => 'Link this product to an active Google promotion.',
        ) );

        woocommerce_wp_checkbox( array(
            'id'      => '_gmc_product_exclude',
            'label'   => 'Exclude from Google Feeds',
            'cbvalue' => 'yes',
            'value'   => $exclude,
            'desc_tip'=> true,
            'description' => 'Prevent this product from being sent to Google Merchant Center.',
        ) );

        echo '</div>';
    }

    /**
     * Render quick-edit controls for Cirrusly Commerce GMC settings within WooCommerce product list.
     *
     * @param string $column_name The quick-edit form column being rendered.
     * @param string $post_type   The post type being edited.
     */
    public function render_quick_edit_box( $column_name, $post_type ) {
        if ( 'gmc_status' !== $column_name || 'product' !== $post_type ) {
            return;
        }

        woocommerce_wp_checkbox( array(
            'id'      => '_gmc_product_custom',
            'label'   => 'Custom',
            'cbvalue' => 'yes',
            'desc_tip'=> true,
            'description' => 'Mark as custom product',
        ) );
    }

    /**
     * Display a success notice for products that were blocked from publication due to critical terms.
     *
     * When a product save is blocked (transient key `cirrusly_gmc_blocked_save_{user_id}`),
     * displays an admin notice and deletes the transient.
     */
    public function render_blocked_save_notice() {
        $message = get_transient( 'cirrusly_gmc_blocked_save_' . get_current_user_id() );
        if ( ! $message ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        delete_transient( 'cirrusly_gmc_blocked_save_' . get_current_user_id() );
    }

    /**
     * Content scan logic that checks for restricted terms defined in the main GMC class.
     *
     * @return array Array of issues with structure: [ 'id' => post_id, 'title' => post title, 'type' => 'page'|'post'|'product', 'terms' => [ [ 'word' => term, 'severity' => 'Critical'|'Warning', 'reason' => explanation ], ... ] ]
     */
    private function execute_content_scan_logic() {
        $monitored = Cirrusly_Commerce_GMC::get_monitored_terms();
        $issues = array();

        foreach ( get_posts( array( 'numberposts' => -1, 'post_type' => array( 'page', 'post', 'product' ) ) ) as $p ) {
            $title = $p->post_title;
            $content = $p->post_content;
            $found_terms = array();

            foreach ( $monitored as $cat => $terms ) {
                foreach ( $terms as $word => $rules ) {
                    if ( stripos( $title, $word ) !== false || stripos( $content, $word ) !== false ) {
                        $found_terms[] = array(
                            'word'     => $word,
                            'severity' => isset( $rules['severity'] ) ? $rules['severity'] : 'Warning',
                            'reason'   => isset( $rules['reason'] ) ? $rules['reason'] : '',
                        );
                    }
                }
            }

            if ( ! empty( $found_terms ) ) {
                $issues[] = array(
                    'id'    => $p->ID,
                    'title' => $title,
                    'type'  => $p->post_type,
                    'terms' => $found_terms,
                );
            }
        }

        return $issues;
    }

    /**
     * Wrapper method to call the Pro class method for fetching account issues.
     *
     * @return WP_Error|array Account status response or error object.
     */
    private function fetch_google_account_issues() {
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && class_exists( 'Cirrusly_Commerce_GMC_Pro' ) ) {
            return Cirrusly_Commerce_GMC_Pro::fetch_google_account_issues();
        }
        return new WP_Error( 'not_pro', 'Pro version required.' );
    }
}
