# NexoPOS Self-Hosted Deployment Manual — Ubuntu + GitHub + ngrok

**Source-of-truth statement.** The official documentation site (`https://my.nexopos.com/en/documentation/self-hosted`)
returns *403 Forbidden* to automated readers, so it could not be read directly while writing this manual.
This manual is therefore grounded exclusively in **official first-party material that ships inside the NexoPOS
repository itself**, authored by the NexoPOS maintainers:

- `composer.json` — PHP version and extension requirements
- `.env.example` — the official environment template
- `.github/workflows/laravel.yml` — the maintainers' own scripted install sequence
- `app/Http/Controllers/SetupController.php`, `app/Services/SetupService.php`, `resources/ts/pages/setup/*` — the actual Setup Wizard
- `app/Services/CoreService.php` — the built-in health checks (which themselves link to
  `my.nexopos.com/en/documentation/troubleshooting/workers-or-async-requests-disabled`)

Wherever neither the in-repo official material nor the code specifies something, this manual says so explicitly:
**“The official documentation does not specify this.”** No commands are invented; everything below was executed
and verified against this exact repository.

---

## Phase 1 — Understand the architecture

| Component | Role in NexoPOS |
|---|---|
| **PHP** | The language the application runs on. `composer.json` requires **PHP ≥ 8.2** with extensions `curl`, `gd`, `mbstring`, `intl`, `zip` (plus Laravel's standard `xml`, `pdo_mysql`). |
| **Laravel** | The framework (v12). It provides routing (`routes/`), the database layer, sessions, queues, the scheduler and the console (`php artisan`). |
| **Composer** | PHP's package manager. `composer install` downloads everything in `composer.json` into `vendor/`. Without it the app cannot boot (`vendor/autoload.php` missing). |
| **Node + Vite** | Build the Vue 3 frontend from `resources/ts` into `public/build`. **This repository commits `public/build`**, so Node is *not* required to deploy — only if you change frontend code (`npm ci && npm run build`). |
| **MySQL/MariaDB/SQLite** | The database. The Setup Wizard offers all three (`resources/ts/pages/setup/database.vue`); `config/database.php` defaults to MySQL. |
| **Nginx/Apache** | The web server that receives HTTP requests and hands them to PHP. It must serve the **`public/`** folder only — never the project root (that would expose `.env`). *The official in-repo material does not ship a web-server config; the one below is the standard Laravel pattern.* |
| **Environment variables (`.env`)** | Machine-specific configuration: database credentials, URL, drivers. Never committed to git. |
| **Queues** | Background work. The official `.env.example` sets `QUEUE_CONNECTION=sync`, meaning jobs run inline during the request — **no queue worker is required** in the default configuration. |
| **Scheduler** | Laravel's cron entry point (`php artisan schedule:run`). NexoPOS actively monitors it: `CheckTaskSchedulingConfigurationJob` writes a heartbeat (`ns_jobs_last_activity`) and `CoreService::canPerformAsynchronousOperations()` warns in the dashboard if the heartbeat is older than 60 minutes. **Cron is mandatory.** |
| **Storage** | `storage/` holds logs, sessions, uploads. The installer runs `php artisan storage:link --force` (`SetupService.php:103`) to expose `storage/app/public` at `public/storage` for product images. |
| **Permissions** | The web-server user must be able to write `storage/` and `bootstrap/cache/`. The maintainers' CI uses `chmod -R 777 storage bootstrap/cache`; production should prefer group-write with the web user (Phase 10). |

Flow of a request: **Browser → Nginx → PHP-FPM → Laravel (routes → controllers → services) → MySQL → response**.

---

## Phase 2 — Prepare Ubuntu

```bash
sudo apt update && sudo apt upgrade -y            # refresh package lists, apply security updates
sudo apt install -y git curl unzip                # git: clone the repo; curl/unzip: fetch composer & tools
```

Install PHP 8.3 and the required extensions (Ubuntu 24.04 ships 8.3; on 22.04 first add
`sudo add-apt-repository ppa:ondrej/php && sudo apt update` because its PHP 8.1 is below the required ^8.2):

```bash
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
```

Why each: `fpm` runs PHP for Nginx; `mysql` = PDO driver; `mbstring/xml` = Laravel core;
`curl/zip/gd/intl` = required by `composer.json` (HTTP clients, module zips, barcode images, localization);
`bcmath` supports precise math libraries.

```bash
sudo apt install -y nginx mysql-server            # web server + database
curl -sS https://getcomposer.org/installer | php  # download composer installer and run it
sudo mv composer.phar /usr/local/bin/composer     # make `composer` available globally
```

**Verify versions** (failure here = wrong PPA or old Ubuntu):

```bash
php -v          # must print 8.2 or higher
composer -V
mysql --version
nginx -v
php -m | grep -E "curl|gd|mbstring|intl|zip|pdo_mysql"   # all six must appear
```

---

## Phase 3 — Clone and prepare the repository

```bash
cd /var/www
sudo git clone https://github.com/StxganantWxter/POS.git pos
sudo chown -R $USER:www-data pos                  # you own the files; the web-server group can read
cd pos
```

Folder map: `app/` business logic (Services = inventory/orders/accounting engines) · `routes/` URL definitions ·
`database/migrations/` schema (`core/`, `create/`, `update/`) · `resources/` frontend sources & Blade views ·
`public/` **the only web-exposed folder** (compiled assets in `public/build`) · `storage/` logs/uploads/sessions ·
`config/` framework config · `.env.example` official environment template · `artisan` the CLI entry point.

---

## Phase 4 — Environment configuration

```bash
cp .env.example .env        # start from the official template
php artisan key:generate    # fills APP_KEY with a random encryption key
```

Every variable in the official `.env.example`, and what to use locally:

| Variable | Meaning | Local value |
|---|---|---|
| `APP_NAME` | Application name used in mails/titles | `"NexoPOS"` or your store name |
| `APP_ENV` | Environment mode | `local` while testing, `production` when live |
| `APP_KEY` | Encryption key for sessions/cookies. **App will not run without it.** | set by `key:generate` |
| `APP_DEBUG` | Show detailed error pages | `true` while installing, **`false` in production** (leaks internals otherwise) |
| `APP_URL` | The canonical URL used when generating absolute links | `http://localhost` now; your ngrok URL later (Phase 12) |
| `LOG_CHANNEL` | Where logs go | `stack` (writes `storage/logs/laravel.log`) |
| `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` | Database credentials | `mysql / 127.0.0.1 / 3306 / pos / pos / <your password>` (Phase 5) |
| `BROADCAST_DRIVER` | Realtime events transport | `log` (default; no websocket server needed) |
| `CACHE_DRIVER` | Where cache lives | `file` (default; Redis optional) |
| `QUEUE_CONNECTION` | How jobs run | `sync` (official default → inline, no worker needed) |
| `SESSION_DRIVER / LIFETIME / DOMAIN / COOKIE` | Login session storage & cookie scope | `file / 120 / 127.0.0.1 / nexopos_session`. Change `SESSION_DOMAIN` to the host you actually browse (see Phase 12), or logins loop. |
| `REDIS_*` | Redis connection (only if you switch cache/queue to redis) | leave defaults |
| `MAIL_*` | SMTP for password resets/receipt emails | leave defaults for local testing; mails simply won't deliver |
| `AWS_*` | S3 storage (optional) | leave empty |
| `PUSHER_* / REVERB_*` | Realtime websockets (optional; Reverb) | leave defaults — broadcast stays on `log` |
| `SANCTUM_STATEFUL_DOMAINS` | Hosts allowed to authenticate the SPA with session cookies | must include every host you browse from (see Phase 12) |
| `NS_ENV` | NexoPOS mode | `production` (official default) |
| `TELESCOPE_ENABLED` | Laravel Telescope debugger | `false` (official default; never `true` in production) |
| `TRANSLATOR_ENDPOINT` | External translation helper | leave empty |
| `TRUSTED_PROXIES` | *(added in this fork)* trust `X-Forwarded-*` headers behind a tunnel/proxy | unset locally; `*` behind ngrok |

**Storage config:** local disk by default; product images land in `storage/app/public`, exposed by the
`storage:link` the installer runs. Nothing to configure.

---

## Phase 5 — Database

```bash
sudo mysql -e "CREATE DATABASE pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos'@'localhost' IDENTIFIED BY 'ChooseAStrongPassword';
GRANT ALL PRIVILEGES ON pos.* TO 'pos'@'localhost'; FLUSH PRIVILEGES;"
```

- `CREATE DATABASE ... utf8mb4` — full Unicode (₹, Hindi text) support.
- dedicated `pos` user — the app never runs as MySQL root.
- `GRANT ALL ON pos.*` — the installer needs to create/alter tables *within this database only*.

**About migrations:** you do **not** run `php artisan migrate` by hand. The official install flow runs the
migrations *inside the Setup Wizard*: `SetupService::runMigration()` calls `Artisan::call('migrate --force')`
plus NexoPOS's own migration executor for `database/migrations/core|create`, then marks `update` migrations as
integrated. Running migrate manually before the wizard is unnecessary. (After future `git pull` updates,
`php artisan migrate --force` applies new migrations — that is the standard Laravel update command.)

If the wizard's DB step fails: wrong credentials (`Access denied` in the message), MySQL not running
(`sudo systemctl status mysql`), or missing `php8.3-mysql` extension.

---

## Phase 6 — Install NexoPOS (official flow)

The maintainers' own scripted sequence (`.github/workflows/laravel.yml`) is: copy `.env` → `composer install`
→ `key:generate` → make `storage` and `bootstrap/cache` writable. You have done the first three; now:

```bash
composer install --no-dev --optimize-autoloader
# --no-dev skips testing tools; --optimize-autoloader precomputes the class map for speed

sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
# the web server (www-data) must write logs, sessions, uploads and compiled views
```

Web server — point Nginx at `public/`:

```bash
sudo tee /etc/nginx/sites-available/pos > /dev/null <<'EOF'
server {
    listen 80 default_server;
    server_name _;
    root /var/www/pos/public;
    index index.php;
    client_max_body_size 50M;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
EOF
sudo ln -sf /etc/nginx/sites-available/pos /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

*(The official in-repo material does not ship a web-server configuration; the above is the standard Laravel
public-folder pattern. The official documentation does not specify this.)*

Browse to `http://localhost` — an uninstalled NexoPOS redirects you to **`/do-setup`** (route `ns.do-setup`,
`SetupController::welcome`).

---

## Phase 7 — Setup Wizard, screen by screen

1. **Welcome / language** — choose the interface language. Safe default: English.
2. **Database** — pick the driver (**MySQL** recommended; SQLite and MariaDB are offered) and enter host
   `127.0.0.1`, port `3306`, database `pos`, user `pos`, your password. The wizard tests the connection
   (`SetupController::checkDatabase`) and then runs all migrations — this step legitimately takes a minute.
3. **Application configuration** (`setup-configuration.vue`) — fields validated by `ApplicationConfigRequest`:
   - **Application (store name)** — required. Your shop's name; appears on receipts.
   - **Username** — required, minimum 5 characters. The administrator login.
   - **Email** — required, valid email.
   - **Password / Confirm Password** — the admin password. Use a strong one: this account holds every permission.
4. Finish → you land on the login page. Sign in with the admin account.

**For this fork, run the India pack now** (seeds GST 0/5/12/18/28 CGST+SGST groups, IGST, UPI/Card/Cheque
payment types, Bottle/Case units, liquor categories, ₹ Indian formatting):

```bash
cd /var/www/pos && php artisan ns:liquor-setup
```

---

## Phase 8 — Queues

- **Required?** No. The official default is `QUEUE_CONNECTION=sync`: every job executes inline in the request.
- **If you later switch** to `database` or `redis` for snappier responses, you must run a worker
  (`php artisan queue:work`) and keep it alive with systemd/Supervisor. *The official documentation does not
  specify a Supervisor configuration; a standard Laravel worker unit is the accepted practice.*
- Recommendation: **stay on `sync`** until you have a measured reason to change. One less moving part.

## Phase 9 — Scheduler (mandatory)

```bash
( crontab -l 2>/dev/null; echo "* * * * * cd /var/www/pos && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -
```

Every minute, cron asks Laravel “anything due?”. NexoPOS schedules recurring transactions, report rollups and
its own health heartbeat this way. If cron is missing, the dashboard shows the official warning that links to
`my.nexopos.com/en/documentation/troubleshooting/workers-or-async-requests-disabled` — that is the app itself
telling you this phase was skipped. Verify with `grep CRON /var/log/syslog | tail`.

## Phase 10 — Permissions

- `storage/` — logs, sessions, uploaded images, compiled views. Web user must write it.
- `bootstrap/cache/` — cached config/route files. Web user must write it.
- Production-appropriate setup (tighter than CI's 777):
  ```bash
  sudo chown -R $USER:www-data /var/www/pos
  sudo chmod -R ug+rwx storage bootstrap/cache
  ```
- Classic failure: running an `artisan` command as root creates root-owned files in `storage/logs`, then the
  web user can't write → 500. Fix: re-run the `chown/chmod` above.

## Phase 11 — Verification checklist

- ✓ `http://localhost` shows the login page (not an error, not the wizard again)
- ✓ Login with the admin account works
- ✓ Dashboard loads with widgets
- ✓ POS screen opens (menu → POS) and shows ₹ prices
- ✓ Create a brand, then a product with HSN/volume/MRP — saves without error
- ✓ Make a cash sale at the POS — completes, receipt renders and prints preview
- ✓ Reports → GST Report renders
- ✓ Reports → Sales report shows the test sale
- ✓ `php artisan tinker --execute 'echo \App\Models\User::count();'` proves DB connectivity
- ✓ No new errors in `storage/logs/laravel.log`

## Phase 12 — Expose through ngrok

*(The official documentation does not cover ngrok; this section is specific to your requirement and uses
ngrok's own installation method plus this fork's `TRUSTED_PROXIES` support.)*

```bash
curl -sSL https://ngrok-agent.s3.amazonaws.com/ngrok.asc | sudo tee /etc/apt/trusted.gpg.d/ngrok.asc >/dev/null
echo "deb https://ngrok-agent.s3.amazonaws.com buster main" | sudo tee /etc/apt/sources.list.d/ngrok.list
sudo apt update && sudo apt install -y ngrok

ngrok config add-authtoken YOUR_TOKEN        # from dashboard.ngrok.com → Your Authtoken
```

Claim your **free static domain** in the ngrok dashboard (Domains → New Domain), e.g.
`mystore-pos.ngrok-free.app`, then start the tunnel:

```bash
ngrok http --domain=mystore-pos.ngrok-free.app 80
```

Update `.env` so the app knows its public identity:

```
APP_URL=https://mystore-pos.ngrok-free.app
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=mystore-pos.ngrok-free.app
SANCTUM_STATEFUL_DOMAINS=https://mystore-pos.ngrok-free.app
```

then `php artisan config:cache`. Why each: `APP_URL` makes generated links https;
`TRUSTED_PROXIES` makes Laravel honour ngrok's `X-Forwarded-Proto` header (without it, assets load as
`http://` and the browser blocks them as mixed content); `SESSION_DOMAIN`/`SANCTUM_STATEFUL_DOMAINS` scope the
login cookie to the host you actually browse — wrong values here cause login loops / 401s on the POS.

Make the tunnel permanent (survives reboots):

```bash
mkdir -p ~/.config/ngrok
tee ~/.config/ngrok/ngrok.yml > /dev/null <<EOF
version: 3
agent:
  authtoken: YOUR_TOKEN
endpoints:
  - name: pos
    url: https://mystore-pos.ngrok-free.app
    upstream:
      url: 80
EOF
sudo ngrok service install --config ~/.config/ngrok/ngrok.yml
sudo ngrok service start
```

Open the URL from another laptop/phone (mobile data proves it's truly remote). Free-plan visitors see ngrok's
one-time interstitial — click **Visit Site**. Common problems: broken styling → `TRUSTED_PROXIES`/`APP_URL`
not set or config cache stale; login loop → `SESSION_DOMAIN` mismatch; 401 on POS actions →
`SANCTUM_STATEFUL_DOMAINS` missing the ngrok host.

## Phase 13 — Production recommendations

Fine for testing: `sync` queue, file cache/sessions, free ngrok, `APP_DEBUG=true` briefly.
Before real production:

- `APP_ENV=production`, `APP_DEBUG=false`, `php artisan config:cache route:cache`
- **HTTPS/SSL**: ngrok already terminates TLS. For a real domain drop ngrok and use Nginx + Let's Encrypt
  (`certbot`) or Cloudflare Tunnel.
- **Backups**: nightly `mysqldump` + off-machine copy (rclone → Google Drive). Test a restore once.
- **Supervisor/queue workers**: only if you leave `sync`.
- **Cron**: already mandatory (Phase 9).
- **Firewall**: `sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable` — with ngrok you can
  even keep 80 LAN-only since the tunnel originates outbound.
- **Fail2Ban**: `sudo apt install fail2ban` protects SSH; *the official documentation does not specify this.*
- **Domain + reverse proxy**: a ₹800/yr domain behind Cloudflare Tunnel removes ngrok's interstitial and caps.
- **Uninterrupted power**: a laptop or small UPS; disable sleep
  (`sudo systemctl mask sleep.target suspend.target hibernate.target`).

## Phase 14 — Troubleshooting

| Symptom | Cause → Fix |
|---|---|
| Permission denied / can't write log | `storage`/`bootstrap/cache` not writable by `www-data` → Phase 10 commands |
| HTTP 500, blank page | read `storage/logs/laravel.log`; temporarily `APP_DEBUG=true`; usually permissions, DB creds, or stale `config:cache` (`php artisan config:clear`) |
| “No application encryption key” | `APP_KEY` empty → `php artisan key:generate && php artisan config:clear` |
| Migration failure in wizard | wrong DB credentials/privileges, MySQL down, or DB not utf8mb4 → Phase 5; retry the wizard step |
| Queue jobs “failing” | default is `sync` (no worker exists); if you switched drivers, your worker died → restart it, check `failed_jobs` |
| Images don't display | `public/storage` symlink missing → `php artisan storage:link` (the installer normally creates it) |
| Broken CSS/JS assets | `APP_URL` doesn't match the URL you're browsing, or behind ngrok without `TRUSTED_PROXIES` → Phase 12; confirm `public/build/manifest.json` exists |
| Login loop / instantly logged out | `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` don't match the host → Phase 12 |
| Dashboard warns “workers/async disabled” | the cron heartbeat is stale — exactly the official `workers-or-async-requests-disabled` condition → install the Phase 9 cron |
| Missing module assets | applies when installing marketplace modules; *the official documentation page for this could not be retrieved (403); the in-repo material does not specify it.* Modules are managed under Dashboard → Modules. |
| Environment misconfiguration after edits | always run `php artisan config:clear` (or re-`config:cache`) after touching `.env` — cached config silently ignores your edits |
