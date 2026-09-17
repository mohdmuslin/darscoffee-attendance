# Reports, corrections and the audit trail

How hours are calculated, how a mistake is fixed, and what the system can prove. This is
the Phase 3 documentation, and Phase 3 is the point at which the system is worth
deploying.

Companion documents: `blueprint.md` (scope and phases), `database-design.md` (schema).

---

## 1. How worked time is calculated

The rule is deliberately narrow:

```
worked = sum of WORK segments
```

Breaks are **not subtracted** — they are a different kind of segment. That is why the
schema stores breaks as their own rows rather than a column on the shift: a sum cannot
get the arithmetic wrong, while a subtraction can.

```
09:00  clock in
12:00  start break        work segment 1 = 3h
13:00  end break          break segment = 1h
18:00  clock out          work segment 2 = 5h

worked   = 8h 00m   ← paid
span     = 9h 00m   ← shown, never used for pay
break    = 1h 00m
overtime = 0h 00m   (threshold 8h of WORKED time)
```

A one-hour lunch does **not** create an hour of overtime. Getting this backwards would
systematically overpay every long shift, which is the single most expensive arithmetic
mistake this system could make.

### Overtime

- Measured on **worked** time by default, per `labour_rules.ot_basis`.
- The threshold is **per outlet, per day**, and **cumulative across the day's work
  segments** — two five-hour shifts in one day share one eight-hour allowance, so two
  hours are overtime, not zero.
- A day spanning two outlets resolves each outlet's rule separately, because folding
  them together would make the answer depend on which outlet was processed first.
- Rules are **versioned by date**. Raising a threshold today does not alter last month's
  figure — success criterion 6 depends on this.

### Rounding

Rounding is applied **in reports only**; stored seconds are always exact.

| Policy | Effect |
|---|---|
| `exact` | No rounding. The default. |
| `nearest_15` | To the nearest quarter hour. |
| `down_15` | Down to the quarter hour, never up. |

Storing exact seconds means a rounding policy can be re-applied to history, while
recovering exact seconds from rounded ones is impossible. It also means the record never
misstates when someone actually arrived.

### Lateness and early-out

- Measured only against a **rostered shift**. With no shift there is nothing to be late
  for, and inventing a start time would make every adhoc day look like a discipline
  problem.
- The **grace window (5 minutes by default) moves the flag only, never pay.** Arriving
  at 09:04 for an 09:00 shift is reported on time and paid from 09:04.
- The delta is **signed**: arriving 20 minutes early is not 20 minutes late.
- Early-out is only computed once the day is closed, because comparing an open segment
  to the rostered end would report everyone as leaving early for their whole shift.

---

## 2. Day status

Every day on a timesheet carries a status, because a manager needs to know which figures
are final.

| Status | Meaning | Complete? |
|---|---|---|
| `ok` | Punched, closed, matched to a shift | Yes |
| `open` | A segment is still open — nobody clocked out | **No** |
| `corrected` | The times were changed by an approved correction | Yes |
| `adhoc` | Worked with no roster. Normal here, not a fault | Yes |
| `no_show` | Rostered, nothing recorded | **No** |

`open` is not complete because those hours are still accumulating: a forgotten clock-out
would otherwise add a full day to the total every day it is left, and the total would be
presented as final while growing.

---

## 3. Corrections

The record is **never edited in place**. Every change goes through a correction that
stores:

- `original_values` — a snapshot of the affected columns **before** the change
- `changes` — only the fields being altered
- `reason` — **required**, enforced at the schema level as well as in the request
- `requested_by`, `reviewed_by`, `reviewed_at`, `review_note`

A second correction snapshots the entry **as it then stands**, so following the chain
leads back to the original punch one step at a time. Snapshotting from the original each
time would make the second row claim the first change never happened.

### Two actions

| Action | Used when |
|---|---|
| `update` | There is a row to amend — a forgotten clock-out, a wrong time |
| `create` | Nothing was recorded at all. A shift nobody punched has nothing to amend |

A created segment is marked `corrected`, not `closed`, so a report can tell an invented
shift apart from a recorded one.

### Who approves

| Corrections by the **owner** | Applied immediately |
|---|---|
| Corrections by a **manager** | Wait for the owner by default |

A manager may raise a correction for their own outlet — waiting on the owner for every
forgotten clock-out would mean the hours simply never get fixed. What keeps that safe is
not a permission but the record, so a manager **cannot approve their own correction**.

> This differs from the rate-change decision (blueprint §10.2), where a manager acts
> directly. The two are not equivalent: a rate change alters what future hours are worth
> and is visible in the rate history, while a correction alters the hours themselves —
> the record the entire system exists to make trustworthy. Letting a manager rewrite
> their own staff's hours unattended would make the timesheet self-certifying.

The approval requirement is a setting (`require_correction_approval`), so an owner can
loosen it later without a deploy.

### Guard rails

`changes` is validated against an **allow-list**: `started_at`, `ended_at`, `type`,
`note`. Not a blocklist — a blocklist would make every column added later writable by
default, including `employee_id` and `outlet_id`, which would move a punch to another
person or outlet and quietly defeat outlet scoping.

Also refused:

- An end at or before the start. Without this the duration is negative, which MySQL
  rejects outright on an unsigned column (a 500 that tells the manager nothing) and
  SQLite would happily store.
- An empty change set.
- Setting `status` by hand — the correction path sets it as a consequence of applying a
  change, and a request setting it would allow marking an entry corrected with no audit
  row saying why.
- Editing a frozen field. Finance periods and similar are out of scope for v1, but the
  allow-list is where such a rule would go.

---

## 4. The punch audit trail

`punch_events` is append-only and has no `updated_at`: a trail that records when it was
modified is an audit log with an audit problem of its own.

Recorded: `scan`, `pin_failed`, `pin_locked`, `clock_in`, `break_start`, `break_end`,
`clock_out`, `rejected`.

**Failed attempts matter as much as successful ones.** A successful punch leaves a time
entry, so a complaint about a missing punch is always about something that *did not
work* — and without the failure recorded, "I clocked in and it says I didn't" cannot be
answered. A `pin_failed` row has no employee id, and that blank is the signal: it means
no PIN matched anyone. A run of them against one code is someone working through PINs,
not a person mistyping.

---

## 5. The anomaly queue

Flags are raised automatically on punch and surfaced for review. They are the
compensating control for the fact that no software can prove who held the phone.

| Type | Severity |
|---|---|
| `missing_clockout` | High |
| `double_outlet`, `long_span`, `no_photo` | Worth checking |
| `outside_shift`, `offline_sync` | For information |
| `manager_rate_change`, `manager_correction` | For information, **shown to the owner** |

The last two are *oversight records* rather than faults: they exist because a manager
acted without a second signature. Keeping them in the same list but grouped separately
means the owner sees them without training everyone to skim past a red flag.

Reviewing a flag changes no hours. It records that someone looked — which is a different
act from correcting an entry, and deliberately needs no approval.

---

## 6. API

All read-only unless stated. Every query is scoped by outlet; a manager asking for
another outlet gets an empty list or a 404, never data.

```
GET  admin/timesheets/summary?from&to&outlet_id     totals per employee
GET  admin/timesheets/employees/{id}?from&to        one employee, day by day
GET  admin/timesheets/entries?from&to&...           flat segment rows
GET  admin/timesheets/export?from&to&...            streamed CSV

GET  admin/corrections?status&outlet_id&...         the audit list
GET  admin/corrections/pending-count                badge count
POST admin/corrections/entries/{entry}              raise a change      (reason required)
POST admin/corrections/missing                      record a missed punch
GET  admin/corrections/entries/{entry}/history      full history for one entry
POST admin/corrections/{correction}/approve         owner only
POST admin/corrections/{correction}/reject          owner only

GET  admin/anomalies?severity&unreviewed_only&...   review queue
POST admin/anomalies/{anomaly}/review               mark looked at
GET  admin/punch-events?employee_id&failures_only   the audit trail
```

### Export

CSV, **streamed** rather than built in memory: a month across thirty staff is a few
thousand rows, and handing that to a shared-hosting PHP process as one string is how an
export becomes an outage. It carries a UTF-8 BOM so Excel on Windows does not render
Malay names as mojibake, and durations are **decimal hours** (`8.50`) rather than `8h
30m`, because a CSV is for arithmetic and a spreadsheet cannot sum a string.

---

## 7. Things that are easy to get wrong

Four real bugs found while building this, all recorded because each is a trap someone
will walk into again.

1. **A date-cast column cannot be compared with `where`.** `business_date` casts to
   `date`, so Laravel binds it as `'Y-m-d H:i:s'`. MySQL's `DATE` column truncates that
   and the query works; SQLite stores the full string and the comparison matches
   **nothing**. Every business-day query silently returned empty on SQLite while passing
   on MySQL. Use `whereDate`. CI now runs the suite on both drivers for this reason.

2. **A datetime cast does not convert timezones.** Assigning `09:00+08:00` writes the
   literal string `"09:00"`, which reads back as 09:00 **UTC** — eight hours late. The
   punch flow masked this because `now()` is already UTC. Moving a shift's start to the
   right instant then shifted every lateness and early-out figure with it. `TimeEntry`
   and `Shift` normalise on set.

3. **A query string can only carry text.** Laravel's `boolean` rule accepts `1`, `0`,
   `"1"`, `"0"`, `true`, `false` — but **not** the strings `"true"` and `"false"`, which
   is exactly what axios sends. A ticked filter box produced a 422 naming a field the
   client did supply. `NormaliseQueryBooleans` converts the two string forms and leaves
   anything else to fail loudly.

4. **`exit()` skips `finally`.** A helper that restored `.env` after switching databases
   appeared to work and then left `.env` pointing at the scratch database, so the *next*
   command quietly targeted the wrong one. Assign the exit code inside the `try` and call
   `exit()` after it.

A fifth, worth its own line because it is a boundary rather than a bug: **the application
runs in UTC and the business does not.** Deriving the default reporting window from
`now()` meant that after 16:00 UTC — midnight in Kuala Lumpur — the window ended
*yesterday* and omitted the shift currently in progress. A timesheet that hides today's
work for a third of every day reads as data loss. The window is anchored on
`config('attendance.business_timezone')`.

---

## 8. What Phase 3 does not do

- **No pay calculation.** Hours only. Tax, EPF and SOCSO are a legal domain; the system
  exports and does not compute.
- **No pay rates applied to hours.** Phase 6.
- **No period locking.** A closed month can still be corrected; Phase 6 adds locking.
- **No shifts UI.** `shifts` exists and is read by lateness and no-show detection, but
  managing the roster is Phase 4.
- **No offline queue.** An offline punch currently warns the employee that it was not
  recorded, rather than queueing it. Phase 7.
