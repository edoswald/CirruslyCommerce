<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Settings_Manager {

    /**
     * Register admin menus.
     * Note: References UI classes for callbacks.
     */
    public function register_admin_menus() {
        // Dashboard (Main Menu) - Assumes Dashboard UI class is loaded
        $dash_cb = class_exists( 'Cirrusly_Commerce_Dashboard_UI' ) ? array( 'Cirrusly_Commerce_Dashboard_UI', 'render_main_dashboard' ) : '__return_false';
        
        add_menu_page( 'Cirrusly Commerce', 'Cirrusly Commerce', 'edit_products', 'cirrusly-commerce', $dash_cb, 'dashicons-analytics', 56 );
        add_submenu_page( 'cirrusly-commerce', 'Dashboard', 'Dashboard', 'edit_products', 'cirrusly-commerce', $dash_cb );
        
        // Submenus
        if ( class_exists( 'Cirrusly_Commerce_GMC' ) ) {
            add_submenu_page( 'cirrusly-commerce', 'Compliance Hub', 'Compliance Hub', 'edit_products', 'cirrusly-gmc', array( 'Cirrusly_Commerce_GMC', 'render_page' ) );
        }
        if ( class_exists( 'Cirrusly_Commerce_Audit' ) ) {
            add_submenu_page( 'cirrusly-commerce', 'Financial Audit', 'Financial Audit', 'edit_products', 'cirrusly-audit', array( 'Cirrusly_Commerce_Audit', 'render_page' ) );
        }
        
        if ( class_exists( 'Cirrusly_Commerce_Manual' ) ) {
            add_submenu_page( 'cirrusly-commerce', 'User Manual', 'User Manual', 'edit_products', 'cirrusly-manual', array( 'Cirrusly_Commerce_Manual', 'render_page' ) );
        }

        add_submenu_page( 'cirrusly-commerce', 'Settings', 'Settings', 'manage_options', 'cirrusly-settings', array( $this, 'render_settings_page' ) );
        
    }

    /**
     * Register settings and sanitization callbacks.
     */
    public function register_settings() {
        // Group: General
        register_setting( 'cirrusly_general_group', 'cirrusly_scan_config', array( 'sanitize_callback' => array( $this, 'handle_scan_schedule' ) ) );
        register_setting( 'cirrusly_general_group', 'cirrusly_msrp_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );
        register_setting( 'cirrusly_general_group', 'cirrusly_google_reviews_config', array( 'sanitize_callback' => array( $this, 'sanitize_options_array' ) ) );
        register_setting( 'cirrusly_general_group', 'cirrusly_countdown_rules', array( 'sanitize_callback' => array( $this, 'sanitize_countdown_rules' ) ) );

        // Group: Profit Engine
        register_setting( 'cirrusly_shipping_group', 'cirrusly_shipping_config', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );

        // Group: Badges
        register_setting( 'cirrusly_badge_group', 'cirrusly_badge_config', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
    }

    /**
     * Sanitization Helpers
     */
    public function sanitize_options_array( $input ) {
        $clean = array();
        if ( is_array( $input ) ) {
            foreach( $input as $key => $val ) {
                $clean[ sanitize_text_field( $key ) ] = sanitize_text_field( $val );
            }
        }
        return $clean;
    }

    public function sanitize_countdown_rules( $input ) {
        $clean_rules = array();
        if ( ! is_array( $input ) ) return array();

        foreach( $input as $rule ) {
            if ( ! is_array( $rule ) ) continue;
            $clean_rule = array();
            $clean_rule['taxonomy'] = isset( $rule['taxonomy'] ) ? sanitize_key( $rule['taxonomy'] ) : '';
            $clean_rule['term']     = isset( $rule['term'] ) ? sanitize_text_field( $rule['term'] ) : '';
            $clean_rule['end']      = isset( $rule['end'] ) ? sanitize_text_field( $rule['end'] ) : '';
            $clean_rule['label']    = isset( $rule['label'] ) ? sanitize_text_field( $rule['label'] ) : '';
            
            $align = isset( $rule['align'] ) ? sanitize_key( $rule['align'] ) : 'left';
            $clean_rule['align'] = in_array( $align, array('left', 'right', 'center'), true ) ? $align : 'left';

            if ( ! empty( $clean_rule['taxonomy'] ) && ! empty( $clean_rule['term'] ) && ! empty( $clean_rule['end'] ) ) {

                $clean_rules[] = $clean_rule;
            }
        }
        return $clean_rules;
    }

    /**
     * Process scan scheduling settings and handle an uploaded service-account file when provided.
     *
     * Schedules or clears the 'cirrusly_gmc_daily_scan' cron based on the `enable_daily_scan` flag,
     * delegates service-account file processing to the Pro handler when a file is uploaded and Pro is active,
     * and records a settings error if upload is attempted without Pro. Returns the sanitized settings array.
     *
     * @param array $input Associative settings array (e.g., ['enable_daily_scan' => 'yes', ...]). May be modified if a Pro upload handler processes a service-account file.
     * @return array The sanitized settings array suitable for storage.
     */
    public function handle_scan_schedule( $input ) {
        // 1. Schedule Logic (Core Feature)
        wp_clear_scheduled_hook( 'cirrusly_gmc_daily_scan' );
        if ( isset($input['enable_daily_scan']) && $input['enable_daily_scan'] === 'yes' ) {
            if ( ! wp_next_scheduled( 'cirrusly_gmc_daily_scan' ) ) {
                wp_schedule_event( time(), 'daily', 'cirrusly_gmc_daily_scan' );
            }
        }
        
        // 2. File Upload Logic (Pro Feature)
        if ( isset( $_FILES['cirrusly_service_account'] ) && ! empty( $_FILES['cirrusly_service_account']['tmp_name'] ) ) {
            
        // Use original tmp_name for security check (Not sanitized as it is a system path)
        $original_tmp_name = $_FILES['cirrusly_service_account']['tmp_name'];

        if ( is_uploaded_file( $original_tmp_name ) ) {
                // Check Pro and Load Delegate
                if ( class_exists( 'Cirrusly_Commerce_Core' ) && Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                    $pro_class = dirname( plugin_dir_path( __FILE__ ) ) . '/pro/class-settings-pro.php';
                    
                    if ( file_exists( $pro_class ) ) {
                        require_once $pro_class;
                        
                        // Construct a sanitized array of file data to pass to the handler
                        // Directly access and sanitize fields to avoid assigning raw $_FILES to a variable
                        $safe_file = array(
                            'name'     => sanitize_file_name( $_FILES['cirrusly_service_account']['name'] ),
                            'type'     => sanitize_mime_type( $_FILES['cirrusly_service_account']['type'] ),
                            'tmp_name' => $original_tmp_name, // System path validated by is_uploaded_file
                            'error'    => intval( $_FILES['cirrusly_service_account']['error'] ),
                            'size'     => intval( $_FILES['cirrusly_service_account']['size'] ),
                        );

                        // The Pro method returns the modified $input array
                        $input = Cirrusly_Commerce_Settings_Pro::cirrusly_process_service_account_upload( $input, $safe_file );
                    }
                } else {
                     add_settings_error( 'cirrusly_scan_config', 'pro_required', 'Using this feature requires Pro or higher. Upgrade today.' );
                }
            }
        }

        return $this->sanitize_options_array( $input );
    }

    public function sanitize_settings( $input ) {
        if ( isset( $input['revenue_tiers'] ) && is_array( $input['revenue_tiers'] ) ) {

            $clean_tiers = array();
            foreach ( $input['revenue_tiers'] as $tier ) {
                if ( isset($tier['min']) && is_numeric($tier['min']) ) {
                    $clean_tiers[] = array( 
                        'min' => floatval( $tier['min'] ), 
                        'max' => floatval( isset($tier['max']) ? $tier['max'] : 99999 ), 
                        'charge' => floatval( isset($tier['charge']) ? $tier['charge'] : 0 ),
                    );
                }
            }
            $input['revenue_tiers_json'] = json_encode( $clean_tiers );
            unset( $input['revenue_tiers'] );
        }
        if ( isset( $input['matrix_rules'] ) && is_array( $input['matrix_rules'] ) ) {
            $clean_matrix = array();
            foreach ( $input['matrix_rules'] as $idx => $rule ) {
                $key = isset($rule['key']) ? sanitize_title($rule['key']) : 'rule_'.$idx;
                if ( ! empty( $key ) && isset( $rule['label'] ) ) {
                    $clean_matrix[ $key ] = array( 'key' => $key, 'label' => sanitize_text_field( $rule['label'] ), 'cost_mult' => isset( $rule['cost_mult'] ) ? floatval( $rule['cost_mult'] ) : 1.0 );
                }
            }
            $input['matrix_rules_json'] = json_encode( $clean_matrix );
            unset( $input['matrix_rules'] );
        }
        if ( isset( $input['class_costs'] ) && is_array( $input['class_costs'] ) ) {
            $clean_costs = array();
            foreach ( $input['class_costs'] as $slug => $cost ) {
                if ( ! empty( $slug ) ) $clean_costs[ sanitize_text_field( $slug ) ] = floatval( $cost );
            }
            $input['class_costs_json'] = json_encode( $clean_costs );
            unset( $input['class_costs'] );
        }
        if ( isset( $input['custom_badges'] ) && is_array( $input['custom_badges'] ) ) {
            $clean_badges = array();
            foreach ( $input['custom_badges'] as $badge ) {
                if ( ! empty($badge['tag']) && ! empty($badge['url']) ) {
                    $clean_badges[] = array(
                        'tag' => sanitize_title( $badge['tag'] ),
                        'url' => esc_url_raw( $badge['url'] ),
                        'tooltip' => isset( $badge['tooltip'] ) ? sanitize_text_field( $badge['tooltip'] ) : '',
                        'width' => isset( $badge['width'] ) && intval( $badge['width'] ) > 0 ? intval( $badge['width'] ) : 60
                    );
                }
            }
            $input['custom_badges_json'] = json_encode( $clean_badges );
            unset( $input['custom_badges'] );
        }
       if ( isset( $input['scheduler_start'] ) ) {
            $input['scheduler_start'] = sanitize_text_field( $input['scheduler_start'] );
        }
        if ( isset( $input['scheduler_end'] ) ) {
            $input['scheduler_end'] = sanitize_text_field( $input['scheduler_end'] );
        }
        
        $fields = ['payment_pct', 'payment_flat', 'payment_pct_2', 'payment_flat_2', 'profile_split'];
        foreach($fields as $f) { if(isset($input[$f])) $input[$f] = floatval($input[$f]); }
        if(isset($input['profile_mode'])) $input['profile_mode'] = sanitize_text_field($input['profile_mode']);

        if ( isset( $input['smart_inventory'] ) ) $input['smart_inventory'] = 'yes';
        if ( isset( $input['smart_performance'] ) ) $input['smart_performance'] = 'yes';
        if ( isset( $input['smart_scheduler'] ) ) $input['smart_scheduler'] = 'yes';

        return $input;
    }

    /**
     * Get global shipping config (Used by Pricing/Audit logic)
     */
    public static function get_global_config() {
        // Delegate to Core class to avoid duplication
        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            $core = new Cirrusly_Commerce_Core();
            return $core->get_global_config();
        }
        // Fallback if Core not loaded (shouldn't happen in admin)
        return get_option( 'cirrusly_shipping_config', array() );
    }

    /**
     * Render the plugin Settings admin page with tabbed sections and the setup wizard link.
     *
     * Determines the active tab from the sanitized `$_GET['tab']`, displays the global header
     * (delegating to Cirrusly_Commerce_Core when available), shows a Setup Wizard button,
     * renders navigation tabs, opens a multipart settings form, and outputs the appropriate
     * settings fields and section UI for the selected tab (General, Profit Engine, or Badge Manager).
     */
    public function render_settings_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
        echo '<div class="wrap">';

        settings_errors();

        if ( class_exists( 'Cirrusly_Commerce_Core' ) ) {
            Cirrusly_Commerce_Core::render_global_header( 'Settings' );
        } else {
            echo '<h1>Settings</h1>';
        }

    // --- NEW: Rerun Wizard Button Here ---
            echo '<div style="float: right; margin-top: -40px;">
                    <a href="' . esc_url( admin_url( 'admin.php?page=cirrusly-setup' ) ) . '" class="button button-secondary">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align:text-top;"></span>Setup Wizard
                    </a>
                </div>';
        
        echo '<nav class="nav-tab-wrapper">
                <a href="?page=cirrusly-settings&tab=general" class="nav-tab '.($tab=='general'?'nav-tab-active':'').'">General Settings</a>
                <a href="?page=cirrusly-settings&tab=shipping" class="nav-tab '.($tab=='shipping'?'nav-tab-active':'').'">Profit Engine</a>
                <a href="?page=cirrusly-settings&tab=badges" class="nav-tab '.($tab=='badges'?'nav-tab-active':'').'">Badge Manager</a>
              </nav>';
        
        echo '<br><form method="post" action="options.php" enctype="multipart/form-data">';
        
        if($tab==='badges'){ 
            settings_fields('cirrusly_badge_group'); 
            $this->render_badges_settings(); 
        } elseif($tab==='shipping') { 
            settings_fields('cirrusly_shipping_group'); 
            $this->render_profit_engine_settings(); 
        } else { 
            settings_fields('cirrusly_general_group'); 
            $this->render_general_settings(); 
        }
        
        submit_button(); 
        echo '</form></div>';
    }

    private function render_general_settings() {
        $msrp = get_option( 'cirrusly_msrp_config', array() );
        $msrp_enable = isset($msrp['enable_display']) ? $msrp['enable_display'] : '';
        $pos_prod = isset($msrp['position_product']) ? $msrp['position_product'] : 'before_price';
        $pos_loop = isset($msrp['position_loop']) ? $msrp['position_loop'] : 'before_price';

        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        $gcr_enable = isset($gcr['enable_reviews']) ? $gcr['enable_reviews'] : '';
        $gcr_id = isset($gcr['merchant_id']) ? $gcr['merchant_id'] : '';

        $scan = get_option( 'cirrusly_scan_config', array() );
        $daily = isset($scan['enable_daily_scan']) ? $scan['enable_daily_scan'] : '';
        
        $merchant_id_pro = isset($scan['merchant_id_pro']) ? $scan['merchant_id_pro'] : '';
        $alert_reports = isset($scan['alert_weekly_report']) ? $scan['alert_weekly_report'] : '';
        $alert_disapproval = isset($scan['alert_gmc_disapproval']) ? $scan['alert_gmc_disapproval'] : '';
        $uploaded_file = isset($scan['service_account_name']) ? $scan['service_account_name'] : '';

        $is_pro = class_exists( 'Cirrusly_Commerce_Core' ) && method_exists( 'Cirrusly_Commerce_Core', 'cirrusly_is_pro' ) && Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cirrusly-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';
        
        $countdown_rules = get_option( 'cirrusly_countdown_rules', array() );
        if ( ! is_array( $countdown_rules ) ) $countdown_rules = array();

        echo '<div class="cirrusly-settings-grid">';
        
        // Integrations
        echo '<div class="cirrusly-settings-card">
             <div class="cirrusly-card-header"><h3>Integrations</h3><span class="dashicons dashicons-google"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Connect your store to Google Customer Reviews to gather post-purchase feedback. Enter your Merchant ID to link reviews and enable the survey opt-in.</p>
                <table class="form-table cirrusly-settings-table">
                    <tr><th scope="row">Google Customer Reviews</th><td><label><input type="checkbox" name="cirrusly_google_reviews_config[enable_reviews]" value="yes" '.checked('yes', $gcr_enable, false).'> Enable</label></td></tr>
                    <tr><th scope="row">Merchant ID</th><td><input type="text" name="cirrusly_google_reviews_config[merchant_id]" value="'.esc_attr($gcr_id).'" placeholder="123456789"></td></tr>
                </table>
            </div>
        </div>';

        // MSRP
        echo '<div class="cirrusly-settings-card">
            <div class="cirrusly-card-header"><h3>Frontend Display</h3><span class="dashicons dashicons-store"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Display manufacturer suggested retail prices to highlight savings. Choose where the MSRP and strikethrough price appear on product pages and catalog loops.</p>
                <table class="form-table cirrusly-settings-table">
                    <tr><th scope="row">MSRP Price</th><td><label><input type="checkbox" name="cirrusly_msrp_config[enable_display]" value="yes" '.checked('yes', $msrp_enable, false).'> Show Strikethrough</label></td></tr>
                    <tr><th scope="row">Product Page</th><td><select name="cirrusly_msrp_config[position_product]">
                        <option value="before_title" '.selected('before_title', $pos_prod, false).'>Before Title</option>
                        <option value="before_price" '.selected('before_price', $pos_prod, false).'>Before Price</option>
                        <option value="inline" '.selected('inline', $pos_prod, false).'>Inline</option>
                        <option value="after_price" '.selected('after_price', $pos_prod, false).'>After Price</option>
                        <option value="after_excerpt" '.selected('after_excerpt', $pos_prod, false).'>After Excerpt</option>
                        <option value="before_add_to_cart" '.selected('before_add_to_cart', $pos_prod, false).'>Before Add to Cart</option>
                        <option value="after_add_to_cart" '.selected('after_add_to_cart', $pos_prod, false).'>After Add to Cart</option>
                        <option value="after_meta" '.selected('after_meta', $pos_prod, false).'>After Meta</option>
                    </select></td></tr>
                    <tr><th scope="row">Catalog Loop</th><td><select name="cirrusly_msrp_config[position_loop]">
                        <option value="before_price" '.selected('before_price', $pos_loop, false).'>Before Price</option>
                        <option value="inline" '.selected('inline', $pos_loop, false).'>Inline</option>
                        <option value="after_price" '.selected('after_price', $pos_loop, false).'>After Price</option>
                    </select></td></tr>
                </table>
            </div>
        </div>';

        // Automation
        echo '<div class="cirrusly-settings-card">
            <div class="cirrusly-card-header"><h3>Automation</h3><span class="dashicons dashicons-update"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Schedule automated daily health scans to detect compliance issues. Enable email reporting to receive summaries of any flagged products.</p>
                <label><input type="checkbox" name="cirrusly_scan_config[enable_daily_scan]" value="yes" '.checked('yes', $daily, false).'> <strong>Daily Health Scan</strong></label>
                <p class="description" style="margin-top:5px;">Checks for missing GTINs and prohibited terms.</p>
                <br><label><input type="checkbox" name="cirrusly_scan_config[enable_email_report]" value="yes" '.checked('yes', isset($scan['enable_email_report']) ? $scan['enable_email_report'] : '', false).'> <strong>Email Reports</strong></label>
            </div>
        </div>';

        // Countdown (Pro)
        echo '<div class="cirrusly-settings-card '.esc_attr($pro_class).'">';
            if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Unlock Smart Rules</a></div>';
        echo '<div class="cirrusly-card-header"><h3>Smart Countdown <span class="cirrusly-pro-badge">PRO</span></h3><span class="dashicons dashicons-clock"></span></div>
            <div class="cirrusly-card-body">
            <p class="description">Create urgency by displaying countdown timers based on specific categories or tags. Define the taxonomy term and the expiration date to automatically show the timer.</p>
            <table class="widefat striped cirrusly-settings-table"><thead><tr><th>Taxonomy</th><th>Term</th><th>End Date</th><th>Label</th><th>Align</th><th></th></tr></thead><tbody id="cirrusly-countdown-rows">';
        if ( ! empty( $countdown_rules ) ) {
            foreach ( $countdown_rules as $idx => $rule ) {
                $align = isset($rule['align']) ? $rule['align'] : 'left';
                echo '<tr>
                    <td><input type="text" name="cirrusly_countdown_rules['.esc_attr($idx).'][taxonomy]" value="'.esc_attr($rule['taxonomy']).'" '.esc_attr($disabled_attr).'></td>
                    <td><input type="text" name="cirrusly_countdown_rules['.esc_attr($idx).'][term]" value="'.esc_attr($rule['term']).'" '.esc_attr($disabled_attr).'></td>
                    <td><input type="text" name="cirrusly_countdown_rules['.esc_attr($idx).'][end]" value="'.esc_attr($rule['end']).'" '.esc_attr($disabled_attr).'></td>
                    <td><input type="text" name="cirrusly_countdown_rules['.esc_attr($idx).'][label]" value="'.esc_attr($rule['label']).'" '.esc_attr($disabled_attr).'></td>
                    <td><select name="cirrusly_countdown_rules['.esc_attr($idx).'][align]" '.esc_attr($disabled_attr).'>
                        <option value="left" '.selected('left', $align, false).'>Left</option>
                        <option value="right" '.selected('right', $align, false).'>Right</option>
                        <option value="center" '.selected('center', $align, false).'>Center</option>
                    </select></td>
                    <td><button type="button" class="button cirrusly-remove-row" '.esc_attr($disabled_attr).'><span class="dashicons dashicons-trash"></span></button></td>
                </tr>';
            }
        }
        echo '</tbody></table><button type="button" class="button" id="cirrusly-add-countdown-row" style="margin-top:10px;" '.esc_attr($disabled_attr).'>+ Rule</button></div></div>';

        // API Connection (Pro)
        echo '<div class="cirrusly-settings-card '.esc_attr($pro_class).'">';
        if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Upgrade</a></div>';
        echo '<div class="cirrusly-card-header"><h3>Content API <span class="cirrusly-pro-badge">PRO</span></h3><span class="dashicons dashicons-cloud"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Upload your Google Service Account JSON to enable real-time API scanning. This allows the plugin to fetch live disapproval statuses directly from Google Merchant Center.</p>
                <table class="form-table cirrusly-settings-table">
                <tr><th>Service Account JSON</th><td><input type="file" name="cirrusly_service_account" accept=".json" '.esc_attr($disabled_attr).'>'.($uploaded_file ? '<br><small>Uploaded: '.esc_html($uploaded_file).'</small>' : '').'</td></tr>
            
                <tr><th>Merchant ID</th><td><input type="text" name="cirrusly_scan_config[merchant_id_pro]" value="'.esc_attr($merchant_id_pro).'" '.esc_attr($disabled_attr).'></td></tr>
            </table>
        </div></div>';

        // Advanced Alerts (Pro)
        echo '<div class="cirrusly-settings-card '.esc_attr($pro_class).'">';
        if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Unlock</a></div>';
        echo '<div class="cirrusly-card-header"><h3>Alerts <span class="cirrusly-pro-badge">PRO</span></h3><span class="dashicons dashicons-email-alt"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Configure proactive notifications for your store health. Receive weekly profit summaries and instant alerts if products are disapproved by Google.</p>
                <label><input type="checkbox" name="cirrusly_scan_config[alert_weekly_report]" value="yes" '.checked('yes', $alert_reports, false).' '.esc_attr($disabled_attr).'> Weekly Profit Reports</label><br>
                <label><input type="checkbox" name="cirrusly_scan_config[alert_gmc_disapproval]" value="yes" '.checked('yes', $alert_disapproval, false).' '.esc_attr($disabled_attr).'> Instant Disapproval Alerts</label>
            </div></div>';
        
        echo '</div>';
    }

    private function render_badges_settings() {
        $cfg = get_option( 'cirrusly_badge_config', array() );
        $enabled = isset($cfg['enable_badges']) ? $cfg['enable_badges'] : '';
        $size = isset($cfg['badge_size']) ? $cfg['badge_size'] : 'medium';
        $calc_from = isset($cfg['calc_from']) ? $cfg['calc_from'] : 'msrp';
        $new_days = isset($cfg['new_days']) ? $cfg['new_days'] : 30;
        
        $smart_inv = isset($cfg['smart_inventory']) ? $cfg['smart_inventory'] : '';
        $smart_perf = isset($cfg['smart_performance']) ? $cfg['smart_performance'] : '';
        $smart_sched = isset($cfg['smart_scheduler']) ? $cfg['smart_scheduler'] : '';
        $sched_start = isset($cfg['scheduler_start']) ? $cfg['scheduler_start'] : '';
        $sched_end = isset($cfg['scheduler_end']) ? $cfg['scheduler_end'] : '';
        
        $custom_badges = isset($cfg['custom_badges_json']) ? json_decode($cfg['custom_badges_json'], true) : array();
        if(!is_array($custom_badges)) $custom_badges = array();

        $is_pro = class_exists( 'Cirrusly_Commerce_Core' ) && method_exists( 'Cirrusly_Commerce_Core', 'cirrusly_is_pro' ) && Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cirrusly-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        echo '<div class="cirrusly-settings-card">
            <div class="cirrusly-card-header"><h3>Badge Manager</h3></div>
            <div class="cirrusly-card-body">
            <p class="description">Configure global settings for product badges, including size and price basis. Define the "New" badge threshold to automatically highlight recently added products.</p>
            <table class="form-table cirrusly-settings-table">
                <tr><th>Enable Module</th><td><label><input type="checkbox" name="cirrusly_badge_config[enable_badges]" value="yes" '.checked('yes', $enabled, false).'> Activate</label></td></tr>
                <tr><th>Badge Size</th><td><select name="cirrusly_badge_config[badge_size]"><option value="small" '.selected('small', $size, false).'>Small</option><option value="medium" '.selected('medium', $size, false).'>Medium</option><option value="large" '.selected('large', $size, false).'>Large</option></select></td></tr>
                <tr><th>Discount Base</th><td><select name="cirrusly_badge_config[calc_from]"><option value="msrp" '.selected('msrp', $calc_from, false).'>MSRP</option><option value="regular" '.selected('regular', $calc_from, false).'>Regular Price</option></select></td></tr>
                <tr><th>"New" Badge</th><td><input type="number" name="cirrusly_badge_config[new_days]" value="'.esc_attr($new_days).'" style="width:70px;"> days</td></tr>
            </table>
            <hr><h4>Custom Tag Badges</h4>
            <p class="description">Map specific product tags to custom badge images. These badges will appear automatically on products containing the specified tag.</p>
            <table class="widefat striped cirrusly-settings-table"><thead><tr><th>Tag Slug</th><th>Image</th><th>Tooltip</th><th>Width</th><th></th></tr></thead><tbody id="cirrusly-badge-rows">';
            if(!empty($custom_badges)) {
                foreach($custom_badges as $idx => $badge) {
                    echo '<tr><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tag]" value="'.esc_attr($badge['tag']).'"></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][url]" class="regular-text" value="'.esc_attr($badge['url']).'"> <button type="button" class="button cirrusly-upload-btn">Upload</button></td><td><input type="text" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][tooltip]" value="'.esc_attr($badge['tooltip']).'"></td><td><input type="number" name="cirrusly_badge_config[custom_badges]['.esc_attr($idx).'][width]" value="'.esc_attr($badge['width']).'" style="width:60px"> px</td><td><button type="button" class="button cirrusly-remove-row"><span class="dashicons dashicons-trash"></span></button></td></tr>';
                }
            }
            echo '</tbody></table><button type="button" class="button" id="cirrusly-add-badge-row" style="margin-top:10px;">+ Add Badge Rule</button></div></div>';

        // Smart Badges (Pro)
        echo '<div class="cirrusly-settings-card '.esc_attr($pro_class).'">';
        if(!$is_pro) echo '<div class="cirrusly-pro-overlay"><a href="'.esc_url( function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#' ).'" class="cirrusly-upgrade-btn"><span class="dashicons dashicons-lock cirrusly-lock-icon"></span> Unlock Smart Badges</a></div>';
        echo '<div class="cirrusly-card-header"><h3>Smart Badges <span class="cirrusly-pro-badge">PRO</span></h3><span class="dashicons dashicons-awards"></span></div>
            <div class="cirrusly-card-body">
                <p class="description">Enable intelligent badges based on live store data. Highlight low stock items, best sellers, or schedule specific event badges for a date range.</p>
                <label><input type="checkbox" name="cirrusly_badge_config[smart_inventory]" value="yes" '.checked('yes', $smart_inv, false).' '.esc_attr($disabled_attr).'> <strong>Low Stock:</strong> Show when qty < 5</label><br>
                <label><input type="checkbox" name="cirrusly_badge_config[smart_performance]" value="yes" '.checked('yes', $smart_perf, false).' '.esc_attr($disabled_attr).'> <strong>Best Seller:</strong> Show for top sellers</label><br>
                <div style="margin-top:10px;">
                    <label><input type="checkbox" name="cirrusly_badge_config[smart_scheduler]" value="yes" '.checked('yes', $smart_sched, false).' '.esc_attr($disabled_attr).'> <strong>Scheduler:</strong> Show "Event" between dates:</label><br>
                    <input type="date" name="cirrusly_badge_config[scheduler_start]" value="'.esc_attr($sched_start).'" '.esc_attr($disabled_attr).'> to 
                    <input type="date" name="cirrusly_badge_config[scheduler_end]" value="'.esc_attr($sched_end).'" '.esc_attr($disabled_attr).'>
                </div>
            </div></div>';
    }

    private function render_profit_engine_settings() {
        $config = self::get_global_config();
        $revenue_tiers = json_decode( $config['revenue_tiers_json'], true );
        $matrix_rules = json_decode( $config['matrix_rules_json'], true );
        $class_costs  = json_decode( $config['class_costs_json'], true );
        
        $payment_pct = isset($config['payment_pct']) ? $config['payment_pct'] : 2.9;
        $payment_flat = isset($config['payment_flat']) ? $config['payment_flat'] : 0.30;
        $profile_mode = isset($config['profile_mode']) ? $config['profile_mode'] : 'single';

        if ( ! is_array( $revenue_tiers ) ) $revenue_tiers = array();
        if ( ! is_array( $matrix_rules ) )  $matrix_rules  = array();
        if ( ! is_array( $class_costs ) )   $class_costs   = array();

        $terms = get_terms( array( 'taxonomy' => 'product_shipping_class', 'hide_empty' => false ) );
        $all_classes = array( 'default' => 'Default (No Class)' );
        if( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $all_classes[ $term->slug ] = $term->name; } }

        $is_pro = class_exists( 'Cirrusly_Commerce_Core' ) && method_exists( 'Cirrusly_Commerce_Core', 'cirrusly_is_pro' ) && Cirrusly_Commerce_Core::cirrusly_is_pro();
        $pro_class = $is_pro ? '' : 'cirrusly-pro-feature';
        $disabled_attr = $is_pro ? '' : 'disabled';

        // Revenue Tiers
        echo '<div class="cirrusly-settings-card"><div class="cirrusly-card-header"><h3>1. Shipping Revenue</h3></div>';
        echo '<div class="cirrusly-card-body">
        <p class="description">Define the shipping revenue collected from customers based on cart total. Set price ranges (Min/Max) and the corresponding shipping charge for each tier.</p>
        <table class="widefat striped cirrusly-settings-table"><thead><tr><th>Min Price</th><th>Max Price</th><th>Charge</th><th></th></tr></thead><tbody id="cirrusly-revenue-rows">';
        foreach($revenue_tiers as $idx => $tier) {
            echo '<tr><td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][min]" value="'.esc_attr($tier['min']).'"></td><td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][max]" value="'.esc_attr($tier['max']).'"></td><td><input type="number" step="0.01" name="cirrusly_shipping_config[revenue_tiers]['.esc_attr($idx).'][charge]" value="'.esc_attr($tier['charge']).'"></td><td><button type="button" class="button cirrusly-remove-row"><span class="dashicons dashicons-trash"></span></button></td></tr>';
        }
        echo '</tbody></table><button type="button" class="button" id="cirrusly-add-revenue-row" style="margin-top:10px;">+ Add Tier</button></div></div>';

        // Class Costs
        echo '<div class="cirrusly-settings-card"><div class="cirrusly-card-header"><h3>2. Internal Cost</h3></div><div class="cirrusly-card-body">
        <p class="description">Estimate your actual shipping and fulfillment costs per shipping class. These values are used to calculate the net profit and margin for each order.</p>
        <table class="widefat striped cirrusly-settings-table"><thead><tr><th>Class</th><th>Cost ($)</th></tr></thead><tbody>';
        foreach ( $all_classes as $slug => $name ) {
            $val = isset( $class_costs[$slug] ) ? $class_costs[$slug] : ( ($slug==='default')?10.00:0.00 );
            echo '<tr><td><strong>'.esc_html($name).'</strong><br><small>'.esc_html($slug).'</small></td><td><input type="number" step="0.01" name="cirrusly_shipping_config[class_costs]['.esc_attr($slug).']" value="'.esc_attr($val).'"></td></tr>';
        }
        echo '</tbody></table></div></div>';

        // Payment
        echo '<div class="cirrusly-settings-card"><div class="cirrusly-card-header"><h3>Payment Fees</h3></div><div class="cirrusly-card-body">
            <p class="description">Enter your payment processor fees (e.g., Stripe, PayPal) to calculate true net revenue. For mixed profiles, define secondary rates and the split percentage.</p>
            <input type="number" step="0.1" name="cirrusly_shipping_config[payment_pct]" value="'.esc_attr($payment_pct).'"> % + <input type="number" step="0.01" name="cirrusly_shipping_config[payment_flat]" value="'.esc_attr($payment_flat).'"> $
            <div class="'.esc_attr($pro_class).'" style="margin-top:15px; border-top:1px dashed #ccc; padding-top:15px;">
                <p><strong>Multi-Profile <span class="cirrusly-pro-badge">PRO</span></strong></p>
                <label><input type="radio" name="cirrusly_shipping_config[profile_mode]" value="single" '.checked('single', $profile_mode, false).' '.esc_attr($disabled_attr).'> Single</label><br>
                <label><input type="radio" name="cirrusly_shipping_config[profile_mode]" value="multi" '.checked('multi', $profile_mode, false).' '.esc_attr($disabled_attr).'> Mixed</label><br>
                <div style="display:'.($profile_mode==='multi'?'block':'none').';">
                    Secondary: <input type="number" step="0.1" name="cirrusly_shipping_config[payment_pct_2]" value="'.esc_attr(isset($config['payment_pct_2'])?$config['payment_pct_2']:3.49).'" style="width:60px"> % + <input type="number" step="0.01" name="cirrusly_shipping_config[payment_flat_2]" value="'.esc_attr(isset($config['payment_flat_2'])?$config['payment_flat_2']:0.49).'" style="width:60px"> $<br>
                    Split: <input type="number" name="cirrusly_shipping_config[profile_split]" value="'.esc_attr(isset($config['profile_split'])?$config['profile_split']:100).'" style="width:60px"> % Primary
                </div>
            </div></div></div>';

        // Matrix
        echo '<div class="cirrusly-settings-card"><div class="cirrusly-card-header"><h3>3. Scenario Matrix</h3></div><div class="cirrusly-card-body">
        <p class="description">Create different cost scenarios (e.g., "High Gas Prices") by applying multipliers to your base costs. Use these in the Financial Audit tool to stress-test your margins.</p>
        <table class="widefat striped cirrusly-settings-table"><thead><tr><th>Key</th><th>Label</th><th>Multiplier</th><th></th></tr></thead><tbody id="cirrusly-matrix-rows">';
        $idx = 0;
        foreach ( $matrix_rules as $rule ) {
            $keyVal = isset( $rule['key'] ) ? $rule['key'] : 'rule_' . $idx;
            echo '<tr><td><input type="text" name="cirrusly_shipping_config[matrix_rules][' . esc_attr( $idx ) . '][key]" value="' . esc_attr( $keyVal ) . '"></td><td><input type="text" name="cirrusly_shipping_config[matrix_rules][' . esc_attr( $idx ) . '][label]" value="' . esc_attr( $rule['label'] ) . '"></td><td>x <input type="number" step="0.1" name="cirrusly_shipping_config[matrix_rules][' . esc_attr( $idx ) . '][cost_mult]" value="' . esc_attr( isset( $rule['cost_mult'] ) ? $rule['cost_mult'] : 1.0 ) . '"></td><td><button type="button" class="button cirrusly-remove-row"><span class="dashicons dashicons-trash"></span></button></td></tr>';
            $idx++;
        }
        echo '</tbody></table><button type="button" class="button" id="cirrusly-add-matrix-row" style="margin-top:10px;">+ Add Scenario</button></div></div>';
    }

    public function render_onboarding_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $config = get_option( 'cirrusly_shipping_config' );
        if ( ! $config || empty( $config['revenue_tiers_json'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Welcome!</strong> Please set up your <a href="'.esc_url( admin_url('admin.php?page=cirrusly-settings&tab=shipping') ).'">Profit Engine</a>.</p></div>';
        }
    }
}