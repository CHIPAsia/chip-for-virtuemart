# CHIP for VirtueMart

A VirtueMart payment plugin for Joomla that lets merchants accept payments via
[CHIP](https://chip-in.asia) — FPX, DuitNow QR, credit/debit cards, and e-wallets.

It integrates with the CHIP Collect API using the hosted-redirect flow: the
plugin creates a CHIP purchase during checkout and redirects the customer to the
CHIP checkout page.

## Requirements

- Joomla 3.10 / 4 / 5
- VirtueMart 4.x (latest stable, compatible with Joomla 3.10–5)
- PHP 7.4+ (cURL recommended; a stream-context fallback is included)
- A CHIP merchant account (Secret Key + Brand ID)

## Installation

1. Download the plugin package (`plg_vmpayment_chip`).
2. In the Joomla backend, go to **System → Install → Extensions** and upload the
   package (or install the `plugins/vmpayment/chip` folder as a plugin).
3. Go to **VirtueMart → Shop → Payment Methods**, create a new payment method,
   and select **CHIP** as the method type.
4. Fill in your **Secret Key** and **Brand ID** (obtained from the CHIP merchant
   dashboard).

## Configuration

| Setting | Description |
|---------|-------------|
| Secret Key | CHIP secret key (Bearer token) |
| Brand ID | CHIP brand ID |
| Payment Method Whitelist | Comma-separated identifiers (e.g. `fpx,card,duitnow_qr`). Empty = all methods. |
| Due Strict | Block payments after the due time passes |
| Purchase Time Zone | IANA timezone for the purchase due timestamp |
| Order Status (pending / success / canceled / refunded) | VirtueMart order statuses per CHIP state |

### Payment method identifiers

Use these exact identifier strings in the whitelist:

`fpx`, `fpx_b2b1`, `card`, `duitnow_qr` / `dnqr`, `razer_atome`, `razer_grabpay`,
`razer_maybankqr`, `razer_shopeepay` / `shopee_pay`, `razer_tng`, `crypto_coin`.

> The DuitNow QR group (`duitnow_qr` / `dnqr`) and the Shopee Pay group
> (`razer_shopeepay` / `shopee_pay`) are resolved against the merchant's actual
> available methods at runtime, preferring `dnqr` and `shopee_pay` respectively.

## How it works

1. Customer selects CHIP at checkout and confirms the order.
2. The plugin calls `POST /purchases/` and stores the CHIP purchase ID.
3. The customer is redirected to the CHIP checkout page.
4. CHIP sends a webhook (`success_callback`) with an `X-Signature` header. The
   plugin verifies the signature against the CHIP public key (falling back to
   `GET /purchases/{id}/` when the signature is missing/invalid), then updates the
   order status.
5. The customer's redirect (`success_redirect` / `failure_redirect`) also re-checks
   the purchase status, so order status stays correct even when the webhook races
   the browser.
6. Refunds are triggered by moving an order into the configured *refunded* status.

## File layout

```
chip.php                  Main plugin class (plgVmPaymentChip extends vmPSPlugin)
chip.xml                  Joomla plugin manifest + VirtueMart config fields
helpers/chip_api.php      CHIP Collect API client (framework-agnostic)
language/en-GB/*.ini      English language strings
```

## Development

```bash
# Syntax check
php -l chip.php
php -l helpers/chip_api.php
```

See `AGENTS.md` for architecture and integration details.
