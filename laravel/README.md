# Cloud ERP (Laravel)

Corporate gifting workspace for Oman. **Developed by Irfan Dossani.**

This is the Laravel edition of Cloud ERP: the same product catalogue, OMR
quotations, AI drafting and mockup studio as the root [Next.js/Cloudflare
build](../README.md), rebuilt on Laravel with real staff authentication and
role-based access (administrator vs. sales agent) instead of a
localhost-only guard.

## Local setup

Requires PHP 8.3+, Composer, and Node.js 22.13+ (the frontend is built from
the repository root, not from this directory).

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
cd .. && npm run install:ci && npm run build:laravel
php artisan serve
```

Open `http://127.0.0.1:8000`. On the sign-in page, **Open local preview**
signs you in as an administrator when the server is running on `localhost`
in the `local` environment — no password needed. It creates the "Irfan
Dossani" administrator account and agent profile the first time it's used
(see `app/Http/Controllers/AuthController::local`).

For a real deployment, set `APP_ENV` to something other than `local` (which
disables the local-preview shortcut) and create the first administrator with:

```sh
php artisan erp:make-admin you@company.com "Your Name"
```

### Frontend changes

`resources/ERP.tsx`, `resources/main.tsx`, `resources/http.ts`, `lib/domain.ts`
and `components/ui/*` live at the **repository root** and are shared build
inputs — edit them there, then rebuild with:

```sh
npm run build:laravel
```

from the repository root. This runs Vite against `vite.laravel.config.ts` and
writes the built assets into `laravel/public/build`, which `resources/views/erp.blade.php`
loads via the `@vite` directive.

## Staff accounts and roles

- **Administrator**: manages the product catalogue's warehouse stock and
  selling prices, company settings and currency rate, supplier sync, and
  sales agent accounts.
- **Sales agent**: has their own login, sees only their own quotations and
  mockups, can add new catalogue products (created with zero warehouse
  stock until an administrator sets it), and can draft/save/print
  quotations for their own agent profile.

An administrator grants a sign-in from **Settings → Sales agents** by
adding an email and password alongside the agent's name; leaving both blank
keeps a name-only profile with no login.

## Connections

Set the following in `.env` and restart the server:

- `LUXURY_API_USERNAME` / `LUXURY_API_PASSWORD`: supplier credentials for
  catalogue sync.
- `GEMINI_API_KEY`: required for AI quotation drafting and mockup
  generation, using the Google Gemini API. `GEMINI_TEXT_MODEL` and
  `GEMINI_IMAGE_MODEL` are configurable and default to `gemini-2.5-flash` /
  `gemini-2.5-flash-image`.

No live AI requests are made until a key is configured and a user requests
generation. Generated mockups are stored on the `local` filesystem disk
(private; never web-accessible directly) and served only to the agent who
owns them, or an administrator, through `/api/assets`.

## Deploying to Plesk (MariaDB)

1. In Plesk, create a MariaDB database and database user under **Databases**,
   and note the database name, username, password, and host (usually
   `localhost` when the app and database share the same Plesk server).
2. Point the domain's document root at `laravel/public` (Plesk → **Hosting
   Settings** → Document root), not the repository root — Laravel serves
   from `public/`.
3. Pull the repository onto the server (Plesk Git integration, or SSH +
   `git clone`), then from the `laravel/` directory:
   ```sh
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate --force
   ```
4. Edit `.env` for production:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.example

   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=<the database Plesk created>
   DB_USERNAME=<the database user Plesk created>
   DB_PASSWORD=<its password>

   LUXURY_API_USERNAME=
   LUXURY_API_PASSWORD=
   GEMINI_API_KEY=
   ```
5. Run migrations and create the first administrator (the "Open local
   preview" shortcut is disabled outside `APP_ENV=local`):
   ```sh
   php artisan migrate --force
   php artisan erp:make-admin you@company.com "Your Name"
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. Confirm Plesk's PHP setting for the domain is **8.3 or newer**, and that
   `storage/` and `bootstrap/cache/` are writable by the web server user
   (Plesk sets this automatically for most PHP hosting).

The built frontend in `laravel/public/build/` is committed to the repository,
so the host does not need Node.js — only PHP and Composer. If you change
`resources/ERP.tsx` (or anything else it imports) after this point, rebuild
it from the repository root with `npm run build:laravel` and commit the
updated `laravel/public/build/` output before deploying again.

## Checks

```sh
php artisan test
```

## Current boundaries

Purchasing, stock movement ledgers, fulfilment, invoicing, tax calculation
and payment reconciliation are not implemented. Status changes do not
reserve or deduct stock. The printed amount is a subtotal; tax, delivery and
branding charges are not automatically added. See the root
[README](../README.md#current-boundaries) for the shared product boundaries.
