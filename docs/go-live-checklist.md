# Go-live checklist — Dars Attendance

**Where things stand (24 Sept):**

| | Status |
|---|---|
| App files on the server | ✅ Correct |
| Document root | ✅ Correct |
| **Application running** | ✅ **`/punch` and `/console` both load** |
| `/.env` protected | ✅ 403 |
| Install (`APP_KEY`, schema, seed) | ✅ Done |
| **Seeded passwords** | ⚠️ **Still `password` — change today** |
| Permanent cron | ❌ Not added |
| FTP deploy | ❌ Broken (`530`) — blocks future releases |

**The site works.** What remains is safety and maintainability, not getting it up: two
passwords that are published in a public repository, and the scheduled jobs that stop a
forgotten clock-out from locking an employee out.

---

## 1. ⚠️ Change the two published passwords — do this now (5 min)

Sign in at `https://attendance.darscoffee.com/console` and change **both**:

- `owner@darscoffee.com`
- `fatimahbokhare@gmail.com`

Both are currently `password`, and that is **published in the public repository**. Anyone who
has seen it can sign in and read every staff member's hours and photographs. This is the only
item here that is a live security exposure.

## 2. Add the permanent cron (2 min)

The application is up, but its scheduled jobs are not running. cPanel → **Cron Jobs**, every
minute:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Do not skip this.** Without it:

- A forgotten clock-out is never flagged — and **that employee then cannot clock in at all**.
  They scan, enter their PIN, press the button, and **nothing happens**, with no error on
  screen. Nobody at the counter can work out why.
- Photographs are kept past the retention window, which is a PDPA gap nobody notices.

Check the PHP path is right (cPanel → **Select PHP Version**). A wrong path fails silently.

## 3. Fix the FTP deploy (5 min)

No future release can ship until this is done. Every deploy since the FTP account's Directory
was changed fails with:

```
FTPError: 530 Login authentication failed
```

The password worked before that edit — **editing an FTP account in cPanel can invalidate its
stored password.** Run:

```
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\ftp-login-check.ps1
```

It asks for the password at runtime (hidden — never printed, never saved) and lists the
directory FTP lands in.

| Result | Meaning | Fix |
|---|---|---|
| **LOGIN FAILED** | the cPanel account/password is wrong | cPanel → FTP Accounts → change the password, then update the GitHub secret |
| **LOGIN OK** | the server is fine; the secret is stale | delete the `FTP_PASSWORD` secret and re-add it by **pasting** |

A trailing newline pasted into a secret produces exactly this 530.

## 4. Test a staff photograph (2 min)

Open **Employees** → an employee with a photograph. **It must render.**

If it 403s while the rest of the console works, add `TRUSTED_PROXIES=*` to `.env` (see
`docs/deployment.md` step 3). This is the one setting that fails *only* for photographs, so it
is easy to miss — test once on wifi and once on mobile data.

## 5. Then the full list

`docs/deployment.md` → **Step 9, Go-live checks** — 12 checks ending with a real phone scanning
a printed QR, a clock in/out, and an offline punch that syncs.

---

## Completed: how the deployment was unblocked

Kept brief; the detail is in `docs/deployment.md`.

1. **The upload failed for weeks on FTPS.** Every TLS *data* connection failed with
   `SSL alert number 50`. Two earlier explanations were wrong — first "too slow" (the transfer
   was cut from 8,599 files to ~250 and it still failed, in 4 minutes), then "the payload". The
   real cause was the transport: the action bundles a `basic-ftp` version that Node 24 broke,
   and no setting or upgrade fixes it. Now **plain FTP**, verified permitted beforehand.
2. **The document root pointed at an empty folder**, and cPanel would not allow a root outside
   `/home/mwstayco/attendance.darscoffee.com/`, so the app was **moved** into that tree.
3. **`public/index.php` was missing** — the FTP tool's sync-state file had travelled with the
   moved folder and still claimed it was deployed. Fixed by renaming `state-name`.
4. **The final 500 was a missing `storage/` tree.** The deploy excludes `storage/**` on purpose
   (it protects the photographs), so a fresh server has no `storage/framework/{views,cache}`
   and no `storage/logs`. The give-away was a 500 **with an empty log** — the error had nowhere
   to be written. `attendance:install` creates the tree as its first action, then generates
   `APP_KEY`, migrates and seeds.

> **Same pattern for any fresh Laravel deploy over FTP:** a 500 with an empty log almost always
> means the `storage/` tree is missing, not that the code is broken.

### The layout, for reference

```
/home/mwstayco/attendance.darscoffee.com/
  public/            <- leftover empty folder. NOT the docroot.
  attendance/        <- the Laravel application root
    artisan   app/   bootstrap/   config/   storage/
    .env             <- lives here (one only)
    vendor.zip       <- must be re-extracted after any dependency change
    vendor/          <- extracted from vendor.zip
    public/          <- DOCROOT: index.php + build/
```

App root: `/home/mwstayco/attendance.darscoffee.com/attendance`

**One manual step that is easy to forget:** after any release that changes dependencies,
re-extract `vendor.zip` in the app root. Each deploy replaces the zip, but the app keeps using
the `vendor/` extracted from the old one — so a new package can appear installed when it is
not. That failure looks like a code bug.

> **Every push to `main` deploys automatically** once the repository variable
> `FTP_DEPLOY_ENABLED` is `true` (Settings → Secrets and variables → Actions → Variables).
> Until it is `true`, runs are rehearsals that report without transferring.
>
> A deploy does **not** apply migrations — see `docs/deployment.md` step 7 for the cron method
> that does.
