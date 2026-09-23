# Go-live checklist — Dars Attendance

**Where things stand (24 Sept):**

| | Status |
|---|---|
| App files on the server | ✅ In the right folder |
| **Document root** | ✅ **Correct** — Laravel's own files are served |
| Front controller (`public/index.php`) | ✅ Present, and **PHP executes** |
| `vendor/` | ❌ **Missing — this is the current 500** |
| Deploy pipeline | ❌ Broken: `FTPError: 530 Login authentication failed` |
| Install / cron / passwords | ❌ Not yet done |

**The current symptom is a 500 with an empty body on every route.**

That is not a general "the app is broken" — it is precisely what you get when the document
root is correct, PHP runs, and `vendor/autoload.php` is absent. The static files prove the
diagnosis: `/robots.txt` and `/build/manifest.json` both return **200**.

**Start at step 1** — it unblocks the site without needing FTP at all.

### The layout that matters

```
/home/mwstayco/attendance.darscoffee.com/
  public/            <- leftover empty folder. NOT the docroot.
  attendance/        <- the Laravel application root
    artisan   app/   bootstrap/   config/   storage/
    .env             <- MUST live here (one only)
    vendor.zip       <- extract INTO this folder
    vendor/          <- must exist, containing autoload.php
    public/          <- DOCROOT: index.php + build/
```

App root: `/home/mwstayco/attendance.darscoffee.com/attendance`

---

## 1. ⚠️ Extract `vendor.zip` (2 min) — start here

The deploy uploads `vendor.zip` but **never extracts it** — there is no shell on this host to
run `unzip`, so this is a permanent manual step. Until it is done, the site cannot run.

File Manager → open **`.../attendance.darscoffee.com/attendance`** →

1. If a **`vendor/`** folder already exists, **delete it first.** A half-populated vendor tree
   produces confusing "class not found" errors instead of an obvious missing-dependency one.
2. Right-click **`vendor.zip`** → **Extract** → into the **current directory**. The archive
   contains `vendor/` itself, so extracting in place lands the files correctly. Do **not**
   create a subfolder.
3. **Confirm `vendor/autoload.php` now exists.** That one file is what `public/index.php`
   requires; without it nothing else matters.
4. Delete **`index.html`** from the app's `public/` folder if present — a placeholder beats
   `index.php` in the directory-index order and makes the site look empty or broken.

Reload the site. **The 500 should turn into something else** — a Laravel error page, a
redirect to `/console`, or a login screen. Any of those means the app is finally running.

> **Repeat this after any release that changes dependencies.** Each deploy replaces
> `vendor.zip`, but the app keeps using the `vendor/` extracted from the old one — so a new
> package appears installed when it is not. That failure looks like a code bug.

## 2. ⚠️ Fix the FTP deploy (5 min)

Every deploy since the FTP account's Directory was changed fails with:

```
FTPError: 530 Login authentication failed
```

The password worked before that edit — **editing an FTP account in cPanel can invalidate its
stored password.** Run this to find out which half is broken:

```
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\ftp-login-check.ps1
```

It asks for the password at runtime (hidden — never printed, never written to disk) and lists
the directory FTP lands in.

| Result | Meaning | Fix |
|---|---|---|
| **LOGIN FAILED** | the cPanel account/password is wrong | cPanel → FTP Accounts → change the password, then update the GitHub secret |
| **LOGIN OK** | the server is fine; the secret is stale | delete the `FTP_PASSWORD` secret and re-add it by **pasting** |

A trailing newline pasted into a secret produces exactly this 530. That is why the check
exists rather than another guess.

While there, confirm the account's **Directory** is
`/home/mwstayco/attendance.darscoffee.com/attendance`. Then say so and I will re-run the
deploy, so anything else missing uploads automatically.

> Until this is fixed, **releases cannot be delivered** — but steps 1 and 3 onward do not need
> it.

## 3. Confirm `.env` is in the app root and correct (2 min)

`.env` must be at `/home/mwstayco/attendance.darscoffee.com/attendance/.env` — the folder
containing `artisan`. **Laravel reads it from there and nowhere else.** A copy one level up is
never read, and the symptom is a **500** saying *"No application encryption key has been
specified"* with the database credentials just as silently missing.

If it is missing or in the wrong place, move it (or recreate it from the template in
`docs/deployment.md` step 3). Required values:

```ini
APP_NAME="Dars Attendance"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # attendance:install fills this in
APP_URL=https://attendance.darscoffee.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mwstayco_attendance
DB_USERNAME=mwstayco_attadm   # attadm, NOT attendance
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

ATTENDANCE_TIMEZONE=Asia/Kuala_Lumpur

# Add ONLY if a proxy/CDN fronts this domain — see step 8.
# TRUSTED_PROXIES=*
```

> Do **not** invent an `APP_KEY`. Generating a new one over a database that already holds data
> makes every stored IC number permanently unreadable, silently. If the database really is
> empty, `attendance:install` generates one for you.

## 4. Prove `.env` is not exposed (1 min)

Open: `https://attendance.darscoffee.com/.env`

- **403 or 404** → correct. Carry on.
- **You can read it** → **stop.** Fix the document root first, then change the database
  password **and** generate a new `APP_KEY`, because both were exposed.

Also check `https://attendance.darscoffee.com/storage/logs/laravel.log` — 403 or 404.

## 5. Install (5 min, one-off)

The database has no tables yet. cPanel → **Cron Jobs** → **Add New Cron Job**, run it **once**:

```
/usr/local/bin/php /home/mwstayco/attendance.darscoffee.com/attendance/artisan attendance:install --seed
```

Use the real PHP path for your host — check cPanel's **Select PHP Version** page, or ask
support. A wrong path makes cron fail silently; nothing appears in the app's logs.

Wait a minute, then **delete that cron job** — leaving it would re-run the installer.

Check the result: `https://attendance.darscoffee.com/storage/logs/laravel.log`

`--seed` creates the 3 outlets (SG-RAMAL, SEDAP-SANTAI, DARS-COFFEE), the owner account, and
Siti Fatimah as manager for all three outlets.

> If the log shows a database error, the cause is almost always a **database name or user that
> is missing its cPanel account prefix** — `mwstayco_` — in `.env`.
>
> ⚠️ **The username is not the database name.** cPanel generates them separately:
> ```ini
> DB_DATABASE=mwstayco_attendance
> DB_USERNAME=mwstayco_attadm      <- attadm, NOT attendance
> ```
> Using the database name as the username gives `Access denied for user`, which reads like a
> wrong password rather than a wrong name.

## 6. ⚠️ Change the two published passwords (2 min)

Sign in at `https://attendance.darscoffee.com/console` and change **both**:

- `owner@darscoffee.com`
- `fatimahbokhare@gmail.com`

Both are currently `password`, and that is published in the repository. Anyone who has seen
it can sign in and read every staff member's hours and photographs.

## 7. Add the permanent cron (2 min)

cPanel → **Cron Jobs**, every minute:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Do not skip this.** Without it:

- a forgotten clock-out is never flagged — and **that employee then cannot clock in at all**.
  They scan, enter their PIN, press the button, and **nothing happens**, with no error on
  screen. Nobody at the counter can work out why.
- photographs are kept past the retention window, which is a PDPA gap nobody notices.

## 8. The one test that catches the proxy problem (2 min)

Open **Employees** → any employee with a photograph. **The photograph must render.**

If it does not, but the console otherwise looks fine, set this in `.env` and re-run
`config:cache` (details in `docs/deployment.md`):

```
TRUSTED_PROXIES=*
```

**I still do not know whether a proxy or CDN sits in front of this domain** — if one does,
this is mandatory, or every staff photograph 403s while everything else looks healthy. Test
it once on wifi and once on mobile data; a CDN can treat the two differently.

## 9. Then the full list

`docs/deployment.md` → **Step 9, Go-live checks** — 12 checks ending with a real phone
scanning a printed QR, a clock in/out, and an offline punch that syncs.

---

## Background: how we got here, and the two wrong turns

Worth reading once, so the same ground is not re-covered.

**Wrong turn 1 — "the upload is too slow."** The first real upload moved 8,599 files over 62
minutes and failed with a TLS error. That was read as a duration problem, and the fix was to
ship `vendor/` as a single `vendor.zip`. The next run disproved it: ~250 files transferred and
it **still failed, in 4 minutes**, with the same error. Duration was never the cause.

**Wrong turn 2 — "the payload is the problem."** The real fault was **FTPS itself**: every TLS
*data* connection failed with `SSL alert number 50 (data socket)`, because the action bundles
a `basic-ftp` version that Node 24 broke, and no setting or upgrade fixes it. The transport is
now **plain FTP**, which was verified to be permitted before switching. The trade is real: the
FTP password and the transferred bytes are unencrypted. It is accepted because `.env`, the app
key and the staff photographs are **excluded** from the upload — only source code and build
artifacts travel.

The zip stayed. It is much faster, it just was not the fix it was described as. That
correction is written into the workflow and this document in place of the original claim,
because the wrong explanation is the one that would have led the next person to keep tuning
timeouts.

**And the deployment is not finished until `vendor.zip` is extracted by hand.** There is no
shell on this host, so nothing can unzip it automatically. That step is manual forever, and it
is the single most likely reason a working deploy produces a 500.

> **Every push to `main` deploys automatically** once the repository variable
> `FTP_DEPLOY_ENABLED` is `true` (Settings → Secrets and variables → Actions → Variables).
> Until it is `true`, runs are rehearsals that report without transferring.
>
> A deploy does **not** apply migrations — see `docs/deployment.md` step 7 for the cron method
> that does.
