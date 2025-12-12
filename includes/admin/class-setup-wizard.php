<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_Setup_Wizard {

    /**
     * Define versions that introduced major features requiring setup.
     */
    const MILESTONES = array( '1.7', '2.0' );

    /**
     * Initialize hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_wizard_page' ) );
        add_action( 'admin_init', array( $this, 'redirect_on_activation' ) );
        
        // Triggers for re-running the wizard
        add_action( 'admin_init', array( $this, 'detect_plan_change' ) );
        add_action( 'admin_init', array( $this, 'detect_feature_update' ) );
        
        // Move form processing and dismissal to admin_init
        add_action( 'admin_init', array( $this, 'process_actions' ) );
        
        add_action( 'admin_notices', array( $this, 'render_upgrade_notice' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_wizard_styles' ), 20 );
    }

    /**
     * Central handler for form submissions and URL actions.
     * Hooks to admin_init to ensure redirects work and nonces are verified early.
     */
    public function process_actions() {
        // 1. Handle Wizard Form Submission
        if ( isset( $_POST['save_step'], $_POST['cirrusly_wizard_nonce_field'] ) ) {
            // Retrieve step from POST to ensure we verify the correct nonce
            $step = isset( $_POST['current_step'] ) ? absint( $_POST['current_step'] ) : 1;
            if ( $step < 1 || $step > 5 ) {
                $step = 1;
            }
            
            check_admin_referer( 'cirrusly_wizard_step_' . $step, 'cirrusly_wizard_nonce_field' );


            // Check Permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'cirrusly-commerce' ) );
            }

            // Process Data
            $this->save_step( $step );

            // Redirect Logic
            if ( $step === 5 ) {
                wp_safe_redirect( admin_url( 'admin.php?page=cirrusly-commerce' ) );
                exit;
            }

            $next_step = min( 5, $step + 1 );
            wp_safe_redirect( admin_url( 'admin.php?page=cirrusly-setup&step=' . $next_step ) );
            exit;
        }

        // 2. Handle Upgrade Notice Dismissal
        if ( isset( $_GET['cirrusly_dismiss_wizard'] ) ) {
            // Verify Nonce
            check_admin_referer( 'cirrusly_dismiss_wizard_nonce' );
            
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            delete_transient( 'cirrusly_upgrade_prompt' );
            update_option( 'cirrusly_wizard_completed_version', defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0' );
            
            // Redirect back to remove the query arg
            wp_safe_redirect( remove_query_arg( array( 'cirrusly_dismiss_wizard', '_wpnonce' ) ) );
            exit;
        }
    }

    /**
     * Register the wizard page as a hidden submenu.
     */
    public function register_wizard_page() {
        add_submenu_page( 
            null, 
            __( 'Setup Cirrusly', 'cirrusly-commerce' ), 
            __( 'Setup Cirrusly', 'cirrusly-commerce' ), 
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
        if ( ! function_exists( 'cirrusly_fs' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $current_plan = 'free';
        if ( cirrusly_fs()->is_plan( 'proplus' ) ) {
            $current_plan = 'proplus';
        } elseif ( cirrusly_fs()->can_use_premium_code() ) {
            $current_plan = 'pro';
        }

        $stored_plan = get_option( 'cirrusly_last_known_plan', 'free' );

        if ( $current_plan !== $stored_plan ) {
            update_option( 'cirrusly_last_known_plan', $current_plan );
            
            $levels = array( 'free' => 0, 'pro' => 1, 'proplus' => 2 );
            if ( isset( $levels[$current_plan] ) && isset( $levels[$stored_plan] ) && $levels[$current_plan] > $levels[$stored_plan] ) {
                set_transient( 'cirrusly_upgrade_prompt', 'plan', 48 * HOUR_IN_SECONDS );
            }
        }
    }

    /**
     * Trigger 2: Major Feature Update (Version based)
     */
    public function detect_feature_update() {
        $last_setup = get_option( 'cirrusly_wizard_completed_version', '0.0.0' );
        $current_ver = defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0';

        foreach ( self::MILESTONES as $milestone ) {
            if ( version_compare( $milestone, $last_setup, '>' ) && version_compare( $current_ver, $milestone, '>=' ) ) {
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

        if ( isset( $_GET['page'] ) && 'cirrusly-setup' === $_GET['page'] ) {
            delete_transient( 'cirrusly_upgrade_prompt' );
            return;
        }

        $type = get_transient( 'cirrusly_upgrade_prompt' );
        if ( ! $type ) return;

        $url = admin_url( 'admin.php?page=cirrusly-setup' );
        // Note: Logic for handling dismissal is now in process_actions()
        $dismiss_url = wp_nonce_url( add_query_arg( 'cirrusly_dismiss_wizard', '1' ), 'cirrusly_dismiss_wizard_nonce' );

        $title = __( 'Setup Recommended', 'cirrusly-commerce' );
        $msg   = __( 'The setup wizard helps you configure important settings for optimal performance.', 'cirrusly-commerce' );

        if ( $type === 'plan' ) {
            $title = __( 'Thanks for Your Support', 'cirrusly-commerce' );
            $msg   = __( 'Additional features are available with your new plan. Run the wizard to configure them.', 'cirrusly-commerce' );
        } elseif ( $type === 'feature' ) {
            $title = __( 'New Features!', 'cirrusly-commerce' );
            $msg   = __( 'This version includes major new features. We recommend running the wizard to configure them.', 'cirrusly-commerce' );
        }

        echo '<div class="notice notice-info is-dismissible" style="padding:15px; border-left-color:#2271b1;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <h3>' . esc_html( $title ) . '</h3>
                    <p>' . esc_html( $msg ) . '</p>
                    <p>
                        <a href="' . esc_url( $url ) . '" class="button button-primary">' . esc_html__( 'Run Setup Wizard', 'cirrusly-commerce' ) . '</a> 
                        <a href="' . esc_url( $dismiss_url ) . '" class="button button-secondary" style="margin-left:10px;">' . esc_html__( 'Dismiss', 'cirrusly-commerce' ) . '</a>
                    </p>
                </div>
            </div>
        </div>';
    }

    /**
     * Enqueue wizard styles.
     */
    public function enqueue_wizard_styles() {
        if ( isset( $_GET['page'] ) && 'cirrusly-setup' === $_GET['page'] ) {
            $wizard_styles = '
                .cirrusly-wizard-container { max-width: 700px; margin: 50px auto; background: #fff; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; }
                .cirrusly-wizard-header { text-align: center; margin-bottom: 30px; }
                .cirrusly-wizard-progress { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
                .cirrusly-step { font-weight: bold; color: #ccc; font-size: 14px; }
                .cirrusly-step.active { color: #2271b1; }
                .cirrusly-wizard-footer { margin-top: 30px; display: flex; justify-content: flex-end; align-items: center; gap: 15px; border-top: 1px solid #eee; padding-top: 20px; }
                .cirrusly-pricing-grid { display: flex; gap: 15px; margin-top: 20px; }
                .cirrusly-pricing-col { flex: 1; border: 1px solid #ddd; padding: 20px; border-radius: 5px; text-align: center; background: #f9f9f9; }
                .cirrusly-pricing-col.featured { border-color: #2271b1; background: #f0f6fc; transform: scale(1.02); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
                .cirrusly-pricing-col h4 { margin: 0 0 10px; font-size: 1.2em; }
                .cirrusly-tag { display: inline-block; background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; text-transform: uppercase; margin-bottom: 10px; }
                .cirrusly-feature-list { text-align: left; font-size: 12px; margin: 15px 0; color: #555; list-style: none; padding: 0; }
                .cirrusly-feature-list li { margin-bottom: 5px; }
            ';
            wp_add_inline_style( 'cirrusly-admin-css', $wizard_styles );
        }
    }

    /**
     * Main Renderer: Handles display only. Logic moved to process_actions.
     */
    public function render_wizard() {
        $step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
        if ( $step < 1 || $step > 5 ) {
            $step = 1;
        }        

        ?>
        <div class="wrap">
            <div class="cirrusly-wizard-container">
                <div class="cirrusly-wizard-header">
                     <img src="<?php echo esc_url( CIRRUSLY_COMMERCE_URL . 'assets/images/logo.svg' ); ?>" style="height: 40px; width: auto;" alt="Cirrusly Commerce">
                    <h2 style="margin-top: 10px;"><?php esc_html_e( 'Setup Guide', 'cirrusly-commerce' ); ?></h2>
                </div>

                <div class="cirrusly-wizard-progress">
                    <span class="cirrusly-step <?php echo esc_attr( $step >= 1 ? 'active' : '' ); ?>"><?php esc_html_e( '1. License', 'cirrusly-commerce' ); ?></span>
                    <span class="cirrusly-step <?php echo esc_attr( $step >= 2 ? 'active' : '' ); ?>"><?php esc_html_e( '2. Connect', 'cirrusly-commerce' ); ?></span>
                    <span class="cirrusly-step <?php echo esc_attr( $step >= 3 ? 'active' : '' ); ?>"><?php esc_html_e( '3. Finance', 'cirrusly-commerce' ); ?></span>
                    <span class="cirrusly-step <?php echo esc_attr( $step >= 4 ? 'active' : '' ); ?>"><?php esc_html_e( '4. Visuals', 'cirrusly-commerce' ); ?></span>
                    <span class="cirrusly-step <?php echo esc_attr( $step >= 5 ? 'active' : '' ); ?>"><?php esc_html_e( '5. Finish', 'cirrusly-commerce' ); ?></span>
                </div>

                <form method="post" enctype="multipart/form-data">
                    <?php 
                    // Explicit nonce field name for better verification in process_actions
                    wp_nonce_field( 'cirrusly_wizard_step_' . $step, 'cirrusly_wizard_nonce_field' );
                    
                    // Pass current step in a hidden field so POST handling knows which step logic to run
                    echo '<input type="hidden" name="current_step" value="' . esc_attr( $step ) . '">';
                    
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

    private function render_step_license() {
        $is_pro = function_exists('cirrusly_fs') && cirrusly_fs()->can_use_premium_code();
        $is_plus = function_exists('cirrusly_fs') && cirrusly_fs()->is_plan('proplus');
        
        if ( $is_pro ) {
            echo '<div style="text-align:center; padding: 40px;">
                <span class="dashicons dashicons-yes-alt" style="font-size:60px; height:60px; width:60px; color:#008a20;"></span>
                <h3>' . esc_html__( 'Premium License Active!', 'cirrusly-commerce' ) . '</h3>
                <p>' . sprintf( 
                    /* translators: s: Feature Level */
                    esc_html__( 'You have unlocked %s features.', 'cirrusly-commerce' ), 
                    '<strong>' . ($is_plus ? esc_html__('Pro Plus', 'cirrusly-commerce') : esc_html__('Pro', 'cirrusly-commerce')) . '</strong>'
                ) . '</p>                <div class="cirrusly-wizard-footer">
                    <button type="submit" name="save_step" class="button button-primary button-hero">' . esc_html__( 'Let\'s Configure &rarr;', 'cirrusly-commerce' ) . '</button>
                </div>
            </div>';
            return;
        }

        $upgrade_url = function_exists('cirrusly_fs') ? cirrusly_fs()->get_upgrade_url() : '#';
        ?>
        <h3><?php esc_html_e( 'Choose your Edition', 'cirrusly-commerce' ); ?></h3>
        <p><?php esc_html_e( 'The free version offers essential features to get you started. However, upgrading unlocks automation and advanced tools. Take advantage of a risk-free trial to explore these benefits, or continue with your current plan.', 'cirrusly-commerce' ); ?></p>
        
        <div class="cirrusly-pricing-grid">
            <div class="cirrusly-pricing-col">
                <h4><?php esc_html_e( 'Free', 'cirrusly-commerce' ); ?></h4>
                <p style="font-size: 24px; font-weight: bold;"><?php esc_html_e( '$0', 'cirrusly-commerce' ); ?></p>
                <ul class="cirrusly-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Health Scan (Manual)', 'cirrusly-commerce' ); ?></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Profit Audit', 'cirrusly-commerce' ); ?></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Basic Badges', 'cirrusly-commerce' ); ?></li>
                </ul>
                <button type="submit" name="save_step" class="button button-secondary" style="width:100%;"><?php esc_html_e( 'Continue Free', 'cirrusly-commerce' ); ?></button>
            </div>

            <div class="cirrusly-pricing-col">
                <span class="cirrusly-tag"><?php esc_html_e( 'Best Value', 'cirrusly-commerce' ); ?></span>
                <h4><?php esc_html_e( 'Pro', 'cirrusly-commerce' ); ?></h4>
                <p style="font-size: 24px; font-weight: bold;"><?php esc_html_e( '3-Day Trial', 'cirrusly-commerce' ); ?></p>
                <ul class="cirrusly-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <strong><?php esc_html_e( 'API Sync', 'cirrusly-commerce' ); ?></strong></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Multi-Profile Profit', 'cirrusly-commerce' ); ?></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Smart Inventory Badges', 'cirrusly-commerce' ); ?></li>
                </ul>
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="button button-primary" style="width:100%;"><?php esc_html_e( 'Start Trial', 'cirrusly-commerce' ); ?></a>
                <p style="font-size:11px; color:#777; margin-top:5px;"><?php esc_html_e( 'Opens in new window.', 'cirrusly-commerce' ); ?> <br><?php esc_html_e( 'Refresh after purchase.', 'cirrusly-commerce' ); ?></p>
            </div>

            <div class="cirrusly-pricing-col featured">
                <span class="cirrusly-tag"><?php esc_html_e( 'Automated', 'cirrusly-commerce' ); ?></span>
                <h4><?php esc_html_e( 'Pro Plus', 'cirrusly-commerce' ); ?></h4>
                <p style="font-size: 24px; font-weight: bold;"><?php esc_html_e( '7-Day Trial', 'cirrusly-commerce' ); ?></p>
                <ul class="cirrusly-feature-list">
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <strong><?php esc_html_e( 'All Pro Features', 'cirrusly-commerce' ); ?></strong></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Automated Discounts', 'cirrusly-commerce' ); ?></li>
                    <li><span class="dashicons dashicons-yes" style="color:green;"></span> <?php esc_html_e( 'Dynamic Repricing', 'cirrusly-commerce' ); ?></li>
                </ul>
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="button button-primary" style="width:100%;"><?php esc_html_e( 'Start Trial', 'cirrusly-commerce' ); ?></a>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #777;">
            <a href="#" onclick="location.reload();"><?php esc_html_e( 'I\'ve already started my trial (Refresh)', 'cirrusly-commerce' ); ?></a>
        </div>
        <?php
    }

    private function render_step_connect() {
        $gcr = get_option( 'cirrusly_google_reviews_config', array() );
        $val = isset( $gcr['merchant_id'] ) ? $gcr['merchant_id'] : '';
        
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        $upload_success = get_transient( 'cirrusly_wizard_upload_success' );
        if ( $upload_success ) {
            delete_transient( 'cirrusly_wizard_upload_success' );
        }
        ?>
        <h3><?php esc_html_e( 'Connect Google Merchant Center', 'cirrusly-commerce' ); ?></h3>
        <p><?php esc_html_e( 'Enter your Merchant ID to enable Health Scans.', 'cirrusly-commerce' ); ?></p>
        <?php if ( ! empty( $upload_success ) ) : ?>
            <div class="notice notice-success" style="margin:10px 0;">
                <p><?php esc_html_e( 'Service Account JSON uploaded successfully.', 'cirrusly-commerce' ); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Merchant ID', 'cirrusly-commerce' ); ?></th>
                <td><input type="text" name="cirrusly_merchant_id" value="<?php echo esc_attr( $val ); ?>" class="regular-text" placeholder="e.g. 123456789"></td>
            </tr>
            <?php if ( $is_pro ): ?>
            <tr>
                <th><?php esc_html_e( 'Service Account JSON', 'cirrusly-commerce' ); ?> <span class="cirrusly-tag">PRO</span></th>
                <td>
                    <input type="file" name="cirrusly_service_account" accept=".json">
                    <p class="description"><?php esc_html_e( 'Upload your Google Cloud Key for Real-Time API scanning. This requires advanced setup. Refer to our documentation for guidance.', 'cirrusly-commerce' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <div class="cirrusly-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero"><?php esc_html_e( 'Next: Financials &rarr;', 'cirrusly-commerce' ); ?></button>
        </div>
        <?php
    }

    private function render_step_finance() {
        $conf = get_option( 'cirrusly_shipping_config', array() );
        $pct = isset( $conf['payment_pct'] ) ? $conf['payment_pct'] : 2.9;
        $flat = isset( $conf['payment_flat'] ) ? $conf['payment_flat'] : 0.30;
        
        $costs = isset( $conf['class_costs_json'] ) ? json_decode( $conf['class_costs_json'], true ) : array();
        if ( ! is_array( $costs ) ) {
            $costs = array();
        }
        $def_cost = isset( $costs['default'] ) ? $costs['default'] : 10.00;
        
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        ?>
        <h3><?php esc_html_e( 'Profit Engine Setup', 'cirrusly-commerce' ); ?></h3>
        <p><?php esc_html_e( 'The Cirrusly Commerce Profit Engine is what sets our plugin apart from others. We recommend spending extra time on this step to ensure your costs are accurately configured. The more accurate your inputs, the better your profit insights.', 'cirrusly-commerce' ); ?></p>
        
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Payment Fees', 'cirrusly-commerce' ); ?></th>
                <td>
                    <input type="number" step="0.1" name="cirrusly_payment_pct" value="<?php echo esc_attr( $pct ); ?>" style="width: 70px;"> % + 
                    <input type="number" step="0.01" name="cirrusly_payment_flat" value="<?php echo esc_attr( $flat ); ?>" style="width: 70px;"> $
                    <p class="description"><?php esc_html_e( 'e.g., Stripe is usually 2.9% + $0.30', 'cirrusly-commerce' ); ?></p>
                </td>
            </tr>
            <?php if ( $is_pro ): ?>
            <tr style="background: #f0f6fc;">
                <th><?php esc_html_e( 'Multi-Profile', 'cirrusly-commerce' ); ?> <span class="cirrusly-tag">PRO</span></th>
                <td>
                    <label><input type="radio" name="cirrusly_profile_mode" value="single" <?php checked('single', isset($conf['profile_mode'])?$conf['profile_mode']:'single'); ?>> <?php esc_html_e( 'Single', 'cirrusly-commerce' ); ?></label>
                    <label><input type="radio" name="cirrusly_profile_mode" value="multi" <?php checked('multi', isset($conf['profile_mode'])?$conf['profile_mode']:''); ?>> <?php esc_html_e( 'Mixed (PayPal + Stripe)', 'cirrusly-commerce' ); ?></label>
                    <p class="description"><?php esc_html_e( 'Calculates blended rates for split-payment stores.', 'cirrusly-commerce' ); ?></p>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e( 'Avg. Shipping Cost', 'cirrusly-commerce' ); ?></th>
                <td>
                    <input type="number" step="0.01" name="cirrusly_default_shipping" value="<?php echo esc_attr( $def_cost ); ?>" class="regular-text"> $
                    <p class="description"><?php esc_html_e( 'Used as the default cost for products without a specific Shipping Class.', 'cirrusly-commerce' ); ?></p>
                </td>
            </tr>
        </table>

        <div class="cirrusly-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero"><?php esc_html_e( 'Next: Storefront &rarr;', 'cirrusly-commerce' ); ?></button>
        </div>
        <?php
    }

    private function render_step_visuals() {
        $is_pro = Cirrusly_Commerce_Core::cirrusly_is_pro();
        
        $msrp_config = get_option( 'cirrusly_msrp_config', array() );
        $badge_config = get_option( 'cirrusly_badge_config', array() );

        $enable_msrp = isset( $msrp_config['enable_display'] ) ? $msrp_config['enable_display'] : 'yes';
        $enable_badges = isset( $badge_config['enable_badges'] ) ? $badge_config['enable_badges'] : 'yes';
        $smart_inventory = isset( $badge_config['smart_inventory'] ) ? $badge_config['smart_inventory'] : 'yes';
        $smart_performance = isset( $badge_config['smart_performance'] ) ? $badge_config['smart_performance'] : 'yes';
        ?>
        <h3><?php esc_html_e( 'Storefront Appearance', 'cirrusly-commerce' ); ?></h3>
        <p><?php esc_html_e( 'Enable visual features to increase urgency and conversion.', 'cirrusly-commerce' ); ?></p>
        
        <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <label>
                <input type="checkbox" name="cirrusly_enable_msrp" value="yes" <?php checked( 'yes', $enable_msrp ); ?>> 
                <strong><?php esc_html_e( 'MSRP Strikethrough', 'cirrusly-commerce' ); ?></strong>
            </label>
            <p class="description" style="margin-left: 25px;"><?php esc_html_e( 'Shows "Original Price" crossed out.', 'cirrusly-commerce' ); ?></p>
        </div>

        <div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <label>
                <input type="checkbox" name="cirrusly_enable_badges" value="yes" <?php checked( 'yes', $enable_badges ); ?>> 
                <strong><?php esc_html_e( 'Smart Badges', 'cirrusly-commerce' ); ?></strong>
            </label>
            <p class="description" style="margin-left: 25px;"><?php esc_html_e( 'Standard "New" and "Sale" badges.', 'cirrusly-commerce' ); ?></p>
            
            <?php if ( $is_pro ): ?>
            <div style="margin-left: 25px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ccc;">
                <span class="cirrusly-tag">PRO</span><br>
                <label><input type="checkbox" name="cirrusly_smart_inventory" value="yes" <?php checked( 'yes', $smart_inventory ); ?>> <?php esc_html_e( 'Low Stock Warning (Qty < 5)', 'cirrusly-commerce' ); ?></label><br>
                <label><input type="checkbox" name="cirrusly_smart_performance" value="yes" <?php checked( 'yes', $smart_performance ); ?>> <?php esc_html_e( 'Best Seller Badge', 'cirrusly-commerce' ); ?></label>
            </div>
            <?php endif; ?>
        </div>

        <div class="cirrusly-wizard-footer">
            <button type="submit" name="save_step" class="button button-primary button-hero"><?php esc_html_e( 'Finish Setup &rarr;', 'cirrusly-commerce' ); ?></button>
        </div>
        <?php
    }

    private function render_step_finish() {
        ?>
        <div style="text-align: center;">
            <span class="dashicons dashicons-yes-alt" style="font-size: 80px; width: 80px; height: 80px; color: #008a20; margin-bottom: 20px;"></span>
            <h3><?php esc_html_e( 'Setup Complete!', 'cirrusly-commerce' ); ?></h3>
            <p><?php esc_html_e( 'Your store is now configured.', 'cirrusly-commerce' ); ?></p>
            <br>
            <button type="submit" name="save_step" class="button button-primary button-hero"><?php esc_html_e( 'Complete Setup', 'cirrusly-commerce' ); ?></button>
        </div>
        <?php
    }

    /**
     * Save handler for all steps.
     * Note: Nonce is now verified in process_actions() before calling this.
     */
    private function save_step( $step ) {
        if ( $step === 2 ) {
            $data = get_option( 'cirrusly_google_reviews_config', array() );
            $data['merchant_id']    = isset( $_POST['cirrusly_merchant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cirrusly_merchant_id'] ) ) : '';
            $data['enable_reviews'] = ! empty( $data['merchant_id'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_google_reviews_config', $data );

            if ( isset( $_FILES['cirrusly_service_account']['error'] ) 
                 && $_FILES['cirrusly_service_account']['error'] === UPLOAD_ERR_OK
                 && ! empty( $_FILES['cirrusly_service_account']['tmp_name'] ) 
                 && Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                if ( class_exists( 'Cirrusly_Commerce_Settings_Pro' ) ) {
                     $input = get_option( 'cirrusly_scan_config', array() );
                     if ( isset( $_FILES['cirrusly_service_account'] ) ) {
                         $input = Cirrusly_Commerce_Settings_Pro::cirrusly_process_service_account_upload( $input, $_FILES['cirrusly_service_account'] );
                         update_option( 'cirrusly_scan_config', $input );
                        if ( isset( $input['service_account_uploaded'] ) && $input['service_account_uploaded'] === 'yes' ) {
                            set_transient( 'cirrusly_wizard_upload_success', true, 30 );
                        }
                     }
                }
            }
        }

        if ( $step === 3 ) {
            $conf = get_option( 'cirrusly_shipping_config', array() );
            $conf['payment_pct']  = isset( $_POST['cirrusly_payment_pct'] ) ? floatval( wp_unslash( $_POST['cirrusly_payment_pct'] ) ) : 2.9;
            $conf['payment_flat'] = isset( $_POST['cirrusly_payment_flat'] ) ? floatval( wp_unslash( $_POST['cirrusly_payment_flat'] ) ) : 0.30;
            
            if ( isset( $_POST['cirrusly_profile_mode'] ) ) {
                $conf['profile_mode'] = sanitize_text_field( wp_unslash( $_POST['cirrusly_profile_mode'] ) );
            }

            $costs = isset( $conf['class_costs_json'] ) ? json_decode( $conf['class_costs_json'], true ) : array();
            if ( ! is_array( $costs ) ) {
                $costs = array();
            }
            
            if ( isset( $_POST['cirrusly_default_shipping'] ) ) {
                $costs['default'] = sanitize_text_field( wp_unslash( $_POST['cirrusly_default_shipping'] ) );
            }
            $conf['class_costs_json'] = json_encode( $costs );

            update_option( 'cirrusly_shipping_config', $conf );
        }

        if ( $step === 4 ) {
            $msrp = get_option( 'cirrusly_msrp_config', array() );
            $msrp['enable_display'] = isset( $_POST['cirrusly_enable_msrp'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_msrp_config', $msrp );

            $badges = get_option( 'cirrusly_badge_config', array() );
            $badges['enable_badges']     = isset( $_POST['cirrusly_enable_badges'] ) ? 'yes' : 'no';
            $badges['smart_inventory']   = isset( $_POST['cirrusly_smart_inventory'] ) ? 'yes' : 'no';
            $badges['smart_performance'] = isset( $_POST['cirrusly_smart_performance'] ) ? 'yes' : 'no';
            update_option( 'cirrusly_badge_config', $badges );
        }

        if ( $step === 5 ) {
            update_option( 'cirrusly_wizard_completed_version', defined('CIRRUSLY_COMMERCE_VERSION') ? CIRRUSLY_COMMERCE_VERSION : '1.0.0' );
        }

    } 
} 

if ( is_admin() ) {
    $cirrusly_wizard = new Cirrusly_Commerce_Setup_Wizard();
    $cirrusly_wizard->init();
}