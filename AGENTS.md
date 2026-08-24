# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project overview

CHIP for VirtueMart is a Joomla/VirtueMart payment plugin that integrates the
CHIP Collect API (`https://gate.chip-in.asia/api/v1`). It uses the hosted-redirect
flow: create a CHIP purchase, redirect the customer to CHIP, then reconcile the
order status via webhook + redirect. Reference implementation for CHIP behaviour
is `chip-for-woocommerce` (sibling repo); the shared API spec lives at
`~/chip-platform/CHIP-API-SPEC.md`.

VirtueMart source (for the plugin API) is cloned at `~/refs/virtuemart`
(upstream SVN: `http://dev.virtuemart.net/svn/virtuemart/trunk/virtuemart/`).

## Commands

```bash
# Syntax check (there is no build step; it is pure PHP)
php -l chip.php
php -l helpers/chip_api.php

# Validate the manifest XML
php -r '$x=simplexml_load_file("chip.xml"); var_dump((string)$x->name);'
```

No linter is configured; follow the existing style (tabs, `{` on new lines).

## Architecture

### Plugin lifecycle

- `chip.php` defines `class plgVmPaymentChip extends vmPSPlugin`. The class name
  is resolved by `vDispatcher` as `plgVmPayment` + `ucfirst($element)`, so the
  folder, the `chip.xml` `<filename plugin="chip">` attribute, and the class name
  must all agree on `chip`.
- `__construct()` registers the core config vars (`addVarsToPushCore`) and the
  plugin table fields. All other config vars (secret key, brand id, whitelist,
  order-status mappings, fees) are read automatically from `chip.xml` via the
  base `getVarsToPush()`.

### Payment events implemented

| Event | Role |
|-------|------|
| `plgVmConfirmedOrder` | Build purchase params, `POST /purchases/`, store purchase ID, redirect to CHIP |
| `plgVmOnPaymentNotification` | Webhook (`success_callback`): verify `X-Signature`, update order status |
| `plgVmOnPaymentResponseReceived` | Customer redirect: re-check status, render thank-you, empty cart |
| `plgVmOnUserPaymentCancel` | Cancel flow |
| `plgVmOnUpdateOrderPayment` | Refund when order moves to the *refunded* status |
| `plgVmOnShowOrderBEPayment` | Backend order view (purchase ID + status) |
| plus the standard VM3 passthrough events | |

### Order data access

- Billing address: `$order['details']['BT']` (fields `email`, `first_name`,
  `last_name`, `address_1`, `address_2`, `city`, `zip`, `phone_1`,
  `virtuemart_country_id`, `virtuemart_state_id`, `virtuemart_order_id`,
  `order_number`, `order_total`, `payment_currency_id`).
- Order items: `$order['items']` (`order_item_name`, `product_quantity`,
  `product_item_price`).
- Currency code: `ShopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3')`.
- Amount in payment currency: `vmPSPlugin::getAmountInCurrency($order_total, $currencyId)`.
- Order ID by number: `VirtueMartModelOrders::getOrderIdByOrderNumber($n)`.
- Status update: `VmModel::getModel('orders')->updateStatusForOneOrder($id, $order, true)`.

### CHIP integration (mirrors chip-for-woocommerce)

- Amounts are sent in **sen** (minor units): `round($amount * 100)`.
- `purchase` params include `total_override`, `due_strict`, `timezone`, `currency`,
  `language`, `products`.
- `client` array drops empty fields.
- `reference` is the VirtueMart `order_number`; used to bind the webhook back to
  the order via `getOrderIdByOrderNumber`.
- Payment method whitelist is a **comma-separated string** in method params
  (VirtueMart has no multiselect JSON field type); `resolvePaymentMethodGroups()`
  resolves the DuitNow QR / Shopee Pay / Card groups. `card` is an in-memory
  aggregator key that is expanded to `visa`/`mastercard`/`maestro` and stripped
  before sending to CHIP (CHIP does not recognise `card` as a whitelist value).
- Webhook signature is verified with `openssl_verify` (RSA SHA-256) against the
  CHIP public key; on missing/invalid signature, fall back to
  `GET /purchases/{id}/`.

### Key constants / names

- API base: `https://gate.chip-in.asia/api/v1` (defined in `helpers/chip_api.php`).
- Creator agent: `VirtueMart: 1.0.0`.
- Platform string: `api` (CHIP has no dedicated `virtuemart`/`joomla` enum value;
  the valid `platform` choices are `api`, `woocommerce`, `prestashop`, `opencart`,
  `magento`, `whmcs`, `shopify`, `gravityforms`, `givewp`, `fluentforms`, etc. —
  custom integrations use `api`).

## Pitfalls

- **SVN trunk vs released 4.8.0 API drift**: the SVN trunk (`~/refs/virtuemart`)
  has migrated core classes to the `VirtueMart\` namespace and renamed helpers
  (`vmFactory`, `vmUri`, `shopFunctions` lowercase). The **released** VirtueMart
  4.8.0 (what merchants actually run) still uses the GLOBAL namespace and
  Joomla's `JFactory`/`JURI`/`JRoute` plus `ShopFunctions` (capital S). Always
  target the released API: `JFactory::getLanguage()/getApplication()/getDbo()`,
  `JURI::root()`, `ShopFunctions::getCountryByID()/getStateByID()/getCurrencyByID()`.
  Do NOT add `use VirtueMart\...` imports — they break on 4.8.0.
- **Class name case**: PHP is case-insensitive, so `plgVmPaymentChip` matches the
  canonical `plgVmPaymentStandard` convention; `vDispatcher` builds
  `plgVmPayment` + `ucfirst('chip')`.
- **Config vars come from the XML**: do not add a `getVarsToPush()` override that
  hardcodes fields already declared in `chip.xml`; the base class parses the XML
  automatically. Only `addVarsToPushCore()` (fees/restrictions/currencies) is
  called in the constructor.
- **`_getPaymentResponseHtml()`** is NOT in the base `vmPSPlugin` class — it is
  plugin-defined (see skrill/tco). It must be implemented here.
- **`email_currency`** must be present in `getTableSQLFields()` if
  `storePSPluginInternalData` writes it (it does, in `plgVmConfirmedOrder`).
- **Field types** must exist in `administrator/components/com_virtuemart/fields/`.
  Use `vmfiles` for logo selection (not `imagelist`), `vmorderstate` for statuses,
  `vmacceptedcurrency` for currency.
- **No WP functions**: this runs under Joomla. The API client is
  framework-agnostic (cURL with stream fallback); never use `wp_remote_request`.
- **Secret handling**: do not commit real secret keys / brand IDs.
