# Calories

A small, single-user food journal built with PHP 8.2+, MySQL 8+, and vanilla JavaScript/CSS bundled by Bun. No frontend framework or PHP dependencies.

## What works

- Daily calorie and macro totals, with configurable targets and date navigation.
- A searchable food library, custom food creation, and food corrections.
- UPC/EAN camera scanning and manual barcode lookup through Open Food Facts, with review before saving.
- Logging in grams, milliliters, or servings, with a live nutrition preview.
- Editing quantities and deleting entries.
- Nutrition snapshots: later food changes never rewrite earlier logs.
- Retry-safe logging, server validation, prepared SQL, session authentication, and CSRF protection.

Verified USDA seed foods, saved-meal endpoints/UI, and PWA installation are next. The meal tables are reserved in the schema. There is no offline storage or synchronization yet.

## Local setup

Requirements: PHP with `pdo_mysql`, `mbstring`, `curl`, and sessions; MySQL; Bun. Run `bun install` once after downloading the source to install the pinned scanner dependency.

1. Create an empty `calories` database and a database user with access to it.
2. Import `database/schema.sql` into that database:

   ```sh
   mysql -u YOUR_USER -p calories < database/schema.sql
   ```

3. Copy `public/private/config.example.php` to `public/private/config.php`. Set your database credentials and DSN, set `environment` to `development`, and choose your timezone. The local file is gitignored.
4. Run:

   ```sh
   bun run dev
   ```

5. Open http://127.0.0.1:8080. Development authentication bypass works only for requests from loopback addresses. This command binds to loopback; do not expose the development server through a proxy or tunnel.

Use **My foods → Create food** to enter verified nutrition, then log an amount. Targets initially have no goals (zero), so the app does not invent dietary recommendations. No foods or personal log entries are installed automatically.

`bun run build` creates `public/assets/`. Rebuild after frontend changes; no watch process is required. Environment variables override local configuration: `APP_ENV`, `APP_TIMEZONE`, `DB_DSN`, `DB_USER`, `DB_PASSWORD`, and `APP_PASSWORD_HASH`.

## Deploy to `/calories/`

The entire running app is in **`public/`**. Upload its contents into `/calories/` on your server, including both `.htaccess` files. No separate private directory, environment variable, or server document-root change is needed.

```text
/calories/
  .htaccess
  index.html
  api.php
  icon.svg
  assets/
    app.js
    style.css
  private/
    .htaccess
    config.example.php
    config.php
    src/
      bootstrap.php
      domain.php
```

1. Run `bun run build`. It builds the assets and exits; `bun run dev` additionally starts a local server and is not needed for deployment.
2. Upload the contents of `public/` into the server's `calories/` directory. Include hidden files (`.htaccess`).
3. On the server, copy `private/config.example.php` to `private/config.php` if you do not already have one. Set your MySQL credentials, timezone, `environment` to `production`, and app password hash. If uploading your existing local config, change its development settings to production server settings.
4. Create your MySQL database and import `database/schema.sql` with your host's database tools. The SQL file is used for setup; it does not need to be uploaded into the web directory.
5. Open `https://iws.mx/calories/` over HTTPS. Assets and API paths work at either `/` or `/calories/`.

Generate a password hash with `php -r 'echo password_hash(readline("Password: "), PASSWORD_DEFAULT), PHP_EOL;'` and put the result in `private/config.php`. Production requires a password and HTTPS session cookies. Everyone with the password accesses the same journal.

For later updates, upload the updated app files **without overwriting the server's `private/config.php`**. That file is gitignored and builds leave it untouched. Your database keeps its existing foods, logs, and targets.

### Access rules

Apache 2.4 must have `mod_rewrite` enabled and honor the included `.htaccess` files. The root rules disable directory listings and block private paths and dotfiles. `private/.htaccess` also denies all direct HTTP access to that directory; PHP can still include its files internally. `mod_headers`, when available, adds browser security headers.

After the first upload, `/calories/api/session` should return JSON, and `/calories/private/config.php` should return **403 Forbidden**. A 404 on API routes usually means the rewrite rules are missing or disabled. A 500 warrants checking the server's PHP error log and database configuration.

Keep backups outside the web directory. For example: `mysqldump --single-transaction -u YOUR_USER -p calories > calories-backup.sql`.

If upgrading from the earlier split layout, the new `api.php` loads `private/src/bootstrap.php` directly. Remove any old `SetEnv CALORIES_PRIVATE_DIR ...` line from the uploaded `.htaccess`; it is no longer used. The old external source/config folders are no longer required once the new deployment works.

## Data conventions

### Barcode scanning

Open **My foods → Scan or enter a barcode**. Start the camera explicitly or enter the digits from the package. Camera access requires HTTPS (localhost also works); permission denial leaves manual entry available. UPC-A, EAN-13, and EAN-8 are supported by the camera. Manual entry accepts 8, 12, 13, or 14 digits. UPC-E scanning is not enabled.

Keep the bars vertical, with the complete barcode and white space at both ends inside the aiming box. If several cameras are available, the Camera selector lets you switch lenses. The app requests a higher-resolution rear-camera stream, preserves the preview's aspect ratio, and uses its bundled UPC/EAN decoder across browsers. For typed UPC-A numbers, include the two small digits often printed outside the main ten-digit group.

Previously saved barcodes immediately open the quantity preview. New products open an editable nutrition review. Confirm the nutrition basis and unit against the package: Open Food Facts uses `_100g` fields for both 100 g and 100 ml, so the app requires an explicit unit choice for those imports. Missing values stay blank and must be completed. Prepared-product nutrition is not substituted for as-sold nutrition.

An unknown barcode opens the custom food form. If the service is unavailable, use **Enter label manually**. Saving keeps the barcode for future lookups. UPC-A and equivalent zero-prefixed EAN codes share one local record. Editing a cached food affects future scans while past log snapshots stay unchanged.

No database migration is required for this update. Upload the updated `public/` contents, including `private/src/barcodes.php` and built assets, while preserving your server's `private/config.php`. The server needs PHP cURL with outbound HTTPS access; a missing extension produces an explicit message in the lookup dialog. Your server doesn't need Bun or `node_modules`.

Only barcode digits are sent to Open Food Facts; camera frames are decoded locally. The server identifies the app with a custom User-Agent, uses bounded request timeouts, and caps new lookups at 12 per minute per session. Nutrition is stored locally only after review and save. This session limit suits this single-user app and is not a global rate limiter across devices.

Sources: [Open Food Facts API](https://openfoodfacts.github.io/openfoodfacts-server/api/), [nutrition field definitions](https://openfoodfacts.github.io/documentation/docs/Product-Opener/schemas/schemas/product_nutrition/), [barcode normalization](https://openfoodfacts.github.io/openfoodfacts-server/api/ref-barcode-normalization/), [html5-qrcode](https://scanapp.org/html5-qrcode-docs/). Food data is provided under [ODbL](https://opendatacommons.org/licenses/odbl/1-0/).

### Stored nutrition

- Each food defines nutrition per `basis_amount` of `basis_unit` (`g`, `ml`, or `serving`). A food with 200 kcal per 100 g contributes 300 kcal when logging 150 g.
- Log entries copy the food name, nutrition basis, unit, and nutrient values at logging time. Their consumed totals are calculated from this immutable snapshot and the editable quantity.
- `logged_date` is the chosen calendar day. `created_at` is UTC. “Today” comes from the configured app timezone, not the database server's clock.
- Calories are stored explicitly rather than inferred from macros. Missing nutrients are rejected, not converted to zero. Inputs are rounded to three decimal places; display rounding happens only in the UI.
- Saved meals will use the unit of each referenced food. Their logging endpoint is not implemented in this milestone.
- Current targets apply to every date shown; target history is intentionally not stored.

## API

All writes require JSON and an `X-CSRF-Token` from `GET /api/session`. Cookies must be retained. All data endpoints require authentication outside local development.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/api/session` | Auth state, CSRF token, timezone, and today |
| POST | `/api/login`, `/api/logout` | Start/end a session |
| GET | `/api/foods?q=...` | Literal name search, up to 50 foods, recently used first |
| POST | `/api/foods/lookup` | Look up a string `barcode`; returns a cached food or unsaved review draft |
| POST | `/api/foods` | Create food |
| PUT | `/api/foods/{id}` | Correct food for future logging |
| GET | `/api/logs?date=YYYY-MM-DD` | Entries and totals; date defaults to today |
| POST | `/api/logs` | Log `food_id`, `quantity`, optional `date`, and UUID v4 `request_key` |
| PATCH | `/api/logs/{id}` | Change `quantity` using the original snapshot |
| DELETE | `/api/logs/{id}` | Delete an entry |
| GET / PUT | `/api/targets` | Read/save current goals |

Food payloads: `name`, `basis_amount`, `basis_unit`, `calories`, `protein`, `carbs`, `fat`, and optional string `barcode`. Targets require the four nutrient fields. Duplicate log request keys return the existing entry, or a conflict if the payload differs.

## Verification

```sh
bun run test
php tests/integration.php
```

Integration tests need PHP cURL and access to a local MySQL account allowed to create/drop test databases. They create a randomly named `calories_test_*` database and a temporary PHP server, then remove both. They do not modify the app database. Defaults use `root` through `/tmp/mysql.sock`; override `TEST_MYSQL_DSN`, `TEST_MYSQL_USER`, and `TEST_MYSQL_PASSWORD` as needed. Test cases cover snapshots, quantities, dates, duplicate retries, literal searches, validation, targets, authentication, and deletion.

Run `bun run build` before the integration suite. It also verifies static assets and API calls at both `/` and `/calories/`; the PHP test router simulates Apache's subdirectory rewrite behavior.
