=== Cirrusly Commerce ===

Contributors: edoswald
Tags: Google Merchant Center, WooCommerce, pricing, MSRP, profit margin
Requires at least: 5.8 
Tested up to: 6.8 
Stable tag: 1.2 
Requires PHP: 8.0 
License: GPLv2 or later 
License URI: http://www.gnu.org/licenses/gpl-2.0.html

All-in-one suite: GMC Validator, Promotion Manager, Pricing Engine, and Store Financial Audit.

== Description ==

Cirrusly Commerce is a comprehensive suite designed to optimize your WooCommerce store's financial health and Google Merchant Center (GMC) compliance. Initially built by us for internal use as a set of code snippets on cirruslyweather.com, Cirrusly Commerce addresses the disconnect between your store's data and Google's strict requirements while providing clear insight into your proper profit margins.

Key Features:

Pricing Engine: Calculate real-time profit margins right on the product edit screen. Enter your Cost (COGS), MSRP, and MAP to see your net profit after shipping and fees instantly. Includes auto-calculation strategies (e.g., "10% Off MSRP", "Target 20% Margin") and rounding rules (.99, .50).

Key Features:

Pricing Engine: Calculate real-time profit margins right on the product edit screen. Enter your Cost (COGS), MSRP, and MAP to see your net profit after shipping and fees instantly. Includes auto-calculation strategies (e.g., "10% Off MSRP", "Target 20% Margin") and rounding rules (.99, .50).

GMC Health Check: Scan your entire catalog for critical issues that cause Google Merchant Center disapprovals, such as missing GTINs, prohibited words in titles (e.g., "Free Shipping"), and missing attributes.

MSRP Display: Display MSRP/List Price on your frontend (Product Pages and Shop Grids) to show value. Includes a Gutenberg Block for complete control over placement and styling.

Badge Manager: Automatically replace default WooCommerce sale badges with smart, percentage-based pills (e.g., "Save 20%"). Includes custom image support for product tags (e.g., USA Flag for "Made in USA" items).

Store Financial Audit: A dedicated dashboard view that lists every product with its Cost, Price, Shipping P/L, and Net Margin. Quickly identify products that are losing money or have missing cost data.

Promotions Manager: Easily manage Promotion IDs and generate valid CSV code snippets for Google's Promotion Feed.

Google Customer Reviews: One-click integration for the Google Customer Reviews survey on your checkout "Thank You" page.

Site Content Audit: Scans your pages and posts for restricted terms (medical claims, guarantees) and checks for required policy pages (Refund Policy, TOS) to prevent account-level suspensions.

Compatibility:

Cirrusly Commerce is designed to work seamlessly with:

Product Feed PRO (AdTribes)

Rank Math SEO & Yoast SEO

WooCommerce Subscriptions

Flexible Shipping (Octolize)

WPFactory MSRP (Shared data keys)

Compatibility:

Cirrusly Commerce is designed to work seamlessly with:

Product Feed PRO (AdTribes)

Rank Math SEO & Yoast SEO

WooCommerce Subscriptions

Flexible Shipping (Octolize)

WPFactory MSRP (Shared data keys)

== Installation ==

Upload the plugin files to the /wp-content/plugins/cirrusly-commerce directory, or install the plugin directly from the WordPress plugins screen.

Activate the plugin through the 'Plugins' screen in WordPress.

Navigate to Cirrusly Commerce > Settings to configure your Shipping Revenue Tiers and enable the modules you need.

**Important:** Ensure the native "Cost of Goods Sold" feature is enabled in WooCommerce > Settings > Advanced.

== Frequently Asked Questions ==

= Why are there so few customization choices? =
This is by design. Part of the reason is to prevent you from adding things that might work against the compliance features of this plugin, but it's also for speed reasons. The less customization, the smaller our plugin is, and the less code your site needs to load to display its features on the front end. Plus, we've incorporated some of our knowledge of what works and what doesn't into what customization we offer as well. 

= Does this plugin add the MSRP to my Google Feed? =
The plugin adds the data to your products (saved as _alg_msrp), but you need a feed plugin (like Product Feed PRO) to map this field to the g:price or g:sale_price attributes in your feed. We add our fields to their dropdowns automatically for easy mapping.

= Can I bulk edit the data? =
Yes! We add "Channel Data" columns to the All Products list, and you can edit Promotion IDs, Custom Labels, and Floor Prices directly from the Quick Edit menu.

= Why isn't the MSRP showing on my product page? =
Check Cirrusly Commerce > Settings > MSRP to ensure "Enable Display" is checked. If you are using a Block Theme (FSE), we recommend using the "MSRP Display" block in the editor.

== Screenshots ==

Dashboard: A high-level view of your store's health, margins, and GMC status.

Pricing Engine: Real-time margin calculation and price setting strategies on the product page.

GMC Health Check: Scan results showing critical data issues and warnings.

Store Audit: A financial breakdown of every product to spot profit leaks.

== Changelog ==

= 1.2.0 =

**Note:** Effective from this release, only changes to the free version of the plugin will be listed. For changes to the Pro version of the plugin (which also will include the free changes), please additionally refer to the README for the pro version instead.
* **New Feature:** Introduced "Freemium" architecture. Pro features are now visible in the interface (grayed out) to showcase advanced capabilities if downloading from Freemius. 
* **Enhancement:** Added "System Info" tool to the support menu (header) to easily copy environment details for faster troubleshooting.
* **Enhancement:** Pricing Engine now supports 5%, 15%, and 25% "Off MSRP" strategies.
* **Enhancement:** Pricing Engine now supports "Nearest 5/0" rounding (e.g., $12.95 -> $15.00, $8.20 -> $10.00).
* **UI Update:** Reorganized settings into tabbed cards for better usability.
* **UI Update:** Added "Hide Pro Features" toggle in General Settings for users who prefer a cleaner, free-only interface.
* **Fix:** Downloadable/virtual items are now correctly handled (no shipping/cost is likely, so alerts suppressed)
* **Fix:** Removal/fixing of code that caused instability post 1.0.5 on non test stores. Some code was still referring to pre v1.0 architecture (code snippet).

= 1.1 =

**Note:** in-house development release
* **New Feature:** Payment Processor Fees configuration (Settings > Profit Engine) allows for accurate Net Profit calculations by factoring in gateway percentages and flat fees (e.g., Stripe/PayPal).
* **New Feature:** "Profit at a Glance" column added to the Products > All Products list, displaying a color-coded margin percentage for every item.
* **New Feature:** "New Arrival" Badge module. Automatically displays a "New" badge on products created within a configurable number of days.
* **New Feature:** Store Audit Dashboard Header. A new summary strip at the top of the Audit page showing total Loss Makers, Low Margin items, and Data Alerts.
* **Enhancement:** Expanded GMC Health Check now detects "Suspicious Image Names" (e.g., filenames containing 'logo', 'watermark') and validates Product Description length.
* **Enhancement:** Context-Aware Scanning. The scanner now ignores allowed words inside other words (e.g., "secure" is no longer flagged as "cure") and distinguishes between Title (strict) and Description (lenient) restrictions.
* **Enhancement:** Added direct "Add Cost" action links in the Store Audit for products missing COGS data.
* **UX:** Added an onboarding admin notice to guide new users through shipping and fee configuration.
* **Fix:** Resolved styling issues on the main Dashboard grid.

= 1.0.5 =

Continuing work to address issues found in Plugin Check to ensure smooth approval. Sent for approval to WP Plugin Directory.

= 1.0.4 =

Style fixes and ensuring consistency across pages.

= 1.0.3 =

Restoration of scheduled scan logic and financial audit functionality lost in conversion from snippet to plugin. Redesigned settings area. Corrected math issues on the dashboard that had more products on sale than the catalog due to errors in counting variable products.

= 1.0.2 =

Security and sanitation fixes.

= 1.0.1 =

Code optimization, including security enhancements, to prepare for submission to WP Plugin Directory, and ensure adherence to best practices. Tested on 'clean' install, and active site (Cirrusly Weather).

= 1.0 =

Initial release.
