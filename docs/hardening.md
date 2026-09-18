# Hardening

Phase 7. Four things that are only worth doing once the features exist, and each of
which is invisible until the day it matters: deleting personal data on a schedule,
proving you have consent for it, not losing a punch when the connection drops, and
being able to get the data back.

The theme running through all four is that each has a **quiet failure**. A retention
policy that does not run, a consent record that cannot be defended, a queue that
loses punches, a backup that has never been restored — none of them produce an error
message. They produce a system that looks fine.

---

## 1. Photograph retention (PDPA minimisation)

`PhotoRetentionService`, run by `php artisan attendance:purge-photos`, scheduled
nightly at 03:00 in the business timezone.

### Why the obvious implementation is wrong

The first version deleted files whose modification time was older than 90 days. That
is what `PhotoService::purgeFolderOlderThan()` did, and on its own it is unsafe: a
punch photograph is **evidence** — it is the entire reason an outlet requires one —
and some of those photographs are attached to disputes that are still open. Deleting
the photograph that would settle an argument because the calendar says ninety days
have passed destroys the one thing that could resolve it, and does so silently.

So eligibility is decided from the **database**, where the dispute state lives, and
file age is only the backstop for files no row refers to any more.

### What is held

| Hold | Why |
|---|---|
| The entry has an **unreviewed anomaly** | The photograph is the evidence for the flag |
| The entry has a **pending correction** | The photograph may be what the decision turns on |

Both holds are released by somebody doing their job — reviewing the anomaly, deciding
the correction — and the command prints them, because **a number that will not go
down is the only signal that a dispute has been left open.** Held photographs are
never purged, so this is the only thing that will ever surface it.

### Each photograph is aged by its own timestamp

A shift starting at 22:00 and ending at 06:00 has a clock-out photo taken eight hours
after the clock-in one. Judging both by `started_at` would delete the clock-out photo
eight hours early. Small, but wrong in the direction that loses data.

### What happens to the row

The column naming the purged file is cleared, so the console never offers to show a
photograph the database insists exists.

Cleared with `->toBase()->update()`, **not** Eloquent's `update()`, which stamps
`updated_at`. On a table whose `updated_at` is read as "these times were altered", a
nightly housekeeping pass would look like a mass edit of historical attendance —
precisely the signature a manager investigating a dispute would chase, and a false
trail created by the retention job itself.

### Profile photographs

Deleted once the person has been gone past the window — `resigned_at`, or `deleted_at`
for a soft-deleted row. **Never while they still work here, however old the file is.**
A profile photograph is what the console shows on the roster and beside every punch;
deleting it because the file is old would leave a nameless placeholder for someone
still working. A profile photograph is not evidence, so a dispute hold does not apply.

### Operational notes

- `--dry-run` prints exactly what would be deleted and touches nothing. **The first
  run on a live database should not be the one that finds out what the rule matches.**
- `--days=N` overrides the window for a one-off catch-up.
- `Setting::PHOTO_RETENTION_DAYS`, default 90, owner-configurable.
- The cron entry on cPanel is `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
- The schedule passes `->timezone(config('attendance.business_timezone'))`. This is not
  decoration: the application runs in UTC, so a bare `dailyAt('03:00')` would fire at
  11am in Kuala Lumpur, deleting photographs while the console is in use.

### The orphan sweep

Files no row refers to at all — an upload written by a request that then failed. They
are personal data with no record keeping them alive, so they are swept on age alone.

The referenced set is built from **every** row, not from the eligible ones. Deriving it
from the eligible rows makes every recent photograph look unreferenced, so the sweep
deletes exactly the photographs that were supposed to survive.

> Observed in practice: after `migrate:fresh --seed`, 64 photograph files remained on
> disk with zero rows referencing them. The sweep is what reclaims those.

---

## 2. Consent (PDPA)

`ConsentService`, `Employee`, and the endpoints under `employees/{id}/consent`.

### A timestamp is not a consent record

A single `consent_at` column answers none of the questions the employer is actually
asked when a consent is challenged:

- **Who took it?** A date with no name cannot be defended when the person says they
  never gave it.
- **What did they agree to?** Consent is consent to a particular notice at a particular
  version. Reusing a column across a rewritten notice claims agreement to terms that had
  not been written yet.
- **How?** A verbal yes witnessed by a manager and a signed form are both legitimate and
  are different strengths of evidence.

So: `consent_recorded_by`, `consent_version`, `consent_method` (a `ConsentMethod` enum),
and a withdrawal pair — `consent_withdrawn_at`, `consent_withdrawal_note`.

The recorder is taken from the authenticated token, never the request body. Attribution
is the part of the record most likely to be relied on, so a caller must not be able to
supply it.

### Withdrawal acts

A withdrawal that sets a timestamp and changes nothing else is theatre. The punch flow
asks `Employee::mayBePhotographed()`, so someone who has refused is not photographed
again — and is **not blocked from punching either**. Their refusal removes the photo
requirement for them; it must not become an inability to clock in.

### Two rules that point in opposite directions

| | Rule | Why this way round |
|---|---|---|
| **Withdrawal** | Always honoured | An explicit instruction. No default can override it. |
| **Missing record** | Refused only if `Setting::REQUIRE_CONSENT_FOR_PHOTOS` is ON | Every existing employee has no consent row, so ON by default would silently stop all photographs on the first morning |

That second row is the decision worth reviewing. The strictest reading of the PDPA is to
require consent before capture, and that was the first implementation. It was turned off
because of what it would actually do in production: not refuse a few photographs, but
**stop photographs being taken at all**, disabling the anti-buddy-punching control the
system rests on, with no obvious symptom. The console shows the backlog
(`GET employees/consent/backlog`) so the owner can clear it and then switch enforcement
on deliberately.

`consent_at` is **not** cleared on withdrawal. Blanking it would imply consent was never
given, calling into question the lawfulness of every photograph taken while it was — and
those were taken lawfully.

Recording consent again **clears the withdrawal**. An implementation that left both
timestamps set would report the person as unconsented for ever, and the only fix would be
a database edit — which is how a compliance report stops being trusted.

### Withdrawal does not delete punch photographs

Those may be evidence in an open dispute, and they are not solely the objector's — a
manager facing a wage claim needs them. Erasing contested evidence is a judgement for a
human, made on a request. The **profile** photograph is deleted, because it is
convenience rather than evidence.

### The bug worth recording

The first implementation asked `isPhotographRequired($outlet)` to decide whether a
photograph was *permitted*. That question is about the **outlet**, so at an outlet that
does not require photographs the answer was "no" — and every photograph an employee
volunteered was thrown away. The camera looked broken.

Split into two questions, both now needed:

- `isPhotographRequired($outlet)` — must a photo accompany the punch? The outlet wants
  one **and** the person may be photographed.
- `mayBePhotographed()` — is a photograph of this person allowed at all?

Consent can only ever narrow what the outlet asks for, never widen it.

---

## 3. Offline punch queue

`resources/js/punch/lib/queue.js`, `OfflinePunchService`.

A kitchen has no signal. Before this, someone clocking out with no connection was told
"your punch was NOT recorded" — honest and useless. Their options were to stand there
retrying or walk away with nothing recorded, and an unrecorded clock-out is the
expensive failure.

### Idempotency

A queued punch is retried until it succeeds, and the phone **cannot tell** "the request
never arrived" from "the reply never arrived". So the retry may well be a punch the
server already recorded.

The first version matched on `time_entries.client_uuid`, which only covers actions that
**create** a segment. A clock-out closes one, so its client id was stored nowhere: the
retry matched nothing, closed the segment again, and was then refused as out-of-order.
The employee was left on the clock with no way to fix it at the screen.

The ledger is now **`punch_events`** — the one table that records every action,
closures included. Unique on `(employee_id, client_uuid)`.

- **Per employee, not global.** Globally unique meant two employees who happened to
  reuse an id collided on insert and got a 500.
- **The lookup is scoped to the employee**, so one person cannot send another's id and
  read back their entry id and state.
- `time_entries.client_uuid` stays a plain unique marker. Writing the client's id there
  *as well* made the two tables disagree about who a punch belonged to.

A duplicate is answered as a **success** carrying the original entry, not a 409. The
client is a phone draining a queue and can do nothing useful with an error — it would
retry for ever.

### The claimed time is bounded, not trusted

The time must come from the phone, or a 7am punch synced at 2pm is recorded at 2pm and
the feature is pointless. But it is the only field a crafted request can lie about, and
what it lies about is money. Everywhere else in this app the client never supplies
identity; here it supplies a **time**, and the response is to bound it:

| Bound | Setting | Default |
|---|---|---|
| Not in the future | `OFFLINE_FUTURE_SKEW_MINUTES` | 15 min (a fast phone clock) |
| Not older than | `OFFLINE_MAX_BACKDATE_HOURS` | 24 h |

A punch outside those is **refused, never clamped.** Clamping would record a different
time from the one claimed and tell nobody: the employee would not know their punch had
been altered, and the manager would have nothing to review. A refusal is visible, and the
correction path exists for it.

Every accepted offline punch is labelled `is_offline_sync` **and** raises an
`OFFLINE_SYNC` anomaly — two different readers. `is_offline_sync` is what the timesheet
and pay code filter on; the anomaly is what a manager sees. A client-reported time must
never be indistinguishable from one the server witnessed.

### Out of order

A queue can present a clock-out before the clock-in it followed. Closing nothing would
create a segment that ends before it starts — the negative duration `durationSeconds()`
clamps against. Refused, because an ignored clock-out leaves the employee believing they
are off the clock while a segment accrues hours.

### Frontend

- The punch time is captured **when the button is pressed**, not when the queue drains.
- Queued punches carry **no photo**: base64 images would fill localStorage and break the
  session token stored beside them. At an outlet that requires photographs, the employee
  is told to see a manager rather than waiting for a queue that cannot deliver. Surfaced
  rather than prevented — an employee with no signal who cannot clock out at all is worse
  off than one whose punch needs a correction afterwards.
- The queue is **cleared on logout**. It is a shared phone in a kitchen; draining one
  person's punches under the next person's session is exactly what the photo and anomaly
  checks exist to catch.
- Local state is **predicted** while offline so the screen stops offering "clock in"
  after it was pressed — otherwise the employee presses again and the queue holds two.
  The server's answer replaces the prediction on the next drain.

### Found in the browser, would have deleted punches in production

The queue drained on page load, the session had expired, the server answered **401**, and
every queued punch was **dropped** as though the server had refused it.

A session expiry is recoverable — they scan the code again and the punch lands. So the
queue is now held and the employee is told to sign in. Only a genuine **422** drops an
entry, and that count is reported so the employee knows to see a manager. Treating "I do
not know who you are" as "this punch is invalid" conflates two completely different
answers.

Also fixed: `Carbon::diffInMinutes` is **signed**, so the anomaly read *"received -180
minute(s) later"*. A negative figure reads as arithmetic done wrong rather than phrasing
done wrong, which sends whoever sees it looking for a fault that is not there.

---

## 4. Backup and restore drill

`php scripts/dev-backup-drill.php` — **run it, do not read it.** A backup that has never
been restored is not a backup; it is a file.

It dumps the live database with `--single-transaction`, restores it into a scratch
database (`{live}_restore_drill`), compares row counts **per table**, and drops the
scratch. The live database is never written to, so running the drill is always safe.

Per-table rather than a single total, because one number hides the failure that matters:
every table matching except one. A restore that loses only `time_entries` still "mostly
works", and the loss is the entire attendance record.

`--single-transaction` gives InnoDB a consistent snapshot without locking. Without it a
punch arriving mid-dump can be captured half-written, and the restored copy either fails
a foreign key or — worse — succeeds with a segment that has no matching employee.

### The half a database dump does not cover

**The photographs are files, not rows.** A database-only backup restores a system that
claims to have hundreds of punch photographs and can serve none of them: every timesheet
showing a broken image, and no evidence to settle a dispute, which is the only reason the
photographs are collected.

So the artefact is **two** things:

```
mysqldump <db> > backup.sql          # the records
tar storage/app/private              # the photographs
```

The drill prints the file counts and says so explicitly, so the reminder arrives at the
moment somebody is thinking about backups rather than in a document nobody rereads.

### On the cPanel target

No Redis, no queue worker, no long-running process. The only scheduling is one cron entry
calling `schedule:run`; `QUEUE_CONNECTION=sync` because a database queue would enqueue
work nothing ever runs.

---

## 5. Load check at three times volume

`php scripts/dev-load-check.php` seeds a realistic multiple of the live volume and times
the queries the console actually runs, so the numbers describe real screens rather than
synthetic ones. `php scripts/dev-count-queries.php [CODE] [DAYS]` counts the queries
instead of timing them.

### What it found

Run at 3× volume (41 employees, ~6,900 entries, 30-day windows):

| Query | Before | After |
|---|---|---|
| Timesheet, one employee, 30 days | 205.6 ms | **49.6 ms** |
| Timesheet, one outlet, all staff | 2057.7 ms | **521.6 ms** |
| Variance, one outlet, 30 days | 330.1 ms | 199.9 ms |
| Pay, one outlet, 30 days | 180.6 ms | 112.0 ms |
| Anomaly queue (100 rows) | 0.7 ms | 0.7 ms |

**An N+1, not a missing index — and the numbers were right the whole time.**

`WorkedHoursService::forPeriod()` fetched the range only to work out which dates had
activity, then called `forDay()` per date, and `forDay()` issued its own query plus a
labour-rule lookup. A single employee's month ran **80 queries**; the query count is now
**4**, and constant regardless of the period length. Confirmed at 26 days: still 4.

This is exactly the failure a timing check exists to catch and an assertion never would.
Nothing was wrong with the output — every test passed, because the totals were correct.
The cost simply grew with the length of the period, so it would have got worse every
month the system was in use and there would have been no moment at which anything
looked broken.

The fix passes the range-wide entries and a pre-resolved rule map down into `forDay()`,
so the rule for a given outlet and date is read from a map keyed `"{outletId}:{date}"`
rather than queried. Both parameters are optional, so a standalone day view still works.

### The honest position on scale

At the expected scale — 3 outlets, ~30 staff, a few hundred punches a day — this
application is nowhere near any limit, and the check exists to **prove that** rather than
to discover a problem. Reporting a comfortable margin is a real result; the alternative is
carrying an unquantified worry about performance for a system whose actual load is trivial.

The queries timed are the ones that **grow** — a period timesheet, variance, pay, and the
anomaly queue. Each is scoped by outlet and bounded by a date range, and that is what keeps
them from degrading as history accumulates: the console never offers an unbounded
"everything" view, because a query that is fast at one year is not fast at five.

### What the numbers do and do not tell you

They measure the **database**, on a development machine, with rows written in bulk. They do
not measure:

- **HTTP and PHP overhead per request.** The console fetches a screen in one or two calls,
  so this is small, but it is not zero and it is not measured here.
- **Concurrency.** Three outlets means maybe half a dozen concurrent users at open. The
  interesting number at this scale would be the nightly retention job overlapping the
  morning punches, and neither is heavy enough to matter.
- **cPanel's disk and CPU limits**, which are shared and are the most likely source of a
  real surprise. The retention purge deletes files one at a time, deliberately unhurried.

---

## What was decided without being asked

Recorded here so the decisions can be reviewed rather than discovered:

1. **Retention defaults to 90 days** (already seeded, unchanged). `Setting` exists, so
   this is a policy question rather than a code change.
2. **Consent enforcement defaults OFF.** The reasoning is above; it is the one decision
   here that deliberately does *not* take the strictest reading, and it is reversible in
   one setting once the backlog is cleared.
3. **Withdrawal does not delete punch photographs.** Judged too consequential to automate
   — it destroys evidence in someone else's dispute.
4. **The backdating window defaults to 24 hours.** Longer than any real connectivity gap
   on the premises, far shorter than the span over which backdating could rewrite a pay
   period.
5. **Held photographs are never purged.** The alternative — purging once a hold is old
   enough — would silently defeat the hold.
6. **The queue is dropped on logout.** Loses punches for someone who logs out with a
   queue pending, but avoids attributing one person's punches to another on a shared
   phone. The warning banner exists so this is a choice, not a surprise.
