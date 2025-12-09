=== Cirrusly Commerce ===

Contributors: edoswald
Tags: Google Merchant Center, WooCommerce, pricing, MSRP, profit margin
Requires at least: 5.8 
Tested up to: 6.9 
Stable tag: 1.3 
Requires PHP: 8.1 
License: GPLv2 or later 
License URI: http://www.gnu.org/licenses/gpl-2.0.html

All-in-one suite: GMC Validator, Promotion Manager, Pricing Engine, and Store Financial Audit.

== Description ==

Cirrusly Commerce is the "Financial Operating System" for WooCommerce stores running Google Shopping ads. It is the only plugin that combines **Google Merchant Center compliance**, **Financial Auditing**, and **Profit Optimization** into a single suite.

Stop guessing if you are profitable. Stop worrying about Merchant Center suspensions.

### 1. GMC Health & Compliance (The "Safety Net")
Your product feed is your business's lifeline. We protect it.
* **Diagnostics Scan:** Instantly check your entire catalog for critical issues like missing GTINs, prohibited words (e.g., "cure", "free shipping" in titles), and policy violations.
* **Suspension Prevention:** We actively block you from saving products with critical data errors that could get your Merchant Center account banned. (Free)
* **Auto-Fix & Clean:** Automatically strip banned words and fix formatting issues the moment you click save. (Pro)
* **Real-Time API Sync:** Bypass the 24-hour feed fetch delay. Push price, stock, and status changes to Google instantly via the Content API. (Agency)

### 2. Profit Engine & Pricing
Revenue is vanity; profit is sanity.
* **Real-Time Margin Calculator:** See your exact Net Profit and Margin % right on the product edit screen. We account for Cost of Goods (COGS), Shipping Costs, and Payment Processor fees (Stripe/PayPal).
* **MSRP Display:** Automatically display "List Price" (MSRP) comparisons on your product pages to increase conversion.
* **Automated Discounts:** Full support for Google's AI-driven "Automated Discounts" program. The plugin validates Google's secure pricing tokens (JWT) and dynamically updates the customer's cart to match the ad price. (Agency)
* **Psychological Pricing:** Automatically round calculated prices to .99, .50, or the nearest 5 to maximize sales psychology. (Pro)

### 3. Financial Audit Dashboard
* **Store Health at a Glance:** A dedicated dashboard identifying "Loss Makers" (products losing money) and "Low Margin" items.
* **Bulk Data Management:** Quickly identify products missing COGS data.
* **Inline Editing:** Fix cost and pricing data directly from the audit table via AJAX without opening hundreds of tabs. (Pro)

### 4. Badge Manager
* **Custom Badges:** Upload custom icons (e.g., "Made in USA") based on product tags.
* **Smart Badges:** Replace default "Sale" badges with dynamic triggers like "Low Stock" (Inventory), "Best Seller" (Performance), or "New Arrival". (Pro)
* **Event Scheduler:** Schedule "Black Friday" or "Cyber Monday" badges to appear automatically during specific date ranges. (Agency)

### 5. Growth Tools
* **Google Customer Reviews:** One-click integration for the Google Customer Reviews survey on your checkout "Thank You" page.
* **Promotion Manager:** Generate valid promotion IDs and snippets for Google's Promotion Feed.
* **Direct API Submit:** Create and push promotions to Google Merchant Center with a single click. (Agency)

### Compatibility
Cirrusly Commerce is designed to work seamlessly alongside your favorite tools:
* Product Feed PRO (AdTribes)
* Google Product Feed (Ademti)
* Rank Math SEO, Yoast SEO, All in One SEO (AIOSEO), & SEOPress (Schema Injection included)
* WooCommerce Cost of Goods (SkyVerge) & WPFactory

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

= 1.3 =
**Note:** Effective from this release, only changes to the free version of the plugin will be listed. For changes to the Pro version of the plugin (which also include the free changes), please refer to the pro version README instead.
* Refactor of entire plugin to improve both frontend and admin loading times
* **New Feature:** Gutenberg Blocks! 

= 1.2.1 =
* **New Feature:** Introduced "Freemium" architecture. Pro features are now visible in the interface (grayed out) to showcase advanced capabilities if downloading from Freemius. They are not visible from the WP Plugin Directory version. 
* **Enhancement:** Added "System Info" tool to the support menu (header) to easily copy environment details for faster troubleshooting.
* **Enhancement:** Pricing Engine now supports 5%, 15%, and 25% "Off MSRP" strategies.
* **Enhancement:** Pricing Engine now supports "Nearest 5/0" rounding (e.g., $12.95 -> $15.00, $8.20 -> $10.00).
* **UI Update:** Reorganized settings into tabbed cards for better usability.
* **UI Update:** Added "Hide Pro Features" toggle in General Settings for users who prefer a cleaner, free-only interface.
* **Fix:** Downloadable/virtual items are now correctly handled (no shipping/cost is likely, so alerts suppressed)
* **Fix:** Removal/fixing of code that caused instability post-1.0.5 on non-test stores. Some code was still referring to pre-v1.0 architecture (code snippets).
* **Fix:** Sent emails now are actually useful.

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

