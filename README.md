# FurniCraft

FurniCraft is a Laravel web application for Lance Furniture Shop. It gives authenticated customers a guided journey through catalog browsing, saved preferences, computer-vision room analysis, hybrid furniture recommendations, scoped Gemini chat, 2D room planning, and reservation requests. Administrators manage catalog records, customer access, reservations, and confirmation notifications.

## Scope

- Exactly two roles: Customer and Administrator/Owner
- Guests can browse the home page and public catalog; customer planning, favorites, reservations, and all administrator features require sign-in
- The protected customer flow is sign-in → save preferences → optionally upload and analyze a room photo → generate recommendations and chat → submit a reservation request
- Content-based and collaborative recommendation scoring with an optional Gemini-generated explanation
- Gemini-assisted chatbot restricted to the current recommendation and Lance Furniture Shop catalog facts
- The customer-facing room upload is photo-first and does not ask for room dimensions. Gemini may estimate width and depth; local planning guidance is used when AI is not authorized or unavailable. Legacy optional dimensions remain supported by the backend.
- Gemini room analysis and furnished-room image generation run in the database queue; the preview page polls for completion and continues to show the local 2D overlay while image generation runs
- Reservation requests only; no payment, delivery tracking, inventory, or financial management
- Queued email verification and reservation-confirmation notifications; password-reset codes are sent synchronously and logged
- Password-reset codes stored as hashes, valid for 15 minutes, single-use, and request-rate-limited
- Responsive Bootstrap interface for phone and desktop browsers

## Requirements

- PHP 8.2 or newer
- Composer 2
- A local MySQL or MariaDB server for development and deployment
- PHP extensions normally enabled by XAMPP for Laravel (`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`)
- Internet access for Gemini and the configured email provider. Bootstrap is bundled through Vite for the frontend.

This workspace is now configured to use a local MariaDB server on `127.0.0.1:3306`. Existing application records were copied from `database/database.sqlite` into the `furnicraft` MariaDB database; the original SQLite file remains untouched as a fallback copy. The private `.env` uses a dedicated local database account. `.env.example` shows generic XAMPP defaults and retains local/debug and log-mail settings; neither file represents a production deployment. See [Production deployment and recovery](#production-deployment-and-recovery) before serving real customers.

## Local MySQL/MariaDB setup

This workspace already has MariaDB installed as an automatic Windows service, with the `furnicraft` database and a dedicated local app account configured in the private `.env`. Do not replace this workspace’s `.env` with `.env.example`.

For a fresh XAMPP setup, start MySQL in the XAMPP Control Panel, create the `furnicraft` database with `utf8mb4`, then copy `.env.example` to `.env` and configure the values below. Use a dedicated app account for regular use.

```env
APP_NAME=FurniCraft
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=furnicraft
DB_USERNAME=root
DB_PASSWORD=
```

Initialize a fresh installation with:

```powershell
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```
## Production deployment and recovery

FurniCraft is currently a locally run prototype. No hosting target, public HTTPS URL, real mail provider, backup destination, restore drill, or supervised production services have been verified. Do not describe the system as production deployed until these steps are completed on the target host.

## Beta test preflight

- Use an isolated beta database and test accounts; do not expose the local development configuration to testers. Start from the credential-free [.env.beta.example](.env.beta.example) on the beta host, then keep its real `.env` private. The local startup script binds to all network interfaces, so the beta host must have `APP_DEBUG=false` and HTTPS before it is shared.
- Decide whether beta should exercise room-preview v2. If enabled, start with `ROOM_PREVIEW_V2_ROLLOUT_PERCENT=5` or less, verify GD in both web and worker PHP runtimes, inspect fallbacks, then increase gradually. The local `.env` is private and is not a beta deployment template.
- Run `php artisan system:readiness`, then independently verify a live queue worker, scheduler, real email delivery, Gemini access, and external `/up` and TLS checks. The readiness command cannot prove those services are running.
- Follow the cross-browser, keyboard, interrupted-network, Gemini-outage, concurrent-reservation, and restore-drill checks in [browser-acceptance.md](docs/browser-acceptance.md) before inviting representative customers.

### Host configuration

- Use a maintained PHP 8.2+ host with required Laravel extensions, MySQL/MariaDB, HTTPS, and a web server whose document root is the app's `public` directory.
- Create a dedicated least-privilege database user. Configure a private `.env` with `APP_ENV=production`, `APP_DEBUG=false`, the HTTPS `APP_URL`, a generated stable `APP_KEY`, MySQL credentials, a real delivery mailer, and `QUEUE_CONNECTION=database` with `DB_QUEUE_RETRY_AFTER=600`.
- Keep `storage/app/private` persistent, writable by the app, outside the web root, and included in encrypted backups. Never expose `.env`, database dumps, logs, or private room photos from the web server.
- Run migrations as a release step with `php artisan migrate --force`. Do not run `migrate:fresh` on a live database. Keep a supervised queue worker running with `php artisan queue:work --tries=3 --timeout=540`; restart it after deployments. Run `php artisan schedule:run` every minute through the host scheduler so retention jobs execute.
- Before opening traffic, run `php artisan system:readiness` and review the administrator’s Deployment readiness page. Also verify real email delivery, `/up`, queue processing, logs, and TLS from outside the host; the readiness command cannot prove those external behaviors.

### Backup and restore procedure

1. Choose an encrypted backup location outside the web root with access restricted to operators. Set and document retention requirements with the shop; do not store backups under `public` or commit them to Git.
2. Put the app in maintenance mode, pause the queue worker, and take a consistent MySQL/MariaDB dump plus a copy of `storage/app/private`. Preserve `.env` separately in an approved secrets backup. Example Windows/XAMPP dump command (replace the output directory and database name):

   ```powershell
   mysqldump --single-transaction --routines --triggers --host=127.0.0.1 --user=furnicraft_backup --password furnicraft --result-file="D:\\FurniCraftBackups\\furnicraft-YYYYMMDD.sql"
   Copy-Item .\\storage\\app\\private "D:\\FurniCraftBackups\\private-YYYYMMDD" -Recurse
   ```

   Supply the backup user password interactively; do not put it in shell history. Encrypt and move both artifacts to the approved off-site destination, verify their checksums, then restore service and resume the worker.
3. For a restore drill, use an isolated MySQL database and a non-public staging copy. Restore the SQL dump, restore `storage/app/private` to the corresponding application storage directory, apply any newer migrations, then verify login, catalog, reservation history, private room-image access, queue processing, and email delivery. Never test restore by overwriting the live database.
4. Record the drill date, operator, duration, and any data loss or errors. A backup is not considered verified until a restore has succeeded. Set the schedule, retention count, recovery-time objective, and recovery-point objective with the hosting operator before launch.

The built-in `/up` endpoint is a basic application health check. The admin Deployment readiness page and `system:readiness` command check configuration and database connectivity; production still needs host-level uptime monitoring, log/exception monitoring, supervised workers, and tested backup alerts.

## Demo accounts

| Role | Email | Password |
| --- | --- | --- |
| Customer | `demo@furnicraft.local` | `password` |
| Administrator/Owner | `admin@furnicraft.local` | `password` |

Change both passwords before using the application outside a local demonstration.

## Gemini configuration

The recommendation algorithm, chatbot, and room workflow have deterministic local fallbacks, so the system remains usable without an API key. To enable Gemini, add these values to `.env`:

```env
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-2.5-flash
```

The model name is configurable so it can be changed without modifying application code. The hybrid recommendation ranking is computed by FurniCraft; Gemini receives the ranked, in-system product facts to explain results. Chat prompts explicitly limit answers to the supplied catalog and recommendation data.

## Email configuration

Email verification and reservation-confirmation notifications use Laravel's queue. Password-reset email is sent synchronously by its controller. For local inspection, the default `log` mailer writes messages to `storage/logs/laravel.log`. Configure a real mail provider for deployment:

```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="FurniCraft"
QUEUE_CONNECTION=database
```

Keep `php artisan queue:work` running so queued mail is delivered. On Linux hosts, configure the scheduler to run every minute, for example `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## Test and quality commands

```powershell
php artisan test
php artisan view:cache
vendor\bin\pint --test
```

The feature suite verifies public and protected access, required preferences and room analysis, safe post-login return, role authorization, partial-word autocomplete, hybrid recommendations, Gemini dimension estimates, catalog-only room previews, hashed reset codes, rate limiting, and reservation-confirmation notifications.

## Important privacy notes

- Do not place people, documents, addresses, or other personal material in room photos.
- Reset codes are never stored in plaintext.
- Individual view and favorite events are used for recommendations and aggregate ranking; the customer interface does not expose another customer’s activity.
- Configure HTTPS, production mail, backups, retention rules, and a privacy notice before real customer use.
- Room-photo AI processing requires an explicit per-upload opt-in; unconsented images use local guidance only. New photos and generated previews are privately stored, metadata-stripped, and scheduled for removal after `ROOM_IMAGE_RETENTION_DAYS` (default 30).
- The retention task is scheduled in Laravel. Run `php artisan schedule:work` locally, or configure the standard Laravel scheduler cron entry in production; also run `php artisan room-images:secure-legacy` once after upgrading older installations.
- The privacy notice describes the application’s controls and external Gemini transfer. It is not a certification or legal compliance determination.




## Automatic 2D room preview

The room preview loads the selected catalog cutout and the uploaded room image into a single static canvas. Gemini analyzes normalized room geometry, openings, scale clues, obstructions, image quality, and lighting. A deterministic client-side placement engine estimates perspective scale, samples safe positions by furniture placement type, and keeps the best result from up to three consented Gemini self-check iterations. Missing catalog dimensions block the preview. Low-quality or uncertain analysis falls back to a rough local estimate with an actionable photo tip; no image is described as an exact measurement.

Room analyses are stored in room_images.analysis and cached by the processed photo SHA-256. Preview iteration history is stored in room_preview_generations.placement_json; reason-code telemetry is recorded in ai_service_events without storing room images. The preview verification endpoint is authenticated, CSRF protected, rate limited, and only accepts requests for an active product with complete dimensions and a photo for which the customer granted Gemini consent.

Run the deterministic geometry unit checks with npm run test:room-preview. The existing Laravel feature suite is run with php artisan test. Gemini settings remain in the existing .env keys GEMINI_API_KEY, GEMINI_MODEL, and GEMINI_FALLBACK_MODEL; the API key is only read by the server.

Known limitations: a single room photo cannot reveal exact depth or hidden obstacles. Perspective and furniture scale remain estimates, product images should be tightly cropped transparent PNG cutouts for the cleanest composite, and room-fit claims must be confirmed against measured dimensions before reserving.
#   l a n c e 2 . 0  
 #   f u r n i t u r e  
 