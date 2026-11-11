=== FisKit for WooCommerce ===
Contributors: fiskit
Tags: factura electronica, arca, afip, argentina, invoices
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 0.1.5
License: MIT
License URI: https://opensource.org/licenses/MIT

Issues the ARCA (ex AFIP) electronic invoice of every paid order, and a credit note for every refund.

== Description ==

FisKit for WooCommerce invoices every paid order with ARCA (formerly AFIP), Argentina's tax agency, through the [FisKit](https://fiskit.com.ar) API. No code: install it, paste your key and every sale is invoiced on its own.

* Monotributistas issue Invoice C. Registered VAT taxpayers issue Invoice A to customers who enter the CUIT of another registered taxpayer, checked against the ARCA registry, and Invoice B to everyone else.
* The customer gets the PDF, with ARCA's QR code, by email and in My Account.
* Each refund issues its credit note, linked to the original invoice.
* If ARCA is slow or down, the invoice is retried in the background and is never issued twice.
* Works with the classic and the block checkout, and with High-Performance Order Storage.

Emite la factura electrónica de ARCA de cada pedido pagado y la nota de crédito de cada reembolso. El cliente recibe el PDF por email.

= External service =

This plugin sends the order data needed for the invoice (amounts, items, and the customer's name, address, email and CUIT or DNI when given) to the FisKit API at https://api.fiskit.com.ar, which issues it with ARCA. It only does so when an API key is set. More about the service at https://fiskit.com.ar.

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce → Settings → Integration → FisKit.
3. Paste your API key from the FisKit dashboard (API Keys) and choose your tax status.

Start with an sk_test_ key: those invoices go to ARCA's testing environment and have no fiscal validity.

== Frequently Asked Questions ==

= Do I need a FisKit account? =

Yes. Create it at https://fiskit.com.ar, connect ARCA from the dashboard and create an API key.

= What happens if ARCA is down? =

The plugin retries in the background with the same idempotency key, so the invoice is issued once ARCA answers and never twice.

= Which currency? =

Only orders in Argentine pesos (ARS) are invoiced.

== Changelog ==

= 0.1.5 =
* Tested up to WordPress 7.1.
* The plugin name stays "FisKit for WooCommerce" in every language.

= 0.1.4 =
* Plugin URI points to the plugin repository.

= 0.1.3 =
* Invoice column in the orders list.
* Bulk action to issue the invoices of several paid orders, including those that failed.
* The customer emails link to the invoice; opened too early, the page says it is still being issued.

= 0.1.2 =
* The API key is checked when the settings are saved, and the connection mode (test or live) is shown.
* The VAT rate is only shown to registered VAT taxpayers.
* Notice about not connecting Mercado Pago in FisKit as well, to avoid invoicing twice.
* Orders marked as completed without going through processing are invoiced too.

= 0.1.1 =
* The plugin now lives in the fiskitapp organization on GitHub.

= 0.1.0 =
* First release.
