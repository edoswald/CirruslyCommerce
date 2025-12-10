<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Cirrusly_Commerce_Audit_Pro {

    /**
     * Initialize Pro hooks.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Stream compiled audit data as a CSV download when the Cirrusly Audit admin export is requested.
     *
     * When the request includes page=cirrusly-audit and action=export_csv, this method verifies the current
     * user has the `edit_products` capability and that the Pro feature is enabled. It sends CSV headers,
     * writes a header row and one CSV row per compiled audit record, then terminates the request.
     *
     * CSV filename pattern: store-audit-YYYY-MM-DD.csv
     *
     * Columns emitted: ID, Product Name, Type, Cost (COGS), Shipping Cost, Price, Net Profit, Margin %,
     * MAP, Google Min, MSRP. Price-like columns that are not greater than zero are emitted as empty.
     */
    public static function handle_export() {
        if ( isset($_GET['page']) && $_GET['page'] === 'cirrusly-audit' && isset($_GET['action']) && $_GET['action'] === 'export_csv' ) {
            if ( ! current_user_can( 'edit_products' ) ) return;
            
            // Double check pro status
            if ( ! Cirrusly_Commerce_Core::cirrusly_is_pro() ) {
                wp_die('Export is a Pro feature.');
            }

            // Reuse the core calculation method
            $data = Cirrusly_Commerce_Audit::get_compiled_data();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="store-audit-' . gmdate('Y-m-d') . '.csv"');
            
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fopen
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
            fclose($fp); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fclose
            exit;
        }
    }

    /**
     * Import product COGS and pricing fields from an uploaded CSV and update matching products' metadata.
     *
     * Processes the uploaded `csv_import` file (when the current user can edit products and the Pro feature is enabled),
     * reads the header to map columns (handles a leading BOM on the first column), requires an "ID" column, and for each
     * row updates available values on the matching post ID. Updated fields and their meta keys:
     * - "Cost (COGS)" -> `_cogs_total_value`
     * - "MAP" -> `_cirrusly_map_price`
     * - "Google Min" -> `_auto_pricing_min_price`
     * - "MSRP" -> `_alg_msrp`
     *
     * Price-like values are normalized with `wc_format_decimal()` before saving. On failure this method registers
     * appropriate settings errors; on success it clears the `cw_audit_data` transient and registers a success message
     * indicating how many products were updated.
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.is_readable
            if ( ! $file || ! is_readable( $file ) ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'Could not read uploaded CSV file.', 'error' );
                return;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fopen
            $handle = fopen( $file, 'r' );
            if ( ! $handle ) {
                add_settings_error( 'cirrusly_audit', 'import_fail', 'Failed to open uploaded CSV file.', 'error' );
                return;
            }
            
            // 1. Get Header Row & Map Indices
            $header = fgetcsv($handle);
            if ( ! $header ) {
                fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fclose
                add_settings_error( 'cirrusly_audit', 'import_fail', 'CSV file is empty or has no header row.', 'error' );
                return;
            }

             $map = array();
             foreach ( $header as $index => $col_name ) {
                 $col_name = (string) $col_name;
                 if ( 0 === $index ) {
                     $col_name = preg_replace( '/^\xEF\xBB\xBF/', '', $col_name ); // Remove BOM
                 }
                 $key = trim( $col_name );
                 if ( '' === $key ) continue;
                 $map[ $key ] = $index;
             }

            if ( ! isset($map['ID']) ) {
                add_settings_error('cirrusly_audit', 'import_fail', 'Invalid CSV: Missing "ID" column.', 'error');
                fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fclose
                return;
            }

            $count = 0;
            while (($row = fgetcsv($handle)) !== FALSE) {
                $id_idx = $map['ID'];
                if ( empty($row[$id_idx]) ) continue;
                
                $pid = intval( $row[ $id_idx ] );
                if ( ! $pid || ! get_post( $pid ) ) continue;

                $updated = false;

                $update_field = function( $key, $meta_key, $is_price = true ) use ($row, $map, $pid, &$updated) {
                    if ( isset($map[$key]) && array_key_exists($map[$key], $row) && $row[$map[$key]] !== '' ) {
                        $val = $row[$map[$key]];
                        if ( $is_price ) $val = wc_format_decimal($val);
                        update_post_meta($pid, $meta_key, $val);
                        $updated = true;
                    }
                };

                $update_field('Cost (COGS)', '_cogs_total_value');
                $update_field('MAP', '_cirrusly_map_price');
                $update_field('Google Min', '_auto_pricing_min_price');
                $update_field('MSRP', '_alg_msrp');

                if ( $updated ) $count++;
            }
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations.fclose
            delete_transient( 'cw_audit_data' ); 
            add_settings_error('cirrusly_audit', 'import_success', "Updated data for $count products.", 'success');
        }
    }
}