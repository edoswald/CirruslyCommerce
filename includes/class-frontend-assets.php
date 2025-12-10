<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cirrusly_Commerce_Frontend_Assets {

	/**
	 * Initialize frontend asset registration.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 1 );
	}

	/**
	 * Register base handles.
	 * * Since we may not have a physical file for every handle, we register 'false' as the source
	 * or use a core dependency like 'jquery' to ensure proper loading order.
	 */
	public function register_assets() {
		// Base CSS Handle
		wp_register_style( 'cirrusly-frontend-base', false );
		wp_enqueue_style( 'cirrusly-frontend-base' );
		
		// Base JS Handle (Dependent on jquery)
		wp_register_script( 'cirrusly-frontend-base', false, array( 'jquery' ), CIRRUSLY_COMMERCE_VERSION, true );
		wp_enqueue_script( 'cirrusly-frontend-base' );
	}
}