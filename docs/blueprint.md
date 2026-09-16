# Blueprint — Dars Coffee Staff Attendance

Scope, phases and screens. What we build, in what order, and what "done" means.

Companion documents: `architecture.md` (how it fits together),
`database-design.md` (schema).

---

## 1. The Problem

Dars Coffee has three outlets and around thirty staff, and **no reliable record of
who worked when.** Pay is calculated from memory, a paper book, or WhatsApp
messages. That produces:

| Problem | Consequence |
|---|---|
| No trustworthy hours record | Pay disputes with no evidence to settle them |
| Adhoc work invisible | People work and are not paid, or are paid twice |
| Buddy punching | Money leaves the business untracked |
| No lateness picture | Rostering problems never get fixed |
| Mixed pay bases | Hourly, daily, weekly and monthly staff calculated by hand |

The goal is a **trusted record of time**, not a payroll system. Payroll
calculation (tax, EPF, SOCSO) is deliberately out of scope — the system produces
hours, and exports them.

---

## 2. Success Criteria

| # | Criterion | Measured by |
|---|---|---|
| 1 | An employee can clock in under 20 seconds | Timed on a real phone |
| 2 | The hours report matches reality | Spot-checked against the paper book for one week |
| 3 | A manager can only see their own outlet | Attempted deliberately, and refused |
| 4 | A missed punch can be corrected with a reason | Done end-to-end, with the original still visible |
| 5 | Buddy punching is detectable | Anomalies are raised and queue for review |
| 6 | A month's figures can be re-derived | Reproduce last month's total from stored seconds |

If criterion 6 fails, the business cannot answer a dispute. It is the one to
protect hardest.

---

## 3. Users & Roles

| Role | Who | Scope | Can |
|---|---|---|---|
| **Owner** | You | All outlets | Everything: outlets, users, rates, all reports, all corrections |
| **Manager** | Store manager | **Their outlet(s) only** | Create/edit shifts, review anomalies, correct entries, view photos and reports — for their outlet |
| **Employee** | Everyone, incl. kitchen | Self | Clock in/out, see own hours. **No login.** |

Scope is enforced on **every query**, not just in the UI. A manager at Sg Ramal
must not be able to reach Sedap Santai data even by crafting a request.

---

## 4. Outlets

| Outlet | Code | Status |
|---|---|---|
| Diberanda Sg Ramal | `SG-RAMAL` | Live |
| Diberanda Sedap Santai | `SEDAP-SANTAI` | Live |
| Dars Coffee | `DARS-COFFEE` | Live |

The owner can add outlets later — nothing is hard-coded to three.

> **Open question.** Whether the `Dars Coffee` outlet is the same site as the
> connected ordering store. Not a blocker for attendance; it matters if that
> outlet later takes QR orders.

---

## 5. How Punching Works

### 5.1 Punch code: two modes, per outlet

| Mode | How the code is delivered | Lifetime |
|---|---|---|
| **Rotating** | A device at the outlet displays it | 90 seconds |
| **Printed** | Owner or manager prints a sheet | Until revoked or replaced |

Both are revocable, and **generating a new code revokes the previous one for that
outlet** — so a leaked printed sheet is neutralised by printing a replacement.

The mode is set per outlet, because the trade-off differs per site:

| | Rotating | Printed |
|---|---|---|
| Photographed code reusable | No (~90s) | Yes, until revoked |
| Needs a powered device | Yes | No |
| Coverage for flaky internet | Fail-closed unless offline queue is on | Robust — paper needs nothing |

### 5.2 The punch flow

```
1. Employee opens the punch site on their own phone
2. Scans the outlet QR           → codes proves WHERE
3. Enters their PIN              → proves WHO (weakly)
4. Takes a photo                 → evidence, and deters impersonation
5. The app confirms the action   → "Clocked in at 09:03"
```

Then the phone shows current state and the two or three available actions. The PIN
is exchanged for a **10-minute punch session**, so break and clock-out don't
require retyping it.

### 5.3 What the system can and cannot prove

Stated plainly, because overclaiming here causes trouble later:

| Claim | Provable? |
|---|---|
| Someone was at the outlet at that time | **Yes** — the code was scanned |
| Which employee | **Probably** — PIN + photo, not proof |
| That the employee was physically present | **No** — a colleague could hold the phone |

No software fix exists for that last row. The controls below make it expensive and
visible rather than impossible.

### 5.4 Anti-buddy-punching controls

| Control | Stops |
|---|---|
| Short-lived rotating code | Reusing a photographed code later |
| Photo on every punch | Casual impersonation; provides dispute evidence |
| **One open segment per employee** | Clocking in twice to inflate hours |
| **Punch must be at a mapped outlet** | Punching from a site you don't work at |
| Shift-window tolerance | Random 03:00 punches |
| Anomaly flags + review queue | Nothing odd passes silently |

> A manager who wants to defraud the system can. The design goal is that doing so
> **leaves a trail** — a photo, a token, a timestamp and a review flag.

---

## 6. Time Rules

These are the rules that decide pay, so they are explicit rather than implied.

| Rule | Decision |
|---|---|
| **Stored precision** | Exact seconds. No rounding in storage. |
| **Grace period** | 5 minutes, affecting the *late* flag only |
| **Rounding** | None by default (`exact`). Configurable per outlet; applied in reports only. |
| **Breaks** | Staff **clock out for breaks**. Break is its own segment. |
| **Break pay** | Unpaid by default |
| **Overtime threshold** | After **8 hours worked** per day |
| **OT measured on** | **Worked time**, not elapsed span |
| **OT rate** | **Flat rate**, adjustable adhoc by owner or manager |
| **Business day** | A shift belongs to the day it **started** |
| **Pay bases** | Hourly, daily, weekly, monthly |

### Worked time, illustrated

```
09:00  clock in
12:00  start break        work segment 1 = 3h
13:00  end break          break segment = 1h
18:00  clock out          work segment 2 = 5h

worked   = 8h 00m   ← paid
span     = 9h 00m   ← not used
break    = 1h 00m
overtime = 0h 00m   (threshold 8h)
```

A 1-hour lunch does **not** create an hour of overtime. Getting this backwards
would systematically overpay every long shift.

---

## 7. Screens

### 7.1 Punch PWA (employee, public, mobile-first)

| Screen | Contents |
|---|---|
| Scan | Camera opens immediately; a manual code entry fallback |
| PIN | Number pad, large targets, works one-handed |
| Photo | Front camera, retake, then confirm |
| Status | Current state, time today so far, and the available actions |
| My hours | This week, by day. Read-only. |

Design constraints: no login, no navigation to get lost in, and it must be usable
in under 20 seconds in a busy kitchen.

### 7.2 Console (owner + manager)

| Screen | Owner | Manager |
|---|---|---|
| Dashboard — who is in now, today's hours, unreviewed flags | All outlets | Own outlet |
| Employees — CRUD, PIN reset, outlet assignment, pay basis | All | View only |
| Outlets — CRUD, token mode | All | View only |
| QR codes — generate, print, revoke | All | Own outlet |
| Shifts — create/edit/cancel the roster | All | Own outlet |
| Timesheets — entries by day/employee, with corrections | All | Own outlet |
| Anomalies — review queue | All | Own outlet |
| Rates — compensation + adhoc adjustments | All | Propose |
| Reports — hours by employee/outlet/period, export | All | Own outlet |
| Settings | All | — |
| Users & outlet mapping | All | — |

### 7.3 Display (per-outlet device, only for rotating outlets)

Full-screen current code, large enough to scan from a metre away, with a visible
countdown so a photographed screen cannot be reused. Authenticated by a device key.

---

## 8. Phases

Each phase is independently deployable from Phase 3 onward.

### Phase 1 — Foundation
- Separate app, own database, own subdomain
- Outlets (the 3), employees, outlet assignment
- PIN set/reset, photo upload
- Owner/manager roles with **per-outlet scoping enforced and tested**
- QR generation, printing and revocation (both modes)

**Done when:** an owner can create 3 outlets and 30 employees, print a code for
each outlet, and a manager of one outlet cannot see another's data.

### Phase 2 — Punch
- `time_entries` as work and break segments
- Punch endpoint: QR → PIN → photo, with the one-open-segment invariant
- Punch PWA: scan, PIN, photo, status, my hours
- Anomaly flags raised on punch
- Rate limiting and PIN lockout

**Done when:** a real person clocks in on a real phone by scanning a printed code,
takes a break, clocks out, and the entry is correct.

### Phase 3 — Reports & corrections **[ DEPLOY HERE ]**
- Worked-hours calculation (work segments only)
- Timesheet report by employee/outlet/period
- Lateness and early-out, with the grace window
- Correction flow with mandatory reason and full audit
- Anomaly review queue
- CSV export

**Done when:** a week of real punches produces an hours report that matches the
paper book, and a missed punch is corrected with the original still visible.

> **This is the point to deploy.** Hours capture plus correction is genuinely
> useful on its own, and living with it for a few weeks will reveal the real
> shift and pay rules — which is far better than guessing them now.

### Phase 4 — Shifts
Manager shift scheduling: create, edit, cancel, copy last week, assign staff.
Planned shifts remain separate from punches.

**Done when:** a manager can build next week's roster for their outlet without help.

### Phase 5 — Planned vs actual
Variance report: scheduled vs worked, no-shows, unscheduled (adhoc) work, plus
adhoc task entry. This is where "we also have adhoc tasks" becomes visible.

### Phase 6 — Pay
- Overtime after 8h worked/day
- Versioned compensation rules across all four bases
- Adhoc rate adjustments with reason and audit
- Pay-period locking
- Payroll export

**Done when:** last month's figure can be reproduced exactly, including a
mid-month rate change.

### Phase 7 — Hardening
- Offline punch queue (idempotent via `client_uuid`)
- PDPA consent capture, retention purge
- Backup and restore drill
- Load check at 3× expected volume

---

## 9. Explicitly Out of Scope (v1)

| Not building | Why |
|---|---|
| Payroll calculation (tax, EPF, SOCSO) | Legal domain with real consequences. Export, don't compute. |
| Leave and holiday balances | Different reporting concern; separate phase if needed |
| Shift swap requests | No evidence it's needed yet |
| Payroll system integration | Add when you tell me the export shape you need |
| Native mobile apps | A PWA covers it; store review is a cost with no benefit |
| GPS enforcement | Schema anticipates it; decide per outlet later |
| Fingerprint punch | Would replace the PIN without changing the model |

---

## 10. Decisions (settled)

| # | Question | Decision |
|---|---|---|
| 1 | **Printed code lifetime** | **No expiry.** A printed code stays valid until a manager or the owner reprints. Reprinting is ad hoc — done when they want to change it, not on a schedule. Built as `expires_at = NULL` + revocation on reprint. |
| 2 | **Manager rate changes** | **No owner approval.** A manager may set rates directly. The audit record is therefore the only control — see below. |
| 3 | Payroll export | Record-keeping for now; export shape to be agreed in Phase 6 |
| 4 | Photo retention | 90 days, configurable (`photo_retention_days`) |
| 5 | Is `Dars Coffee` the same site as the ordered-from store? | Unresolved, and it does **not** block attendance |

### 10.1 Printed codes: what this means operationally

Choosing no expiry is a deliberate trade-off, and it shifts where the risk sits.

> **A printed code identifies the outlet, not the person's presence.** Anyone who
> photographs it can punch from home, and nothing in the system can tell. The code
> keeps working until someone reprints it.

Reprinting is therefore **the revoke mechanism**, and it must be easy — one button
on the Codes screen, reachable by a manager. If reprinting is awkward, a leaked
code stays live, so this is a usability requirement rather than a nicety.

What carries the load instead:

| Control | Effect |
|---|---|
| Photo on every punch | Deters, and gives evidence in a dispute |
| PIN | Names the person — shareable, so weak on its own |
| Shift-window tolerance | Random 03:00 punches are refused or flagged |
| Anomaly queue | Same PIN at two outlets, long spans, out-of-shift |
| Reprint | Kills a leaked code, immediately |

### 10.2 Rate changes without approval: the audit is the control

A manager may set what their own staff are paid, and no one countersigns it. That
is a conscious decision, and it makes one thing non-negotiable:

> **Every rate change records what changed, who changed it, and why.**
> A reason is required, and the previous value is retained.

Without approval, the audit trail is not a feature — it is the only thing standing
between a dispute and an argument. Two consequences for the build:

1. `rate_adjustments.reason` is **mandatory**, and `created_by` is never null.
2. Rate rows are **never updated in place**. A change closes the current row and
   inserts a new one, so "what was he paid in March?" stays answerable even after
   a later raise.

The owner still sees every change in the console, flagged as a manager's edit. The
choice removes the second signature, not the visibility.

---

## 11. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Internet down at an outlet | Medium | Staff cannot clock in | Printed code works without a device; offline queue in Phase 7 |
| Employees share PINs | Medium | Inflated hours | Photo + anomaly detection; PIN lockout on repeated failures |
| Staff resist being photographed | Medium | Adoption failure | Explain the *reason* (protecting their own pay), and make the photo instant |
| Manager forgets to approve corrections | Medium | Unpaid time | Unreviewed queue is prominent on the dashboard |
| Printed code photographed | Medium | Buddy punching | Revocable; shift window; review queue. Accept explicitly. |
| Owner changes rates and breaks history | Low | Disputes | Versioned rates; locked periods |
| Timezone/midnight mishandling | Low | Wrong pay | Business-day rule; tested against a midnight fixture |
| Scope creep into payroll | Medium | Delays everything | Section 9 is a contract. Export, don't compute. |

---

## 12. What "Done" Looks Like

- Every employee clocks in and out on their own phone in under 20 seconds
- Every outlet has a working code, rotating or printed
- A manager can see their outlet's hours and cannot see another's
- Missed punches are corrected with a reason and an audit trail
- Overtime appears correctly for anything over 8 hours worked
- Last month's hours can be reproduced exactly
- A dispute can be settled from the system, not from memory
