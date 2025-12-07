<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap">
    <?php Cirrusly_Commerce_Core::render_global_header( __( 'User Manual', 'cirrusly-commerce' ) ); ?>

    <div class="cc-notice-top">
        <p style="margin:0;">
            <?php _e( '<strong>🚧 Work in Progress:</strong> We are currently working on a comprehensive version of this manual.', 'cirrusly-commerce' ); ?>
        </p>
    </div>

    <div class="card" style="max-width: 1000px; padding: 40px; margin-top: 20px;">
        <h2 style="margin-top:0;"><?php _e( 'Cirrusly Commerce User Manual', 'cirrusly-commerce' ); ?></h2>
        <p>
            <strong><?php _e( 'Version:', 'cirrusly-commerce' ); ?></strong> 
            <?php echo esc_html( CIRRUSLY_COMMERCE_VERSION ); ?>
        </p>
        <hr>

        <nav class="cc-manual-nav" style="background:#f0f6fc; padding:15px; border-radius:4px; margin-bottom:30px; line-height: 2;">
            <strong><?php _e( 'Quick Links:', 'cirrusly-commerce' ); ?></strong> 
            <a href="#intro"><?php _e( 'Introduction', 'cirrusly-commerce' ); ?></a>
            <a href="#dashboard"><?php _e( 'Dashboard & Widgets', 'cirrusly-commerce' ); ?></a>
            </nav>

        <div class="cc-manual-section" id="intro">
            <h3><?php _e( 'Introduction', 'cirrusly-commerce' ); ?></h3>
            <p><?php _e( 'Cirrusly Commerce is a comprehensive suite designed to optimize your WooCommerce store...', 'cirrusly-commerce' ); ?></p>
        </div>

        </div>
</div>