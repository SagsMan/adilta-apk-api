# Adildata APK API — Monnify Edition

Backend REST API for the Adildata mobile app.

## Base URL
```
https://api.adildata.com.ng/api.php?action=ACTION
```

## Changes in This Version
- **PaymentPoint completely removed** — all virtual account logic now uses Monnify only
- **Auto account generation on register** — Monnify account is created immediately after signup
- **Auto account generation on login** — if an existing user has no Monnify account, one is created on first login
- **Continuous loading fix** — `funding_accounts` and `getAccountDetails.php` now always return a valid account or generate one on the fly

## Endpoints

| Action | Method | Auth | Description |
|---|---|---|---|
| `health` | GET | No | Health check |
| `register` | POST | No | Register + auto-generate Monnify account |
| `login` | POST | No | Login, returns token |
| `profile` | GET/POST | Yes | User profile with Monnify account |
| `wallet` | GET/POST | Yes | Wallet balance |
| `wallet_history` | GET/POST | Yes | Wallet transaction history |
| `transactions` | GET/POST | Yes | Service transaction history |
| `dashboard_stats` | GET/POST | Yes | Dashboard summary |
| `funding_accounts` | GET/POST | Yes | **Monnify accounts only** |
| `generate_monnify` | POST | Yes | Force-generate Monnify account |
| `verify_monnify` | GET/POST | Yes | Verify/fetch Monnify account |
| `buy_airtime` | POST | Yes | Purchase airtime |
| `buy_data` | POST | Yes | Purchase data |
| `data_plans` | GET/POST | No | List data plans |
| `notifications` | GET/POST | Yes | User notifications |
| `mark_notification_read` | POST | Yes | Mark notification read |
| `referral` | GET/POST | Yes | Referral info |
| `change_password` | POST | Yes | Change password |
| `change_pin` | POST | Yes | Change transaction PIN |
| `submit_kyc` | POST | Yes | Submit BVN/NIN |

## APK-Compatible JSON Format

### `funding_accounts` response
```json
{
  "status": "success",
  "data": {
    "accounts": [
      { "provider": "Monnify", "bank_name": "Wema Bank", "account_number": "9876543210", "account_name": "JOHN DOE" }
    ],
    "has_accounts": true,
    "has_monnify": true,
    "acc_no": "9876543210",
    "bank_name": "Wema Bank",
    "acc_name": "JOHN DOE",
    "account_number": "9876543210",
    "account_name": "JOHN DOE",
    "provider": "Monnify"
  }
}
```

### `getAccountDetails.php` response
```json
{
  "success": true,
  "account_number": "9876543210",
  "bank_name": "Wema Bank",
  "account_name": "JOHN DOE",
  "provider": "Monnify"
}
```

## Monnify Configuration
Set these values in the `edutech_settings` table:
| Key | Description |
|---|---|
| `MONNIFY_API_KEY` | Your Monnify API Key |
| `MONNIFY_API_SECRET` | Your Monnify API Secret |
| `MONNIFY_BASE_URL` | `https://api.monnify.com` |
| `MONNIFY_API_CONTRACT` | Your Monnify Contract Code |

## Authentication
Pass the token via:
- Header: `X-API-Token: YOUR_TOKEN`
- POST body: `token=YOUR_TOKEN`
- GET param: `?token=YOUR_TOKEN`

## Files
- `api.php` — Main unified API (all endpoints)
- `getAccountDetails.php` — Monnify account fetch/generate (APK direct call)
- `generateBankAccount.php` — Monnify account generation helper (include file)
- `conn.php` — DB connection
- `webhook.php` — Monnify payment webhook (unchanged)
