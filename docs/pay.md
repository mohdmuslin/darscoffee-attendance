# Pay

What the recorded hours are worth, and how a committed figure survives the data changing
underneath it. This is the Phase 6 documentation.

Companion documents: `reports-and-corrections.md` (how hours are calculated),
`variance.md` (plan against actual).

---

## 1. What this is, and what it is not

> **The system produces hours and amounts. It does not calculate payroll.**

No tax, no EPF, no SOCSO, no PCB. Those are a legal domain with real consequences, and
getting them subtly wrong is worse than not doing them at all. The figure here is what hours
are worth; the CSV is handed to whoever runs payroll.

That boundary is deliberate and worth stating plainly, because "payroll" is the word people
reach for when they see a ringgit amount.

---

## 2. The basis decides the arithmetic

Each basis answers a different question, and they are not interchangeable.

| Basis | How the amount is derived |
|---|---|
| **Hourly** | rate × hours worked |
| **Daily** | rate × **days actually worked** |
| **Weekly** | rate × weeks covered |
| **Monthly** | rate × the fraction of the month covered |

Two of these are easy to get wrong:

- **Daily is per day, not per eight hours.** A daily rate pays for turning up: a nine-hour day
  and a seven-hour day both pay one day. Dividing by a nominal day length would pay the short
  day as a fraction, which is not what a daily agreement means.
- **Monthly is not derived from an hourly figure.** Dividing a monthly salary by the hours in a
  month produces a different hourly rate every month, and multiplying it back never returns the
  salary. A full calendar month returns **exactly** the monthly rate — which is the property
  that matters, because a figure that misses by a few sen every month is one somebody has to
  explain.

A period spanning **two** months is summed month by month rather than prorated as one. Prorating
it as a single month would pay two months of work as one salary — the most expensive mistake
this file could make.

---

## 3. Overtime

| Rule | Decision |
|---|---|
| Threshold | After 8 hours **worked**, per day, per outlet |
| Measured on | **Worked** time, so a lunch hour never creates overtime |
| Rate | A **flat per-hour figure**, not a multiplier |

The threshold is a **daily** one, cumulative across that day's work segments:

- Three six-hour days are eighteen hours but **no** overtime — no single day crossed the line.
- Two five-hour shifts in one day **are** ten hours, so two of them are overtime.

Per outlet because sites differ, and versioned by date so a threshold change does not rewrite an
old period's overtime.

### No overtime rate is not the same as zero overtime rate

If no overtime rate is set anywhere — neither on the rate row nor as an adjustment — the
overtime hours are **recorded but priced at zero**, and a warning says so.

This is deliberate. Guessing a multiplier would invent an agreement nobody made, and overtime
paid at the wrong rate is worse than overtime visibly unpaid and queried.

---

## 4. Versioned rates

`compensation_rules` rows are **inserted, never updated**. Setting a rate closes the current row
the day *before* the new one starts and inserts a new row:

```
from        to          basis   rate    overtime
2025-08-01  2026-07-31  hourly  10.00   15.00
2026-08-01  2026-09-10  hourly  11.00   16.00
2026-09-11  2026-09-17  hourly  12.50   18.00
2026-09-18  open        hourly  14.00   21.00
```

No gaps, no overlaps. This is the only way "what was he paid in March?" stays answerable after a
raise, and it is success criterion 6.

Setting a rate that starts on a date a row **already** starts on replaces that row rather than
closing it — closing it would give it an `effective_to` before its `effective_from`, a row that
can never apply and would confuse every future reader.

### A mid-period change is flagged, not averaged

A period is priced at the rate in force **at its start**, and a warning says the rate changed.
Prorating automatically would guess at an agreement nobody wrote down; the warning tells the
manager to split the period into two if that is what they meant.

---

## 5. Adhoc adjustments

An adjustment is a **record**, not a formula: a flat rate for a day, week or month, with a
required reason.

- `applies_to` names **which** rate it changes — `ordinary` or `overtime`. Explicit, because an
  employee can have both and "changed the rate" would be ambiguous the moment they do.
- It qualifies by **overlap** with the period, not containment of its first day. An adjustment
  for a single busy Saturday must price that Saturday whether the pay period begins before it or
  on it — otherwise the adjustment exists, looks approved, and silently does nothing.
- Where several overlap, the **most specific and most recent** wins. Deliberately simple:
  portion-by-portion allocation is a different feature, and guessing at one on a payslip is worse
  than a rule a manager can state in a sentence.
- An **unapproved** adjustment does not move money. That is the whole point of having an
  approval step.

### Governance

A manager may set rates for their own staff with **no owner approval** (blueprint §10.2). That
was decided deliberately, because waiting on the owner for every rate means rates stay wrong for
weeks.

What keeps it safe is not a permission but the record: every change stores who made it, when,
why, and what it replaced — and a manager's change raises an **oversight flag** to the owner
rather than sitting in a log only the manager would read.

`Setting::REQUIRE_RATE_APPROVAL` exists so an owner can tighten this later without a deploy.

---

## 6. Rounding

**Plain floats internally, rounded once, at the end.**

Rounding each line as it is computed accumulates error across twenty staff and a hundred lines:
each line can be half a sen out in the same direction, and the total drifts by real money. Three
twenty-minute stints at 10.00/hour are 3.3333 each — rounded per line that is 9.99, a sen lost to
nothing but the order of operations.

Storage is `DECIMAL(12,2)`, so a rate cannot hold sub-sen precision in the first place. That
removes a whole class of drift *before* the arithmetic runs.

**The total is the sum of the rounded per-employee amounts**, not a separately-rounded whole. What
the business pays is the sum of the amounts on each payslip, so anything else produces a total
that does not match its parts and somebody has to reconcile it by hand.

---

## 7. Pay periods and the lock

A period is a named date range. **Locking commits its figures.**

Without it, "last month's total" changes every time somebody fixes a forgotten clock-out, and a
payslip already handed out stops matching the system.

### The lock is detectable, not hard

A hard freeze — refusing corrections because a period is locked — sounds stronger and is worse. A
genuine missed clock-out does not stop being genuine because the period was closed, and an
attendance system that cannot fix it after month end is one a manager works around on paper.

So locking stores three things:

| Stored | Why |
|---|---|
| `locked_at`, `locked_by` | Who committed it, and when |
| `snapshot` | The figures **at the moment of locking** — the record of what was paid |
| `snapshot_hash` | A digest of the inputs, so drift is detectable rather than silent |

Reconciling a period reports one of three states:

| State | Meaning |
|---|---|
| `open` | Never locked. Nothing was committed, so nothing can have drifted. |
| `locked` | Locked, inputs unchanged. The stored figure is still right. |
| `drifted` | Locked, inputs **changed**. The stored figure is what was paid; the recomputed one is what is now owed. |

Drift is attributed **per employee**, because "the total is 4 sen different" is not actionable
while "Ali's overtime is 4 sen different" is.

### Inputs can change without the money moving

Extending a shift into overtime at an outlet with **no overtime rate** moves the hours but not the
amount. Reported explicitly as `inputs_changed_only`, because "drifted" beside an empty difference
list reads as a bug.

### Locking is owner-only

Locking is the act that makes a figure the record of what was paid, and a manager committing their
own outlet's payroll is not a power to hand out.

---

## 8. API

```
GET  admin/rates?active_only                    current rate per employee
GET  admin/rates/employees/{id}                 full version history + adjustments
POST admin/rates/employees/{id}                 set a new rate from a date
POST admin/rates/adjustments                    adhoc adjustment (reason required)
POST admin/rates/adjustments/{id}/approve       owner only

GET  admin/pay/summary?from&to&outlet_id        per-employee amounts
GET  admin/pay/employees/{id}?from&to           day-by-day detail
GET  admin/pay/export?from&to                   streamed CSV

GET  admin/pay-periods                          periods, with committed totals
POST admin/pay-periods                          create (overlap refused)
POST admin/pay-periods/{id}/lock                owner only
GET  admin/pay-periods/{id}/reconcile           locked / open / drifted
```

Scoping is by outlet throughout, and record-level routes answer **404** rather than 403 so a
manager probing ids cannot learn who exists elsewhere. A rate is personal data, so the same rule
applies to it.

### The CSV

Streamed, UTF-8 BOM for Excel, and **every amount at two decimals** — an unformatted float reaches
a CSV as `10`, and a column of mixed `10` / `10.5` / `10.55` invites misreading and can make a
spreadsheet infer a wrong column type.

An **unpriced** employee's amount columns are **blank, not `0.00`**. A zero in a payroll
spreadsheet is a figure somebody will pay.

---

## 9. Bugs found while building this

1. **A cancelled shift absorbed punches** in the variance report — found in Phase 5, and the
   reason Phase 6's assumptions were checked rather than assumed.
2. **Money reached the CSV unformatted**, as `10` rather than `10.00`. Caught by a test asserting
   on the file's contents rather than its status code.
3. **A rate with three decimals was silently rounded.** The validation now refuses it, and the test
   revealed that the `DECIMAL(12,2)` column already prevents it — a constraint that removes a
   class of drift before the arithmetic runs.

Also worth recording: several test failures were **my expectations being wrong, not the code** —
extending a shift into overtime at an outlet with no overtime rate correctly leaves the amount
alone, and a period spanning two months correctly pays two months. Both were fixed in the tests.

---

## 10. What Phase 6 does not do

- **No tax, EPF, SOCSO or PCB.** Stated at the top and repeated here because it is the most
  likely thing to be assumed.
- **No pay-slip PDFs.** The CSV is the deliverable. A payslip format is a design conversation.
- **No part-period auto-splitting.** A rate change mid-period is flagged; splitting it is a
  deliberate act by a manager, not a guess by the system.
- **No hour-level allocation of overlapping adjustments.** The most specific wins for the whole
  period. Allocating hour by hour is a different feature and needs stating as a rule first.
- **No bank export format.** Needs the accounts team's format before it can be written.
