=== Run my Accounts for WooCommerce===
* Contributors: openstream, codestylist
* Tags: Run my Accounts, WooCommerce, Billing
* Requires at least: 4.7
* Tested up to: 6.2
* Stable tag: 1.7.1
* Requires PHP: 7.2
* License: GPLv3
* License URI: https://www.gnu.org/licenses/gpl-3.0.html

This plug-in connects WooCommerce with Run my Accounts.

== Description ==

Run my Accounts for WooCommerce is a powerfully WooCommerce solution to create customers and invoice in Run My Account when a order is placed.

The main features are:
* create invoices automatically in Run my Accounts after an order is placed in WooCommerce
* create customers automatically in Run my Accounts after a new user is created in WooCommerce

Please note, you need a paid account with Run my Accounts to use this plugin.

== Installation ==
Upload the files to your server and put it in your folder /plugins/.
Activate the plugin, enter the API key and check the settings page.

== Frequently Asked Questions ==

= Can I use this plugin without WooCommerce? =

No. Run my Accounts for WooCommerce requires WooCommerce to provide all features.

= Do I need a paid account with Run my Accounts? =

Yes. Without the paid account you are not able to set up your company within Run my Accounts.

= Is the plugin for free? =

The source code is freely available, but you need a Run my Accounts API key. Openstream and Run my Accounts agreed that plugin users will be charged CHF 200.- yearly for plugin maintenance, i.e. for making sure that the plugin works with new versions of WordPress and WooCommerce.

= I miss a feature. What to do? =

Please send a request to the Openstream Internet Solutions. We provide customization on an hourly rate. Otherwise, you can fork the plugin on GitHub and develop the missing feature yourself. We only ask to send a pull request so, each user can benefit from your extension.

== Screenshots ==
1. General settings.
2. Settings for dedicated receivable account for each payment gateway.
3. Settings on user page.

== Known issues ==
* Discount codes does not be reflected on the invoice.
* Credits are not created in Run My Accounts.
* The collective invoice only works with available products. If a product was deleted between the order and the invoice, the product cannot be included in the invoice anymore.

== Changelog ==
= 1.7.1 =
* Fix - added handling cURL error on settings page
* Compatibility - tested up to WooCommerce 7.4
* Compatibility - tested up to WordPress 6.2

= 1.7.0 =
* Feature - added collective invoice per customer
* Feature - added native support for WooCommerce Rental & Booking System (https://codecanyon.net/item/rnb-woocommerce-rental-booking-system/14835145)
* Fix - fixed creating invoice sometimes returns an error when creating invoice with "status change completed"
* Fix - fixed loading language files sometimes not working
* Tweak - code improvements
* Compatibility - tested up to WooCommerce 6.3
* Compatibility - tested up to WooCommerce 5.9
* Developer - added filter 'rma_invoice_part'

= 1.6.4 =
* Tweak - added payment account when creating the invoice
* Fix - fixed debug warning when opening the settings page
* Compatibility - tested up to WooCommerce 5.8
* Compatibility - tested up to WooCommerce 6.1

= 1.6.3 =
* Tweak - code improvements
* Compatibility - tested up to WooCommerce 5.7 and WordPress 5.8

= 1.6.2 =
* Feature - added option to disable payment booking by payment method
* Compatibility - tested up to WooCommerce 5.3

= 1.6.1 =
* Tweak - moved shipping address from notes to internal notes
* Feature - added optional shipping text for invoicing

= 1.6.0 =
* Feature - added bulk creation for invoice on backend order page
* Feature - added handling of shipping costs as a dedicated product
* Feature - added shipping address to Run My Accounts notes field
* Feature - added payment booking in Run My Accounts
* Fix - fixed title language when creating customer
* Tweak - improved class handling
* Compatibility - tested up to WordPress 5.7
* Compatibility - tested up to WooCommerce 5.1

= 1.5.3 =
* Bug Fix - fixed issue with sku if a product has variation with different sku
* Compatibility - tested up to WooCommerce 4.8.0

= 1.5.2 =
* Tweak - fixed log error message
* Tweak - required PHP version reduced to 7.2

= 1.5.1 =
* Tweak - improved output error message
* Compatibility - tested up to WordPress 5.6.0 and WooCommerce 4.7.1

= 1.5.0 =
* Feature - added configuration for a fallback sku if WC sku does not exist in Run My Accounts
* Compatibility - tested up to WordPress 5.5.1 and WooCommerce 4.5.2

= 1.4.0 =
* Feature - added handling for WooCommerce guest order
* Feature - added update customer in Run my Accounts when user data is updated in WooCommerce
* Feature - added option for creating invoice on admin order page as long as no invoice was created
* Feature - added option for sending email on error
* Bug Fix - fixed error with wrong PHP version (min. requirement PHP 7.3)
* Tweak - added Run my Accounts customer number as column to admin user page
* Tweak - added Run my Accounts invoice number as column to admin oder page
* Tweak - added output for log information
* Tweak - improve error handling
* Tweak - added warning if PHP version is too low
* Compatibility - tested up to WordPress 5.4.2 and WooCommerce 4.2.2

= 1.3.3 =
* Tweak - optimized code to publish on wordpress.org
* Localization - added Swiss and Swiss (formal) translation

= 1.3.2 =
* Bug Fix - added delete a missing option group when plugin will be de-installed
* Compatibility - tested up to WordPress 5.3.2 and WooCommerce 3.9.1
* Compatibility - pre-tested with WooCommerce 4.0.0 Beta

= 1.3.1 =
* Bug Fix - fixed the wrong settings page when activating the plugin for the very first time

= 1.3.0 =
* Feature - added dedicated receivable account for each payment gateway
* Enhancement - use settings API for settings page
* Enhancement - added order note if invoice is created
* Enhancement - check if user_id is already linked to a Run my Accounts customer id before creating customer in Run my Accounts

= 1.2.0 =
* Feature - create customer in Run my Accounts when new WooCommerce user is created on checkout page
* Enhancement - added title to billing address if no WooCommerce Germanized is active
* Enhancement - added compatibility with WooCommerce Germanized and use the same user meta for billing title (e.g. billing_title)
* Enhancement - added warning if php extension curl is not loaded (is required for communication with Run my Accounts API)

= 1.1.1 =
* Tweak - new Run my Accounts API url

= 1.1 =
* Feature - added logging feature for RMA results
* Feature - added connection test on settings page
* Feature - added handling for different access data for live and test
* Enhancement - improved error handling
* Enhancement - improved security of files
* Localization - added German and German (formal) translation   

= 1.0 =
version 1.0 is the initial version

== Upgrade Notice ==

= 1.3.0 =
With version 1.3 we have added dedicated receivable account for each payment gateway

= 1.2.0 =
With version 1.2 we have pushed Run my Accounts for WooCommerce to your B2C business.