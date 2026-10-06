# Go-live checklist — Dars Attendance

**Where things stand (6 Oct):**

| | Status |
|---|---|
| Application running | ✅ `/punch` and `/console` both load |
| `/.env` protected | ✅ 403 |
| Install (`APP_KEY`, schema, seed) | ✅ Done |
| Permanent cron | ✅ Added and **verified firing** |
| **Deploy pipeline** | ✅ **Working** — the `530` was a stale FTP username |
| Punch photos viewable | ✅ Timesheet → any punch → **Photo** |
| **Seeded passwords** | ⚠️ **Still `password` — the one real exposure** |
| iPhone scanning / camera shape | ✅ Fixed — **needs a real iPhone to confirm** |

**The system works and releases can ship.** What remains is the published passwords and a
round of testing on real devices.

---

## 1. ⚠️ Change the two published passwords — do this now (5 min)

**There is no change-password screen in the console.** `AccountsView.vue` can only *set* a
password when creating an account, and the API's owner-only `PATCH /admin/users/{id}` supports
it but has no UI in front of it. So the two seeded accounts keep the password `password`, which
is **published in the public repository** — anyone who has seen it can sign in as owner and read
every staff member's hours and photographs.

Adding the missing screen is the proper fix. Until then, the script:

**1. Upload** `scripts/set-console-password.php` to the **application root** (the folder
containing `artisan`) — File Manager → Upload.
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
> hand. The app root is not reachable over HTTP, but remove it anyway.

Then sign in at `https://attendance.darscoffee.com/console` to confirm the new password works.

## 2. ✅ Permanent cron — done and verified

cPanel → **Cron Jobs**, every minute:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**This was verified, not assumed.** cron redirects its output to `/dev/null`, so a wrong PHP
path or a wrong directory fails **completely silently** — no error, no log, and the only symptom
appears days later as "the app stopped working".

To re-verify at any time, temporarily log instead of discarding:

```
* * * * * cd /home/mwstayco/attendance.darscoffee.com/attendance && /usr/local/bin/php artisan schedule:run >> /home/mwstayco/schedule.log 2>&1
```

Any readable output — even `No scheduled commands are ready to run.` — proves cron fired, the
PHP path is right, and the directory is right. Then switch back to `>> /dev/null 2>&1`.

**What is scheduled** (confirmed with `php artisan schedule:list`):

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

## 3. ✅ The FTP deploy is fixed

The two-week outage was a **stale username**, not a password. cPanel names FTP accounts
`<user>@<domain>`, and when the account was recreated after the app moved it was named against
`attendance.darscoffee.com`. A **missing account** answers `530 Login authentication failed`,
which is identical to a wrong password — so it read as a credentials problem and re-entering
the password could not fix it.

The `FTP_USERNAME` secret is now `darscoffeeeftipi@attendance.darscoffee.com`, and deploys
succeed.

**If it breaks again**, `scripts/ftp-login-check.ps1` separates the two causes — run it with
its **absolute path**:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File "c:\Users\User\CustomerDatabase\Dars Attendance\scripts\ftp-login-check.ps1"
```

| Result | Meaning | Fix |
|---|---|---|
| **LOGIN FAILED** | the cPanel account/password is wrong | cPanel → FTP Accounts → check the account exists, then its password |
| **LOGIN OK** | the server is fine; the secret is stale | delete the `FTP_PASSWORD` secret and re-add by **pasting** |

Also remember: **the deploy gates on CI**, so a failing *code-style* check blocks shipping just
as effectively as a broken test. Run `pint` before pushing.

## 4. Test on a real iPhone (5 min)

**The most important remaining test.** The scanner previously used `BarcodeDetector`, which
does not exist on **any** iOS device, so every iPhone showed a black rectangle. It now uses
`jsQR`, which works everywhere, with the native API kept as an Android speed-up — but **no
automated test can open a camera**, so this can only be confirmed by hand.

On an iPhone:
1. Open `https://attendance.darscoffee.com/punch`
2. Allow camera access
3. **The preview should be the normal 4:3 shape, not a stretched rectangle**
4. Point it at a printed code — it should scan
5. Complete a clock-in, including the photo

Also worth testing on Android, to confirm the fast path still works.

## 5. Confirm the punch photos and the code lifetime

- **Employees** → open an employee → timesheet → **Photo** on any punch. Both clock-in and
  clock-out images should show with their times.
- **Outlets** → **Edit** on an outlet → change *Code expires after*. The range is 30 seconds to
  24 hours. A window above 10 minutes shows a warning — that is deliberate: a photographed code
  that stays valid for hours is effectively a printed sheet, and `Printed` mode says so honestly.

## 6. Test a staff profile photograph

Open **Employees** → an employee with a photograph. It must render.

If it 403s while the rest of the console works, add `TRUSTED_PROXIES=*` to `.env` (see
`docs/deployment.md` step 3). This is the one setting that fails *only* for photographs, so it
is easy to miss — test once on wifi and once on mobile data.

## 7. Then the full list

`docs/deployment.md` → **Step 9, Go-live checks** — 12 checks ending with a real phone scanning
a printed QR, a clock in/out, and an offline punch that syncs.

---

## Rollback

The tag **`last-known-good-deploy`** points at the last commit verified working on the live
site. Procedure, plus what a code rollback does *not* fix, is in `docs/deployment.md` →
**Rollback**.

Move the tag forward only after confirming on the **live site**, not merely when CI is green.

---

## How the deployment was unblocked

Kept brief; the detail is in `docs/deployment.md` and `docs/hardening.md`.

1. **Uploads failed for weeks on FTPS.** Every TLS *data* connection failed with
   `SSL alert number 50`. Two earlier explanations were wrong: first "too slow" (the transfer
   was cut from 8,599 files to ~250 and still failed, in 4 minutes), then "the payload". The
   real cause was the transport — the action bundles a `basic-ftp` version that Node 24 broke,
   and no setting or upgrade fixes it. Now **plain FTP**, verified permitted beforehand.
2. **The document root pointed at an empty folder**, and cPanel would not allow a root outside
   the subdomain folder, so the app was **moved** into that tree.
3. **`public/index.php` was missing** — the FTP tool's sync-state file had travelled with the
   moved folder and still claimed it was deployed. Fixed by renaming `state-name`.
4. **The 500 was a missing `storage/` tree.** The deploy excludes `storage/**` on purpose (it
   protects the photographs), so a fresh server has none of it. The give-away was a 500 **with an
   empty log** — the error had nowhere to be written. `attendance:install` creates it first.
5. **The remaining `530` was a stale FTP username**, not the password.
6. **iPhone scanning was a missing browser API**, not a bug in the code — see
   `docs/hardening.md` §7.
7. **Punch photographs were captured but not viewable** — the API returned a boolean and no
   screen read it.

> **Two patterns worth keeping.** A 500 *with an empty log* almost always means the `storage/`
> tree is missing, not that the code is broken. And when a login fails, **verify the account
> exists before touching the password** — a missing account and a wrong password answer with the
> same code.

