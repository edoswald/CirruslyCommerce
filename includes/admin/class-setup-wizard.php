<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Setup_Wizard {

    /**
     * Define versions that introduced major features requiring setup.
     * Add to this array when you release significant updates to prompt a re-run.
     */
    const MILESTONES = array( '1.5', '2.0' );

    /**
     * Initialize hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_wizard_page' ) );
        add_action( 'admin_init', array( $this, 'redirect_on_activation' ) );
        
        // Triggers for re-running the wizard (Plan changes or Feature updates)
        add_action( 'admin_init', array( $this, 'detect_plan_change' ) );
        add_action( 'admin_init', array( $this, 'detect_feature_update' ) );
        
        add_action( 'admin_notices', array( $this, 'render_upgrade_notice' ) );
    }

    /**
     * Register the wizard page as a hidden submenu (parent = null).
     * This keeps it hidden from the sidebar but accessible via URL/redirect.
     */
    public function register_wizard_page() {
        add_submenu_page( 
            null, 
            'Setup Cirrusly', 
            'Setup Cirrusly', 
            'manage_options', 
            'cirrusly-setup', 
            array( $this, 'render_wizard' ) 
        );
    }

    /**
     * Redirect to wizard after plugin activation if configuration is missing.
     */
    public function redirect_on_activation() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( get_transient( 'cirrusly_activation_redirect' ) ) {
            delete_transient( 'cirrusly_activation_redirect' );
            
            // Only redirect if config is empty (new install)
            if ( ! get_option( 'cirrusly_shipping_config' ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=cirrusly-setup' ) );
                exit;
            }
        }
    }

    /**
     * Trigger 1: Plan Upgrade (Free -> Pro -> Pro Plus)
     */
    public function detect_plan_change() {
        if ( ! function_exists( 'cc_fs' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $current_plan = 'free';
        if ( cc_fs()->is_plan( 'proplus' ) ) {
            $current_plan = 'proplus';
        } elseif ( cc_fs()->can_use_premium_code() ) {
            $current_plan = 'pro';
        }

        $stored_plan = get_option( 'cirrusly_last_known_plan', 'free' );

        // If plan changed (e.g. free -> pro), set a transient to show the notice
        if ( $current_plan !== $stored_plan ) {
            update_option( 'cirrusly_last_known_plan', $current_plan );
            
            // Define levels to ensure we only prompt on upgrades, not downgrades
            $levels = array( 'free' => 0, 'pro' => 1, 'proplus' => 2 );
            if ( isset( $levels[$current_plan] ) && isset( $levels[$stored_plan] ) && $levels[$current_plan] > $levels[$stored_plan] ) {
                // Set transient with type 'plan'
                set_transient( 'cirrusly_upgrade_prompt', 'plan', 48 * HOUR_IN_SECONDS );
            }
        }
    }

    /**
     * Trigger 2: Major Feature Update (Version based)
     */
    public function detect_feature_update() {
        // Get the version of the plugin when the wizard was last completed
        $last_setup = get_option( 'cirrusly_wizard_completed_version', '0.0.0' );
        $current_ver = defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0';

        foreach ( self::MILESTONES as $milestone ) {
            // If Milestone is newer than Last Setup AND We have installed the Milestone version
            if ( version_compare( $milestone, $last_setup, '>' ) && version_compare( $current_ver, $milestone, '>=' ) ) {
                // Set transient with type 'feature'
                set_transient( 'cirrusly_upgrade_prompt', 'feature', 48 * HOUR_IN_SECONDS );
                break;
            }
        }
    }

    /**
     * Display a notice prompting the user to run the wizard.
     */
    public function render_upgrade_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

// Suppress notice if already on the wizard page
        if ( isset( $_GET['page'] ) && 'cirrusly-setup' === $_GET['page'] ) {
            delete_transient( 'cirrusly_upgrade_prompt' );
            return;
        }

        $type = get_transient( 'cirrusly_upgrade_prompt' );
        if ( ! $type ) return;

        $url = admin_url( 'admin.php?page=cirrusly-setup' );
        $dismiss_url = wp_nonce_url( add_query_arg( 'cc_dismiss_wizard', '1' ), 'cc_dismiss_wizard_nonce' );

        // Handle Dismissal
        if ( isset( $_GET['cc_dismiss_wizard'] ) && check_admin_referer( 'cc_dismiss_wizard_nonce' ) ) {
            delete_transient( 'cirrusly_upgrade_prompt' );
            // If dismissed, assume they are "up to date" to prevent immediate resurfacing
            update_option( 'cirrusly_wizard_completed_version', defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0' );
            return;
        }

        // Dynamic Message Logic
        $title = 'Setup Required';
        $msg   = 'Please run the setup wizard.';

        if ( $type === 'plan' ) {
            $title = 'Thanks for upgrading!';
            $msg   = 'You have unlocked new Pro features. Run the wizard to configure them.';
        } elseif ( $type === 'feature' ) {
            $title = 'New Features Available!';
            $msg   = 'We have added major new features that require configuration. Please check your settings.';
        }

        echo '<div class="notice notice-info is-dismissible" style="padding:15px; border-left-color:#2271b1;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <h3>' . esc_html( $title ) . '</h3>
                    <p>' . esc_html( $msg ) . '</p>
                    <p>
                        <a href="' . esc_url( $url ) . '" class="button button-primary">Run Setup Wizard</a> 
                        <a href="' . esc_url( $dismiss_url ) . '" class="button button-secondary" style="margin-left:10px;">Dismiss</a>
                    </p>
                </div>
            </div>
        </div>';
    }

    /**
     * Main Renderer: Handles saving and step navigation.
     */
    public function render_wizard() {
        $step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
        if ( $step < 1 || $step > 5 ) {
            $step = 1;
        }        

        // Handle Save Logic
        if ( isset( $_POST['save_step'] ) && check_admin_referer( 'cirrusly_wizard_step_' . $step ) ) {
            $this->save_step( $step );
            
            // If finishing the wizard (Step 5), redirect to dashboard
            if ( $step === 5 ) {
                wp_safe_redirect( admin_url( 'admin.php?page=cirrusly-commerce' ) );
                exit;
            }

            // Otherwise, go to next step
            $step++;
            wp_safe_redirect( admin_url( 'admin.php?page=cirrusly-setup&step=' . $step ) );
            exit;
        }

        ?>
        <div class="wrap">
            <style>
                .cc-wizard-container { max-width: 700px; margin: 50px auto; background: #fff; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; }
                .cc-wizard-header { text-align: center; margin-bottom: 30px; }
                .cc-wizard-progress { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
                .cc-step { font-weight: bold; color: #ccc; font-size: 14px; }
                .cc-step.active { color: #2271b1; }
                .cc-wizard-footer { margin-top: 30px; display: flex; justify-content: flex-end; align-items: center; gap: 15px; border-top: 1px solid #eee; padding-top: 20px; }
                
                /* Pricing Columns */
                .cc-pricing-grid { display: flex; gap: 15px; margin-top: 20px; }
                .cc-pricing-col { flex: 1; border: 1px solid #ddd; padding: 20px; border-radius: 5px; text-align: center; background: #f9f9f9; }
                .cc-pricing-col.featured { border-color: #2271b1; background: #f0f6fc; transform: scale(1.02); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
                .cc-pricing-col h4 { margin: 0 0 10px; font-size: 1.2em; }
                .cc-tag { display: inline-block; background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; text-transform: uppercase; margin-bottom: 10px; }
                .cc-feature-list { text-align: left; font-size: 12px; margin: 15px 0; color: #555; list-style: none; padding: 0; }
                .cc-feature-list li { margin-bottom: 5px; }
            </style>

            <div class="cc-wizard-container">
                <div class="cc-wizard-header">
                     <img src="<?php echo esc_url( CIRRUSLY_COMMERCE_URL . 'assets/images/logo.svg' ); ?>" style="height: 40px; width: auto;" alt="Cirrusly Commerce">
                    <h2 style="margin-top: 10px;">Setup Guide</h2>
                </div>

                <div class="cc-wizard-progress">
                    <span class="cc-step <?php echo $step >= 1 ? 'active' : ''; ?>">1. License</span>
                    <span class="cc-step <?php echo $step >= 2 ? 'active' : ''; ?>">2. Connect</span>
                    <span class="cc-step <?php echo $step >= 3 ? 'active' : ''; ?>">3. Finance</span>
                    <span class="cc-step <?php echo $step >= 4 ? 'active' : ''; ?>">4. Visuals</span>
                    <span class="cc-step <?php echo $step >= 5 ? 'active' : ''; ?>">5. Finish</span>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <?php 
                    wp_nonce_field( 'cirrusly_wizard_step_' . $step );
                    
                    switch ( $step ) {
                        case 1: $this->render_step_license(); break;
                        case 2: $this->render_step_connect(); break;
                        case 3: $this->render_step_finance(); break;
                        case 4: $this->render_step_visuals(); break;
                        case 5: $this->render_step_finish(); break;
                        default: $this->render_step_license(); break;
                    }
                    ?>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * STEP 1: License & Edition Selection
     */
    private function render_step_license() {
        $is_pro = function_exists('cc_fs') && cc_fs()->can_use_premium_code();
        $is_plus = function_exists('cc_fs') && cc_fs()->is_plan('proplus');
        
        // If already Pro/Plus, show success and move on
        if ( $is_pro ) {
            echo '<div style="text-align:center; padding: 40px;">
                <span class="dashicons dashicons-yes-alt" style="font-size:60px; height:60px; width:60px; color:#008a20;"></span>
                <h3>Premium License Active!</h3>
                <p>You have unlocked ' . ($is_plus ? '<strong>Pro Plus</strong>' : '<strong>Pro</strong>') . ' features.</p>
                <div class="cc-wizard-footer">
                    <button type="submit" name="save_step" class="button button-primary button-hero">Let\'s Configure &rarr;</button>
                </div>
            </div>';
            return;
        }

        // Logic for Free Users: Offer Trials
        $upgrade_url = function_exists('cc_fs') ? cc_fs()->get_upgrade_url() : '#';
        ?>
        <h3>Choose your Edition</h3>
        <p>You are currently on the Free version. Continue for free or start a risk-free trial to unlock automation.</p>
        
        <div class="cc-pricing-grid">
            <div class="cc-pricing-col">
                <h4>Free</h4>
                <p style="font-size: 24px; font-weight: bold;">$0</p>
                <ul class="cc-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Health Scan (Manual)</li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Profit Audit</li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Basic Badges</li>
                </ul>
                <button type="submit" name="save_step" class="button button-secondary" style="width:100%;">Continue Free</button>
            </div>

            <div class="cc-pricing-col">
                <span class="cc-tag">Best Value</span>
                <h4>Pro</h4>
                <p style="font-size: 24px; font-weight: bold;">3-Day Trial</p>
                <ul class="cc-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <strong>API Sync</strong></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Multi-Profile Profit</li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Smart Inventory Badges</li>
                </ul>
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="button button-primary" style="width:100%;">Start Trial</a>
                <p style="font-size:11px; color:#777; margin-top:5px;">Opens in new window. <br>Refresh after purchase.</p>
            </div>

            <div class="cc-pricing-col featured">
                <span class="cc-tag">Automated</span>
                <h4>Pro Plus</h4>
                <p style="font-size: 24px; font-weight: bold;">7-Day Trial</p>
                <ul class="cc-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <strong>All Pro Features</strong></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Automated Discounts</li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> Dynamic Repricing</li>
                </ul>
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="button button-primary" style="width:100%;">Start Trial</a>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #777;">
            <a href="#" onclick="location.reload();">I've already started my trial (Refresh)</a>
        </div>
        <?php
    }

    /**
     * STEP 2: Connect (GMC)
     */
    private function render_step_connect() {
        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        $val = isset( $gcr['merchant_id'] ) ? $gcr['merchant_id'] : '';
        
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $upload_success = get_transient( 'cirrusly_wizard_upload_success' );
        if ( $upload_success ) {
            delete_transient( 'cirrusly_wizard_upload_success' );
        }
        ?>
        <h3>Connect Google Merchant Center</h3>
        <p>Enter your Merchant ID to enable Health Scans.</p>
        <?php if ( ! empty( $upload_success ) ) : ?>
            <div class="notice notice-success" style="margin:10px 0;">
                <p><?php echo esc_html( 'Service Account JSON uploaded successfully.' ); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th>Merchant ID</th>
                <td><input type="text" name="merchant_id" value="<?php echo esc_attr( $val ); ?>" class="regular-text" placeholder="e.g. 123456789"></td>
            </tr>
            <?php if ( $is_pro ): ?>
            <tr>
                <th>Service Account JSON <span class="cc-tag">PRO</span></th>
                <td>
                    <input type="file" name="cirrusly_service_account" accept=".json">
                    <p class="description">Upload your Google Cloud Key for Real-Time API scanning.</p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <div class="cc-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero">Next: Financials &rarr;</button>
        </div>
        <?php
    }

    /**
     * STEP 3: Finance
     */
    private function render_step_finance() {
        $conf = get_option( 'cirrusly_shipping_config', array() );
        $pct = isset( $conf['payment_pct'] ) ? $conf['payment_pct'] : 2.9;
        $flat = isset( $conf['payment_flat'] ) ? $conf['payment_flat'] : 0.30;
        
        $costs = isset( $conf['class_costs_json'] ) ? json_decode( $conf['class_costs_json'], true ) : array();
        // Fallback if decode failed or wasn't an array
        if ( ! is_array( $costs ) ) {
            $costs = array();
        }
        $def_cost = isset( $costs['default'] ) ? $costs['default'] : 10.00;
        
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        ?>
        <h3>Profit Engine Setup</h3>
        <p>Configure your baseline costs to calculate accurate Net Profit margins.</p>
        
        <table class="form-table">
            <tr>
                <th>Payment Fees</th>
                <td>
                    <input type="number" step="0.1" name="payment_pct" value="<?php echo esc_attr( $pct ); ?>" style="width: 70px;"> % + 
                    <input type="number" step="0.01" name="payment_flat" value="<?php echo esc_attr( $flat ); ?>" style="width: 70px;"> $
                    <p class="description">e.g., Stripe is usually 2.9% + $0.30</p>
                </td>
            </tr>
            <?php if ( $is_pro ): ?>
            <tr style="background: #f0f6fc;">
                <th>Multi-Profile <span class="cc-tag">PRO</span></th>
                <td>
                    <label><input type="radio" name="profile_mode" value="single" <?php checked('single', isset($conf['profile_mode'])?$conf['profile_mode']:'single'); ?>> Single</label>
                    <label><input type="radio" name="profile_mode" value="multi" <?php checked('multi', isset($conf['profile_mode'])?$conf['profile_mode']:''); ?>> Mixed (PayPal + Stripe)</label>
                    <p class="description">Calculates blended rates for split-payment stores.</p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Avg. Shipping Cost</th>
                <td>
                    <input type="number" step="0.01" name="default_shipping" value="<?php echo esc_attr( $def_cost ); ?>" class="regular-text"> $
                    <p class="description">Used as the default cost for products without a specific Shipping Class.</p>
                </td>
            </tr>
        </table>

        <div class="cc-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero">Next: Storefront &rarr;</button>
        </div>
        <?php
    }

    /**
     * STEP 4: Visuals
     */
    private function render_step_visuals() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        
        $msrp_config = get_option( 'cirrusly_msrp_config', array() );
        $badge_config = get_option( 'cirrusly_badge_config', array() );

        // Determine states, default to 'yes' for new installs
        $enable_msrp = isset( $msrp_config['enable_display'] ) ? $msrp_config['enable_display'] : 'yes';
        $enable_badges = isset( $badge_config['enable_badges'] ) ? $badge_config['enable_badges'] : 'yes';
        $smart_inventory = isset( $badge_config['smart_inventory'] ) ? $badge_config['smart_inventory'] : 'yes';
        $smart_performance = isset( $badge_config['smart_performance'] ) ? $badge_config['smart_performance'] : 'yes';
        ?>
        <h3>Storefront Appearance</h3>
        <p>Enable visual features to increase urgency and conversion.</p>
        
        <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <label>
                <input type="checkbox" name="enable_msrp" value="yes" <?php checked( 'yes', $enable_msrp ); ?>> 
                <strong>MSRP Strikethrough</strong>
            </label>
            <p class="description" style="margin-left: 25px;">Shows "Original Price" crossed out.</p>
        </div>

        <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <label>
                <input type="checkbox" name="enable_badges" value="yes" <?php checked( 'yes', $enable_badges ); ?>> 
                <strong>Smart Badges</strong>
            </label>
            <p class="description" style="margin-left: 25px;">Standard "New" and "Sale" badges.</p>
            
            <?php if ( $is_pro ): ?>
            <div style="margin-left: 25px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ccc;">
                <span class="cc-tag">PRO</span><br>
                <label><input type="checkbox" name="smart_inventory" value="yes" <?php checked( 'yes', $smart_inventory ); ?>> Low Stock Warning (Qty < 5)</label><br>
                <label><input type="checkbox" name="smart_performance" value="yes" <?php checked( 'yes', $smart_performance ); ?>> Best Seller Badge</label>
            </div>
            <?php endif; ?>
        </div>

        <div class="cc-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero">Finish Setup &rarr;</button>
        </div>
        <?php
    }

    /**
     * STEP 5: Finish
     */
    private function render_step_finish() {
        ?>
        <div style="text-align: center;">
            <span class="dashicons dashicons-yes-alt" style="font-size: 80px; width: 80px; height: 80px; color: #008a20; margin-bottom: 20px;"></span>
            <h3>Setup Complete!</h3>
            <p>Your store is now configured.</p>
            <br>
            <button type="submit" name="save_step" class="button button-primary button-hero">Complete Setup</button>
        </div>
        <?php
    }

    /**
     * Save handler for all steps.
     */
    private function save_step( $step ) {
        // Step 1 (License) is just a view/redirect step.
        
        if ( $step === 2 ) {
            // Save Connect Settings
            $data = get_option( 'cirrusly_google_reviews_config', array() );
            $data['merchant_id']    = isset( $_POST['merchant_id'] ) ? sanitize_text_field( $_POST['merchant_id'] ) : '';
            $data['enable_reviews'] = ! empty( $data['merchant_id'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_google_reviews_config', $data );

            // Pro File Upload
            if ( isset( $_FILES['cirrusly_service_account'] ) 
                 && $_FILES['cirrusly_service_account']['error'] === UPLOAD_ERR_OK
                 && ! empty( $_FILES['cirrusly_service_account']['tmp_name'] ) 
                 && Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                if ( class_exists( 'Cirrusly_Commerce_Settings_Pro' ) ) {
                     $input = get_option( 'cirrusly_scan_config', array() );
                     $input = Cirrusly_Commerce_Settings_Pro::process_service_account_upload( $input, $_FILES['cirrusly_service_account'] );
                     update_option( 'cirrusly_scan_config', $input );
                    // Store success flag for wizard feedback
                    if ( isset( $input['service_account_uploaded'] ) && $input['service_account_uploaded'] === 'yes' ) {
                        set_transient( 'cirrusly_wizard_upload_success', true, 30 );
                    }

                }
            }
        }

        // Step 3: Finance
        if ( $step === 3 ) {
            $conf = get_option( 'cirrusly_shipping_config', array() );
            $conf['payment_pct']  = isset( $_POST['payment_pct'] ) ? floatval( $_POST['payment_pct'] ) : 2.9;
            $conf['payment_flat'] = isset( $_POST['payment_flat'] ) ? floatval( $_POST['payment_flat'] ) : 0.30;
            
            // Pro: Profile Mode
            if ( isset( $_POST['profile_mode'] ) ) {
                $conf['profile_mode'] = sanitize_text_field( $_POST['profile_mode'] );
            }

            // Default Shipping (Stored in class costs JSON)
            $costs = isset( $conf['class_costs_json'] ) ? json_decode( $conf['class_costs_json'], true ) : array();
            if ( ! is_array( $costs ) ) {
                $costs = array();
            }
            
            if ( isset( $_POST['default_shipping'] ) ) {
                $costs['default'] = sanitize_text_field( $_POST['default_shipping'] );
            }
            $conf['class_costs_json'] = json_encode( $costs );

            update_option( 'cirrusly_shipping_config', $conf );
        }

        // Step 4: Visuals
        if ( $step === 4 ) {
            // MSRP
            $msrp = get_option( 'cirrusly_msrp_config', array() );
            $msrp['enable_display'] = isset( $_POST['enable_msrp'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_msrp_config', $msrp );

            // Badges
            $badges = get_option( 'cirrusly_badge_config', array() );
            $badges['enable_badges']     = isset( $_POST['enable_badges'] ) ? 'yes' : 'no';
            $badges['smart_inventory']   = isset( $_POST['smart_inventory'] ) ? 'yes' : 'no';
            $badges['smart_performance'] = isset( $_POST['smart_performance'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_badge_config', $badges );
        }

        // Step 5: Finish
        if ( $step === 5 ) {
            // Mark wizard as complete with current version
            update_option( 'cirrusly_wizard_completed_version', defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0' );
        }

    } // End save_step
} // End Class

// Initialize the Wizard on admin screens only
if ( is_admin() ) {
    $wizard = new Cirrusly_Commerce_Setup_Wizard();
    $wizard->init();
}