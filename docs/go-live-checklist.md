# Go-live checklist — Dars Attendance

**Where things stand (24 Sept):**

| | Status |
|---|---|
| App files on the server | ✅ Correct |
| Document root | ✅ Correct |
| **Application running** | ✅ **`/punch` and `/console` both load** |
| `/.env` protected | ✅ 403 |
| Install (`APP_KEY`, schema, seed) | ✅ Done |
| **Permanent cron** | ✅ Added and **verified firing** |
| **Seeded passwords** | ⚠️ **Still `password` — change today** |
| FTP deploy | ❌ Broken (`530`) — blocks future releases |

**The site works and the scheduled jobs run.** What remains is safety and maintainability: two
passwords that are published in a public repository, and an FTP deploy that cannot ship a
future release until it is fixed.

---

## 1. ⚠️ Change the two published passwords — do this now (5 min)

**There is no change-password screen in the console.** `AccountsView.vue` can only *set* a
password when creating an account, and the API's owner-only `PATCH /admin/users/{id}` supports
it but has no UI in front of it. So the two seeded accounts keep the password `password`, which
is **published in the public repository** — anyone who has seen it can sign in as owner and read
every staff member's hours and photographs.

Adding the missing screen is the real fix, but **the FTP deploy is broken (`530`)**, so no new
code can reach the server yet. Use the script instead — it needs only File Manager and the cron
job you already have working.

**1. Upload** `scripts/set-console-password.php` to the **application root** (the folder
containing `artisan`) — via File Manager → Upload.
It must go in the app root, not `public/`, because its paths are relative to its own location.

**2. Add a one-off cron job** — cPanel → Cron Jobs (`*` in the minute field is fine):

```
/usr/local/bin/php /home/mwstayco/attendance.darscoffee.com/attendance/set-console-password.php >> /home/mwstayco/password-change.log 2>&1
```

**3. Wait a minute**, then open `/home/mwstayco/password-change.log` in File Manager and
**copy the two generated passwords.** They are printed once and cannot be recovered.

**4. Delete** the cron job, the log file, and the script.

> The script **deletes itself**, so a forgotten copy cannot be re-run later to reset the
> passwords again. It prints whether that worked — if it says it could not, delete the file by
> hand. Deleting a file in the app root is not possible over HTTP, so it is not web-reachable,
> but remove it anyway.

Then sign in at `https://attendance.darscoffee.com/console` to confirm the new password works.

> **Worth fixing properly:** once the FTP deploy is repaired, add a change-password screen.
> Until then, changing a password means repeating this. Note that both accounts are now random
> 24-character strings, so they need to be stored somewhere a manager can reach — hand them over
> in person or by whatever channel you use for credentials, not in a shared document.

## 2. ✅ Permanent cron — done and verified

cPanel → **Cron Jobs**, every minute:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**This was verified, not assumed.** That matters here because cron redirects its output to
`/dev/null`, so a wrong PHP path or a wrong directory fails **completely silently** — no error,
no log, and the only symptom appears days later as "the app stopped working".

To re-verify at any time, temporarily log instead of discarding:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /home/mwstayco/schedule.log 2>&1
```

Any readable output — even just `No scheduled commands are ready to run.` — proves cron fired,
the PHP path is right, and the directory is right. Then switch back to `>> /dev/null 2>&1`.

**What is scheduled** (confirmed locally with `php artisan schedule:list`):

```
0 19 * * *  attendance:purge-photos        -> 03:00 Kuala Lumpur
0  * * * *  attendance:flag-open-segments   -> hourly
```

The `0 19` is deliberately **not** `0 3`. The application runs in UTC and the business does
not, so 03:00 local is 19:00 UTC. A naive `dailyAt('03:00')` would fire at 11am in Kuala
Lumpur — deleting photographs while the console is in use.

**Why each job matters:**

- A forgotten clock-out is never flagged — and **that employee then cannot clock in at all**.
  They scan, enter their PIN, press the button, and **nothing happens**, with no error on
  screen. Nobody at the counter can work out why.
- Photographs are kept past the retention window, which is a PDPA gap nobody notices.

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
