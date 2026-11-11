# FisKit for WooCommerce

[Español más abajo](#español)

Issues the ARCA (formerly AFIP) electronic invoice of every paid WooCommerce order through [FisKit](https://fiskit.com.ar), and a credit note for every refund. No code: install, paste your key, done.

- **Monotributista:** Invoice C for every order.
- **Registered VAT taxpayer (responsable inscripto):** Invoice A when the customer enters the CUIT of another registered taxpayer (checked against the ARCA registry), Invoice B for everyone else.
- The customer gets the PDF by email and can download it from My Account.
- The order shows the voucher number and CAE, with the PDF one click away.
- If ARCA is down, the invoice is retried in the background and is never issued twice.
- Works with the classic and the block checkout, and with High-Performance Order Storage.

## Install

1. Download `fiskit-for-woocommerce.zip` from the [latest release](https://github.com/fiskitapp/fiskit-woocommerce/releases/latest).
2. In WordPress: Plugins → Add New → Upload Plugin, choose the zip and activate it.
3. WooCommerce → Settings → Integration → FisKit: paste your API key (from the FisKit dashboard, API Keys) and pick your tax status.

Start with an `sk_test_...` key: invoices go to ARCA's testing environment and have no fiscal validity. Switch to `sk_live_...` when the test orders look right. Before that, connect ARCA in the FisKit dashboard.

Requirements: WordPress 6.2+, WooCommerce 8.0+, PHP 7.4+, prices in Argentine pesos.

---

## Español

Emite la factura electrónica de ARCA (ex AFIP) de cada pedido pagado de WooCommerce con [FisKit](https://fiskit.com.ar), y una nota de crédito por cada reembolso. Sin programar: lo instalás, pegás tu clave y listo.

- **Monotributista:** Factura C en cada pedido.
- **Responsable inscripto:** Factura A cuando el cliente carga el CUIT de otro responsable inscripto (se verifica en el padrón de ARCA) y Factura B para el resto.
- El cliente recibe el PDF por email y lo puede descargar desde Mi cuenta.
- En el pedido ves el número y el CAE, con el PDF a un clic.
- Si ARCA está caída, la factura se reintenta sola y nunca se emite dos veces.
- Funciona con el checkout clásico y con el de bloques.

**Instalación:**

1. Descargá `fiskit-for-woocommerce.zip` de la [última versión](https://github.com/fiskitapp/fiskit-woocommerce/releases/latest).
2. En WordPress: Plugins → Añadir nuevo → Subir plugin, elegí el zip y activalo.
3. WooCommerce → Ajustes → Integración → FisKit: pegá tu clave de API (del panel de FisKit, en Claves de API) y elegí tu condición fiscal.

Empezá con una clave `sk_test_...`: las facturas van a la homologación de ARCA, sin validez fiscal. Cuando los pedidos de prueba se vean bien, cambiala por la `sk_live_...`. Antes, conectá ARCA en el panel de FisKit.
