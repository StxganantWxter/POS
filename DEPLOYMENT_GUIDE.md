# NexoPOS Deployment Guide — Liquor Shop Edition

> A complete, reverse‑engineered deployment manual for **this exact repository**.
> Written for someone technically capable but new to DevOps. Nothing is skipped.
>
> Repository analysed: `StxganantWxter/POS` (NexoPOS core).
> App version found in `config/nexopos.php` line 12: **`6.2.0`**.
> Date of analysis: 2026‑06‑29.

---

## How to read this document

Each deployment step uses this shape:

- **Purpose** — why you do it (the "caveman" plain‑English reason).
- **Command / Action** — exactly what to type or click.
- **Expected result** — what you should see.
- **If it goes wrong** — the common mistake and the fix.
- **Verify** — how to prove it worked before moving on.

Anything I could **not** prove from the files is flagged as **UNCERTAIN** and listed again in Phase 10. I never invented a value.

---

# PHASE 1 — Repository Reconnaissance (what this thing actually is)

NexoPOS is a **server application**, not a desktop program. It runs as a website on a computer, and the billing screen is just a web browser pointing at that computer. Caveman version: **one computer is the "brain" (server). Browsers are the "hands" (counters). Brain holds all the data.**

### 1.1 Technology stack (cited)

| Layer | Technology | Evidence (file) |
|---|---|---|
| Application | NexoPOS `6.2.0` | `config/nexopos.php:12` |
| Backend framework | **Laravel 12** | `composer.json` → `"laravel/framework": "^12.0"` |
| Language / runtime | **PHP ≥ 8.2** | `composer.json` → `"php": "^8.2.0"`; `.php-version` = `8.2`; `railpack.json` = `8.2` |
| Frontend framework | **Vue 3** + Vue Router 4 | `package.json` → `"vue": "^3.5.28"`, `vite.config.js` uses `@vitejs/plugin-vue` |
| CSS | **Tailwind CSS v4** + SCSS | `package.json` → `tailwindcss`, `@tailwindcss/vite`; `tailwind.config.js` |
| Build tool | **Vite 7** (Laravel Vite plugin) | `vite.config.js`, `package.json` `"build": "vite build"` |
| JS package manager / runtime | **npm**, **Node 24.x** | `package.json` → `"engines": { "node": "24.x" }` |
| PHP package manager | **Composer** | `composer.json`, `composer.lock` |
| Database (default) | **MySQL** (utf8mb4) | `.env.example` `DB_CONNECTION=mysql`; `config/database.php`; `.do/deploy.template.yaml` engine `MYSQL` |
| Database (also supported) | SQLite, PostgreSQL, SQL Server | `config/database.php` connections |
| ORM / DB layer | **Eloquent** + `doctrine/dbal` | Laravel core; `composer.json` `doctrine/dbal` |
| Authentication | **Laravel Sanctum** (session + API tokens) + custom roles/permissions | `composer.json` `laravel/sanctum`; `config/sanctum.php`; `database/permissions/` |
| Realtime / WebSockets | **Laravel Reverb** (or Pusher) — optional | `composer.json` `laravel/reverb`; `config/reverb.php`, `config/broadcasting.php` |
| Queue | Laravel Queue, default **`sync`** | `config/queue.php` `QUEUE_CONNECTION=sync` |
| Cache / Session | **file** by default (Redis supported) | `.env.example`, `config/session.php`, `config/cache.php` |
| Scheduler (cron) | Laravel Scheduler — **required** | `routes/console.php`, `bootstrap/modules-schedule.php` |
| PDF (receipts/invoices) | **dompdf/dompdf** | `composer.json` |
| Barcodes | **picqer/php-barcode-generator** + scale‑barcode support | `composer.json`; `app/Console/Commands/GenerateScaleBarcodeCommand.php` |
| QR codes | `simplesoftwareio/simple-qrcode` (PHP), `qrcode` (JS) | `composer.json`, `package.json` |
| Spreadsheet import/export | `phpoffice/phpspreadsheet` | `composer.json` |
| Email | SMTP / Mailgun / Postmark / SES | `config/mail.php`, `config/services.php` |
| DB snapshots (backup) | `spatie/laravel-db-snapshots` | `composer.json`; `config/filesystems.php` `snapshots` disk |
| Debug tooling | Laravel Telescope (off by default) | `composer.json`; `config/telescope.php`; `.env.example` `TELESCOPE_ENABLED=false` |
| AI / translation (optional) | Ollama, external translator | `config/services.php` `ollama`, `translator`; `app/Mcp` |

### 1.2 Required PHP extensions (cited)

From `composer.json` `require`: `ext-curl`, `ext-gd`, `ext-mbstring`, `ext-intl`, `ext-zip`. Plus `pdo_mysql` (implied by MySQL use in `config/database.php:61`). `bcmath`/`xml`/`tokenizer`/`ctype`/`fileinfo`/`openssl` are standard Laravel needs.

### 1.3 Third‑party / external services

- **Required: none for offline operation.** The app runs fully self‑hosted on one machine.
- **Optional:** SMTP email provider (password resets, notifications), Pusher (only if you don't self‑host Reverb), AWS S3 (only if you move media off the local disk), Ollama (AI translation/features), `my.nexopos.com` marketplace (only to buy/install paid modules).

### 1.4 Hardware‑integration reality check (important for a shop)

- **Barcode scanner:** A normal USB/Bluetooth scanner behaves like a **keyboard** (HID). No driver, no integration code. You point the cursor at the product field on the POS screen and scan. Default barcode type for **printed labels** is configurable (recent commit `e2dd0d0 feat(pos): add configurable default barcode type setting`).
- **Receipt printer:** NexoPOS prints receipts **from the browser** using an HTML/PDF receipt template (`resources/views/pages/dashboard/orders/templates/_receipt.blade.php`). For thermal (ESC/POS) printers, the **"NexoPOS For Windows"** companion app improves thermal printing (README, Desktop Utilities). On Linux you configure the printer via CUPS and print from the browser.
- **Camera scanning:** Done through the **mobile "Barcode Utility" app** (README) that turns a phone into a wireless scanner; there is a `routes/api/scan-utility.php` endpoint for it. The core has no built‑in webcam scanner page.

### 1.5 Docker / Compose / reverse proxy

- **No Dockerfile and no docker-compose.yml exist** in this repo (searched — none found). Do not look for one; you install natively.
- Reverse proxy is **not required**. The included `public/.htaccess` (Apache) and `public/web.config` (IIS) handle URL routing. Document root must be `public/`.
- Cloud one‑click deploy files exist but are **stale**: `.do/deploy.template.yaml` points at the old **`NexoPOS-4x` v4.7.x** repo and uses `npm run prod` (a script that **does not exist** in this `package.json`). **Do not use that template for this code.** The README "Deploy to DO" button points at branch `v5.0.x`, not this code. Treat both as not matching this repository.

### 1.6 Build process (cited)

- **Frontend build:** `npm run build` → `vite build` (`package.json:8`).
- **Good news — assets are pre‑built and committed.** `public/build/manifest.json` plus 123 asset files are tracked in git. So **you do NOT need Node/npm to deploy this snapshot** — the compiled JS/CSS is already in the repo. You only need Node if you later edit frontend source and must rebuild.
- **Backend build:** `composer install` to generate `vendor/` (which is git‑ignored — `.gitignore` line `vendor`). This step is **mandatory**.

### 1.7 Installation detection (how the app knows it's installed)

`App\Services\Helpers\App::installed()` returns true when the database has the **`nexopos_options`** table (`app/Services/Helpers/App.php:32`). Before that, every page redirects to the setup wizard at **`/do-setup`** (`routes/web-base.php:65`, `NotInstalledStateMiddleware`).

### 1.8 Two ways to install

1. **Web wizard (recommended for you):** browse to `http://<server>/do-setup`. It asks language → database connection → admin account, writes the DB settings into `.env` itself (`SetupService::updateAppDBConfiguration`), runs migrations + seeders, and creates the admin user.
2. **CLI:** `php artisan ns:setup --store_name= --admin_username= --admin_email= --admin_password= --language=en` (`app/Console/Commands/SetupCommand.php`). Requires `DB_*` already set in `.env`.

---

# PHASE 2 — Architecture (how a click becomes a sale)

```
                         ONE SHOP, LOCAL NETWORK (LAN)
   ┌───────────────────────────────────────────────────────────────────┐
   │                                                                     │
   │   Counter 1 (browser)        Counter 2 (browser, future)           │
   │   + USB barcode scanner      + USB barcode scanner                 │
   │   + receipt printer          + receipt printer                     │
   │            │                          │                            │
   │            └──────────── HTTP ───────┴───────────┐                 │
   │                                                   ▼                 │
   │                                   ┌───────────────────────────┐     │
   │                                   │   SERVER MINI PC          │     │
   │                                   │   Apache  → public/       │     │
   │                                   │   PHP 8.3 (Laravel 12)    │     │
   │                                   │   MariaDB/MySQL  ◄ DATA   │     │
   │                                   │   cron: schedule:run      │     │
   │                                   │   (optional) Reverb 8080  │     │
   │                                   │   storage/app/public ◄ media│   │
   │                                   └───────────────────────────┘     │
   │                                          │                          │
   │                                          ▼                          │
   │                                External USB SSD  ◄ nightly backups  │
   └───────────────────────────────────────────────────────────────────┘
```

**Request flow (caveman):** Cashier scans bottle → browser sends HTTP request → Apache hands it to PHP → Laravel checks the cashier is logged in (Sanctum session cookie) → reads/writes the bottle, price, tax, stock in **MySQL** → returns the updated cart → browser shows it → "Pay" writes the order + payment + stock movement rows → receipt is rendered and printed.

**Where persistent data lives (the stuff you must never lose):**
1. **MySQL database** — products, prices, customers, every order, payments, stock, users, settings. *This is the crown jewels.*
2. **`storage/app/public/`** — uploaded images (product photos, shop logo) and generated files. Symlinked to `public/storage` via `php artisan storage:link`.
3. **`.env`** — configuration + secrets (`APP_KEY`, DB password, Reverb keys). Losing `APP_KEY` can make encrypted values unreadable.
4. **`storage/snapshots/`** + your backup drive — database snapshots/backups.
5. **`modules/`** — any paid modules you install later (git‑ignored, not in this repo).

**Stateless parts (safe to rebuild/throw away):** `vendor/`, `node_modules/`, `public/build/` (re‑buildable), `bootstrap/cache/`, `storage/framework/cache|views|sessions`. The PHP code itself is stateless — all state is in the DB + `storage` + `.env`.

**Services that must always be running:**
- **Apache** (web server) — always.
- **MySQL/MariaDB** — always.
- **cron** running `php artisan schedule:run` every minute — needed for low‑stock alerts, recurring transactions, update checks, daily reports (`routes/console.php`).
- **Optional:** a **queue worker** (only if you switch `QUEUE_CONNECTION` away from `sync`), and **Reverb** (only if you want live realtime updates / smoother multi‑counter sync).

---

# PHASE 3 — Hosting Strategy (where to run it)

### 3.1 Options compared

| Option | Works if internet dies? | Cost | Maintenance | Fit for a shop |
|---|---|---|---|---|
| **Local Linux mini PC (native)** | ✅ Yes | Low (one‑time) | Low–medium | ★★★★★ Best |
| Local Linux PC/desktop | ✅ Yes | Low | Medium | ★★★★ Good (bulkier, more power draw) |
| Local Windows PC | ✅ Yes | Low | Higher (PHP on Windows is fiddly) | ★★ OK but harder |
| VPS / Cloud VM | ❌ No — billing stops when ISP/cloud is down | Monthly | Low | ★★ Risky for billing |
| Docker | n/a | — | — | ✖ Repo ships no Docker files |
| Bare‑metal server rack | ✅ | High | High | Overkill |

### 3.2 Recommendation — and WHY

**Run NexoPOS natively on a dedicated Linux mini PC sitting in the shop, on the local network, on a UPS. Use Ubuntu Server 24.04 LTS + Apache + PHP 8.3 + MariaDB.**

Why this and not the cloud:
- **A till must keep working when the internet is down.** A cloud VPS makes your billing depend on your ISP — unacceptable for a liquor shop on a busy evening. Local hosting keeps selling regardless of the internet.
- **Hardware lives at the counter.** Barcode scanner and receipt printer are USB on the LAN. Local server = zero latency, no cloud round‑trip per scan.
- **Apache (not Nginx)** because this repo already ships a working `public/.htaccess`. With Nginx you must hand‑write rewrite rules — the single most common Laravel deploy mistake. Apache + the provided `.htaccess` removes that whole failure class.
- **MariaDB** is the drop‑in MySQL that ships in Ubuntu's own repository — fewer moving parts than adding Oracle's MySQL repo.
- **Mini PC** (e.g. Intel N100/N97 class) is silent, sips power (good on a UPS), and is far more than enough for one shop.

> You can later add a cheap **cloud off‑site backup** (just copying the nightly dump) to get "best of both": local speed + off‑site safety. That is covered in Phase 8/9.

### 3.3 Hardware sizing (single shop, 1 counter now, a few later)

| Resource | Minimum | Recommended | Reasoning |
|---|---|---|---|
| **CPU** | 2 cores | 4 cores (Intel N100/N97/i3) | PHP‑FPM + MariaDB for ≤5 concurrent users is light |
| **RAM** | 4 GB | **8 GB** (16 GB if you add Redis + Reverb + many modules) | Headroom for MariaDB cache + PHP workers |
| **System SSD** | 128 GB | **256–512 GB NVMe SSD** | OS + app + DB + local backups; POS rows are tiny |
| **Backup drive** | 128 GB USB | **256 GB+ external SSD** (or NAS) + optional cloud | 3‑2‑1 backup rule |
| **Network** | Wired LAN | Wired LAN + small UPS‑powered switch | Wi‑Fi drops = failed scans |
| **Power** | — | **UPS (≥600 VA)** for mini PC + router/switch + printer | Survive cuts, clean shutdown |

**Expected load (estimate):** 1–5 staff users; even a busy liquor shop at ~200–1,000 bills/day is trivial for MariaDB. Years of data typically stays in the low single‑digit GB.

### 3.4 Risk / failure‑point audit of this recommendation

| Risk | Mitigation (covered later) |
|---|---|
| Single mini PC dies | Nightly backups to external + cloud; documented "move to new PC" (Phase 9) |
| SSD failure | Backups + "replace failed SSD" runbook (Phase 9); consider a 2nd SSD/NAS |
| Power cut mid‑sale | UPS + MySQL is transactional (an interrupted sale rolls back, not corrupts) + auto‑start on boot |
| Someone deletes data | Off‑site backup retention; restricted admin accounts (Phase 8) |
| Internet down + you used cloud | You are **not** on cloud — local keeps working |
| Forgot cron | Low‑stock alerts / scheduled jobs silently stop; Phase 8 monitoring catches it |

---

# PHASE 4 — Your Use Case (liquor shop, operational)

| Need | How NexoPOS covers it | Notes / action |
|---|---|---|
| One shop, one counter now | Single server + one browser | Start here |
| Multiple counters later | Other PCs open the same `http://<server>` URL | Just add LAN clients; consider enabling Reverb for live sync |
| Barcode scanner | USB HID scanner → POS product field | No setup; works as keyboard |
| Receipt printer | Browser print of receipt template; Windows companion for thermal | Set printer paper size (e.g. 80mm); Phase 7 §7.13 |
| Inventory management | Products, units, stock, procurements, low‑stock job | Core feature; low‑stock cron alert built in |
| Daily billing | POS + cash registers + Z/X reports | `routes/api/registers.php`, reports |
| Customer database | Customers, groups, rewards/coupons | Core feature |
| **GST invoices** | Configurable tax system (tax groups, inclusive/exclusive, shown on receipt) | **UNCERTAIN / partial** — see below |
| Local network reliability | Wired LAN + local server | Avoid Wi‑Fi for the counter |
| Automatic backups | `mysqldump`/snapshots via cron | Phase 7 §7.14, Phase 9 |
| UPS support | OS clean‑shutdown on low battery | Phase 4.2 |
| Recovery after power failure | Services auto‑start on boot; MySQL is crash‑safe (InnoDB) | Phase 7 enables auto‑start |

### 4.1 GST / liquor tax — read this carefully (honesty)

NexoPOS has a **flexible tax engine** (tax types, tax groups, percentage rates, inclusive/exclusive pricing) and the receipt template shows tax breakdown. You **can** model a GST‑style percentage and print your shop's tax number on the receipt.

**However**, from the code I did **not** find India‑specific statutory GST features: no dedicated GSTIN/HSN/SAC fields, no GSTR return export, no e‑invoice/IRN. Also, in India **liquor is generally outside GST** and taxed under **state excise/VAT**, which you would model as a custom tax rate. So: treat NexoPOS taxes as "generic configurable tax that can represent your VAT/GST %," **not** as a certified Indian GST compliance suite. **Confirm your exact statutory invoice requirements with your accountant**, and check the NexoPOS marketplace for a region/GST module if you need formal compliance. (Flagged again in Phase 10.)

### 4.2 UPS auto‑shutdown (recommended extra)

Install Network UPS Tools so the server shuts down cleanly before the battery dies:
```bash
sudo apt install nut
```
Then configure `nut` for your specific UPS model (varies by brand — see `/etc/nut/`). Goal: when battery is low, the OS runs a clean shutdown so MySQL flushes to disk. **UNCERTAIN:** exact config depends on your UPS hardware.

### 4.3 Other production must‑haves for a liquor shop

- A **wired** connection for the billing counter (Wi‑Fi drops cause missed scans).
- A spare **paper roll** stock and a tested **"reprint last receipt"** flow.
- **Static IP** for the server on your router (so the counter URL never changes) — Phase 7 §7.3.
- A **printed copy of this guide + your passwords stored offline** (in a safe), not only on the machine.
- **Age‑verification / legal signage** is a process, not software — out of scope here.

---

# PHASE 5 — Environment Variables

Source: `.env.example` and every `env(...)` call in `config/`. **No values are invented.** "—" means the file ships it empty/blank. Things the **web installer writes for you** are marked.

### 5.1 Core (you must get these right)

| Variable | Purpose | Required? | Default (in repo) | Where to obtain | Example |
|---|---|---|---|---|---|
| `APP_NAME` | App/shop display name | Yes | `"NexoPOS"` | You choose | `"Mihir Wines"` |
| `APP_ENV` | Environment mode | Yes | `local` (example) → set `production` | You set | `production` |
| `APP_KEY` | Laravel encryption key | **Yes** | empty | `php artisan key:generate` creates it | `base64:....` (auto) |
| `APP_DEBUG` | Show detailed errors | Yes | `true` → set `false` in prod | You set | `false` |
| `APP_URL` | Base URL the app is served at | Yes | `http://127.0.0.1` | Your server LAN URL; **installer auto‑sets it** | `http://192.168.1.50` |
| `APP_TIMEZONE` | App timezone | No (default `UTC`) | not in example | You set | `Asia/Kolkata` |
| `APP_LOCALE` | Default language | No (default `en`) | not in example | `config/nexopos.php` languages | `en` |
| `NS_ENV` | NexoPOS env flag | No | `production` | Leave as is | `production` |

### 5.2 Database (installer writes these from the wizard)

| Variable | Purpose | Required? | Default | Where to obtain | Example |
|---|---|---|---|---|---|
| `DB_CONNECTION` | DB driver | Yes | `mysql` | Keep `mysql` | `mysql` |
| `DB_HOST` | DB server address | Yes | `127.0.0.1` | Same machine = `127.0.0.1` | `127.0.0.1` |
| `DB_PORT` | DB port | Yes | `3306` | MySQL/MariaDB default | `3306` |
| `DB_DATABASE` | Database name | Yes | `laravel` | You create it (Phase 7 §7.6) | `nexopos` |
| `DB_USERNAME` | DB user | Yes | `root` | You create it | `nexopos_user` |
| `DB_PASSWORD` | DB password | Yes | empty | **You generate a strong one** | `<your-strong-pw>` |
| `DB_PREFIX` | Table prefix | No | `''` | Leave blank | (blank) |

### 5.3 Session / cache / queue

| Variable | Purpose | Required? | Default | Notes | Example |
|---|---|---|---|---|---|
| `SESSION_DRIVER` | Where sessions are stored | No | `file` | `file` is fine for one shop | `file` |
| `SESSION_LIFETIME` | Minutes until logout | No | `120` | Raise for long shifts | `480` |
| `SESSION_DOMAIN` | Cookie domain | Yes (must match host) | `127.0.0.1` | **Installer auto‑sets it** | `192.168.1.50` |
| `SESSION_COOKIE` | Cookie name | No | `nexopos_session` | Leave | `nexopos_session` |
| `CACHE_DRIVER` | Cache store | No | `file` | `file` fine; `redis` optional | `file` |
| `QUEUE_CONNECTION` | Background job mode | No | `sync` | `sync` = run inline (simplest). `database`/`redis` need a worker | `sync` |
| `BROADCAST_DRIVER` | Realtime driver | No | `log` (example) / `null` (config default) | `null` if no realtime; `reverb` to enable | `null` |
| `SANCTUM_STATEFUL_DOMAINS` | Domains allowed to use cookie auth | Yes | `http://127.0.0.1:8000/,http://localhost/,...` | **Installer auto‑sets it** to your host | `192.168.1.50` |

### 5.4 Realtime (Reverb / Pusher) — only if you enable live updates

| Variable | Purpose | Required? | Default | Where to obtain | Example |
|---|---|---|---|---|---|
| `REVERB_APP_ID` | Reverb app id | If using Reverb | — | **Installer generates** (`SetupService:119`) | `app-key-xxxxxxxxxx` |
| `REVERB_APP_KEY` | Reverb key | If using Reverb | — | **Installer generates** | `app-key-xxxxxxxxxx` |
| `REVERB_APP_SECRET` | Reverb secret | If using Reverb | — | **Installer generates** (a UUID) | `(uuid)` |
| `REVERB_HOST` | Public socket host | If using Reverb | `ns-socket.dev` (example) | Your domain/IP | `192.168.1.50` |
| `REVERB_PORT` | Public socket port | If using Reverb | `443` | `8080` local / `443` behind TLS | `8080` |
| `REVERB_SCHEME` | http/https for socket | If using Reverb | `http` | `http` local, `https` w/ TLS | `http` |
| `REVERB_SERVER_HOST` | Bind address of Reverb server | If using Reverb | `127.0.0.1` | `0.0.0.0` to accept LAN | `0.0.0.0` |
| `REVERB_SERVER_PORT` | Reverb server port | If using Reverb | `8080` (config default) | Keep | `8080` |
| `PUSHER_APP_ID/KEY/SECRET/CLUSTER` | Pusher (alt. to Reverb) | Only if using Pusher cloud | empty | pusher.com dashboard | — |

### 5.5 Email (optional but recommended for password resets)

| Variable | Purpose | Required? | Default | Where to obtain | Example |
|---|---|---|---|---|---|
| `MAIL_MAILER` | Mail transport | No | `smtp` | Your provider | `smtp` |
| `MAIL_HOST` | SMTP host | If sending mail | `smtp.mailtrap.io` | Provider (Gmail/SES/Mailgun/your host) | `smtp.gmail.com` |
| `MAIL_PORT` | SMTP port | If sending mail | `2525` | Provider | `587` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | SMTP creds | If sending mail | `null` | Provider | — |
| `MAIL_ENCRYPTION` | TLS/SSL | If sending mail | `null` | Provider | `tls` |
| `MAIL_FROM_ADDRESS` | "From" address | If sending mail | `null` | You set | `pos@yourshop.com` |
| `MAILGUN_*` / `POSTMARK_TOKEN` / AWS SES keys | Provider‑specific | Only for that provider | empty | Provider dashboard | — |

### 5.6 Optional storage / AI / debug

| Variable | Purpose | Required? | Default | Notes |
|---|---|---|---|---|
| `FILESYSTEM_DRIVER` | Default disk for files | No | `local` | Keep `local` for on‑prem |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_BUCKET` / `AWS_URL` / `AWS_ENDPOINT` | S3 storage | Only if using S3 | empty (`us-east-1` region) | Not needed locally |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | Redis | Only if using Redis | `127.0.0.1` / `6379` / `null` | For cache/queue scaling |
| `TELESCOPE_ENABLED` | Debug dashboard | No | `false` | Keep `false` in prod |
| `OLLAMA_ENDPOINT` / `OLLAMA_MODEL` / `OLLAMA_TIMEOUT` | Local AI features | No | `http://127.0.0.1:11434` | Optional |
| `TRANSLATOR_ENDPOINT` | External translation service | No | empty | Optional dev tool |
| `LOG_CHANNEL` / `LOG_LEVEL` | Logging | No | `stack` | Keep |

### 5.7 Env audit findings

- **`APP_KEY` is blank in `.env.example`** — must be generated, or the app throws an encryption error. ✅ Step included.
- **`.env.example` ships `APP_DEBUG=true` and `APP_ENV=local`** — insecure for production. ✅ You will flip both. Auditing flag: do not forget, or you leak stack traces.
- **`BROADCAST_DRIVER` differs** between `.env.example` (`log`) and `config/broadcasting.php` default (`null`). Harmless; set explicitly.
- **Reverb keys** are generated by the web installer (`SetupService.php:119‑121`). If you install via **CLI** instead, and you want realtime, you must add them yourself.
- No undocumented **secret** is silently required beyond the above. The only truly mandatory secrets are `APP_KEY` and `DB_PASSWORD`.

---

# PHASE 6 — Database

### 6.1 Type & layout
- **Engine:** MySQL/MariaDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci` (`config/database.php:55‑56`).
- **Migration system:** Laravel migrations, organised into `database/migrations/{core,create,update}` plus root migrations. NexoPOS runs core/create on install and marks `update` migrations as already‑done at install time for speed (`SetupService::runMigration`).
- **Seeders:** `database/seeders/` (default categories, units, taxes, demo data, etc.).

### 6.2 Initialising the database (what actually happens)
The **web wizard** or `php artisan ns:setup` triggers `SetupService::runMigration()` which:
1. `php artisan migrate --force` (root tables)
2. publishes Sanctum, runs `ns:translate`
3. runs all `core` + `create` migrations
4. marks `update` migrations as executed
5. clears cache, registers permissions, **creates your admin user**

You normally never run these by hand — the installer does. Manual equivalents exist (Phase 9) for recovery.

### 6.3 Backup process (the most important habit)
Two equivalent options:

**A) Plain `mysqldump` (simplest, recommended):**
```bash
mysqldump --single-transaction -u nexopos_user -p nexopos | gzip > /backups/nexopos-$(date +%F-%H%M).sql.gz
```

**B) Built‑in snapshots (`spatie/laravel-db-snapshots`):**
```bash
php artisan snapshot:create
# files land in storage/snapshots/ (config/filesystems.php 'snapshots' disk)
```

Also back up: `storage/app/public/` (media) and `.env`.

### 6.4 Restore process
**From mysqldump:**
```bash
gunzip < /backups/nexopos-YYYY-MM-DD-HHMM.sql.gz | mysql -u nexopos_user -p nexopos
```
**From snapshot:**
```bash
php artisan snapshot:load <snapshot-name>
```

### 6.5 Recover from database corruption
1. Stop Apache: `sudo systemctl stop apache2`.
2. Try MariaDB repair: `sudo mysqlcheck --auto-repair --all-databases -u root -p`.
3. If unrecoverable: drop & recreate the empty DB (Phase 7 §7.6), then **restore the latest good backup** (§6.4). Restore media + `.env` too.
4. Start Apache. Verify with a test login + a test sale.

> Because InnoDB is transactional, a power cut usually rolls back the half‑finished sale rather than corrupting tables — which is exactly why MySQL/MariaDB + UPS is the safe combo.

---

# PHASE 7 — Deployment Guide (baby steps, nothing skipped)

**Target:** a mini PC with **Ubuntu Server 24.04 LTS** freshly installed, stack = **Apache + PHP 8.3 + MariaDB**. Node is **not** needed (assets are pre‑built). Replace `192.168.1.50` with your server's actual IP everywhere.

> Convention: lines starting with `$` are typed in the terminal (don't type the `$`).

---

### Step 1 — Get a terminal on the server
**Purpose:** you need command‑line access to the mini PC.
**Action:** sit at the mini PC and open the terminal, **or** from another PC: `ssh youruser@192.168.1.50`.
**Expected:** a shell prompt like `youruser@nexopos:~$`.
**If it goes wrong:** SSH refused → install it on the server: `sudo apt install -y openssh-server`. Wrong IP → run `ip a` on the server to find it.
**Verify:** `$ whoami` prints your username.

---

### Step 2 — Update the operating system
**Purpose:** start from patched, known‑good packages.
```bash
$ sudo apt update && sudo apt -y upgrade
```
**Expected:** packages download and configure; ends back at the prompt.
**If it goes wrong:** "Could not get lock" → another update is running; wait a minute and retry.
**Verify:** `$ lsb_release -a` shows `Ubuntu 24.04`.

---

### Step 3 — Install Apache, PHP 8.3, MariaDB, and tools
**Purpose:** install the whole stack in one go. (Ubuntu 24.04's `php8.3` satisfies the repo's `php ^8.2`.)
```bash
$ sudo apt install -y \
    apache2 mariadb-server \
    php8.3 libapache2-mod-php8.3 php8.3-cli \
    php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring \
    php8.3-intl php8.3-zip php8.3-bcmath php8.3-xml \
    git unzip curl
```
**Expected:** all packages install without red errors.
**If it goes wrong:** a `php8.3-*` package "has no installation candidate" → run `sudo apt update` first. If your Ubuntu only offers `php8.2`, that also works — replace `8.3` with `8.2` everywhere.
**Verify:**
```bash
$ php -v          # shows PHP 8.3.x (or 8.2.x)
$ php -m | grep -E 'curl|gd|mbstring|intl|zip|pdo_mysql|bcmath'   # all listed
$ apache2 -v ; mariadb --version
```
**Common mistake:** missing `intl` or `gd` → NexoPOS errors later. The `grep` line above must show **all** of them.

---

### Step 4 — Install Composer (PHP dependency manager)
**Purpose:** Composer builds the `vendor/` folder the app cannot run without.
```bash
$ cd /tmp
$ php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
$ sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
$ rm composer-setup.php
```
**Expected:** "Composer (version 2.x) successfully installed".
**If it goes wrong:** download blocked → check the server has internet (`ping -c2 getcomposer.org`).
**Verify:** `$ composer --version`.

---

### Step 5 — Get the application code onto the server
**Purpose:** put this repository at `/var/www/nexopos`.
```bash
$ sudo mkdir -p /var/www
$ sudo git clone https://github.com/StxganantWxter/POS.git /var/www/nexopos
$ cd /var/www/nexopos
```
**Expected:** files appear (`$ ls` shows `app`, `composer.json`, `public`, …).
**If it goes wrong (private repo):** use a Personal Access Token: `git clone https://<TOKEN>@github.com/StxganantWxter/POS.git /var/www/nexopos`. **UNCERTAIN:** I can't see your repo's visibility — if `git clone` asks for credentials, it's private; use a token.
**Verify:** `$ cat config/nexopos.php | grep version` shows `6.2.0`.

---

### Step 6 — Create the database and database user
**Purpose:** give NexoPOS an empty database to fill.
```bash
$ sudo mariadb
```
At the `MariaDB [(none)]>` prompt, paste (replace the password with a strong one of your own):
```sql
CREATE DATABASE nexopos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nexopos_user'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON nexopos.* TO 'nexopos_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```
**Expected:** each line replies `Query OK`.
**If it goes wrong:** "Access denied" running `sudo mariadb` → on fresh installs root uses socket auth; `sudo` is required. Re‑typed password mismatch later → you must use the **exact** password you set here.
**Verify:**
```bash
$ mariadb -u nexopos_user -p -e "SHOW DATABASES;"   # type the password; 'nexopos' is listed
```
**Write the password down now** (you'll enter it in the installer).

---

### Step 7 — Give the server a fixed IP (so the URL never changes)
**Purpose:** counters must always reach the same address.
**Action (recommended):** in your **router's** admin page, reserve a DHCP lease for the mini PC's MAC address (e.g. `192.168.1.50`). This is the easiest, most reliable method.
**Expected:** router shows the reservation.
**If you instead set a static IP on the server:** edit `/etc/netplan/*.yaml` (advanced; wrong YAML can drop your network — router reservation is safer).
**Verify:** reboot the server; `$ ip a` still shows `192.168.1.50`.

---

### Step 8 — Install PHP dependencies (build `vendor/`)
**Purpose:** download the Laravel/NexoPOS PHP libraries.
```bash
$ cd /var/www/nexopos
$ sudo composer install --no-dev --optimize-autoloader
```
**Expected:** packages install; a `vendor/` folder appears. The post‑install script also copies `.env.example` → `.env` and tries `key:generate`.
**If it goes wrong:** "Your requirements could not be resolved" / "ext-… missing" → a PHP extension from Step 3 is missing; install it and rerun. Memory errors → `COMPOSER_MEMORY_LIMIT=-1 sudo composer install --no-dev --optimize-autoloader`.
**Verify:** `$ ls vendor/autoload.php` exists; `$ ls -a | grep .env` shows `.env`.

---

### Step 9 — Create / confirm `.env` and the app key
**Purpose:** the app needs a config file and an encryption key.
```bash
$ [ -f .env ] || sudo cp .env.example .env
$ sudo php artisan key:generate
```
Then open `.env` and set the basics:
```bash
$ sudo nano .env
```
Set at minimum:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.50
APP_TIMEZONE=Asia/Kolkata
```
Save in nano with `Ctrl+O`, `Enter`, then `Ctrl+X`.
**Expected:** `key:generate` prints "Application key set successfully." `APP_KEY=base64:...` now filled in `.env`.
**If it goes wrong:** "No application encryption key" later → you skipped `key:generate`. Re‑run it.
**Verify:** `$ grep APP_KEY .env` shows a non‑empty `base64:` value.
**Note:** You can leave `DB_*` as the defaults here — the **web installer in Step 14 will overwrite them** with what you type in the browser. (Or set them now if you prefer the CLI install.)

---

### Step 10 — Set correct file ownership and permissions
**Purpose:** the web server (user `www-data`) must read the code and **write** to `storage` and `bootstrap/cache`. This is the #1 cause of "500 errors" on Laravel.
```bash
$ sudo chown -R www-data:www-data /var/www/nexopos
$ sudo find /var/www/nexopos -type d -exec chmod 755 {} \;
$ sudo find /var/www/nexopos -type f -exec chmod 644 {} \;
$ sudo chmod -R ug+rwX /var/www/nexopos/storage /var/www/nexopos/bootstrap/cache
```
**Expected:** no output = success.
**If it goes wrong:** later "Permission denied" / "failed to open stream" on a log or cache file → re‑run the last two lines.
**Verify:** `$ sudo -u www-data test -w storage && echo writable` prints `writable`.

---

### Step 11 — Create the storage symlink
**Purpose:** make uploaded images (product photos, logo) reachable from the web.
```bash
$ sudo -u www-data php artisan storage:link
```
**Expected:** "The [public/storage] link has been connected to [storage/app/public]."
**If it goes wrong:** "link already exists" is fine. Permission error → re‑run Step 10.
**Verify:** `$ ls -l public/storage` shows an arrow `->` to `storage/app/public`.

---

### Step 12 — Configure Apache to serve the `public/` folder
**Purpose:** point the website at the right folder and allow the bundled `.htaccess` to work.
```bash
$ sudo nano /etc/apache2/sites-available/nexopos.conf
```
Paste:
```apache
<VirtualHost *:80>
    ServerName 192.168.1.50
    DocumentRoot /var/www/nexopos/public

    <Directory /var/www/nexopos/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/nexopos-error.log
    CustomLog ${APACHE_LOG_DIR}/nexopos-access.log combined
</VirtualHost>
```
Save and exit. Then:
```bash
$ sudo a2enmod rewrite
$ sudo a2dissite 000-default.conf
$ sudo a2ensite nexopos.conf
$ sudo apache2ctl configtest        # must say: Syntax OK
$ sudo systemctl restart apache2
```
**Expected:** `Syntax OK`, Apache restarts cleanly.
**If it goes wrong:** `AllowOverride All` missing → the `.htaccess` is ignored and every URL except the homepage 404s. `mod_rewrite` not enabled → same symptom; `a2enmod rewrite` fixes it.
**Verify:** `$ curl -I http://192.168.1.50` returns an HTTP response (likely a redirect to `/do-setup`).

---

### Step 13 — Make services start automatically on boot (power‑cut recovery)
**Purpose:** after a power failure, the POS must come back by itself.
```bash
$ sudo systemctl enable --now apache2
$ sudo systemctl enable --now mariadb
```
**Expected:** both report `enabled` and `active (running)`.
**Verify:** `$ systemctl is-enabled apache2 mariadb` prints `enabled` twice. (Optional real test: reboot and confirm the site loads.)

---

### Step 14 — Run the web installer (create the app + admin)
**Purpose:** initialise the database and create your admin login — the friendly way.
**Action:** on any PC on the LAN, open a browser to:
```
http://192.168.1.50/do-setup
```
Follow the wizard:
1. **Language** → English (or your choice).
2. **Database** → Driver `MySQL`, Host `127.0.0.1`, Port `3306`, Database `nexopos`, Username `nexopos_user`, Password = the one from Step 6. Click to test/continue.
3. **Administrator** → store name (≥6 chars), admin username (≥5), valid email, password (≥6 — **use a strong one**).
4. Finish.
**Expected:** "successfully installed" and you land on the login page.
**If it goes wrong:**
- "database variables are missing" / connection fails → the DB creds are wrong; re‑check Step 6, and that `DB_HOST=127.0.0.1`.
- Page shows a raw 500 → check `storage/logs/laravel.log`; usually permissions (Step 10) or missing PHP extension (Step 3).
- "NexoPOS is already installed" → the `nexopos_options` table exists; you're done — just log in.
**Verify:** you can log in with the admin account and see the dashboard.

> **CLI alternative to Step 14** (if the browser wizard misbehaves): set `DB_*` in `.env`, then
> `sudo -u www-data php artisan ns:setup --store_name="Mihir Wines" --admin_username="admin" --admin_email="you@example.com" --admin_password="StrongPass" --language=en`

---

### Step 15 — Cache config for speed, then set up the scheduler (cron)
**Purpose:** (a) make Laravel fast in production; (b) run the background jobs NexoPOS relies on (low‑stock alerts, recurring transactions, daily reports, update checks — `routes/console.php`).
```bash
$ sudo -u www-data php artisan config:cache
$ sudo -u www-data php artisan route:cache
$ sudo -u www-data php artisan view:cache
$ sudo crontab -u www-data -e
```
(If asked, choose `nano`.) Add this single line at the bottom:
```
* * * * * cd /var/www/nexopos && php artisan schedule:run >> /dev/null 2>&1
```
Save and exit.
**Expected:** "crontab: installing new crontab".
**If it goes wrong:** edited the **wrong user's** crontab → it must be `www-data` (the `-u www-data` flag), so jobs run with the right permissions. After **any** later `.env` change you must re‑run `php artisan config:cache` (or `config:clear`), or your change is ignored.
**Verify:** wait ~2 minutes, then `$ sudo -u www-data php artisan schedule:list` shows scheduled tasks; the app's "last cron activity" warning (if any) disappears.

---

### Step 16 — First real‑world checks
**Purpose:** prove the POS works end to end before trusting it.
1. Log in as admin.
2. Create one **product** with a barcode and price.
3. Open **POS**, focus the search field, **scan** that product — it should add to the cart.
4. Take a **payment** and **print** the receipt (browser print dialog).
**Expected:** sale is recorded; stock drops by one; receipt prints.
**If it goes wrong:** scanner types into the wrong field → click the POS barcode field first. Receipt layout wrong on thermal printer → see §7.13.
**Verify:** the order appears under Orders, and the dashboard sale total increases.

> **Frontend note:** because `public/build/` (the compiled Vue/Tailwind assets) is committed in this repo, the UI works **without** Node. You only need Node/npm if you later modify frontend source — then install Node 24 and run `npm install && npm run build`.

---

### 7.13 Receipt printer setup (thermal)
- **Easiest:** set the receipt printer as the **default printer** on the counter PC, and in NexoPOS receipt settings choose the matching paper width (commonly **80mm** or **58mm**). Print from the browser; in the print dialog set margins to none and paper size to your roll.
- **Best thermal quality on Windows counters:** install **"NexoPOS For Windows"** (README → Desktop Utilities) which improves ESC/POS thermal connectivity.
- **On a Linux counter:** add the printer in CUPS (`http://localhost:631`) and print from the browser.
- **UNCERTAIN:** exact ESC/POS auto‑cut/cash‑drawer kick behaviour depends on your printer model and the companion app; test with your specific hardware.

### 7.14 Automatic nightly backup (do this before going live)
**Purpose:** a shop without backups is one power surge away from disaster.
```bash
$ sudo mkdir -p /backups && sudo chown www-data:www-data /backups
$ sudo -u www-data crontab -e
```
Add (replace the DB password):
```
30 1 * * * mysqldump --single-transaction -u nexopos_user -p'CHANGE_ME_STRONG_PASSWORD' nexopos | gzip > /backups/nexopos-$(date +\%F).sql.gz
40 1 * * * tar czf /backups/media-$(date +\%F).tar.gz -C /var/www/nexopos storage/app/public
0 2 * * * find /backups -name '*.gz' -mtime +30 -delete
```
**Verify:** next morning, `$ ls -lh /backups` shows a fresh `.sql.gz` and media `.tar.gz`. Then **copy these off the machine** (external SSD / cloud) — Phase 9. (Putting the DB password in cron is convenient but readable by root; for tighter security use a `~www-data/.my.cnf` credentials file instead — see Phase 8.)

---

# PHASE 8 — Production Hardening

| Control | What to do | Why it matters |
|---|---|---|
| **HTTPS** | If you ever expose this beyond the LAN, put a TLS cert in front (Let's Encrypt via `certbot` needs a domain). On a pure LAN, HTTPS is optional but enables Reverb/secure cookies. Then set `SESSION_SECURE_COOKIE=true`. | Stops password sniffing; required for the web outside the shop |
| **Firewall (UFW)** | `sudo ufw allow OpenSSH` → `sudo ufw allow 80/tcp` → (`8080/tcp` only if using Reverb) → `sudo ufw enable` | Blocks every other port; shrinks attack surface |
| **Keep the LAN private** | Do **not** port‑forward the POS to the internet unless you add HTTPS + a domain + Fail2ban | A POS facing the open internet is a target |
| **Automatic OS updates** | `sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades` | Security patches without you remembering |
| **Database backups** | Phase 7 §7.14 + off‑site copy (Phase 9) | Survive disk death / ransomware / fat‑finger |
| **Log rotation** | Set `LOG_CHANNEL=daily` in `.env` (Laravel keeps 14 days); Apache logs already rotate via `logrotate` | Logs don't fill the disk |
| **Monitoring** | A weekly check: disk space (`df -h`), that backups exist, that cron ran (`schedule:list`); optionally `monit`/Uptime Kuma | Catch silent failures (full disk, dead cron) before they bite |
| **Password policy** | Strong unique admin password; one login per staff member; use NexoPOS **roles/permissions** to limit cashiers | Accountability; limit blast radius if a till PIN leaks |
| **Admin account security** | Don't share the admin login; create per‑user accounts; review the users list periodically | Trace who did what |
| **Secrets management** | `chmod 640 .env` (owner `www-data`); never commit `.env` (already git‑ignored); store DB password in `/var/www/.my.cnf` (chmod 600) for cron instead of inline | `.env` holds `APP_KEY` + DB password |
| **Rate limiting** | Laravel ships throttling on auth/API routes (Sanctum). Keep defaults; tighten if exposed | Slows brute‑force on login |
| **Fail2ban** | `sudo apt install fail2ban` (protects SSH out of the box; add an Apache/Laravel‑auth jail only if internet‑exposed) | Bans repeated attackers |
| **Turn off debug** | Confirm `APP_DEBUG=false`, `APP_ENV=production`, `TELESCOPE_ENABLED=false` | Debug pages leak secrets/stack traces |
| **DB user least privilege** | `nexopos_user` already only has rights on the `nexopos` DB (Step 6), not all DBs | Limits damage if app is breached |

**Security‑gap audit (honest):**
- The bundled `.env.example` defaults (`APP_DEBUG=true`, `APP_ENV=local`) are **dev defaults** — you must flip them (done in Step 9). If you skip it, you ship an insecure box.
- `config/broadcasting.php` Reverb/Pusher set `verify => false` / `CURLOPT_SSL_VERIFYPEER => false` for the socket client — fine on a trusted LAN, but means the socket layer doesn't verify TLS. Don't expose Reverb to the internet without thought.
- Telescope, if ever enabled in production, exposes request/DB data — keep it `false`.

---

# PHASE 9 — Maintenance (runbooks)

> Golden rule before **any** change: **back up first** (Phase 7 §7.14). Most steps below run from `/var/www/nexopos`.

### 9.1 Update the software
```bash
$ cd /var/www/nexopos
$ sudo -u www-data php artisan down                      # maintenance mode
$ sudo -u www-data git pull origin master                # or your release branch
$ sudo -u www-data composer install --no-dev --optimize-autoloader
$ sudo -u www-data php artisan migrate --force           # apply new DB changes
$ sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
$ sudo -u www-data php artisan up
```
*(If you customised frontend, also `npm install && npm run build`.)* NexoPOS also has an in‑app updater and `php artisan ns:update` (`app/Console/Commands/UpdateCommand.php`) — but the git+composer+migrate flow above is the transparent one.
**Verify:** version on the dashboard changes; do a test sale.

### 9.2 Roll back a bad update
```bash
$ sudo -u www-data php artisan down
$ sudo -u www-data git log --oneline -5                  # find the previous good commit
$ sudo -u www-data git checkout <previous-good-commit>
$ sudo -u www-data composer install --no-dev --optimize-autoloader
# restore the DB backup you took before updating:
$ gunzip < /backups/nexopos-YYYY-MM-DD.sql.gz | mysql -u nexopos_user -p nexopos
$ sudo -u www-data php artisan config:cache
$ sudo -u www-data php artisan up
```
> Why restore the DB too: migrations may have changed the schema; rolling back code without rolling back data can mismatch. Always pair them.

### 9.3 Back up everything (the three things)
1. **Database:** `mysqldump … | gzip > db.sql.gz` (§7.14).
2. **Media:** `tar czf media.tar.gz -C /var/www/nexopos storage/app/public`.
3. **Config:** copy `.env`.
Then **copy all three off the machine**: external SSD (`rsync -a /backups /mnt/usb-backup/`) and/or cloud (`rclone copy /backups remote:nexopos-backups`).

### 9.4 Restore everything onto a clean machine
1. Do Steps 1–13 of Phase 7 (fresh stack, code, empty DB, Apache, permissions).
2. Put back your saved `.env` (keep the **same `APP_KEY`** — critical).
3. `composer install --no-dev --optimize-autoloader`.
4. Restore DB: `gunzip < db.sql.gz | mysql -u nexopos_user -p nexopos`.
5. Restore media: `tar xzf media.tar.gz -C /var/www/nexopos`.
6. `php artisan storage:link`, fix permissions (Step 10), `config:cache`, re‑add cron (§7.15/7.14).
**Verify:** log in, check products/orders are present, do a test sale.

### 9.5 Move to another computer / clone the whole setup
Same as 9.4 — it **is** the clone procedure. Backups + `.env` (with the original `APP_KEY`) reproduce the system anywhere. Update `APP_URL`/`SESSION_DOMAIN` if the new IP differs, then `config:cache`.

### 9.6 Replace a failed SSD
1. New SSD → install Ubuntu Server 24.04 LTS.
2. Run the §9.4 restore from your latest off‑site backup.
3. Re‑point counters (same IP if you kept the router reservation — they won't even notice).
> This is exactly why backups must live **off** the server. A backup on the dead SSD is no backup.

### 9.7 Recover after accidental deletion
- **Deleted a record in‑app:** restore the most recent DB backup into a *scratch* database, copy out just the needed rows, or accept restoring the whole DB if recent.
- **Deleted files on disk (code/media):** code → `git checkout .` / re‑clone; media → restore `media.tar.gz`.
- **Deleted `.env`:** recreate from `.env.example`, then restore your saved `APP_KEY` and DB settings. **If `APP_KEY` is truly lost**, encrypted values can't be decrypted — another reason to back up `.env`.

---

# PHASE 10 — Final Audit

### 10.1 Self‑review checklist (I checked each against the files)

| Check | Status | Note |
|---|---|---|
| Build commands correct | ✅ | `composer install` (mandatory); `npm run build` only if rebuilding (assets pre‑committed) |
| Correct PHP version | ✅ | `^8.2` (`composer.json`); guide uses 8.3 which satisfies it; 8.2 also fine |
| All PHP extensions listed | ✅ | curl, gd, mbstring, intl, zip, pdo_mysql, bcmath/xml |
| Correct DB engine | ✅ | MySQL/MariaDB, utf8mb4 (`config/database.php`) |
| Ports consistent | ✅ | HTTP 80, MySQL 3306, Reverb 8080 (`config/reverb.php`) — all reconciled |
| Env vars complete | ✅ | Enumerated every `env()` in `config/`; mandatory secrets = `APP_KEY`, `DB_PASSWORD` |
| Install method real | ✅ | `/do-setup` route + `ns:setup` command both verified in code |
| Permissions step present | ✅ | `storage` + `bootstrap/cache` writable by `www-data` |
| Scheduler/cron included | ✅ | `routes/console.php` requires it |
| Backup/restore verified | ✅ | mysqldump + `spatie/laravel-db-snapshots` both confirmed |
| Docker assumptions | ✅ | Confirmed **no** Docker files — guide is native install |
| Reverse‑proxy correctness | ✅ | Apache + shipped `.htaccess`, `AllowOverride All`, `mod_rewrite` |
| Networking | ✅ | Static IP via router reservation; UFW rules |
| OS‑specific issues | ✅ | Written for Ubuntu 24.04; `php8.2` fallback noted |

### 10.2 Confidence score

**8.5 / 10** that, followed literally on a clean Ubuntu 24.04 mini PC, this brings up a working NexoPOS for one liquor‑shop counter.

The deductions are not about the core stack (which is solidly verified) but about the externally‑dependent items below.

### 10.3 Uncertainties (each listed separately — not guessed)

1. **Repository visibility / clone URL.** I can't confirm whether `StxganantWxter/POS` is public. If `git clone` (Step 5) asks for credentials, it's private — use a Personal Access Token.
2. **Exact GST/statutory compliance.** Verified: a flexible, configurable tax engine + tax on receipts. **Not found:** India‑specific GSTIN/HSN fields, GSTR export, or e‑invoice/IRN. Liquor in India is usually state excise/VAT, not GST. Confirm legal invoice requirements with your accountant; check the marketplace for a regional/GST module.
3. **Thermal printer specifics.** Auto‑cut, cash‑drawer kick, and exact paper width depend on your printer model and whether you use the "NexoPOS For Windows" companion. Must be tested on your hardware.
4. **UPS auto‑shutdown.** `nut` config is hardware‑specific; I gave the package, not your model's exact settings.
5. **PHP version on your specific Ubuntu image.** Guide assumes 24.04 ships `php8.3`. If yours offers only `php8.2`, swap the version in package names (still satisfies `^8.2`). Note: `AGENTS.md` mentions 8.4 as the *developers'* environment, but `composer.json` only requires `^8.2`, so 8.2/8.3 are correct and safe.
6. **Reverb / realtime.** Not needed for a single counter and left optional. If you enable it, you'll add a long‑running `php artisan reverb:start` service (e.g. via `systemd`) — that systemd unit is **not** included here because you likely don't need it yet.
7. **Queue worker.** With `QUEUE_CONNECTION=sync` (default) none is needed. If you later switch to `database`/`redis`, you must add a `php artisan queue:work` service.
8. **Stale cloud‑deploy templates.** `.do/deploy.template.yaml` and the README "Deploy to DO/InstaPods" buttons target older NexoPOS branches (v4/v5), not this v6.2 code — I deliberately did **not** base the guide on them.

### 10.4 What would raise the score to ~10

- Confirmation the repo is public (or a token).
- Your printer model + paper width.
- Your statutory invoice requirements (so the tax setup can be verified, not just described).
- Whether you want realtime (Reverb) and multiple counters from day one (so I'd add the systemd units and the HTTPS/Reverb config).

---

*End of guide. Built entirely from the files in this repository; every factual claim is traceable to a cited file, and every gap is flagged rather than guessed.*
