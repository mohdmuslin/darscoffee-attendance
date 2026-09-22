# Deployment — Dars Attendance (cPanel, no SSH)

For taking this from a local machine to the live cPanel host **without shell access**. The
host has no SSH and no Terminal, so every step below happens through cPanel's GUI, GitHub
Actions, or FTP.

> **Read this whole page before starting.** Steps 3, 5 and 7 are where a mistake costs data
> or breaks the punch screen for every employee at once.

---

## Why no shell changes the approach

Three things normally done with `php artisan` cannot be run:

```
php artisan key:generate       → done by attendance:install
php artisan migrate --force    → done by attendance:install / attendance:deploy
php artisan db:seed --force    → done by attendance:install --seed
```

Those are wrapped in two commands triggered from cPanel's Cron Jobs GUI. Everything else —
installing dependencies, building the frontend — happens on GitHub's runners and arrives as
finished files.

**Git is used for the code, but never cloned onto the server.** `vendor/` and
`public/build` are both gitignored (correctly), so a clone on the server would give source
code with no dependencies and no compiled frontend: a blank page. GitHub Actions builds
them and uploads everything over FTP.

> **Do not use cPanel's Git™ Version Control for this app.** It clones the repository but
> cannot install Composer dependencies or build the frontend, and `.cpanel.yml` needs shell
> execution the host does not provide. You would get code that does not run.

---

## Target

```
Domain         attendance.darscoffee.com
FTP host       ftp.mwstay.com        port 21 (plain FTP — see below)
FTP user       darscoffeeeftipi@darscoffee.com
FTP lands at   /home/mwstayco/attendance.darscoffee.com/attendance
Access         NO SSH, NO cPanel Terminal — cron + File Manager + FTP only
```

**The layout on the server:**

```
/home/mwstayco/attendance.darscoffee.com/
  public/            <- leftover empty folder, NOT the docroot
  attendance/        <- the Laravel application root
    artisan          app/   bootstrap/   config/   storage/
    vendor/ (from vendor.zip)          .env
    public/          <- DOCROOT: index.php + build/
```

| What | Path |
|---|---|
| App root | `/home/mwstayco/attendance.darscoffee.com/attendance` |
| **Document root** | `/home/mwstayco/attendance.darscoffee.com/attendance/public` |
| `.env` | `/home/mwstayco/attendance.darscoffee.com/attendance/.env` |
| Database | own MySQL database + user, NOT shared with the ordering system |
| Cron | `php artisan schedule:run` every minute |
| Timezone | store UTC, display Asia/Kuala_Lumpur |

> ⚠️ **cPanel will not let the document root escape `/home/mwstayco/attendance.darscoffee.com/`.**
> A Laravel app therefore has to live *inside* that folder, which is the layout above. This is
> why the app was moved out of `darscoffee.com/attendance` — that location can never be served,
> no matter how the domain is configured.
>
> ⚠️ **`.env` must be in the APP ROOT**, i.e. `.../attendance.darscoffee.com/attendance/.env`.
> Laravel reads it from the app root — the folder containing `artisan`. A copy one level up is
> never read, and the app then dies with "No application encryption key has been specified"
> and no database credentials. Keep exactly one, in the app root.
>
> ⚠️ **Delete `public/index.html` if the host created one.** A placeholder `index.html` can
> take precedence over Laravel's `index.php` depending on `DirectoryIndex` order, which looks
> like a broken app while every path and permission is correct.

> **The transport is plain, unencrypted FTP, and the host is why.** It offers FTPS, and the
> TLS *control* connection works — but every TLS **data** connection fails with
> `SSL alert number 50 (data socket)`, so no file can be transferred over FTPS at all. This
> is a Node 24 / `basic-ftp` 5.x incompatibility, not a setting; see the note under
> **Step 4** for the full evidence. The mitigation is that `.env` is excluded from the
> upload, so no database password, app key or staff photograph ever crosses the wire —
> only source code and a build artifact.

---

## 1. ⚠️ The document root — do this first

**Laravel must be served from its `public/` subfolder.** If the app directory itself is the
web root, then `.env` — which holds your database password and `APP_KEY` — is downloadable
by anyone at `https://attendance.darscoffee.com/.env`.

`APP_KEY` decrypts the employee IC numbers. If it leaks, that data is compromised; if it is
lost, it is unreadable for ever.

In cPanel → **Domains**, set the document root to the `public` folder inside your app
directory. If cPanel will not let you (some hosts fix the root to `public_html`), use
step 8.

**The test, which you must actually run:** once Step 5 is done, browse to
`https://attendance.darscoffee.com/.env`.

- **403 or 404** → correct, carry on.
- **You can read it** → **stop.** Rename the file, fix the document root, then rotate your
  database password and `APP_KEY`, because both were exposed.

Also check `https://attendance.darscoffee.com/storage/logs/laravel.log`. Same expectation.

---

## 2. Create the database

cPanel → **MySQL® Databases**:

1. Create a database. cPanel prefixes it with your account name, e.g. `mwstayco_attendance`.
2. Create a user.
3. **Add the user to the database** with ALL PRIVILEGES.

Write down the full database name, the full username, and the password. The names are
prefixed — `.env` needs the complete values, not what you typed in the box.

---

## 3. Upload `.env`

`.env` is **not in the repository** (it holds secrets), so it is uploaded once by hand and
never touched by deploys — the deploy config explicitly excludes it, or it would be deleted
on the first run.

Create `.env` **in the app directory** via cPanel → **File Manager** → New File, then edit it:

```ini
APP_NAME="Dars Attendance"
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://attendance.darscoffee.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mwstayco_attendance
DB_USERNAME=mwstayco_attendance
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

ATTENDANCE_TIMEZONE=Asia/Kuala_Lumpur

# Only if a proxy or CDN sits in front of this host — see below.
# TRUSTED_PROXIES=*
```

Leave `APP_KEY` empty — `attendance:install` fills it in.

### ⚠️ `APP_DEBUG=false` is not optional

With debug on, any error page prints database credentials and environment values to
whoever triggers it.

### ⚠️ `TRUSTED_PROXIES` — the setting that breaks every photograph

If a proxy or CDN (Cloudflare, or a host terminating TLS upstream) sits in front, PHP
receives a **plain HTTP** request carrying `X-Forwarded-Proto: https`. Laravel trusts no
proxies by default, so it believes the request is insecure. Photograph URLs are then
generated over `http://`, redirect to `https://`, and **fail signature validation**,
because the signature is checked against the request's scheme.

The symptom is specific and misleading:

> **The console loads. The API works. Every staff photograph returns 403.**

The obvious suspects are the signed-URL expiry and the file permissions. Neither is the
cause. Set `TRUSTED_PROXIES=*` and they appear.

**Do not try to fix this with `URL::forceScheme('https')`** — it makes it worse: the URL is
generated as HTTPS while the request still reports HTTP, so validation fails immediately.

### ⚠️ If config is cached, `.env` is not read

`attendance:deploy` deliberately does **not** run `config:cache`. On this host the setting
that matters (`TRUSTED_PROXIES`) is read before the config repository is even bound, and a
stale config cache is the most common cause of "I changed the setting and nothing
happened". The performance gain is not worth that trap at this scale.

---

## 4. First deploy

GitHub → **Settings → Secrets and variables → Actions** → add three repository secrets:

| Name | Value |
|---|---|
| `FTP_SERVER` | `ftp.mwstay.com` |
| `FTP_USERNAME` | `darscoffeeeftipi@darscoffee.com` |
| `FTP_PASSWORD` | *(your FTP password)* |

Then trigger the workflow: **Actions → Deploy to cPanel → Run workflow**. Or push any
commit to `main`.

It will:

1. **Wait for the CI tests to pass on that commit** — a broken commit must not reach staff,
   and this application's failures are silent ones.
2. Install dependencies and build the frontend on Linux.
3. Upload over **plain FTP**, with `.env` and `storage/` excluded.

### ⚠️ The first run is a rehearsal, on purpose

**Nothing is uploaded until you arm it.** The transfer action *reconciles* rather than adds:
it **deletes** remote files that are not in the local tree. On the first run that is
dangerous, because it does not yet know which files are the application's.

So until you set the repository variable, the workflow connects, compares, and **reports**
what it would do without changing anything.

1. Run the workflow and open its log.
2. **Read the list of files it says it would delete.** Confirm they are only files this
   application owns — nothing of yours that happened to be in that folder.
3. If the list looks right, arm it:

   **Settings → Secrets and variables → Actions → Variables → New variable**

   Name `FTP_DEPLOY_ENABLED`, value `true`.

4. Run the workflow again. That run transfers for real.

### Why `vendor/` arrives as a zip

`vendor/` is almost the entire deploy: **9,919 of the ~10,000 files**. It is a **build
artifact** — Composer generates it from `composer.lock` — so the workflow packages it into a
single `vendor.zip` and you unpack it on the server. That turns ~10,000 transfers into about
250.

> **Correction, because the first explanation here was wrong.** The original upload moved
> 8,599 individual files over **62 minutes** and failed with a TLS error, and this document
> blamed **duration** — 2.3 files/second against a server that drops idle connections. The
> next run disproved it: with the zip in place, ~250 files transferred and it **failed
> anyway, in 4 minutes**, with the identical error. Duration was never the cause.
>
> The zip is still worth keeping — it is far faster and far less likely to be interrupted —
> but it is a speed improvement, **not** what fixed, or failed to fix, the deploy.

### Why the upload is plain FTP and not FTPS

Every TLS **data** connection fails:

```
Error: ...:tlsv1 alert decode error ... SSL alert number 50 (data socket)
```

Recorded so nobody has to rediscover it:

- The **control** connection over TLS is fine — it authenticates and lists the tree. Only
  the **data** channel fails.
- The **rehearsal (dry-run) succeeds**. A dry run compares file listings and never transfers
  contents, which is exactly why it passes while the real upload dies.
- It is **not size, speed, or timeouts.** 8,599 files failed in 62 minutes; 250 files failed
  in 4 minutes. Same error.
- Every run logs `Node.js 20 is deprecated ... SamKirkland/FTP-Deploy-Action@v4.3.5 is being
  forced to run on Node.js 24`. The action bundles `basic-ftp` 5.x, and Node 24.17 binds a
  resumed TLS session to its original host (the fix for CVE-2026-48934), which broke FTPS
  data connections. Upgrading to the newest release (v4.4.0) does **not** help — it still
  depends on `basic-ftp ^5.0.5`. There is no action input that changes this.

So FTPS from GitHub's runners to this host cannot be made to work by configuration. Plain
FTP was **verified** to be permitted before switching: sending `USER` before `AUTH TLS` is
answered `331 ... Password required`, meaning TLS is optional on this host rather than
forced. If the host were ever reconfigured to require TLS, the deploy would fail immediately
on authentication instead — an auth error in the log means stop and upload by hand with
cPanel's File Manager.
connection.

Later deploys send only what changed, so they are fast.

### ⚠️ After a deploy: unpack `vendor.zip`

**Do this first — nothing runs without it, including the installer.**

cPanel → **File Manager** → your app folder → right-click `vendor.zip` → **Extract** → into the
**current directory** (the app folder, not a subfolder).

The archive contains the `vendor` folder itself, so extracting it at the app root puts the files
exactly where they belong. Leave `vendor.zip` in place afterwards — deleting it makes the next
deploy slower, and it is excluded from the reconciliation so it does no harm.

If you forget this step, every page returns a 500 and `storage/logs/laravel.log` says
`Failed opening required '/home/.../vendor/autoload.php'`.

### ⚠️ Point the workflow at your directory

`server-dir` is `./`, meaning wherever the FTP account lands. If that is not the application
directory, the dry-run log will make it obvious — it would list deletions of files that are
not yours. In that case set `server-dir` to the correct path before arming.

This is the single most important thing to check before the first real transfer, and the
reason the rehearsal exists.

---

## 5. Install — the one manual step
**Before this: confirm `vendor.zip` is unpacked.** Step 4 explains it. Nothing runs without it.

If a previous upload died partway, `vendor/` on the server may be incomplete. **Delete that
`vendor/` folder in File Manager and extract `vendor.zip` again** — a half-populated vendor
tree produces confusing "class not found" errors rather than an obvious missing-dependency
message, and it is worth thirty seconds to rule out.

The database has no tables yet. cPanel → **Cron Jobs** → add a job, running **once**
(`* * * * *` is fine for a single manual run):

```
/usr/local/bin/php /home/mwstayco/attendance.darscoffee.com/attendance/artisan attendance:install --seed
```

Use the real PHP path for your host — check cPanel's **Select PHP Version** page, or ask
support. A wrong path makes cron fail silently; nothing appears in the app's logs.

Wait one minute, then:

1. **Delete that cron job.** Leaving it would re-run the installer.
2. Check `storage/logs/laravel.log` — it should show the install output.

`--seed` creates the 3 outlets (`SG-RAMAL`, `SEDAP-SANTAI`, `DARS-COFFEE`), the owner
account, and Siti Fatimah as a manager mapped to all three outlets.

> ⚠️ **Change both passwords immediately.** They are `owner@darscoffee.com` and
> `fatimahbokhare@gmail.com`, both with the password `password` — published in this
> repository. Anyone who has seen it can sign in and read every staff member's hours.
> The deploy check fails until this is done.

If the install logs an error about the database, re-read step 2 — the most common cause is
a database name or user that was not prefixed with the cPanel account name.

---

## 6. Cron — required, permanent

Two jobs run on a schedule, and both fail silently without cron. cPanel → **Cron Jobs**,
every minute:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

| Job | Frequency | What breaks without it |
|---|---|---|
| `attendance:flag-open-segments` | hourly | A forgotten clock-out is never flagged — **and that employee cannot clock in again at all** |
| `attendance:purge-photos` | daily 03:00 | Photographs are kept past the retention window — a PDPA compliance gap nobody notices |

**The forgotten clock-out job matters more than it sounds.** The database allows only one
open segment per employee, so someone who forgets to clock out cannot clock in the next
morning: they scan, enter their PIN, press the button, and **nothing happens**, with no
error shown. Nobody at the counter can see why.

The retention purge runs at 03:00 in the **business** timezone. That is not decoration: the
application runs in UTC, so a naive 03:00 would fire at 11am in Kuala Lumpur, deleting
photographs while the console is in use.

---

## 7. Applying future changes

Once installed, a release is: **push to `main`**, wait for the deploy, then — **only if the
release included a migration** — apply it.

### Applying migrations with no shell

**Option A — one-off cron job.** The simplest, and fine if releases are occasional:

```
/usr/local/bin/php /home/mwstayco/attendance.darscoffee.com/attendance/artisan attendance:deploy
```

Wait a minute, then delete the job.

**Option B — permanent flag-watching cron job.** For regular releases, add one more
permanent entry that does nothing until a flag appears:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan attendance:deploy --if-flagged >> /dev/null 2>&1
```

Then a release is:

1. Push to `main` and let the deploy finish.
2. Create an empty file at `storage/app/private/deploy.flag` (File Manager → New File).
3. Within a few minutes the cron entry applies it and **removes the flag**, which is how
   you know it ran.

The flag is deliberately a file rather than a web endpoint: it exists outside the web root,
needs no credentials, and adds nothing reachable from the internet. A deploy endpoint is the
usual approach on hosts like this, but it would be a permanently reachable URL whose worst
case is arbitrary database work — on a host where the punch endpoint is already public.

### What `attendance:deploy` refuses to do, and why

| Refused | Because |
|---|---|
| `migrate:fresh` | Drops every table and takes the attendance history with it. Not offered as an option even — the one time somebody reaches for it "to fix things" is the time it matters. |
| `key:generate` | **`APP_KEY` decrypts the employee IC numbers.** Regenerating it makes every stored IC permanently unreadable, and the damage is silent: the console shows a number that can never be revealed. |
| `db:seed` | It is idempotent, which is exactly the problem — it would RECREATE the published default accounts after you had deleted them. Re-running it on each deploy would quietly re-open a hole you closed. |

---

## 8. If you cannot move the document root

Some shared hosts only serve from `public_html`. Put the app outside the web root and
forward only its `public` folder:

```
/home/mwstayco/attendance.darscoffee.com/attendance/          ← the app, NOT web-accessible
/home/mwstayco/public_html/                                  ← document root
```

Copy `attendance/public/*` into `public_html/`, then edit `public_html/index.php`:

```php
require __DIR__.'/../attendance.darscoffee.com/attendance/vendor/autoload.php';
$app = require_once __DIR__.'/../attendance.darscoffee.com/attendance/bootstrap/app.php';
```

Adjust the paths to your layout. **Then re-run the step 1 test** — browse to `/.env` and
confirm it is not readable.

---

## 9. Go-live checks

Run these on the live site before staff use it.

| # | Check | Expected |
|---|---|---|
| 1 | Browse to `/.env` | **403 or 404** — never the file contents |
| 2 | Browse to `/storage/logs/laravel.log` | **403 or 404** |
| 3 | `https://attendance.darscoffee.com/console` | Console loads; sign in as the owner |
| 4 | Change both seeded passwords, then delete the default accounts | See step 5 |
| 5 | **Employees** → open one with a photo | **The photograph renders.** This is the `TRUSTED_PROXIES` check |
| 6 | **Outlets & codes** → print a code | QR renders and prints |
| 7 | Scan that printed code with a real phone | Punch screen opens; PIN accepted; photo taken |
| 8 | Clock in, take a break, clock out | Timesheet shows correct hours |
| 9 | Set the phone to aeroplane mode and clock out | "Saved on this phone" appears, not "not recorded" |
| 10 | Turn the connection back on | The queued punch syncs, and the timesheet shows the time you actually punched |
| 11 | **Anomalies** | The offline punch is listed as `Synced from offline` |
| 12 | Leave for an hour, then check `storage/logs` | No repeating errors |

> **Check 5 is the one that catches the proxy problem**, and it is worth doing twice — once on
> wifi and once on mobile data. A CDN or proxy can treat the two differently.

---

## 10. Backups

**A database dump is only half the backup.** The photographs are FILES, not rows: a
database-only restore gives you a system that references hundreds of staff photographs and
can serve none of them — every timesheet showing a broken image, and no evidence to settle
a dispute, which is the only reason they are collected.

In cPanel:

- **Backup Wizard** → download a *Home Directory* backup (includes `storage/`) **and** a
  *MySQL Databases* backup.
- Or, if your host provides it, set up scheduled backups.

> **A backup that has never been restored is not a backup; it is a file.** Restore one into a
> scratch database before you need it in anger.

The deploy never deletes photographs — `storage/app/private/**` is in the workflow's
`exclude` list precisely so the FTP action's reconciliation cannot remove them.

---

## Rollback

Push a revert to `main`:

```
git revert <bad-commit>
git push origin main
```

The workflow redeploys. If the bad release included a migration, restore the database from a
backup instead — **do not** try to roll migrations back on a live site once punches exist,
because the down-migrations drop tables.

`APP_KEY` is not in git and is never touched by a deploy, so it survives a rollback.

---

## The development scripts are guarded

`scripts/` contains helpers that **delete data**. They are committed, so they exist on the
server after a deploy — but they refuse to run unless `APP_ENV` is `local` or `testing`.

`dev-test-mysql.php` is the most dangerous of them and the reason the guard exists: it
**rewrites `.env`** to point at a scratch database. Interrupted halfway, it would leave the
live application pointed at an empty database — staff would scan the code the next morning
and be told their PIN does not match, with nothing in the logs to explain it.

The deploy workflow also excludes `scripts/` entirely, so they are not on the server at all.
The guard is the second line of defence.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Blank page | `public/build` missing — the frontend did not build. Check the workflow log. |
| "View path not found" | `storage/framework/views` missing. Run `attendance:deploy`; it recreates the tree. |
| Photographs 403, everything else works | `TRUSTED_PROXIES` not set. See step 3. |
| "No application encryption key" | `APP_KEY` empty and `attendance:install` has not run. |
| Database connection refused | Wrong name, user, or password in `.env`; or the user was not added to the database. |
| Cron does nothing | Wrong PHP path. Check cPanel → Select PHP Version, or ask support. |
| 500 on every page | Read `storage/logs/laravel.log` — the real error is there, not on screen (debug is off, correctly). |
