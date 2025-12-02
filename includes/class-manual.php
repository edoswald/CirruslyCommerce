<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Cirrusly_Commerce_Manual {
    public static function render_page() {
        echo '<div class="wrap">'; // Moved up for consistency
        Cirrusly_Commerce_Core::render_global_header( 'User Manual' );
        ?>
            <div class="card" style="max-width: 1000px; padding: 40px; margin-top: 20px;">
                <h2 style="margin-top:0;">Cirrusly Commerce User Manual</h2>
                <p><strong>Version:</strong> <?php echo esc_html( CIRRUSLY_COMMERCE_VERSION ); ?></p>
                <hr>
                <nav style="background:#f0f6fc; padding:15px; border-radius:4px; margin-bottom:30px;">
                    <strong>Quick Links:</strong> 
                    <a href="#intro">Introduction</a> | 
                    <a href="#compat">Compatibility</a> |
                    <a href="#dashboard">Dashboard</a> | 
                    <a href="#config">Configuration</a> | 
                    <a href="#gmc">GMC Hub</a> | 
                    <a href="#pricing">Pricing Engine</a> | 
                    <a href="#badges">Badge Manager</a> |
                    <a href="#msrp">MSRP Display</a> | 
                    <a href="#support">Support</a>
                </nav>

                <h3 id="intro">What is Cirrusly Commerce?</h3>
                <p>Cirrusly Commerce is an all-in-one suite designed to optimize your WooCommerce store’s financial health and Google Merchant Center (GMC) compliance.</p>

                <h3 id="compat">Plugin Compatibility</h3>
                <p>Cirrusly Commerce is designed to work alongside popular WooCommerce plugins:</p>
                <ul>
                    <li><strong>Product Feed PRO (AdTribes):</strong> Our custom fields (MSRP, MAP, etc.) automatically appear in the attribute mapping dropdowns.</li>
                    <li><strong>Rank Math & Yoast SEO:</strong> MSRP and other fields are registered for use in meta tags and schema output.</li>
                    <li><strong>WooCommerce Subscriptions:</strong> The Pricing Engine detects subscription pricing fields automatically.</li>
                    <li><strong>Flexible Shipping (Octolize):</strong> Shipping classes created here are automatically detected for cost calculation.</li>
                    <li><strong>WPFactory MSRP:</strong> We use the same database key (<code>_alg_msrp</code>) so you can switch between plugins without losing data.</li>
                </ul>
                
                <h3 id="dashboard">The Dashboard</h3>
                <p>Use the navigation tabs to access key tools. The dashboard widgets provide a quick snapshot of system health, margins, and issues.</p>

                <h3 id="config">Configuration</h3>
                <p>Ensure your <strong>Shipping Revenue Tiers</strong> are set up in the Settings tab to ensure accurate profit calculations. If a critical error occurs, try resetting the tiers.</p>

                <h3 id="gmc">GMC Hub</h3>
                <p>Use the <strong>Health Check</strong> to scan your catalog for compliance issues. Use the <strong>Promotion Manager</strong> to generate valid CSV codes for Google's promotion feed.</p>

                <h3 id="pricing">Pricing Engine</h3>
                <p>Located on the product edit screen. Enter your financial data to see real-time profit margins:</p>
                <ul>
                    <li><strong>Google Min ($):</strong> The lowest price Google is allowed to auto-discount this item to (for Automated Discounts).</li>
                    <li><strong>MAP ($):</strong> Minimum Advertised Price. Used for internal policy compliance.</li>
                    <li><strong>MSRP ($):</strong> Manufacturer Suggested Retail Price.</li>
                </ul>
                <p>Use the dropdown strategies to auto-calculate optimal prices (e.g. "10% Off MSRP").</p>
                
                <h3 id="badges">Badge Manager</h3>
                <p>Automatically replace default theme sale badges with smart, percentage-based pills. Add custom image badges via the settings tab.</p>

                <h3 id="msrp">MSRP Display</h3>
                <p>We recommend using the <strong>Gutenberg Block ("MSRP Display")</strong> for full control. Use the block settings sidebar to bold text or remove strikethroughs.</p>

                <h3 id="support" style="margin-top:40px; border-top:1px solid #eee; padding-top:20px;">Support</h3>
                <p>If you encounter issues or have questions, please use the button below to email our support team directly. Be sure to include your plugin version number.</p>
                <p>
                    <a href="mailto:help@cirruslyweather.com?subject=Support%20Request%3A%20Cirrusly%20Commerce" class="button button-primary button-large">Email Support</a>
                </p>
                
                <h3 id="keys">Database Key Reference</h3>
                <p>If you are using a custom feed solution or need to access raw data, use these meta keys:</p>
                <table class="widefat striped" style="max-width:600px;">
                    <thead><tr><th>Field</th><th>Meta Key</th></tr></thead>
                    <tbody>
                        <tr><td>Cost of Goods</td><td><code>_cogs_total_value</code></td></tr>
                        <tr><td>GMC Floor Price</td><td><code>_auto_pricing_min_price</code></td></tr>
                        <tr><td>MSRP Price</td><td><code>_alg_msrp</code></td></tr>
                        <tr><td>MAP Price</td><td><code>_cirrusly_map_price</code></td></tr>
                        <tr><td>Promotion ID</td><td><code>_gmc_promotion_id</code></td></tr>
                        <tr><td>Custom Label 0</td><td><code>_gmc_custom_label_0</code></td></tr>
                        <tr><td>Identifier Exists</td><td><code>_gla_identifier_exists</code></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
