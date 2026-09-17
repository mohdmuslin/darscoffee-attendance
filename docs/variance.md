# Planned vs actual

What the roster said against what actually happened. This is the Phase 5 documentation,
and the report the separate-tables design exists to make possible.

Companion documents: `roster.md` (the plan), `reports-and-corrections.md` (the actual).

---

## 1. What the report answers

> *"We also have adhoc tasks."*

That sentence is why this exists. Until now the roster was a document nobody checked and
unplanned work was invisible; this puts a number on both, per employee, per period.

Six things fall out of comparing the two tables:

| Situation | Reported as |
|---|---|
| Rostered, worked the plan | **To plan** |
| Rostered, worked fewer hours | **Worked short** |
| Rostered, worked more hours | **Worked over** |
| Rostered, nothing punched | **No show** |
| Worked, nothing rostered | **Not rostered** (adhoc) |
| Rostered, called off | **Cancelled** |

---

## 2. Matching is by time overlap, not by `shift_id`

`time_entries.shift_id` looks like the obvious key and is a trap.

It records **which shift was live when the punch happened** — a snapshot. Amending a shift
cancels it and writes a *replacement* row, so after a manager moves a shift, the entry
still points at the cancelled original while the live shift is a different record.

Keying the report on that column would therefore describe a perfectly normal day as:

- a punch against a **cancelled** shift, **plus**
- a **no-show** for the replacement

Two wrong rows, from an ordinary roster edit. This was verified, not assumed.

Overlap matching is immune to it, and it is also the only thing that works for a punch that
was never linked at all — a correction-added segment, an imported row, anything recorded
before the roster existed.

Three rules govern it:

- **Overlap with a tolerance, not containment.** A punch that starts twenty minutes late and
  ends four hours in still covers the shift. Required containment would call that a no-show.
- **An open punch runs to now.** Otherwise today's shift reads as a no-show while the
  employee is standing there working it.
- **Only WORK segments match.** Counting a break as covering a shift would let a stray
  clock-in during lunch make a no-show look attended.

> The greedy match is safe because a person's shifts cannot overlap — the roster refuses it.
> The only ambiguity is a punch across two adjacent shifts, decided in favour of the earlier
> one, which is nearly always right for a handover.

A default tolerance of 30 minutes lives in `variance_tolerance_minutes`.

---

## 3. What counts as a variance

A report that flags everything is one nobody reads, so three deliberate silences:

| Case | Why it is not a variance |
|---|---|
| A shift still **running** | The hours are still accumulating. Half way through, they are always less than planned — calling that "short" would flag every shift until the moment it ended. |
| A shift **not yet started** | The day has not happened. Calling it a shortfall would make the report cry wolf every morning. |
| A **cancelled** shift | The plan was withdrawn. Treating it as a shortfall would make every called-off shift look like a problem. |

Both pending states are counted separately as `upcoming_count`, so a screen can say "3
shifts still to come" rather than leaving the reader to wonder whether the shortfall they
are looking at is real.

The **tolerance doubles as the shortfall threshold**: arriving twenty minutes late and
leaving on time is reported as met. Ten minutes is available for an outlet that disagrees.

---

## 4. Three judgement calls

### A cancelled shift never absorbs a punch

If a shift was called off and somebody worked anyway, those hours are reported as
**unplanned** — not folded into the cancelled shift.

That case matters operationally: it is exactly how an outlet quietly opens on a day it said
it was closed. Folding the hours in would report the day as met when nobody had agreed to it,
and would also count the cancelled shift's planned hours, showing a shortfall that never
existed.

### Unplanned work gets its own row

Not attached to a nearby shift. Attaching it would hide the one thing this report exists to
show.

### The variance keeps its sign

`worked − planned`, signed. "Under by 4h" and "over by 4h" need different responses, so an
absolute value would destroy the only information that matters.

Positive variance — more worked than planned — is shown in **amber, not green**. It is money
going out that nobody agreed to. Colour follows what needs attention, not whether the number
is bigger.

---

## 5. Totals

| Figure | Meaning |
|---|---|
| `planned_seconds` | Sum of planned shift hours, **excluding cancelled shifts** |
| `worked_seconds` | Sum of work segments — the same figure the timesheet shows |
| `variance_seconds` | worked − planned, **including** unplanned hours |
| `adhoc_seconds` | Hours worked with no rostered shift |

Variance includes the unplanned hours on purpose: it answers "what did we pay for against
what did we agree to", which is the question a manager actually has.

`has_variance` is a single boolean over no-shows, short/over shifts and unplanned rows, so a
screen can highlight a row without re-implementing the rule.

---

## 6. API

```
GET admin/variance/summary?from&to&outlet_id&active_only     per-employee totals
GET admin/variance/employees/{id}?from&to                    day-by-day detail
GET admin/variance/export?from&to&...                        streamed CSV
```

Read only, deliberately, and there is a test asserting the report **changes nothing** — no
shift or entry is touched, row for row. A report that quietly altered a shift would be
reporting on figures it had just changed, and the mistake would be invisible.

Every query is scoped by outlet, and record-level routes answer **404** rather than 403, so a
manager probing ids cannot learn which employees exist elsewhere. An `outlet_id` filter cannot
widen the scope: it is applied after `visibleTo`, so asking for an outlet the manager cannot
see returns nothing.

The CSV is streamed, carries a UTF-8 BOM for Excel, and uses **decimal hours** — the same
rules as the timesheet export, deliberately, so the two files can be joined.

---

## 7. Bugs found while building this

1. **A cancelled shift absorbed punches.** Caught by a test written for exactly that case. It
   made cancelled-then-worked read as met, which is the opposite of the truth.
2. **A shift in progress was compared against its own plan.** Half way through, hours worked
   are always less than planned, so every shift was reported as a shortfall until the moment
   it ended.
3. **A negative duration reached the screen as "worked -4h 9m".** Caused by a future-dated
   clock-in, which is possible from a device with a wrong clock. A negative duration reads as
   a *credit* and would quietly reduce someone's hours, so `durationSeconds()` now clamps at
   zero — wrong in the safe direction, with the entry still visible to correct.
4. **A future shift showed a −12h variance.** A number nobody can act on. Variance figures are
   now only shown for shifts that have finished.

---

## 8. What Phase 5 does not do

- **No adhoc task entry.** The blueprint mentions it; the report already shows adhoc *hours*,
  but recording what the work *was* — "deep clean", "stock take" — is not built. It needs a
  decision about whether that is a shift with a label or its own table.
- **No pay.** Hours only. Rates, overtime rates and adhoc adjustments are Phase 6.
- **No scheduling suggestions.** "You are short on Thursday" is stated, not solved.
- **No notifications.** Nobody is told their roster changed, or that they were recorded as a
  no-show. That needs a decision about SMS versus WhatsApp first.
