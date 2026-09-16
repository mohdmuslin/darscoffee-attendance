# Database Design — Dars Coffee Staff Attendance

Schema blueprint. This is the **source of truth for data rules**; where code and
this document disagree, one of them is a bug.

Companion documents: `architecture.md`, `blueprint.md`.

---

## 1. Principles

| Principle | Consequence |
|---|---|
| **Store instants in UTC** | Every timestamp is UTC. Display converts to `Asia/Kuala_Lumpur`. |
| **Store durations as seconds** | No round-tripping through `H:i:s`, no rounding loss. |
| **Money is DECIMAL** | Never float. Never store a derived hourly rate for salaried staff. |
| **Rate rows are versioned, never updated** | History must reproduce exactly. |
| **History is immutable; corrections are additive** | A correction records what changed and who approved it. |
| **Never hard-delete people** | Employees are soft-deleted/archived; their history must survive. |
| **One open segment per employee** | Enforced by a partial unique index, not just application code. |

---

## 2. Entity Overview

```
outlets ──┬── outlet_user ──── users            (manager scope: many-to-many)
          ├── employee_outlet ── employees      (who works where: many-to-many)
          ├── outlet_tokens                     (printed or rotating punch codes)
          ├── device_keys                       (per-outlet display device)
          ├── shifts                            (PLANNED)
          └── time_entries                      (ACTUAL: work + break segments)

employees ──┬── time_entries
            ├── compensation_rules               (versioned, effective-dated)
            ├── rate_adjustments                 (adhoc overrides, audited)
            └── user_id  ──► users               (nullable: only the few who log in)

time_entries ──┬── attendance_corrections
               └── punch_events/audit
```

**Employees are not users.** Most people who clock in — kitchen crew especially —
never log into anything. The few who do (managers) have both records, linked by a
nullable `users.id`.

---

## 3. Tables

### outlets

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| code | varchar(20) UNIQUE | Short slug for URLs and QR payloads, e.g. `SG-RAMAL` |
| name | varchar(100) | Diberanda Sg Ramal |
| address | varchar(255) NULL | |
| timezone | varchar(64) | Default `Asia/Kuala_Lumpur`. Per-outlet so future expansion works. |
| token_mode | ENUM('rotating','printed') | Default `rotating`. Which punch-code style this outlet uses. |
| qr_ttl_seconds | int | Default 90. Rotating code lifetime. |
| requires_photo | bool default true | Per-outlet; a shop may negotiate this away |
| is_active | bool default true | |
| timestamps | | |

### users (console logins: owner + managers)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | |
| email | varchar(150) UNIQUE | |
| password | varchar(255) | hashed |
| role | ENUM('owner','manager') | **Two roles only.** Employees are not users. |
| is_active | bool default true | Deactivating must take effect immediately, not at token expiry |
| last_login_at | datetime NULL | |
| timestamps | | |

> Deactivation is checked on every request, not only at login. The ordering system
> learned this the hard way: a deactivated admin could keep using a live token.

### outlet_user (manager scope)

| Column | Type | Notes |
|---|---|---|
| user_id | FK → users | |
| outlet_id | FK → outlets | |
| PK(user_id, outlet_id) | | |

The admin creates these mappings. A manager with no rows sees nothing — fail
closed, not open.

### employees

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_code | varchar(20) UNIQUE | Human-facing, e.g. `DBI-004`. Printed on the payslip. |
| name | varchar(100) | |
| phone | varchar(20) NULL | |
| ic_number | varchar(20) NULL | **Encrypted at rest.** Sensitive personal data. |
| pin_hash | varchar(255) | bcrypt of a 4–6 digit PIN. **Never store the PIN.** |
| pin_set_at | datetime NULL | |
| pin_failed_attempts | smallint default 0 | Drives lockout |
| pin_locked_until | datetime NULL | |
| photo_path | varchar(255) NULL | Profile photo, outside the web root |
| pay_basis | ENUM('hourly','daily','weekly','monthly') NULL | Set per employee |
| user_id | FK → users NULL | Links the few who also have a console login |
| joined_at | date NULL | |
| resigned_at | date NULL | |
| is_active | bool default true | |
| timestamps, deleted_at | | Soft delete only. |

Indexes: `UNIQUE(employee_code)`, `INDEX(is_active)`, `INDEX(user_id)`.

### employee_outlet

| Column | Type | Notes |
|---|---|---|
| employee_id | FK → employees | |
| outlet_id | FK → outlets | |
| is_primary | bool default false | Main site, for defaults |
| PK(employee_id, outlet_id) | | Many-to-many: staff cover outlets. |

**This table is a permission boundary.** A punch is only accepted at an outlet the
employee is mapped to.

### outlet_tokens (the punch code)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| outlet_id | FK → outlets | |
| token | varchar(64) UNIQUE | URL-safe random. 32+ bytes. |
| mode | ENUM('printed','rotating') | Copied from the outlet at creation |
| expires_at | datetime NULL | **NULL for printed** (valid until revoked) |
| revoked_at | datetime NULL | Set when superseded or deliberately killed |
| revoked_by | FK → users NULL | |
| created_by | FK → users NULL | NULL if auto-generated by a display device |
| generations | int | Rotating only: how many times this outlet has rolled |
| timestamps | | |

Indexes: `UNIQUE(token)`, `INDEX(outlet_id, expires_at)`, `INDEX(revoked_at)`.

**Validity rule:** a token is usable when `revoked_at IS NULL` **and**
(`expires_at IS NULL` OR `expires_at > now()`).

**"Latest applicable" rule:** generating a new token for an outlet revokes all
previous live tokens for that outlet in the same transaction. This is what makes
printing a replacement sheet safe — the old sheet dies.

### device_keys

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| outlet_id | FK → outlets | |
| key_hash | varchar(255) | hashed; shown once at creation |
| label | varchar(64) NULL | "Front counter tablet" |
| last_seen_at | datetime NULL | So a dead device is noticeable |
| revoked_at | datetime NULL | |
| timestamps | | |

Separate from `users` because a wall-mounted tablet is a device, not a person.

### shifts (PLANNED)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | FK → employees | |
| outlet_id | FK → outlets | |
| starts_at | datetime | UTC |
| ends_at | datetime | UTC |
| position | varchar(50) NULL | "Kitchen", "Front", "Adhoc task" |
| note | varchar(255) NULL | |
| created_by | FK → users | Manager or owner |
| cancelled_at | datetime NULL | Cancelling keeps it visible in history |
| timestamps | | |

Indexes: `INDEX(employee_id, starts_at)`, `INDEX(outlet_id, starts_at)`.

**A shift is a plan, not a record.** It never contributes to worked hours — those
come only from `time_entries`. Adhoc work is simply a punch with no matching
shift, which is why "rostered *and* adhoc" needs no special case.

### time_entries (ACTUAL — the source of truth for pay)

The important table. **Work and breaks are separate rows of different `type`.**

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| client_uuid | char(36) UNIQUE | **Generated on the phone.** Makes offline retries idempotent. |
| employee_id | FK → employees | |
| outlet_id | FK → outlets | Where the punch was made |
| shift_id | FK → shifts NULL | Matched, if any. NULL = adhoc. |
| type | ENUM('work','break') | |
| started_at | datetime | UTC. Client-supplied when offline, server-validated. |
| ended_at | datetime NULL | NULL while open |
| duration_seconds | int NULL | **Cached on close**, recomputed from timestamps anyway |
| started_photo_path | varchar(255) NULL | |
| ended_photo_path | varchar(255) NULL | |
| started_token_id | FK → outlet_tokens NULL | Which code was scanned |
| ended_token_id | FK → outlet_tokens NULL | |
| started_device | varchar(32) NULL | user agent class, for anomaly detection |
| business_date | date | **Derived from `started_at`**, not `ended_at` — see §5 |
| status | ENUM('open','closed','flagged','corrected') | |
| note | varchar(255) NULL | |
| is_offline_sync | bool default false | Arrived via the offline queue |
| timestamps | | |

Indexes:
- `UNIQUE(client_uuid)` — the idempotency guarantee
- `UNIQUE(employee_id) WHERE ended_at IS NULL` — **the one-open-segment invariant**
- `INDEX(employee_id, business_date)`
- `INDEX(outlet_id, business_date)`
- `INDEX(status)`

> **Why a partial unique index rather than only application logic.** Two
> simultaneous requests — a double-tap on a flaky connection — both pass an
> application-level `exists()` check. The database catches what the application
> cannot. MySQL 8 supports functional/partial workarounds via a generated column,
> so the exact mechanism is settled in the migration.

> **Why `duration_seconds` is cached and still recomputed.** Reporting sums
> millions of seconds; storing the total avoids recomputation on every read. But
> the timestamps remain authoritative, so the cache can always be rebuilt and is
> verified in tests. A cached value that cannot be re-derived is a liability.

### labour_rules (per outlet, versioned)

Overtime policy is per outlet because outlets differ and a rule change must not
rewrite history.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| outlet_id | FK → outlets | |
| ot_after_seconds | int | Default 28800 (8h) |
| ot_basis | ENUM('worked','span') | Default `worked` |
| rounding_policy | ENUM('exact','nearest_15','down_15') | Default `exact` |
| grace_seconds | int | Default 300 (5 min). Affects the *late* flag only. |
| break_paid | bool default false | |
| effective_from | date | |
| effective_to | date NULL | |
| created_by | FK → users | |
| timestamps | | |

Index: `INDEX(outlet_id, effective_from)`.

Overtime is measured on **worked** seconds, so a 1-hour lunch never generates an
hour of overtime.

### compensation_rules (versioned)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | FK → employees | |
| basis | ENUM('hourly','daily','weekly','monthly') | |
| rate | DECIMAL(12,2) | Per hour/day/week/month according to basis |
| currency | char(3) default 'MYR' | |
| effective_from | date | |
| effective_to | date NULL | NULL = current |
| note | varchar(255) NULL | Why it changed |
| created_by | FK → users | |
| timestamps | | |

Index: `INDEX(employee_id, effective_from)`.

Rows are **inserted, never updated**. Changing a rate means closing the current
row (`effective_to`) and inserting a new one. This is the only way "what was he
paid in March?" stays answerable after a raise.

### rate_adjustments (adhoc, audited)

Because the OT rate is a **flat rate that admin or manager can adjust adhoc**,
the adjustment is a record — not a formula.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | FK → employees | |
| applies_to_date | date | The day (or period start) it applies to |
| applies_to_period | ENUM('day','week','month') | Scope of the override |
| hours | DECIMAL(6,2) NULL | Hours at this rate; NULL = whole period |
| rate | DECIMAL(12,2) | The flat per-hour rate applied |
| reason | varchar(255) | **Required.** An unexplained rate change is unauditable. |
| created_by | FK → users | |
| approved_by | FK → users NULL | Pending when NULL, if approval is required |
| approved_at | datetime NULL | |
| timestamps | | |

Index: `INDEX(employee_id, applies_to_date)`.

> **Governance.** If a manager can set the rate for their own staff, they can
> influence what those staff are paid, and the first line of defence is gone. The
> schema supports "manager proposes, owner approves" (`approved_by` NULL = pending).
> Whether approval is *required* is a setting, not a schema change.

### attendance_corrections

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| time_entry_id | FK → time_entries | |
| requested_by | FK → users | |
| original_values | JSON | Snapshot before the change |
| changes | JSON | Only the fields being altered |
| reason | varchar(255) | **Required** |
| status | ENUM('pending','approved','rejected') | |
| reviewed_by | FK → users NULL | |
| reviewed_at | datetime NULL | |
| review_note | varchar(255) NULL | |
| timestamps | | |

`original_values` is what makes this auditable rather than merely logged: after an
approval you can still say exactly what the record said before.

### punch_events (audit trail)

Append-only. Every punch attempt, successful or not.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| employee_id | FK → employees NULL | NULL when the PIN did not match anyone |
| outlet_id | FK → outlets NULL | |
| token_id | FK → outlet_tokens NULL | |
| event | ENUM('scan','pin_failed','pin_locked','clock_in','break_start','break_end','clock_out','rejected') | |
| ip_address | varchar(45) NULL | |
| user_agent | varchar(255) NULL | |
| meta | JSON NULL | |
| created_at | datetime | |

This is how you investigate "I clocked in but it says I didn't" — including the
attempts that failed, which are exactly the ones people ask about.

### anomalies (per-entry flags)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| time_entry_id | FK → time_entries | |
| type | ENUM('outside_shift','long_span','double_outlet','no_photo','missing_clockout','offline_sync') | |
| severity | ENUM('info','warn','high') | |
| detail | varchar(255) NULL | |
| reviewed_by | FK → users NULL | |
| reviewed_at | datetime NULL | |
| timestamps | | |

Kept separate from `time_entries` so an entry can accumulate flags without
repeated schema changes, and so "show me everything unreviewed" is one query.

### settings

Same key/value pattern as the ordering system.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| key | varchar(100) UNIQUE | |
| value | TEXT/JSON | |
| timestamps | | |

Keys: `photo_retention_days`, `require_rate_approval`,
`pin_max_attempts`, `pin_lockout_minutes`, `company_name`, `payslip_footer`.

### pay_periods

Explicit locking, so a finalised month cannot be silently edited.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| outlet_id | FK → outlets NULL | NULL = all outlets |
| starts_on | date | |
| ends_on | date | |
| locked_at | datetime NULL | NULL = open |
| locked_by | FK → users NULL | |
| reopened_at | datetime NULL | Any reopen is itself recorded |
| reopened_by | FK → users NULL | |
| reopen_reason | varchar(255) NULL | |
| timestamps | | |

---

## 4. The One-Open-Segment Invariant

The rule that keeps the whole model honest:

> **At most one row in `time_entries` per employee may have `ended_at IS NULL`.**

Consequences, all desirable:

- Clocking in twice without clocking out is rejected by the **database**, not just
  by a race-prone application check.
- Worked time is always `SUM(duration)` with no overlapping segments to
  de-duplicate.
- "Who is currently clocked in" is a single indexed query — which is also the
  live floor view on the console.

Break handling falls out of the same rule: starting a break **closes** the work
segment and **opens** a break segment. There is never more than one open row.

---

## 5. Business Day Rule

> **A time entry belongs to the business day of its `started_at`.**

A 22:00–02:00 shift is one working day, not two. `business_date` is a stored,
indexed derivation of `started_at` so reports group correctly and overtime
thresholds never misfire across midnight.

This is the single most common source of payroll disputes in shift work.

---

## 6. Worked Time, Span and Overtime

```
Given work segments W₁…Wₙ and break segments B₁…Bₘ for one employee's day:

  worked_seconds = Σ duration(Wᵢ)          ← what is paid
  span_seconds   = last.end − first.start  ← NOT used for pay
  break_seconds  = Σ duration(Bⱼ)
  overtime       = max(0, worked_seconds − ot_after_seconds)
```

Verified against a fixture in tests, including a shift that crosses midnight and
one containing two separate breaks.

Rounding (`rounding_policy`) and grace (`grace_seconds`) are applied **in the
reporting layer only**. Stored seconds are always exact, so changing policy later
can be re-applied to history without re-entering data — and the stored record
never lies about when someone actually arrived.

---

## 7. Sync & Idempotency Strategy

No external system is mirrored, so sync here means **offline punch reconciliation**.

| Concern | Approach |
|---|---|
| Duplicate punch from a retry | `client_uuid` is UNIQUE; the server upserts on it |
| Out-of-order arrival | Reconcile by client timestamp, not arrival order |
| Wrong "now" from a phone | The client timestamp is accepted but **bounded** — a punch claiming a time far in the past or future is rejected and flagged |
| Was the QR valid *then*? | Token validity is checked against the punch timestamp over a window, not only against "now" |
| Clock skew between devices | Server time is authoritative; the difference is recorded for diagnosis |

`client_uuid`, `is_offline_sync` and token-window validation are in the schema
from the start **because adding them later would mean backfilling data that
cannot be backfilled**.

---

## 8. Privacy (PDPA)

| Data | Handling |
|---|---|
| Photographs | Stored outside the web root, served by signed temporary URLs, purged after `photo_retention_days` |
| IC number | Encrypted at rest; masked in every non-editing view |
| Location | Optional; if enabled, treated as personal data and disclosed at onboarding |
| Access | Managers see photos and records for **their outlets only** |
| Consent | Recorded at onboarding with a date |
| Retention | A cron purge, not a manual clean-up |

Retention is enforced by a scheduled command rather than good intentions, because
a manual process is one that stops happening.

---

## 9. Migration Order

```
1. settings
2. users                      (Laravel default, extended with role/last_login_at)
3. outlets
4. outlet_user
5. employees                  (needs users)
6. employee_outlet
7. outlet_tokens
8. device_keys
9. labour_rules
10. shifts                    (needs employees, outlets)
11. time_entries              (needs employees, outlets, shifts, outlet_tokens)
12. compensation_rules
13. rate_adjustments
14. attendance_corrections    (needs time_entries)
15. punch_events
16. anomalies                 (needs time_entries)
17. pay_periods
18. personal_access_tokens    (Sanctum)
```

---

## 10. Deliberate Omissions (v1)

| Not included | Why |
|---|---|
| Leave / holiday balances | Changes the reporting layer more than the punch layer; a separate concern |
| Payroll computation | v1 records hours and rates. Tax, EPF, SOCSO are a different domain with legal consequences — export, don't compute. |
| Multi-currency | One country |
| Shift swap requests | No evidence it is needed yet |
| GPS enforcement | Schema anticipates it; enabling it is a per-outlet setting |
| Biometric punch | Would replace the PIN factor without changing the model |
