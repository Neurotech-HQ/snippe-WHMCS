# Snippe WHMCS Payment Gateway

WHMCS payment gateway module for [Snippe](https://snippe.sh) — accept mobile money, card, and QR payments in Tanzania directly from your WHMCS install.

This module creates a Snippe **hosted checkout session** for each invoice and redirects the customer to a mobile-optimised payment page. Paid invoices are reconciled automatically via signed webhooks.

## Features

- Hosted checkout — no PCI scope, Snippe renders the payment UI
- Mobile money (M-Pesa, Airtel Money, Mixx by Yas, Halotel), Visa/Mastercard, dynamic QR
- TZS settlement to your Snippe balance
- HMAC-SHA256-signed webhooks with replay protection
- Automatic invoice reconciliation via `addInvoicePayment()`

## Repository layout

```
snippe-WHMCS/
├── modules/
│   └── gateways/
│       ├── snippe.php                  # gateway module (defines snippe_config, snippe_link, snippe_MetaData)
│       ├── snippe/
│       │   └── whmcs.json              # marketplace manifest (drop logo.png alongside)
│       └── callback/
│           └── snippe.php              # webhook handler — verifies signature, marks invoice paid
├── README.md
└── LICENSE
```

## Requirements

- WHMCS 8.0 or newer
- PHP 7.4+ with `curl`, `json`, and `hash` extensions (all standard)
- A Snippe merchant account ([sign up](https://snippe.sh))
- A publicly reachable HTTPS URL for your WHMCS install (Snippe rejects HTTP webhooks)

## Installation

### 1. Upload the files

Copy the contents of `modules/` into your WHMCS install root, preserving the directory structure:

| Source (this repo) | Destination on the WHMCS server |
| --- | --- |
| `modules/gateways/snippe.php` | `<whmcs-root>/modules/gateways/snippe.php` |
| `modules/gateways/snippe/whmcs.json` | `<whmcs-root>/modules/gateways/snippe/whmcs.json` |
| `modules/gateways/callback/snippe.php` | `<whmcs-root>/modules/gateways/callback/snippe.php` |

Example over SSH:

```bash
scp -r modules/* user@your-whmcs-host:/path/to/whmcs/modules/
```

Or clone directly on the server and copy:

```bash
cd /tmp
git clone https://github.com/Neurotech-HQ/snippe-WHMCS.git
cp -r snippe-WHMCS/modules/* /path/to/whmcs/modules/
```

After upload, set permissions to match the rest of `modules/gateways/`:

```bash
chown www-data:www-data <whmcs-root>/modules/gateways/snippe.php
chown www-data:www-data <whmcs-root>/modules/gateways/callback/snippe.php
chmod 644 <whmcs-root>/modules/gateways/snippe.php
chmod 644 <whmcs-root>/modules/gateways/callback/snippe.php
```

(Replace `www-data` with your web server user.)

### 2. Get your Snippe credentials

In the [Snippe Dashboard](https://snippe.sh):

1. **API Key** — Settings → API Keys → Create key. Select scopes `collection:create` and `collection:read`. Copy the `snp_...` value (shown only once).
2. **Webhook Signing Secret** — Settings → Webhook Secret. Copy the secret.
3. **Payment Profile ID** *(optional)* — Settings → Payment Profiles. Copy `prof_...` if you want consistent branding across sessions.

### 3. Activate the gateway in WHMCS

1. WHMCS Admin → **Setup** → **Payments** → **Payment Gateways**
2. Click the **All Payment Gateways** tab
3. Find **Snippe Payment Gateway** in the list and click to activate
4. Fill the configuration form:

   | Field | Value |
   | --- | --- |
   | API Key | Your `snp_...` API key |
   | Webhook Signing Secret | Your webhook signing secret |
   | Payment Profile ID | Optional `prof_...` |
   | Allowed Methods | `mobile_money,card,qr` (omit any you don't want) |
   | Session Expiry (seconds) | `3600` |

5. Click **Save Changes**

### 4. Register the webhook URL with Snippe

In the Snippe Dashboard → **Webhooks** → **Add endpoint**:

- **URL**: `https://<your-whmcs-domain>/modules/gateways/callback/snippe.php`
- **Events**: enable `payment.completed` (recommended: also `payment.failed`, `payment.expired`)

The URL must be **HTTPS** and ≤ 500 characters. Plain HTTP is rejected.

### 5. Make sure WHMCS can charge in TZS

WHMCS Admin → **Setup** → **Payments** → **Currencies** — add **TZS** as an available currency. Snippe accepts **TZS only**; any other currency returns a validation error.

For products priced in another currency, configure WHMCS auto-conversion or set TZS prices explicitly.

## How it works

```
┌────────────────┐  click "Pay with Snippe"   ┌─────────────────────────────┐
│ Customer (WHMCS│ ─────────────────────────► │ snippe.php POST handler     │
│  invoice page) │                            │ POST /api/v1/sessions       │
└────────────────┘                            └──────────────┬──────────────┘
        ▲                                                    │ checkout_url
        │ redirect back after pay                            ▼
        │                                     ┌─────────────────────────────┐
        │                                     │ snippe.me/checkout/...      │
        │                                     │ (Snippe hosted page)        │
        │                                     └──────────────┬──────────────┘
        │                                                    │ payment.completed
        │                                                    ▼
        │                                     ┌─────────────────────────────┐
        └─────────────────────────────────────│ callback/snippe.php         │
                                              │ - verify HMAC signature     │
                                              │ - addInvoicePayment()       │
                                              └─────────────────────────────┘
```

1. The customer clicks **Pay with Snippe** on the invoice page. The form posts the encoded session payload to the gateway file itself.
2. `snippe.php` calls `POST https://api.snippe.sh/api/v1/sessions` with `Authorization: Bearer <api_key>`, the invoice amount in TZS, and `metadata.invoice_id` so the webhook can find the invoice later.
3. Snippe returns `data.checkout_url`. The gateway issues an HTTP 302 to that URL and the customer pays on Snippe's hosted page.
4. On payment success, Snippe POSTs a `payment.completed` event to `callback/snippe.php`.
5. The callback verifies `X-Webhook-Signature` (HMAC-SHA256 over `{timestamp}.{raw_body}`), rejects timestamps older than 5 minutes, looks up the invoice by `data.metadata.invoice_id`, and calls `addInvoicePayment()` with `data.amount.value` and `data.settlement.fees.value`.
6. Snippe redirects the customer to `viewinvoice.php?id=<id>&paymentsuccess=true`, where WHMCS shows the paid invoice.

## Configuration reference

All settings live in the WHMCS gateway settings page (Setup → Payments → Payment Gateways → Snippe Payment Gateway).

| Setting | Required | Description |
| --- | --- | --- |
| API Key | Yes | Snippe API key with `collection:create` + `collection:read` scopes. Stored encrypted in `tblpaymentgateways`. |
| Webhook Signing Secret | Yes | HMAC secret used to verify webhook authenticity. Required for invoices to be marked paid. |
| Payment Profile ID | No | Snippe payment profile (`prof_...`) — applies brand colour, logo, locale to the checkout page. |
| Allowed Methods | No | Comma-separated subset of `mobile_money`, `card`, `qr`. Empty/missing = all methods enabled on the account. |
| Session Expiry (seconds) | No | Default `3600`. How long the checkout URL is valid before expiry. |

## Testing

1. Confirm TZS is enabled in WHMCS currencies.
2. Create a test client with a Tanzanian phone number (`255XXXXXXXXX`).
3. Generate an invoice in TZS — minimum **500 TZS** (Snippe's payment floor).
4. Open the invoice as the client → click **Pay with Snippe**.
5. You'll land on `snippe.me/checkout/...`. Complete payment with a test mobile number.
6. After Snippe processes the payment, you'll be redirected to `viewinvoice.php?id=<id>&paymentsuccess=true`.
7. Verify the invoice flips to **Paid** in WHMCS admin.

## Troubleshooting

### Gateway doesn't appear in the WHMCS admin list

- Confirm the file is at exactly `<whmcs-root>/modules/gateways/snippe.php` (not in a subfolder).
- Run `php -l <whmcs-root>/modules/gateways/snippe.php` — must report no syntax errors.
- Verify the file owner/permissions match `azampay.php` or other working gateways: `ls -la <whmcs-root>/modules/gateways/`.
- Check no UTF-8 BOM at the top of the file: `head -c 5 snippe.php | xxd` should start with `3c 3f 70 68 70` (`<?php`).
- Hard-refresh the admin page (Ctrl+Shift+R).

### "Configuration function ( _config ) not found"

The file was loaded but `snippe_config()` wasn't registered. Almost always a corrupted upload or a fatal error before the function definition. Re-upload via SFTP in text/ASCII mode.

### "Invalid signature" in transaction logs

- The webhook signing secret in WHMCS doesn't exactly match the one in the Snippe Dashboard. Re-paste it.
- A reverse proxy or WAF is modifying the request body before it reaches PHP. Verify against the **raw** body — never `json_encode(json_decode($body))`.
- Server clock drift > 5 minutes against UTC. Run `timedatectl` and ensure NTP is enabled.

### Webhooks aren't reaching the server

- The webhook URL must be **HTTPS** with a valid TLS cert (Snippe won't deliver to self-signed certs in production).
- Test reachability: `curl -X POST https://<your-whmcs-domain>/modules/gateways/callback/snippe.php -H "Content-Type: application/json" -d '{}'` should return `400` with `Missing webhook headers` (not a timeout or 502).
- Check WHMCS Admin → **Utilities → Logs → Activity Log** and **Gateway Log** for entries.

### Payment succeeds at Snippe but invoice stays unpaid

- The signature check is failing — see "Invalid signature" above.
- The webhook secret isn't set in the gateway config (the callback exits early with `Webhook secret not configured`).
- The `metadata.invoice_id` isn't reaching Snippe. Inspect the **Gateway Log** for the `create-link` entry and confirm `metadata.invoice_id` is in the JSON payload.

### Useful WHMCS log locations

- **Gateway Log**: Admin → Utilities → Logs → Gateway Log — shows requests/responses logged via `logModuleCall`.
- **Transactions**: Admin → Utilities → Logs → Transactions — shows callback outcomes logged via `logTransaction`.
- **Activity Log**: Admin → Utilities → Logs → Activity Log — module load errors, file-not-found.

## Limits and constraints (from Snippe)

| Constraint | Value |
| --- | --- |
| Currency | TZS only |
| Minimum payment | 500 TZS |
| Webhook URL | HTTPS, ≤ 500 chars |
| Session lifetime | Configurable, default 1 hour |
| API rate limit | 60 requests/minute |
| Webhook retry schedule | 3 → 6 → 12 → 24 minutes (5 attempts max) |

For full API behaviour see the [Snippe docs](https://snippe.sh/docs/2026-01-25).

## Security notes

- API key and webhook secret are stored encrypted by WHMCS in `tblpaymentgateways` — never hard-code them in source.
- The callback verifies signatures with `hash_equals()` (constant-time) to prevent timing attacks.
- Webhook timestamps older than 5 minutes are rejected to prevent replay attacks.
- Make sure your `<whmcs-root>` is not world-writable — gateway secrets sit in the WHMCS database, but config files and `.htaccess` rules around it must be tight.

## Contributing

Issues and PRs welcome at [github.com/Neurotech-HQ/snippe-WHMCS](https://github.com/Neurotech-HQ/snippe-WHMCS).

## License

See `LICENSE`. The module is provided as-is; production use is at your own risk — please test against a sandbox/staging WHMCS before going live.

## Support

- **Snippe API issues** — Snippe support / [docs](https://snippe.sh/docs/2026-01-25)
- **WHMCS module issues** — open an issue on this repo
- **WHMCS platform questions** — [WHMCS Developer Docs](https://developers.whmcs.com/payment-gateways/)
