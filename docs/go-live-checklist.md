# Morning checklist — Dars Attendance go-live

**State as of 3:45am, 22 Sept:** the deployment **works**. See "What changed" at the bottom
if you want the why. You still have a short list of cPanel steps — nothing is broken, the
site is just not pointed at the upload yet.

The whole list below is about 30 minutes, and steps 1–3 are the ones that matter.

---

## 1. Confirm the upload landed (1 min)

GitHub → **Actions** → **Deploy to cPanel** → the newest run.

It should be **green**, and `Deploy over FTP` should say it ran ~5 minutes.

> **A green run does NOT mean the files are visible yet.** The workflow only *stores* files;
> cPanel decides which folder the domain serves. Step 3 is what makes them visible.

## 2. Unpack `vendor.zip` (2 min)

`vendor/` ships as one archive because uploading ~10,000 files individually took over an
hour and failed. **Nothing runs until you extract it.**

cPanel → **File Manager** → open the folder you uploaded to (see step 3) →

1. If a **`vendor/`** folder already exists there, **delete it first.** A half-populated
   vendor tree produces confusing "class not found" errors instead of an obvious
   "missing dependency" one.
2. Right-click **`vendor.zip`** → **Extract** → into the **current directory**.
   The archive contains `vendor/` itself, so extracting in place puts the files exactly
   where they belong. Do **not** create a subfolder.
3. Leave `vendor.zip` in place. Deleting it is fine, but leaving it avoids nothing.

## 3. ⚠️ Point the domain at the right folder (3 min) — the main remaining step

Right now `https://attendance.darscoffee.com/` shows **"Index of /"** with an empty list.
That is not an error — it is an **empty directory**. The domain is serving a folder that the
deploy does not write to.

cPanel → **Domains** → find `attendance.darscoffee.com` → **Document Root** → set it to:

```
/home/mwstayco/darscoffee.com/attendance/public
```

Two things to get right:

- It must end in **`/public`**, not the app folder. If the app folder itself is the web root,
  `/.env` — which holds your database password and `APP_KEY` — is downloadable by anyone.
  `APP_KEY` decrypts the employee IC numbers.
- **Confirm the first part of that path is where the upload actually went.** Open File Manager
  and check that you can see `artisan`, `app/`, `vendor/` and `vendor.zip` in
  `/home/mwstayco/darscoffee.com/attendance`. If your FTP account lands somewhere else, use
  that path followed by `/public`. **This is the one thing I could not verify from here** —
  I cannot see the server's filesystem.

Save, wait a minute, then the site should stop showing "Index of /".

## 4. Prove `.env` is not exposed (1 min)

Open: `https://attendance.darscoffee.com/.env`

- **403 or 404** → correct. Carry on.
- **You can read it** → **stop and fix the document root first.** Then change the database
  password **and** generate a new `APP_KEY`, because both were exposed.

Also check `https://attendance.darscoffee.com/storage/logs/laravel.log` — 403 or 404.

## 5. Install (5 min, one-off)

The database has no tables yet. cPanel → **Cron Jobs** → **Add New Cron Job**, run it **once**
(minute `*` is fine for a single manual run):

```
/usr/local/bin/php /home/mwstayco/darscoffee.com/attendance/artisan attendance:install --seed
```

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
* * * * * cd /home/mwstayco/darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
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
