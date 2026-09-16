# Architecture — Dars Coffee Staff Attendance

Companion documents: `blueprint.md` (scope, phases, screens) and
`database-design.md` (schema). Read all three before changing anything about
time, money or identity — those are the parts that are expensive to get wrong.

---

## 1. Overview

A staff attendance system for Dars Coffee: employees clock in and out by scanning
an outlet QR code with their own phone, confirming identity with a PIN, and
taking a photo. Managers schedule shifts and review the hours; the owner sets pay
rates and sees everything.

It is a **separate application** from the table-ordering system, with its own
repository, database and deployment.

### Why separate, and not a module of the ordering system

| Reason | Detail |
|---|---|
| **Independent deploys** | Attendance must go live first. Sharing an app would ship the ordering system — including its live payment path — before its UAT has run. |
| **Blast radius** | Staff clock in at 07:00 whether or not a payment gateway or the Loyverse POS is healthy. A shared app couples attendance availability to both. |
| **Different cadence** | Ordering is money-sensitive and near-frozen. Attendance will change constantly (shifts, OT, leave, corrections). |
| **Different data** | Attendance holds personal data (photos, IC numbers) that should not sit in the same database as customer orders. |

They share nothing at runtime. The only seam is a nullable link from an employee
to a login user, for the few people who need both.

---

## 2. Goals & Non-Functional Requirements

| Goal | Target |
|---|---|
| Clock-in time | Under 20 seconds on a phone, from scan to confirmed |
| Availability | Attendance must keep working if the internet drops (Phase 7) |
| Accuracy | Stored to the second; no rounding loss in the database |
| Auditability | Every correction and rate change attributable to a person |
| Privacy | PDPA-aligned: consent, minimisation, retention, access control |
| Hosting | cPanel shared hosting — **no Redis, no WebSockets, no worker processes** |
| Scale | 3 outlets, ~30 employees, ~60 punches/day. Trivial load. |

The hosting constraint is the same as the ordering system and drives several
decisions below (polling instead of push, cron instead of queues).

---

## 3. Technology

Deliberately identical to the ordering system so conventions, CI and the
deployment guide carry over.

| Layer | Choice |
|---|---|
| Backend | PHP 8.3, Laravel 13 |
| Database | MySQL 8.4 |
| Frontend | Vue 3 + Pinia + Vue Router, Vite, Tailwind 4 |
| Auth | Laravel Sanctum (token) for the console; short-lived punch sessions for the public flow |
| Tests | Pest |
| Style | Pint |
| Hosting | cPanel, own subdomain, own database |

---

## 4. Components

Three frontends, one backend.

```
                    ┌──────────────────────────────────────────┐
                    │            Laravel API                   │
                    │  (single codebase, three entry points)   │
                    └──────────────────────────────────────────┘
                       ▲                ▲                ▲
        ┌──────────────┘                │                └──────────────┐
        │                               │                               │
┌───────────────┐             ┌──────────────────┐            ┌─────────────────┐
│  PUNCH PWA    │             │  CONSOLE         │            │  DISPLAY        │
│  (employee's  │             │  (owner +        │            │  (per-outlet    │
│   own phone)  │             │   manager)       │            │   device)       │
│  PUBLIC       │             │  AUTHENTICATED   │            │  DEVICE KEY     │
└───────────────┘             └──────────────────┘            └─────────────────┘
   scan QR                       employees, shifts               shows the
   PIN                           reports, corrections            rotating code
   photo                         rates, outlets
   clock in/out
```

### 4.1 Punch PWA — public, mobile-first

The employee's own phone. Deliberately requires **no login**: kitchen crew have
no accounts and never will.

Flow: **scan outlet QR → enter PIN → take photo → confirm the action.**

State machine, enforced server-side:

```
CLOCKED_OUT ──clock in──► WORKING ──start break──► ON_BREAK
     ▲                       │  ▲                      │
     │                       │  └────end break─────────┘
     └──────clock out────────┘
```

**The core invariant: at most ONE open time entry per employee.** Clocking in
opens a work segment; taking a break closes it and opens a break segment; ending
the break reverses that. Clock out closes whatever is open. This is what makes
"worked time = sum of work segments" correct without any arithmetic tricks.

### 4.2 Console — authenticated

Owner sees all outlets. A manager sees **only their own**, enforced on every
query. Screens: employees, outlets, QR codes, shifts, timesheets, corrections
queue, reports, pay rates.

### 4.3 Display — per-outlet device (optional)

Only used for outlets on a *rotating* code. Shows the current code with a
visible countdown so a screen can't be silently photographed and reused.
Authenticated by a per-outlet **device key**, not a user login.

If an outlet uses a printed code instead, this component is unused for it. Both
modes are supported per outlet.

---

## 5. Layered Architecture

```
HTTP          Controllers (thin) → validate → call a service → return a resource
Services      TimeEntryService, WorkedTimeCalculator, PunchService,
              OutletTokenService, ReportingService, CorrectionService
Models        Eloquent, with outlet scoping
Policies      One per resource; every action checks outlet scope
```

Rules carried over from the ordering project because they prevented real bugs
there:

- **Controllers stay thin.** Business rules live in services so they can be
  tested without HTTP.
- **One uniform JSON envelope** (`ApiResponse`): `{ success, data, message, errors }`.
  Clients parse one shape everywhere.
- **The client never dictates a price, a time or an identity.** The server
  re-derives all three. On the ordering system, a bug where a client could claim
  its own price was the single most important thing to prevent; here the
  equivalent is a client claiming its own timestamp or employee.
- **Never trust a client-supplied `employee_id`.** The punch session names the
  employee, and that session was issued only after a PIN check.

---

## 6. Key Data Flows

### 6.1 Clock in

```
Employee phone                    Server
     │                              │
     │ scan outlet QR → token       │
     │ POST /punch/scan ───────────►│ 1. token valid, live, not revoked?
     │   { outlet_token, pin }      │ 2. PIN matches an active employee at that outlet?
     │                              │ 3. rate-limit per token and per employee
     │◄── punch_session (10 min) ───│ 4. issue a short-lived session bound to
     │                              │    (employee, outlet, token)
     │ photo (camera) ─────────────►│ 5. store photo, open a WORK segment
     │◄── state: WORKING ───────────│ 6. stamp time, outlet, token id, photo
```

The PIN is exchanged once for a session so it is not retyped (and not
re-transmitted) for break and clock-out.

### 6.2 Rotating code

```
Display device                    Server
     │ GET /display/{outlet}/token ─►│  no valid token? create one (TTL 90s)
     │◄── { token, expires_at } ─────│
     │  (shows it with a countdown)  │
     │  polls every 30s              │
```

TTL is 90 seconds rather than 60 to absorb clock skew and the time taken to
frame a QR in a camera.

### 6.3 Worked time

```
work segments:  09:00–12:00, 13:00–18:00
break segments: 12:00–13:00

worked   = 3h + 5h            = 8h 00m     ← what gets paid
span     = 09:00 to 18:00     = 9h 00m     ← NOT used
break    = 1h 00m
overtime = worked − 8h        = 0h 00m     (threshold: 8h/day, worked time)
```

Overtime is measured on **worked** time, never on the elapsed span. Getting this
backwards would pay an hour of overtime for every hour of lunch.

---

## 7. Security Model

| Concern | Approach |
|---|---|
| Employee identity | PIN (bcrypt-hashed, 4–6 digits) + photo per punch |
| Presence proof | Outlet QR token — rotating (90s) or printed (revocable) |
| PIN brute force | Rate limit per token *and* per employee; lockout after N failures |
| Token entropy | 32+ random bytes, URL-safe. Never sequential or guessable. |
| Device keys | One per outlet display; revocable without affecting others |
| Console auth | Sanctum tokens, role + outlet scope checked on every request |
| Public endpoints | Only the punch flow. No listing, no enumeration, no employee data returned beyond a first name. |
| **Photos** | Stored outside the web root, served via **signed temporary URLs** |
| Personal data | PDPA: consent at onboarding, retention period, access restricted by outlet |

> **Lesson carried over from the ordering project.** `<img src="...">` cannot send
> an `Authorization` header, so protected images served behind auth fail silently.
> The ordering system solved this with `URL::temporarySignedRoute`. Photos must do
> the same from day one — this is not a detail to add later.

### Threat: the buddy punch

The system cannot *prove* who held the phone. What it can do is make impersonation
costly and visible:

| Control | Effect |
|---|---|
| Short-lived rotating code | A photographed code is worthless in ~90 seconds |
| Photo on every punch | Deters, and produces evidence for disputes |
| Shift-window restriction | Punches far outside a scheduled shift are refused or flagged |
| Same PIN, two outlets | Detected and queued for review |
| Above-average span | Flagged |
| Manager review queue | Nothing anomalous passes silently |

**Accepted residual risk:** if an outlet uses a *printed* code, that code can be
photographed once and reused until revoked. This is a conscious trade-off, and
the compensating controls are the photo, the shift window and the review queue.

---

## 8. Deployment (cPanel)

```
Subdomain      attendance.darscoffee.com   (own SSL via AutoSSL)
Document root  ~/attendance/backend/public
Database       own MySQL database, own user — NOT shared with ordering
Cron           php artisan schedule:run   every minute
Timezone       store UTC, display Asia/Kuala_Lumpur
```

**Cron is required**, unlike a purely on-demand system. Scheduled work:

| Job | Frequency | Why |
|---|---|---|
| Flag entries left open too long | hourly | Someone forgot to clock out |
| Close/flag abandoned segments | daily | Keeps the open-entry invariant honest |
| Auto-lock closed pay periods | monthly | Prevents silent edits to finalised months |
| PDPA retention purge | monthly | Delete photos older than the retention window |

The same deployment constraints as the ordering system apply: **no workers, so no
queues.** Anything long-running is a cron command, and anything interactive must
respond in well under a minute.

---

## 9. Offline Strategy

Phase 7, but the design must accommodate it now because it constrains the schema.

Reliable internet at a shop cannot be assumed, and a staff member who cannot
clock in will simply not be paid correctly. So the punch PWA will queue punches
locally and sync when connectivity returns.

This forces three things into the design **from the start**:

1. **A client-generated UUID per punch.** Without it, a retry after a flaky
   connection creates a duplicate punch. The server upserts on this key.
2. **The client records when the punch actually happened**, not when it synced.
   The server must accept that timestamp — while still validating that the QR
   token was genuinely valid *at that moment*, which means token validity must be
   checkable over a window, not just "is it valid right now".
3. **Punches may arrive out of order.** Clock-out can reach the server before
   clock-in. Reconciliation is by client timestamp, not arrival order.

> **Not just a setting.** The ordering system's service worker deliberately caches
> nothing (a stale menu would show a wrong price). This app needs the opposite
> behaviour, so the offline layer is new work rather than a configuration change.

---

## 10. Time, Dates and the Midnight Problem

Three rules, all of which cause real payroll disputes if ignored:

1. **Store UTC, display Asia/Kuala_Lumpur.** Never store a local wall-clock
   time; never mix the two.

2. **A business day is not a calendar day.** A shift running 22:00–02:00 belongs
   entirely to the day it *started*. Without this rule, the tail of a late shift
   lands in the following day and overtime thresholds misfire. A work segment is
   attributed to the business day of its **start**.

3. **Never recompute history from current rates.** A rate change today must not
   alter last month's figure. Rates are versioned with effective dates, and a
   closed period is locked.

---

## 11. Why Polling, Not Push

Same reasoning as the ordering system: shared hosting has no WebSockets and no
long-lived processes.

| Client | Interval | Why |
|---|---|---|
| Display | 30s | Keeps the rotating code current |
| Punch PWA | on demand | The employee acts, then waits for one response |
| Console | on demand + manual refresh | Managers work in bursts, not in real time |

Nothing here needs sub-second latency. Polling is simpler, cheaper and survives
shared hosting.

---

## 12. Future Evolution

- **Multi-outlet ordering.** If outlets 2–3 later take QR orders, that is a change
  to the *ordering* system (which currently binds exactly one store) — not to
  attendance. Worth planning separately.
- **GPS / geofence.** The schema anticipates optional coordinates; enabling them
  is a per-outlet setting and a PDPA consideration, not a redesign.
- **Payroll integration.** If the export must feed an external payroll system,
  the export shape becomes a contract.
- **Leave management.** Out of scope for v1; it changes the reporting layer more
  than the punch layer.
- **Biometric punch.** A fingerprint reader would replace the PIN as the identity
  factor while keeping the same session and time-entry model.
