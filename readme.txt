=== ShipStation for WooCommerce ===
Contributors: woocommerce, automattic, royho, akeda, mattyza, bor0, woothemes, dwainm, laurendavissmith001, Kloon
Tags: shipping, woocommerce, automattic
Requires at least: 6.8
Tested up to: 6.9
WC tested up to: 10.6
WC requires at least: 10.4
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 4.9.8
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Power your entire shipping operation from one platform.

== Description ==

Power your entire shipping operation from one platform.

https://www.youtube.com/watch?v=V50bZh_o3uo

= The smarter way to ship, every time =
Our comprehensive shipping platform connects directly to your Woo store, consolidating all your orders into a single, intuitive dashboard. From there, you can process orders, generate shipping labels from a wide range of carriers, and automatically update tracking information back to your customers. With a centralized view of your entire shipping workflow, you can easily manage fulfillment, returns, and inventory across multiple channels. This integration simplifies complex logistics and provides the visibility you need to make informed decisions and scale your operations with confidence.

Available in local languages for the United States, Canada, United Kingdom, Australia, New Zealand, France, and Germany. [Try it for free for 30 days!](https://www.shipstation.com/?ref=partner-woocommerce&utm_campaign=partner-referrals&utm_source=woocommerce&utm_medium=partner).

= Why use ShipStation =
- __Automatically import orders__ from your Woo store and other sales channels to a single platform.
- __Access a wide network of shipping carriers__ to generate labels, compare rates, and track packages.
- __Create custom rules__ to automatically apply shipping preferences, allocate orders, and streamline repetitive tasks.
- __Generate hundreds of labels__ at once and manage fulfillment in batches to maximize productivity.
- __Simplify the returns process__ by generating return labels and managing shipments from a single dashboard.

= Get started in minutes =
1. Sign up for a [no-risk, free ShipStation trial](https://www.shipstation.com/?ref=partner-woocommerce&utm_campaign=partner-referrals&utm_source=woocommerce&utm_medium=partner).
2. Install the extension and connect your store.
3. Immediately enable discounted carrier rates or connect your own accounts.
4. Sync orders from WooCommerce and other channels and automate your order management.
5. Batch-print labels.
6. Update your customers with customized, branded emails and tracking.
7. Delight your customers, and rinse and repeat when they buy again!

== Frequently Asked Questions ==

= Where can I find documentation and a setup guide? =
You’ve come to the right place. [Our documentation](https://woocommerce.com/document/shipstation-for-woocommerce/) for ShipStation for WooCommerce includes detailed setup instructions, troubleshooting tips, and more.

= Where can I get support? =
To start, [review our troubleshooting tips](https://woocommerce.com/document/shipstation-for-woocommerce/#troubleshooting) for answers to common questions. Then, if you need further assistance, get in touch via the [official support forum](https://wordpress.org/support/plugin/woocommerce-shipstation-integration/).

= Do I need a ShipStation account? =
Yes; [sign up for a free 30-day trial](https://www.shipstation.com/?ref=partner-woocommerce&utm_campaign=partner-referrals&utm_source=woocommerce&utm_medium=partner).

= Does this extension provide real-time shipping quotes at checkout? =
No. Merchants will need a _real-time shipping quote extension_ (such as USPS, FedEx, UPS, etc.) or an alternate method (e.g. [flat rate charges](https://woocommerce.com/document/flat-rate-shipping/).

= Does ShipStation send data when not in use (e.g. for free shipping)? =
Yes; conditional exporting is not currently available.

= Why are multiple line items in a WooCommerce order combined when they reach ShipStation? =
This commonly occurs when products and variations do not have a unique [stock-keeping unit (SKU)](https://woocommerce.com/document/managing-products/product-editor-settings/#what-is-sku) assigned to them. Allocate a unique SKU to each product — and each variation of that product — to ensure order line items show up correctly in ShipStation.

= My question is not listed; where can I find more answers? =
[Review our general FAQ](https://woocommerce.com/document/shipstation-for-woocommerce/#frequently-asked-questions) or [contact support](https://wordpress.org/support/plugin/woocommerce-shipstation-integration/).

== Screenshots ==

1. Streamline not just labels, but your entire e-commerce logistics operation.
2. Delight customers with branded returns, easy tracking, and more.
3. Compare rates and save up to 90% off on top carriers.
4. Eliminate manual work, automate tasks, and ship more orders, faster.
5. Power complex international shipments with confidence.
6. Manage every order from one dashboard, with a single login.

== Changelog ==

= 4.9.8 - 2026-03-23 =
* Fix   - Decode HTML entities in item option names and values before export to prevent special characters (e.g. & and £) from appearing as HTML entities on ShipStation packing slips.
* Fix   - Prevent API credentials from being displayed when the database write fails, and ensure old keys are not deleted until new ones are successfully saved.

= 4.9.7 - 2026-03-16 =
* Fix   - Eliminate redundant order item lookups in the REST API.

= 4.9.6 - 2026-03-16 =
* Fix   - Trigger `woocommerce_api_wc_shipstation` action when calling REST API orders export endpoint for 3rd party plugins compatibility.

= 4.9.5 - 2026-03-09 =
* Tweak - WooCommerce 10.6 Compatibility.

= 4.9.4 - 2026-03-05 =
* Fix   - REST API payment adjustments now correctly use negative values for cart discounts.
* Fix   - Prevent spurious log warnings when retrieving cost of goods sold on stores with the COGS feature disabled.

= 4.9.3 - 2026-03-04 =
* Add   - Populate shipping service name in REST API order export fulfillment data.
* Fix   - Order dates exported to ShipStation via the XML and REST APIs now correctly reflect UTC time.
* Fix   - REST API order export now correctly places cart discounts in payment adjustments instead of as separate line items.

= 4.9.2 - 2026-02-16 =
* Fix   - XML API date filtering incorrectly interpreted dates using server timezone instead of ShipStation's PST/PDT timezone.

= 4.9.1 - 2026-02-03 =
* Tweak - WooCommerce 10.5 Compatibility.

= 4.9.0 - 2026-01-12 =
* Add   - REST API endpoints to export and import order data.

= 4.8.3 - 2025-12-10 =
* Tweak - WordPress 6.9 and WooCommerce 10.4 Compatibility.

= 4.8.2 - 2025-11-17 =
* Fix   - Authentication Data modal could fail to load on some environments.

= 4.8.1 - 2025-10-15 =
* Tweak - WooCommerce 10.3 compatibility.

= 4.8.0 - 2025-10-07 =
* Add   - Enhanced ShipStation authentication interface for a smoother user experience.

= 4.7.8 - 2025-09-16 =
* Fix   - Error when processing renewal via WooCommerce Subscription.

= 4.7.7 - 2025-09-15 =
* Tweak - WooCommerce 10.2 compatibility.

= 4.7.6 - 2025-08-11 =
* Tweak - WooCommerce 10.1 compatibility.

= 4.7.5 - 2025-08-05 =
* Fix   - Out of memory allocation error on checkout page.
* Fix   - Remove deprecated load_plugin_textdomain() call.

= 4.7.4 - 2025-07-07 =
* Tweak - WooCommerce 10.0 compatibility.

= 4.7.3 - 2025-06-30 =
* Fix   - Compatibility issue with WooCommerce version lower than 8.9.

= 4.7.2 - 2025-06-24 =
* Fix   - Fatal error on Checkout page.

= 4.7.1 - 2025-06-18 =
* Fix   - Fatal error on WooCommerce Subscriptions edit page.

= 4.7.0 - 2025-06-17 =
* Add   - REST API endpoints to update and retrieve product inventory data.
* Add   - Gift feature.

= 4.6.1 - 2025-06-09 =
* Tweak - WooCommerce 9.9 compatibility.

= 4.6.0 - 2025-06-02 =
* Add   - New hook `woocommerce_shipstation_shipnotify_status_updated` that will be called after the order status is changed.
* Add   - REST API endpoints to update and retrieve product inventory data.

= 4.5.2 - 2025-05-26 =
* Fix   - Security updates.
* Tweak - Update ShipStation branding.

= 4.5.1 - 2025-04-22 =
* Add   - Include the product dimensions when exporting an order to ShipStation.
* Tweak - Added a filter to allow the user to disable exporting order discounts as a separate line item to ShipStation.

= 4.5.0 - 2025-04-14 =
* Add   - woocommerce_shipstation_shipnotify_order_shipped filter - Allow to override is order shipped.
* Add   - woocommerce_shipstation_shipnotify_tracking_note filter - Allow to override tracking note.
* Add   - woocommerce_shipstation_shipnotify_send_tracking_note filter - Allow to override should tracking note be sent to customer.
* Tweak - Move woocommerce_shipstation_shipnotify action before order status is updated.

= 4.4.9 - 2025-04-07 =
* Tweak - WooCommerce 9.8 compatibility.

= 4.4.8 - 2025-03-10 =
* Fix   - Make the value of `woocommerce_shipstation_get_order_id` filter consistent by removing the conversion function.

= 4.4.7 - 2025-03-04 =
* Tweak - PHP 8.4 Compatibility.
* Tweak - WooCommerce 9.7 Compatibility.

= 4.4.6 - 2024-11-27 =
* Tweak - Reimplemented compatibility with WordPress 6.7 while maintaining unchanged execution priorities.

= 4.4.5 - 2024-10-28 =
* Tweak - WordPress 6.7 Compatibility.

= 4.4.4 - 2024-07-02 =
* Fix   - Security updates.
* Tweak - WooCommerce 9.0 and WordPress 6.6 Compatibility.

= 4.4.3 - 2024-05-27 =
* Tweak - Performance enhancement.

= 4.4.2 - 2024-04-09 =
* Fix - Cannot retrieve order number on from GET variable.

= 4.4.1 - 2024-03-25 =
* Tweak - WordPress 6.5 compatibility.

= 4.4.0 - 2024-03-19 =
* Fix - Applying WordPress coding standards.

= 4.3.9 - 2023-09-05 =
* Fix - Security updates.
* Tweaks - Developer dependencies update.
* Add - Developer QIT workflow.

= 4.3.8 - 2023-08-09 =
* Fix - Security updates.

= 4.3.7 - 2023-05-08 =
* Fix - Allow filtering the order exchange rate and currency code before exporting to ShipStation.

= 4.3.6 - 2023-04-20 =
* Fix - Compatibility for Sequential Order Numbers by WebToffee.
* Add - New query var for WC_Order_Query called `wt_order_number` to search order number.

= 4.3.5 - 2023-04-17 =
* Fix - Revert version 4.3.4's compatibility update for Sequential Order Numbers by WebToffee.

= 4.3.4 - 2023-04-12 =
* Fix   - Compatibility for Sequential Order Numbers by WebToffee.

= 4.3.3 - 2023-03-29 =
* Fix   - Fatal error when product image does not exist.

= 4.3.2 - 2022-11-29 =
* Fix   - Use product variation name when exporting a product variation.

= 4.3.1 - 2022-10-25 =
* Add   - Declared HPOS compatibility.

= 4.3.0 - 2022-10-13 =
* Add   - High-Performance Order Storage compatibility.

= 4.2.0 - 2022-09-07 =
* Add   - Filter for manipulating address export data.
* Fix   - Remove unnecessary files from plugin zip file.
* Tweak - Transition version numbering to WordPress versioning.
* Tweak - WC 6.7.0 and WP 6.0.1 compatibility.
* Fix - Remove 'translate : true' in package.json.

= 4.1.48 - 2021-11-03 =
* Fix - Critical Error when null value is passed to appendChild method.
* Fix - $logging_enabled compared against string instead of boolean.

= 4.1.47 - 2021-09-29 =
* Fix - Change API Export order search to be accurate down to the second, not just the date.

= 4.1.46 - 2021-09-10 =
* Fix   - Order is not changed to completed when the order has partial refund and is marked as shipped in ShipStation.

= 4.1.45 - 2021-08-24 =
* Fix    - Remove all usage of deprecated $HTTP_RAW_POST_DATA.

= 4.1.44 - 2021-08-12 =
* Fix    - Changing text domain to "woocommerce-shipstation-integration" to match with plugin slug.
* Fix    - Order product quantities do not sync to Shipstation when using a refund.
* Fix    - PHP notice error "wc_cog_order_total_cost" was called incorrectly.

= 4.1.43 - 2021-07-27 =
* Fix   - API returns status code 200 even when errors exist.
* Tweak - Add version compare for deprecated Order::get_product_from_item().

= 4.1.42 - 2021-04-20 =
* Fix - Use order currency code instead of store currency.

= 4.1.41 - 2021-03-02 =
* Add - Add currency code and weight units to orders XML.

= 4.1.40 - 2020-11-24 =
* Tweak - PHP 8 compatibility fixes.

= 4.1.39 - 2020-10-06 =
* Add   - Add woocommerce_shipstation_export_order_xml filter.
* Tweak - Update Readme.
* Tweak - WC 4.5 compatibility.
* Fix   - Updated shop_thumbnail to woocommerce_gallery_thumbnail for thumbnail export.

[See changelog for all versions](https://github.com/woocommerce/woocommerce-shipstation/raw/master/changelog.txt).
