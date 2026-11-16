=== FisKit for WooCommerce ===
Contributors: fiskit
Tags: factura electronica, arca, afip, argentina, invoices
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 0.1.6
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

== External services ==

This plugin relies on the FisKit API (https://api.fiskit.com.ar), an electronic invoicing service provided by FisKit (https://fiskit.com.ar). FisKit issues the invoices and credit notes with ARCA, Argentina's tax agency, which the plugin cannot do on its own. Nothing is sent until you enter a FisKit API key in the settings.

What is sent, and when:

* When you save the settings: your API key, to check that it is valid and whether it is in test or live mode.
* When an order is paid (processing or completed), or when you issue its invoice from the order screen or the bulk action: the order amounts and items, and the customer's name, address and CUIT or DNI when given, so FisKit can issue the invoice. The customer's email is sent too when the option to email the invoice is on, so FisKit can send them the PDF.
* When an order is refunded: the refund amount and reason, the same customer data and the number of the original invoice, to issue the credit note.
* At checkout, when the customer enters a CUIT for an Invoice A (registered VAT taxpayers only): that CUIT, to check it against the ARCA taxpayer registry.
* When you or the customer open an invoice PDF, and while an invoice is pending: the invoice ID, to fetch its PDF or its status.

Every request carries your API key. Requests made with an sk_test_ key go to ARCA's testing environment and have no fiscal validity.

FisKit terms of service: https://fiskit.com.ar/terms
FisKit privacy policy: https://fiskit.com.ar/privacy

== Changelog ==

= 0.1.6 =
* Translations are served by translate.wordpress.org instead of being bundled.
* External services documented in this readme.
* The notice shown when WooCommerce is not active only appears on the Plugins screen.

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
