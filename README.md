# Adildata APK API

Backend REST API for the Adildata mobile app.

## Base URL
```
https://api.adildata.com.ng/api.php?action=ACTION
```

## Authentication
Pass the token via **any** of:
- Header: `X-API-Token: YOUR_TOKEN`
- JSON body: `{ "token": "YOUR_TOKEN" }`
- POST field: `token=YOUR_TOKEN`
- GET param: `?token=YOUR_TOKEN`

---

## Endpoints

### No Auth Required

| Action | Method | Description |
|---|---|---|
| `health` / `ping` | GET | Health check |
| `register` | POST | Register new user + auto-generate Monnify account |
| `login` | POST | Login, returns API token |
| `data_plans` | GET/POST | List VTPass data plans for a network |

### Auth Required

| Action | Method | Description |
|---|---|---|
| `profile` | GET/POST | Full user profile with Monnify account |
| `wallet` | GET/POST | Wallet balance |
| `wallet_history` | GET/POST | Wallet transaction history |
| `transactions` | GET/POST | Service (airtime/data) transaction history |
| `dashboard_stats` | GET/POST | Dashboard summary (balance, tx counts, notification count, referral count) |
| `funding_accounts` | GET/POST | Monnify virtual accounts |
| `generate_monnify` | POST | Force-generate Monnify account |
| `verify_monnify` | GET/POST | Verify/fetch existing Monnify account |
| `buy_airtime` | POST | Purchase airtime |
| `buy_data` | POST | Purchase data bundle |
| `submit_kyc` | POST | Submit BVN/NIN — triggers Monnify account creation |
| `get_kyc_status` | GET/POST | KYC and Monnify account status |
| **`notifications`** | GET/POST | Get user notifications (admin-sent) + unread count |
| **`get_notifications`** | GET/POST | Same as `notifications` (alias with table auto-create) |
| **`get_unread_count`** | GET/POST | Bell badge — returns just `unread_count` integer |
| **`mark_notification_read`** | POST | Mark one notification read (`notification_id` or `id`) |
| **`mark_all_notifications_read`** | POST | Mark all notifications as read |
| **`referral`** | GET/POST | Referral code, link, earnings, list of referred users |
| **`get_referral_stats`** | GET/POST | Full referral stats + ready-to-share message |
| `change_password` | POST | Change account password |
| `change_pin` | POST | Change transaction PIN |

---

## Notification Endpoints (APK)

### `GET /api.php?action=notifications`
Returns all admin-sent notifications for this user with read/unread status.

**Response:**
```json
{
  "status": "success",
  "data": {
    "notifications": [
      {
        "id": 1,
        "title": "Welcome to Adildata",
        "message": "Your account is ready!",
        "type": "success",
        "target": "all",
        "created_at": "2026-05-30 10:00:00",
        "is_read": false,
        "read": false
      }
    ],
    "unread_count": 1
  }
}
```

### `GET /api.php?action=get_unread_count`
Lightweight endpoint for the notification bell badge.

**Response:**
```json
{ "status": "success", "data": { "unread_count": 3 } }
```

### `POST /api.php?action=mark_notification_read`
Mark a single notification as read. Accepts either `notification_id` or `id`.

**Body:**
```json
{ "token": "...", "notification_id": 5 }
```

### `POST /api.php?action=mark_all_notifications_read`
Mark every notification for the user as read.

---

## Referral Endpoints (APK)

### `GET /api.php?action=referral`
Basic referral info.

**Response:**
```json
{
  "status": "success",
  "data": {
    "referral_code": "abc123...",
    "referral_link": "https://adildata.com.ng/easyfinder/dashboard/register?join_with_referal=abc123...",
    "total_earnings": 500,
    "referred_users": [
      { "sname": "John", "oname": "Doe", "email": "john@example.com", "date_join": "2026-05-01" }
    ]
  }
}
```

### `GET /api.php?action=get_referral_stats`
Extended referral stats including share message.

**Response:**
```json
{
  "status": "success",
  "data": {
    "referral_code": "abc123...",
    "referral_link": "https://adildata.com.ng/...",
    "total_referred": 3,
    "total_earnings": 500,
    "referred_users": [...],
    "share_message": "Join Adildata and earn on every data, airtime purchase! Use my referral code: abc123..."
  }
}
```

---

## Other Key Endpoints

### `POST /api.php?action=login`
```json
{ "email": "user@example.com", "password": "password123" }
```
Returns `token` — store this for all subsequent requests.

### `POST /api.php?action=register`
```json
{
  "fullName": "John Doe",
  "email": "user@example.com",
  "password": "password123",
  "phone": "08012345678",
  "pin": "1234",
  "state": "Lagos",
  "referal": "OPTIONAL_REFERRAL_CODE"
}
```

### `GET /api.php?action=dashboard_stats`
Returns wallet balance, transaction counts, notification count, referral count.

---

## Files
- `api.php` — Main unified API (all endpoints)
- `getAccountDetails.php` — Monnify account fetch/generate (APK direct call)
- `generateBankAccount.php` — Monnify account generation helper (include file)
- `conn.php` — DB connection

## Monnify Configuration
Set in the `edutech_settings` table:

| Key | Description |
|---|---|
| `MONNIFY_API_KEY` | Monnify API Key |
| `MONNIFY_API_SECRET` | Monnify API Secret |
| `MONNIFY_BASE_URL` | `https://api.monnify.com` |
| `MONNIFY_API_CONTRACT` | Monnify Contract Code |
