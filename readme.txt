=== UltiCommerce PayPal ===
Contributors: thondalmonde
Tags: paypal, payment, gateway, checkout, e-commerce
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PayPal payment gateway for UltiCommerce. Process payments via PayPal V2 Checkout Orders.

== Description ==

UltiCommerce PayPal adds PayPal payment processing to UltiCommerce. Customers pay through PayPal's V2 Checkout Orders API, with support for both return-based capture and webhook fallback.

Major features:

* PayPal V2 Checkout Orders integration
* Sandbox mode for testing
* Automatic payment capture on return from PayPal
* Webhook fallback via `CHECKOUT.ORDER.APPROVED` and `PAYMENT.CAPTURE.COMPLETED`
* Configurable client ID, secret, and webhook ID

Requires the UltiCommerce core plugin.

== Installation ==

1. Upload the `ulticommerce-paypal` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Go to Orders → PayPal to configure your API credentials
4. Create a PayPal app at https://developer.paypal.com/dashboard/applications to get your Client ID and Secret
5. Optionally set up a webhook in your PayPal app pointing to `/wp-json/wpc/v1/payment/webhook`

== Frequently Asked Questions ==

= Do I need the UltiCommerce core plugin? =

Yes. This plugin requires the UltiCommerce core plugin to be installed and activated.

= Do I need a PayPal business account? =

Yes. You need a PayPal Business account to create REST API apps.

= What is Sandbox Mode? =

Sandbox Mode lets you test payments using PayPal's test environment without real money. Create sandbox accounts at https://developer.paypal.com.

== Changelog ==

= 1.0.0 =
*Release Date - July 2026*

* Initial release
* PayPal V2 Checkout Orders payment processing
* Return-based capture with webhook fallback
* Sandbox mode for testing
* Admin settings page for API configuration
