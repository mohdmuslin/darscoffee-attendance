# The roster (shifts)

How the plan is recorded, and why it is kept apart from the hours people actually work.
This is the Phase 4 documentation.

Companion documents: `reports-and-corrections.md` (how hours are calculated),
`blueprint.md` (scope and phases).

---

## 1. The plan and the actual are different tables

```
shifts        — the PLAN.   Who is expected to work, and when.
time_entries  — the ACTUAL. What somebody actually clocked.
```

Nothing in the roster code writes a time entry, and nothing in the punch flow writes a
shift. That separation is the whole design, and it is what makes the awkward cases fall
out without special handling:

| Situation | What it is |
|---|---|
| Rostered, and punched | A normal day |
| Rostered, nothing punched | A **no-show** |
| Not rostered, but punched | **Adhoc** work. Normal in this business, not an error |
| Rostered, then called off | A **cancelled** shift, which is what explains the no-show |

If the roster lived in the same table as the punches, every one of those would need a flag
and a rule. Kept apart, they are just the presence or absence of a row.

> **A shift is never evidence that someone worked.** It is a plan. Pay comes from the
> timesheet, which counts only work segments.

---

## 2. Creating a shift

Times are entered as **local wall-clock** (`2026-09-21T09:00`), never with an offset, and
the server converts using the outlet's timezone before storing UTC.

That is deliberate. The browser is not asked to do the conversion, because doing so would
mean trusting the phone's clock and its timezone setting — and the failure mode is not an
error message. It is a shift eight hours out, with every lateness figure computed against
it silently wrong.

The API therefore **rejects** an offset (`date_format:Y-m-d\TH:i`, not `date`) rather than
reinterpreting it. `date` would accept `2026-09-21T09:00:00+08:00` and silently read it as
UTC.

---

## 3. Overlaps

A shift that collides with one the same person already has is **refused** by default:

> *"Ahmad Bin Ali is already rostered for part of that time."*

A double-booking is almost always a mistake, and it silently creates two shifts where the
manager believes there is one.

Three details matter:

- **The test is half-open.** Two ranges collide when one starts before the other ends and
  ends after the other starts. Using `<=`/`>=` would make a shift that starts exactly when
  the previous one ends count as a clash — refusing every legitimate handover.
- **Cancelled shifts are ignored.** Otherwise re-rostering after a cancellation — the most
  common roster action there is — would fail against the shift it is replacing.
- **`allow_overlap` exists** for a split shift or a handover. The alternative would be
  cancelling a shift to make room for its replacement, which loses the first one.

Two outlets in one day is *not* an overlap. Staff cover between sites, and that is flagged
by the punch anomaly rather than refused at roster time.

---

## 4. Amending a shift

| Change | What happens |
|---|---|
| **Times move** | The original is **cancelled** and a replacement is written |
| **Note or position only** | Edited **in place** |

A shift whose hours changed is not the same plan. Editing in place would leave the answer
to *"why am I down for a late shift?"* nowhere, and the manager being asked would have no
way to show that it changed. A typo fix, by contrast, moves nothing — duplicating the row
for it would make the roster unreadable.

> The order matters: the original is cancelled **before** the replacement is created.
> The other way round, every amendment failed with *"already rostered for part of that
> time"* — the clash was the shift with itself.

---

## 5. Cancelling

Cancelling sets a timestamp. It **never deletes**, and there is no destroy endpoint.

A shift that was rostered and then called off is information: it explains a no-show. A
manager being asked "were we expecting anyone on Friday?" needs that answer to still exist
in three weeks.

The reason is **appended** to the note rather than replacing it, so a manager's own note
about the shift is not lost by cancelling it.

Cancelling is idempotent — a double-click, or a roster left open on a stale screen, must
not produce an error the manager has to interpret.

---

## 6. Copying

"Copy last week" is the action a manager uses most, so it is built as one operation:

```
POST admin/shifts/copy
  source_from, source_to     the range to copy
  target_from                where to start writing
  employee_id?               copy onto a different person
  on_conflict                skip (default) | replace
```

Four decisions behind it:

- **Whole days, not seconds.** The range shifts by a number of *days*, so a copy across a
  daylight-saving boundary keeps its local start time rather than drifting an hour.
- **One transaction.** A partial copy — half a week written, then an error — is worse than
  none, because the manager cannot tell which half landed.
- **`skip` is the default.** The safe failure is to leave something alone. Silently
  overwriting a roster would destroy work and give no sign it happened.
- **`replace` cancels rather than deletes** the shift it overwrites, so a roster that was
  overwritten can still be explained.

The result is reported honestly: *"4 shift(s) copied, 5 skipped where a shift already
existed."* A count that omitted the skips would leave a manager looking at a roster they
believe is a complete copy.

---

## 7. The roster screen

A week at a time, Monday to Sunday.

**Every day in the range is emitted, including days with nothing on them.** This is the
single most useful thing a roster can tell a manager, and it is invisible in a plain list
of shifts. A missing row reads as "no data"; an empty day reads as *"nobody is on"*, which
is the actual problem.

`has_cover` is computed **per day** and excludes cancelled shifts, so a day whose only
shift was called off reports **no cover**. Counting the cancelled row as cover is how a
shop opens with nobody on.

The screen also shows:

- A banner counting days with no cover, linking each to an **Add** button.
- A "today" marker, because a week view loses track of which day it is.
- Planned hours per employee for the week — the commonest rostering mistake is giving one
  person six days and another one, and that is invisible in a day-by-day list.
- A "Show cancelled" toggle, off by default.

---

## 8. API

```
GET   admin/shifts?from&to&outlet_id&employee_id&include_cancelled
GET   admin/shifts/current                       this week and next
POST  admin/shifts                               create
GET   admin/shifts/{shift}
PATCH admin/shifts/{shift}                       amend
POST  admin/shifts/{shift}/cancel                cancel, never delete
POST  admin/shifts/copy                          bulk copy
```

Every listing is scoped by outlet. Record-level routes answer **404**, not 403: a manager
asking for shift 42 must not be able to tell "that shift is at another outlet" from "no
such shift", because a 403 would confirm the record exists and reveal how busy another
outlet is.

Rostering an employee at an outlet the manager cannot see is refused as well as rostering
*at* another outlet — otherwise the employee would appear to vanish from the manager's own
roster.

---

## 9. What Phase 4 does not do

- **No variance report.** Planned-versus-actual, no-shows and unscheduled work as a report
  is Phase 5. The data is already there — `plannedTotals()` and the shift_id on each entry —
  but no screen presents the comparison yet.
- **No recurring patterns.** "Every weekday for the next month" is a copy, repeatedly.
- **No shift swaps or open shifts.** No evidence it is needed yet.
- **No working-time rules.** Rest-period checks between shifts are a labour-law domain with
  real consequences; the overlap check is about roster hygiene, not compliance.
- **No notifications.** Nobody is told their roster changed. Worth doing, but it needs a
  decision about SMS versus WhatsApp first.
