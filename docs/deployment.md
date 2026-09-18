# Deployment — Dars Attendance (cPanel)

For taking this from a local machine to the live cPanel host. Follow it in order; each
step assumes the previous one succeeded.

> **Read this whole page before starting.** Steps 3, 5 and 7 are where a mistake costs
> data or breaks the punch screen for every employee at once.

This app is deployed **before** the ordering system, on its own subdomain with its own
database. That is deliberate: Attendance owns a working login and never calls the
ordering system, so it can go live and stay live whether or not the ordering system is
ready. See `docs/sso.md`.

---

## Target

```
Subdomain      attendance.darscoffee.com   (own SSL via AutoSSL)
Document root  ~/attendance/public         — NOT the project root
Database       own MySQL database, own user — NOT shared with ordering
Cron           php artisan schedule:run    every minute
Timezone       store UTC, display Asia/Kuala_Lumpur
```

Every phase from 3 onward is independently deployable. **Phase 3 was the deploy point**;
Phases 4–7 are all built and deployed together.

---

## What you need ready

| Item | Where to get it |
|---|---|
| cPanel login with SSH or Terminal access | Your hosting provider |
| Domain + SSL certificate | `AutoSSL` in cPanel is fine |
| MySQL database + user | cPanel → MySQL® Databases |

**Decide one thing before you start:** does this host terminate HTTPS itself, or is there a
proxy or CDN in front? If in front — Cloudflare, or a host whose TLS is terminated upstream
— you must set `TRUSTED_PROXIES`. Step 3 explains what goes wrong otherwise, and it is the
single most likely thing to break on this deploy.

---

## 1. Upload the code

Preferred: deploy with Git rather than dragging files up.

```bash
cd ~
git clone https://github.com/mohdmuslin/darscoffee-attendance.git attendance
```

Point the subdomain's document root at **`attendance/public`** — not at the project root.
If you cannot change the document root, see step 8.

---

## 2. Install PHP dependencies

```bash
cd ~/attendance
composer install --no-dev --optimize-autoloader
```

`--no-dev` keeps PHPUnit, Pest and Pint off the server.

Confirm PHP is 8.3 or newer (`php -v`). Laravel 13 will not run on 8.2.

---

## 3. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Then edit `.env`:

```ini
APP_NAME="Dars Attendance"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://attendance.darscoffee.com

DB_CONNECTION=mysql
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

ATTENDANCE_TIMEZONE=Asia/Kuala_Lumpur

# Only if a proxy or CDN sits in front of this host — see below.
TRUSTED_PROXIES=*
```

### ⚠️ `APP_DEBUG=false` is not optional

With debug on, any error page prints database credentials and environment values to
whoever triggers it. The deploy check treats this as a hard failure.

### ⚠️ `TRUSTED_PROXIES` — the one that breaks photographs

If a proxy terminates TLS, PHP receives a **plain HTTP** request carrying
`X-Forwarded-Proto: https`. Laravel trusts no proxies by default, so it believes the
request is insecure. Photograph URLs are then generated over `http://`, redirect to
`https://`, and **fail signature validation**, because the signature is checked against
the request's scheme.

The result is a specific and misleading symptom:

> The console loads. The API works. **Every staff photograph returns 403.**

The obvious suspects are the signed-URL expiry and the file permissions. Neither is the
cause. Set `TRUSTED_PROXIES=*` (or the provider's CIDR range) and the photographs appear.

**Do not "fix" this with `URL::forceScheme('https')`.** That makes it worse: the URL is
then generated as HTTPS while the request still reports HTTP, so validation fails
immediately.

### ⚠️ On a cached config, `.env` is not enough

`TRUSTED_PROXIES` is read in `bootstrap/app.php`, which runs **before** the config
repository is bound — `config()` there throws and takes the whole app down, so the value
comes from the environment directly.

If you run `php artisan config:cache`, `.env` is **not read on requests at all**. The
value must then exist in the real environment — the PHP-FPM pool and the cron entry, not
just `.env`. This is the most common cause of "I changed the setting and nothing
happened."

**File permissions:**

```bash
chmod -R 775 storage bootstrap/cache
```

---

## 4. Build the frontend

Either build locally and upload `public/build`, or build on the server:

```bash
npm ci
npm run build
```

The app shows a blank page without these assets, because the Blade views load them via
`@vite`.

---

## 5. Database and tables

```bash
php artisan migrate --force
php artisan db:seed --force
```

The seeders create the 3 outlets (`SG-RAMAL`, `SEDAP-SANTAI`, `DARS-COFFEE`), one owner,
and Siti Fatimah as a manager mapped to all three outlets.

> ⚠️ **Change both passwords immediately.** The seeded credentials are
> `owner@darscoffee.com` and `fatimahbokhare@gmail.com` with the password `password` — and
> they are published in this repository. Anyone who has seen the repo can sign in and read
> every staff member's hours until you change them.
>
> The deploy check reports these as a **failure** until the accounts are removed or their
> passwords changed. Sign in, change them under **Accounts**, then re-run the check.

### The `APP_KEY` is not replaceable

`APP_KEY` encrypts employee IC numbers. Restoring a database dump onto a server with a
**different** `APP_KEY` makes those values permanently unreadable — the console shows a
masked number that cannot be revealed. If you are restoring, restore `.env` with it.

---

## 6. Scheduled work — required

Two jobs run on a schedule, and both fail silently without cron.

cPanel → **Cron Jobs** → add, every minute:

```
* * * * * cd /home/USER/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Replace `USER` and the PHP path with your own (`which php`).

| Job | Frequency | What breaks without it |
|---|---|---|
| `attendance:flag-open-segments` | hourly | A forgotten clock-out is never flagged — **and that employee cannot clock in again at all** (see below) |
| `attendance:purge-photos` | daily 03:00 | Photographs are kept past the retention window — a PDPA compliance gap nobody notices |

**The forgotten clock-out job matters more than it sounds.** The database allows only one
open segment per employee, so someone who forgets to clock out cannot clock in the next
morning: they scan, enter their PIN, press the button, and **nothing happens**, with no
error shown. Nobody at the counter can see why. This job is what surfaces it.

The retention purge runs at 03:00 in the **business** timezone. That is not decoration:
the application runs in UTC, so a naive 03:00 would fire at 11am in Kuala Lumpur, deleting
photographs while the console is in use.

**Verify it is running.** After a few minutes:

```bash
php artisan schedule:list
```

---

## 7. Pre-flight check

**Run this before telling staff to use the site.**

```bash
php artisan attendance:deploy-check
```

It reports each setting, and exits non-zero if anything is wrong. Every item is a
misconfiguration that produces a *working-looking* application with one thing quietly
broken — which is why they are checked rather than assumed.

| Check | Why it matters |
|---|---|
| `APP_ENV` / `APP_DEBUG` | Debug on leaks credentials in error pages |
| `APP_KEY` | Missing means IC numbers cannot be decrypted |
| `APP_URL` | Must be https, or signed photo URLs fail validation |
| `QUEUE_CONNECTION` | Must be `sync` — there is no worker, so a queued job never runs |
| `CACHE_STORE` | Redis is not available on this host |
| Database + migrations | A missed `migrate --force` fails on the first request touching a new column |
| Photo storage path | Must be **outside** the web root, or every photograph is public |
| Photo storage writable | Otherwise every punch with a photo fails |
| `TRUSTED_PROXIES` | Warns, because "correctly unset" and "wrongly unset" look identical |
| Signed URL scheme | The photograph-403 check |
| Config cached | Changes how `.env` values must be supplied |
| Seeded accounts | Fails while the published default passwords could still be in use |
| Server clock | A drifting server mis-stamps every punch |

It reports rather than repairs. A command that silently rewrites a production
configuration is one nobody trusts twice.

### The checks it cannot make

- **Whether cron is installed.** It cannot read the crontab. Verify step 6 separately —
  reporting "cron is fine" from inside the application would be a lie, and the
  consequences are described above.
- **Whether the host clock is correct.** It prints the server time for you to compare.
- **Whether HTTPS actually works from a phone.** See step 9.

---

## 8. If you cannot move the document root

Some shared hosts only serve from `public_html`. Put the app outside the web root and
forward only its `public` folder:

```
/home/USER/attendance/        ← the app, NOT web-accessible
/home/USER/public_html/       ← document root
```

Copy `attendance/public/*` into `public_html/`, then edit `public_html/index.php`:

```php
require __DIR__.'/../attendance/vendor/autoload.php';
$app = require_once __DIR__.'/../attendance/bootstrap/app.php';
```

**Test that `/.env` and `/storage` are not reachable** by browsing to them. If either
loads, stop and fix it before going further.

---

## 9. Go-live checks

Run these on the live site before staff use it.

| # | Check | Expected |
|---|---|---|
| 1 | `https://attendance.darscoffee.com/console` | Console loads over HTTPS; sign in as the owner |
| 2 | Change both seeded passwords | Then re-run `attendance:deploy-check` — no seeded-account failures |
| 3 | Browse to `/.env` | **403 or 404** — never the file contents |
| 4 | Browse to `/storage/logs/laravel.log` | **403 or 404** |
| 5 | **Employees** → open one with a photo | **The photograph renders.** This is the `TRUSTED_PROXIES` check |
| 6 | **Outlets & codes** → print a code | QR renders and prints |
| 7 | Scan that printed code with a real phone | Punch screen opens; PIN accepted; photo taken |
| 8 | Clock in, take a break, clock out | Timesheet shows the correct hours |
| 9 | Set the phone to aeroplane mode and clock out | "Saved on this phone" message appears, not "not recorded" |
| 10 | Turn the connection back on | The queued punch syncs; timesheet shows the time you actually punched |
| 11 | **Anomalies** | The offline punch is listed as `Synced from offline` |
| 12 | `php artisan schedule:list` | Both jobs listed |
| 13 | Leave for an hour, then check `storage/logs` | No repeating errors |

> **Check 5 is the one that catches the proxy problem**, and it is worth doing twice — once
> on wifi and once on mobile data. A CDN or proxy can treat the two differently.

---

## 10. Ongoing

```bash
php artisan attendance:deploy-check        # after any .env change
php artisan attendance:purge-photos --dry-run   # preview the retention purge
php artisan schedule:list                  # confirm scheduled work
php artisan config:clear                   # after editing .env if config is cached
```

### Backups

**A database dump is only half the backup.** Photographs are FILES, not rows: a
database-only restore gives you a system that references hundreds of staff photographs and
can serve none of them — every timesheet showing a broken image, and no evidence to settle
a dispute, which is the only reason the photographs are collected.

```bash
mysqldump your_db > backup.sql        # the records
tar czf photos.tar.gz storage/app/private   # the photographs
php artisan attendance:deploy-check   # prints the file counts as a reminder
```

The drill script (`scripts/dev-backup-drill.php`) dumps, restores into a scratch database,
and compares row counts per table. It is guarded to refuse outside a local environment
because it creates and drops databases — run it on a copy, not on the live host.

> **A backup that has never been restored is not a backup; it is a file.** Restore one into
> a scratch database before you need it in anger.

---

## Rollback

```bash
git log --oneline -10          # find the last good commit
git checkout <commit>
composer install --no-dev --optimize-autoloader
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan attendance:deploy-check
```

Do **not** use `migrate:rollback` on a live site once punches exist — it drops tables and
takes the attendance history with them. Restore from a database backup instead.

---

## The development scripts are guarded

`scripts/` contains helpers that **delete data** — `dev-reset-punches.php` removes an
employee's time entries, `dev-seed-*.php` clear ranges before reseeding,
`dev-delete-employee.php` force-deletes a record. They are committed, so they are present
on the server after a deploy.

They refuse to run unless `APP_ENV` is `local` or `testing`. `dev-test-mysql.php` is the
most dangerous of them and the reason the guard exists: it **rewrites `.env`** to point at
a scratch database, runs the suite, and restores it. Interrupted halfway it would leave the
**live application pointed at an empty scratch database** — staff would scan the code the
next morning and be told their PIN does not match, with nothing in the application's logs
to explain it.

The guard reads `.env` directly, because it runs before the framework boots. It fails
closed: an unreadable `.env` is treated as production.
