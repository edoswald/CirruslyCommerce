<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cirrusly_Commerce_Frontend_Assets {

	/**
	 * Hook the class's asset registration method into WordPress's frontend enqueue action.
	 *
	 * Registers `register_assets` on the `wp_enqueue_scripts` action with priority 1.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 1 );
	}

	/**
	 * Register and enqueue base frontend CSS and JavaScript handles used by Cirrusly Commerce.
	 *
	 * Registers a style handle `cirrusly-frontend-base` with no source and enqueues it.
	 * Registers a script handle `cirrusly-frontend-base` with no source, dependent on `jquery`,
	 * versioned by `CIRRUSLY_COMMERCE_VERSION`, loaded in the footer, and enqueues it.
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