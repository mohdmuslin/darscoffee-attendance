# Morning checklist — Dars Attendance go-live

**Where things stand (22 Sept, morning):**

| | Status |
|---|---|
| The deploy pipeline | ✅ **Working** — three consecutive successful uploads |
| The app files on the server | ✅ Moved to the subdomain folder |
| Document root | ❌ Still serving an empty folder → "Index of /" |
| `vendor.zip` | ❌ Not yet extracted |
| Install / cron / passwords | ❌ Not yet done |

The list below is about 30 minutes. **Steps 1–3 are the ones that matter** — until they are
done, nothing else can work.

### The layout that matters

```
/home/mwstayco/attendance.darscoffee.com/
  public/            <- leftover empty folder. NOT the docroot.
  attendance/        <- the Laravel application root
    artisan   app/   bootstrap/   config/   storage/
    .env             <- MUST live here (one only)
    vendor.zip       <- extract into this folder
    public/          <- DOCROOT: index.php + build/
```

App root: `/home/mwstayco/attendance.darscoffee.com/attendance`

---

## 1. ⚠️ Set the document root (3 min) — the main remaining step

`https://attendance.darscoffee.com/` shows **"Index of /"** with an empty list. That is not an
error: the domain is serving an **empty folder** (`.../attendance.darscoffee.com/public`), which
is not where the app is.

cPanel → **Domains** → `attendance.darscoffee.com` → **Document Root** → set it to:

```
/home/mwstayco/attendance.darscoffee.com/attendance/public
```

It must end in **`/public`**, never the app folder. If the app folder itself is the web root,
`/.env` — the database password and `APP_KEY` — is downloadable by anyone. `APP_KEY` decrypts
the employee IC numbers.

Save, wait a minute, then reload the site.

## 2. ⚠️ Move `.env` into the app root (1 min)

`.env` is currently at `.../attendance.darscoffee.com/.env` — **one level above** where Laravel
looks. **Laravel reads `.env` from the app root**, the folder containing `artisan`.

cPanel → **File Manager** → **Move** that `.env` to:

```
/home/mwstayco/attendance.darscoffee.com/attendance/.env
```

If this is missed, the symptom is a **500 error** saying *"No application encryption key has
been specified"*, and the database credentials are silently absent too. It looks like a
broken install rather than a misplaced file.

Keep exactly one `.env`. A stray copy outside the app root is also a secrets file sitting in a
folder that could later become a document root.

> If `.env` did not survive the move at all, recreate it from the template in
> `docs/deployment.md` step 3, and make sure `APP_KEY` is present — generating a **new** one on
a database that already holds data makes stored IC numbers unreadable.

## 3. Unpack `vendor.zip` (2 min)

`vendor/` ships as one archive because uploading ~10,000 files individually took over an hour
and failed. **Nothing runs until you extract it.**

File Manager → open **`.../attendance.darscoffee.com/attendance`** →

1. If a **`vendor/`** folder already exists there, **delete it first.** A half-populated vendor
   tree gives confusing "class not found" errors instead of an obvious missing-dependency one.
2. Right-click **`vendor.zip`** → **Extract** → into the **current directory**. The archive
   contains `vendor/` itself, so extracting in place lands the files exactly where they belong.
   Do **not** create a subfolder.
3. Delete `index.html` from the app's **`public/`** folder if one is there — a placeholder can
   take precedence over Laravel's `index.php` and make the app look broken.

## 4. Prove `.env` is not exposed (1 min)

Open: `https://attendance.darscoffee.com/.env`

- **403 or 404** → correct. Carry on.
- **You can read it** → **stop and fix the document root first.** Then change the database
  password **and** generate a new `APP_KEY`, because both were exposed.

Also check `https://attendance.darscoffee.com/storage/logs/laravel.log` — 403 or 404.

At this point the site should show a Laravel error (a 500 about the database), **not** "Index
of /". That is progress: it means the app is finally being served.

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
> is missing its cPanel account prefix** — `mwstayco_` — in `.env`. `.env` needs the full
> name, not what you typed into the box.

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

## What changed overnight, in one paragraph

Every deploy had been failing, and my first explanation was wrong. I said the upload was too
**slow** — 8,599 files in 62 minutes against a host that drops idle connections — and fixed it
by shipping `vendor/` as a single zip. The next run proved that wrong: with the zip in place,
~250 files transferred and it **still failed, in 4 minutes**, with the same error. The real
fault was **FTPS itself**: every TLS *data* connection failed
(`SSL alert number 50 (data socket)`) because the action bundles a `basic-ftp` version that
Node 24 broke, and no setting or upgrade fixes it. So the transport is now **plain FTP**,
which I verified the host permits before switching. The trade: the FTP password and the
transferred bytes are unencrypted. That is a real loss, accepted because `.env`, the app key
and the staff photographs are **excluded** from the upload — only source code and build
artifacts travel. The zip stayed, because it is much faster, it just was not the fix.

> **Every future push to `main` deploys automatically** once the repository variable
> `FTP_DEPLOY_ENABLED` is `true` — check under **Settings → Secrets and variables → Actions →
> Variables** if you are unsure whether it is still set. Until it is `true`, runs are
> rehearsals that report without transferring.
>
> If a release includes a migration, the deploy has **not** applied it — see
> `docs/deployment.md` step 7 for the cron method that does.
