# Pharmezel API

Backend for **Pharmezel Supply Commission System**: a Laravel API (Laravel 11-style stack; PHP 8.3+) consumed by the React Native mobile app. Uses **PostgreSQL** in production (SQLite is common for local `.env` defaults).

## Role system

| Role | Capabilities (summary) |
|------|-------------------------|
| **superadmin** | Full system access: all users, orders, commissions, withdrawals, catalog writes, global/brand commission settings, admin dashboard. Seeded account for platform owner. |
| **admin** | Elevated from buyer: read catalog, **add products** and **set stock** on existing products, manage own orders, commissions, referrals, withdrawal requests; cannot create brands/categories, edit full product fields, or delete products (superadmin only). |
| **buyer** | Shop, checkout, own profile, own orders, referrals when applicable. |

**Default users (after `php artisan db:seed`):**

| Role | Email | Password | Referral code | Notes |
|------|-------|----------|---------------|--------|
| **superadmin** | `admin@pharmicare.com` | `pharmicare` | `PHARMICARE` | Use this code in buyer signup |
| **admin** | `admin@mercury.demo` | `admin` | `MERCURYAD` | Referred by superadmin; buyer demo uses this code |
| **buyer** | `buyer@demo.pharmezel.com` | `buyer` | — | Referred by admin (`MERCURYAD`); gains own code after **Become admin** |

## Setup

1. **Install dependencies**

   ```bash
   composer install
   ```

2. **Environment**

   ```bash
   cp .env.example .env
   ```

   Edit `.env`: set `DB_*` for PostgreSQL (or SQLite for local), and `MAIL_*` if using email (e.g. Brevo SMTP for OTP).

3. **Application key**

   ```bash
   php artisan key:generate
   ```

4. **Database**

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Run the API**

   ```bash
   php artisan serve
   ```

   JSON API base URL is typically `http://localhost:8000/api` (routes in `routes/api.php` are prefixed with `api` by the framework).

6. **CORS** — configured in `config/cors.php` for mobile/web clients (`allowed_origins: *`, token auth without credentials).

7. **Authentication** — Laravel Sanctum **personal access tokens** (Bearer). Tokens do not expire by default (`config/sanctum.php` → `expiration: null`); revoke via logout or DB.

## API endpoints

All paths below are under the `/api` prefix unless noted. **Auth**: `No` = public; `Bearer` = `Authorization: Bearer {token}`.

| Method | Route | Auth | Role | Description |
|--------|-------|------|------|-------------|
| GET | `/referrals/check` | No | — | Validate referral code (`?code=`); returns `valid`, `referrer_name`. |
| POST | `/login` | No | — | Login; returns token, `user_id`, `role`, `referral_code`, `points`. |
| POST | `/register/request` | No | — | Start buyer signup: send OTP (requires valid referral code). |
| POST | `/register/verify-otp` | No | — | Complete signup; returns Sanctum token. |
| POST | `/logout` | Bearer | * | Revoke current token. |
| GET | `/dashboard` | Bearer | admin, buyer | Personal dashboard stats (orders, commissions, referrals, points, recent orders). |
| GET | `/admin/dashboard` | Bearer | superadmin | Platform stats; optional `?range=today\|week\|month\|year\|all`. |
| GET | `/admin/users` | Bearer | superadmin | List users; `?role=`, `?search=`. |
| PUT | `/admin/users/{id}/role` | Bearer | superadmin | Set user role to `buyer` or `admin` (not superadmin). |
| GET | `/users/{id}` | Bearer | own or superadmin | Public profile fields (no password). |
| PUT | `/users/{id}` | Bearer | own | Update profile fields. |
| PUT | `/users/{id}/shipping-address` | Bearer | own | Update `shipping_address` only. |
| DELETE | `/users/{id}` | Bearer | * | Delete user (route exists; tighten in production if needed). |
| POST | `/users/{id}/become-admin` | Bearer | own buyer | Upgrade buyer → admin; issues referral code if missing. |
| GET | `/brands` | Bearer | * | List brands. |
| POST | `/brands` | Bearer | superadmin | Create brand. |
| PUT | `/brands/{id}/commission` | Bearer | superadmin | Set brand commission override (`commission_rate` present/nullable). |
| PUT | `/brands/{id}` | Bearer | superadmin | Update brand. |
| DELETE | `/brands/{id}` | Bearer | superadmin | Delete brand. |
| GET | `/categories` | Bearer | * | List categories. |
| POST | `/categories` | Bearer | superadmin | Create category. |
| PUT | `/categories/{id}` | Bearer | superadmin | Update category. |
| DELETE | `/categories/{id}` | Bearer | superadmin | Delete category. |
| GET | `/commission-rate` | Bearer | superadmin | Global default commission rate. |
| PUT | `/commission-rate` | Bearer | superadmin | Set global rate (`rate`). |
| GET | `/commissions` | Bearer | superadmin / admin+buyer (as referrer) | List commissions + summary; `?status=pending\|released\|cancelled`. |
| PUT | `/commissions/{id}/status` | Bearer | superadmin | Set commission `released` or `cancelled`. |
| GET | `/withdrawals` | Bearer | superadmin / own | List withdrawals. |
| POST | `/withdrawals` | Bearer | admin, buyer | Create pending withdrawal. |
| PUT | `/withdrawals/{id}/approve` | Bearer | superadmin | Approve withdrawal. |
| PUT | `/withdrawals/{id}/complete` | Bearer | superadmin | Complete; deduct points. |
| PUT | `/withdrawals/{id}/cancel` | Bearer | superadmin | Cancel (pending/approved). |
| PUT | `/withdrawals/{id}/restore` | Bearer | superadmin | Restore cancelled → pending. |
| GET | `/orders` | Bearer | * | List orders (buyer: own; staff: all); `?status=`. |
| POST | `/orders` | Bearer | * | Create order (checkout). |
| GET | `/orders/{id}` | Bearer | own or staff | Order detail. |
| PUT | `/orders/{id}/status` | Bearer | superadmin, admin | Update/cancel order status. |
| POST | `/orders/{id}/cancel` | Bearer | own buyer | Cancel if `processing`. |
| GET | `/products` | Bearer | * | List products (resolved commission, `effective_price`). |
| POST | `/products` | Bearer | superadmin | Create product. |
| PUT | `/products/{id}` | Bearer | superadmin | Update product. |
| DELETE | `/products/{id}` | Bearer | superadmin | Delete product. |
| PUT | `/products/{id}/commission` | Bearer | superadmin | Update product commission. |
| GET | `/referrals/mine` | Bearer | * | Referral code, referred users, commission summary. |
| POST | `/referral/create/{userId}` | Bearer | * | Generate referral code for user. |
| POST | `/referral/check` | Bearer | * | Validate referral code (body). |
| POST | `/referral/apply` | Bearer | * | Apply referral to current user. |
| POST | `/referral/delete` | Bearer | * | Remove referral link for current user. |
| GET | `/` | No | — | Health JSON (`web` route): `status`, `app` — not under `/api`. |

## Error responses (API)

| Status | Shape |
|--------|--------|
| 401 | `{ "message": "Unauthenticated" }` |
| 403 | `{ "message": "Forbidden" }` |
| 404 | `{ "message": "<Resource> not found" }` |
| 422 (validation) | `{ "message": "Validation failed", "errors": { ... } }` |
| 422 (business rules) | `{ "message": "..." }` (no `errors` unless validation) |
| 500 | `{ "message": "Something went wrong" }` (no stack traces in response) |

Validation is forced to JSON for `api/*` routes even without `Accept: application/json`.

## License

MIT (Laravel application skeleton; project-specific code as maintained by the Pharmezel team.)
