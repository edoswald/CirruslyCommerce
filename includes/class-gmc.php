<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Cirrusly_Commerce_GMC {

    /**
     * Initialize the GMC integration: load admin and pro submodules when available and register core hooks.
     *
     * Registers handlers for product meta saving (standard, quick-edit, and bulk-edit) and for the admin
     * "mark as custom" redirect action; loads the admin UI when running in admin context and loads the
     * Pro module when the site is pro and the pro file exists.
     */
    public function __construct() {
        // 1. Load Sub-Modules
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'admin/class-gmc-ui.php';
            new Cirrusly_Commerce_GMC_UI();
        }

        // 2. Pro Logic Loading (API & Automation)
        if ( Cirrusly_Commerce_Core::cirrusly_is_pro() && file_exists( plugin_dir_path( __FILE__ ) . 'pro/class-gmc-pro.php' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'pro/class-gmc-pro.php';
            new Cirrusly_Commerce_GMC_Pro();
        }

        // 3. Core Data Handlers (Always needed)
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );
        add_action( 'woocommerce_product_quick_edit_save', array( $this, 'save_quick_bulk_edit' ) );
        add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_quick_bulk_edit' ) );

        // 4. Handle "Mark as Custom" action (Redirect logic)
        add_action( 'admin_post_cc_mark_custom', array( $this, 'handle_mark_custom' ) );
    }

    /**
     * Entry point for the Admin Page (Called by admin menu)
     */
    public static function render_page() {
        if ( class_exists( 'Cirrusly_Commerce_GMC_UI' ) ) {
            $ui = new Cirrusly_Commerce_GMC_UI();
            $ui->render_gmc_hub_page();
        }
    }

    /**
     * Persist GMC-related product meta and clear related promo statistics.
     *
     * Updates the product's `_gla_identifier_exists` meta to `'no'` when the
     * POST field `gmc_is_custom_product` is present, otherwise sets it to `'yes'`.
     * When present in POST, saves sanitized values for `_gmc_promotion_id` and
     * `_gmc_custom_label_0`. Deletes the transient `cirrusly_active_promos_stats`.
     *
     * @param int $post_id The ID of the product post being saved.
     */
    public function save_product_meta( $post_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $val = isset( $_POST['gmc_is_custom_product'] ) ? 'no' : 'yes';
        update_post_meta( $post_id, '_gla_identifier_exists', $val );
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_gmc_promotion_id'] ) ) {
            update_post_meta( $post_id, '_gmc_promotion_id', sanitize_text_field( wp_unslash( $_POST['_gmc_promotion_id'] ) ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_gmc_custom_label_0'] ) ) {
            update_post_meta( $post_id, '_gmc_custom_label_0', sanitize_text_field( wp_unslash( $_POST['_gmc_custom_label_0'] ) ) );
        }
        
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    /**
     * Update a product's GMC identifier flag based on quick/bulk edit input and clear cached promotion stats.
     *
     * When the request includes `gmc_is_custom_product`, the product meta `_gla_identifier_exists` is set to `no`.
     * When the request indicates a quick edit (`woocommerce_quick_edit`) but not a bulk edit, the meta is set to `yes`.
     *
     * @param \WC_Product $product The product being edited; its ID is used to update post meta.
     */
    public function save_quick_bulk_edit( $product ) {
        $post_id = $product->get_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_REQUEST['gmc_is_custom_product'] ) ) {
            update_post_meta( $post_id, '_gla_identifier_exists', 'no' );
        } elseif ( isset( $_REQUEST['woocommerce_quick_edit'] ) && ! isset( $_REQUEST['bulk_edit'] ) ) {
            update_post_meta( $post_id, '_gla_identifier_exists', 'yes' );
        }
        delete_transient( 'cirrusly_active_promos_stats' );
    }

    /**
     * Marks a product as non-custom and redirects to the GMC admin scan tab.
     *
     * Verifies the current user has the 'edit_products' capability and the request nonce for the provided `pid`,
     * updates the product meta `_gla_identifier_exists` to `'no'` for that post ID, then redirects to the
     * Cirrusly GMC scan page and exits. If the user lacks capability, the request is terminated with an error;
     * nonce verification will also halt the request on failure.
     */
    public function handle_mark_custom() {
        if ( ! current_user_can( 'edit_products' ) ) wp_die('No permission');
        $pid = intval( $_GET['pid'] );
        check_admin_referer( 'cc_mark_custom_' . $pid );
        update_post_meta( $pid, '_gla_identifier_exists', 'no' );
        wp_redirect( admin_url('admin.php?page=cirrusly-gmc&tab=scan&msg=custom_marked') );
        exit;
    }

    /**
     * Returns the set of GMC-monitored terms grouped by category and their enforcement metadata.
     *
     * Each top-level key is a category (e.g., 'promotional', 'medical'). Each category maps terms to an
     * associative array with keys:
     * - `severity`: enforcement level such as "Medium", "High", or "Critical".
     * - `scope`: where the term is monitored (e.g., "title", "all").
     * - `reason`: short explanation for monitoring or restriction.
     *
     * @return array<string, array<string, array{severity:string,scope:string,reason:string}>> Associative array of categories to term metadata.
     */
    public static function get_monitored_terms() {
        return array(
            'promotional' => array(
                'free shipping' => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Allowed in descriptions, but prohibited in titles.'),
                'sale'          => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Prohibited in titles. Use "Sale Price".'),
                'buy one'       => array('severity' => 'Medium', 'scope' => 'title', 'reason' => 'Promotional text.'),
                'best price'    => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim (Misrepresentation).'),
                'cheapest'      => array('severity' => 'High',   'scope' => 'title', 'reason' => 'Subjective claim.'),
            ),
            'medical' => array( 
                'cure'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim.'),
                'heal'        => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Medical claim implying permanent fix.'),
                'virus'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Prohibited sensitive event claim.'),
                'covid'       => array('severity' => 'Critical', 'scope' => 'all', 'reason' => 'Sensitive event.'),
                'guaranteed'  => array('severity' => 'Medium',   'scope' => 'all', 'reason' => 'Must have linked policy.')
            )
        );
    }
}