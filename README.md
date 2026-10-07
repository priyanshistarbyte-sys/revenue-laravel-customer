# Amaira (Laravel)

A Laravel 12 port of the original raw-PHP **Amaira** app — a daily ad-revenue
P&L ledger that reconciles **Meta Ads spend** against **Google Ad Manager (GAM)
revenue** per link, with GST and multi-currency support.

The original lives in `../sb_revenue`. This project preserves its features,
UI (Bootstrap 5 + the same `assets/css/style.css` and `assets/js/app.js`),
data model, and business logic — re-expressed as idiomatic Laravel.

## Requirements

- PHP 8.2+
- MySQL / MariaDB
- Composer
- PHP extensions: `pdo_mysql`, `curl`, `openssl`, `zip` (ZIP invoice downloads)

## Setup

```bash
composer install
```

Point the DB settings in `.env` at your MySQL server (default database:
`adledger_laravel`), create the database, then run the migrations:

```bash
php artisan migrate
```

Run it:

```bash
php artisan serve --host=127.0.0.1 --port=8123
```

Open http://127.0.0.1:8123 — the first visit shows a **setup** screen to create
the admin account (name + 6-digit PIN). There is no email/password login; every
user signs in with a unique 6-digit PIN (admins may add a second PIN for
two-step verification). Sessions unlock for 3 hours.

`GET /setup` (or `/setup?reset=1` to rebuild) also runs the migrations from the
browser.

## Structure

| Original file            | Laravel equivalent |
|--------------------------|--------------------|
| `config.php` helpers     | `app/Support/helpers.php` (global functions, autoloaded) |
| PIN auth / `requireAuth` | `app/Http/Middleware/EnsureAuthenticated.php`, `EnsureAdmin.php` |
| `login.php` / `logout.php` | `AuthController` + `resources/views/auth/login.blade.php` |
| `index.php` (dashboard)  | `DashboardController` + `dashboard.blade.php` |
| `monthly.php`            | `MonthlyController` + `monthly.blade.php` |
| `save_value.php` (AJAX)  | `SaveValueController` → `POST /save_value` |
| `links.php` (main domains) | `DomainsController` + `domains.blade.php` (`/domains`) |
| `links.php` (subdomains)   | `LinksController` + `links.blade.php` (`/links`) |
| `adx.php`                | `AdxController` |
| `expenses.php`           | `ExpensesController` |
| `invoices.php` (+download/preview) | `InvoicesController` |
| `upload.php` / `delete_data.php` | `UploadController` (CSV import) |
| `currencies.php`         | `CurrenciesController` |
| `settings.php`           | `SettingsController` |
| `users.php`              | `UsersController` |
| `setup.php`              | `SetupController` |
| `gam_sync.php`           | `App\Services\GamSync` → `gam:sync` cmd + `GET /gam_sync` |
| `meta_sync.php`          | `App\Services\MetaSync` → `meta:sync` cmd + `GET /meta_sync` |
| DB schema (setup.php migrations) | `database/migrations/*` |

Routes use clean URLs (`/`, `/monthly`, `/links`, …) instead of the original
`.php` filenames. The POST endpoints are exempted from CSRF (the original app
used none and everything is behind PIN auth) — see `bootstrap/app.php`.

## API syncs

Both syncs are disabled by default. Configure credentials on the ADX page
(GAM: network code, saved-report id, service-account JSON key) and the
`meta_accounts` table (Meta System User token + ad account ids + owner — the
Accounts page has been removed, so edit the table directly), then enable in
`.env`:

```env
GAM_ENABLED=true
META_ENABLED=true
```

Run them from the CLI or the buttons on the ADX / Upload pages:

```bash
php artisan gam:sync  [--account=ID] [--force] [--list-reports]
php artisan meta:sync [--account=ID] [--force] [--list-accounts]
```

### Scheduled GAM sync (cron)

`gam:sync --force` runs on Laravel's scheduler (`routes/console.php`) every
hour by default — change it with `GAM_SYNC_CRON` (a cron expression; empty
disables it). Output is appended to `storage/logs/gam-sync.log`. The server's
crontab must call the scheduler once a minute:

```cron
* * * * * cd /path/to/revenue-laravel-customer && php artisan schedule:run >> /dev/null 2>&1
```

Locally, `php artisan schedule:work` does the same in the foreground;
`php artisan schedule:list` shows when the next run is due.

Set `GAM_MOCK=true` / `META_MOCK=true` to exercise the import pipeline with
sample rows before real credentials are ready. Shared settings (lookback days,
throttle interval, column map, Graph API version) live in `config/adledger.php`.

Uploaded files: invoices are stored under `storage/app/invoices`, GAM
service-account keys under `storage/app/private/gam_keys`.
