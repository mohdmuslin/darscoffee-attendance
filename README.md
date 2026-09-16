# Dars Coffee — Staff Attendance System

Clock-in and timesheet system for Dars Coffee's three outlets.

Staff scan an outlet QR code with their own phone, confirm with a PIN, and take a
photo. Managers schedule shifts and review the hours. The owner sets rates and
sees everything.

**Status:** Phase 1 in progress (scaffold done).

---

## Read the docs first

| Document | What it covers |
|---|---|
| `docs/blueprint.md` | **Start here.** Scope, roles, time rules, screens, phases, open decisions |
| `docs/architecture.md` | Components, data flows, security model, deployment, offline strategy |
| `docs/database-design.md` | Schema and data rules — the source of truth |

> **Read `docs/` before changing anything involving time, money or identity.**
> The business rules there (one open segment per employee, worked time excludes
> breaks, business day from the start time, versioned rates) are deliberate and
> each one prevents a specific real-world failure.

---

## Why this is a separate app

Deliberately **not** a module of the table-ordering system, with its own
repository and database:

| Reason | Detail |
|---|---|
| Independent deploys | Attendance goes live first; sharing an app would ship the ordering system — including its live payment path — before its own UAT |
| Blast radius | Staff clock in at 07:00 whether or not a payment gateway is healthy |
| Different cadence | Ordering is money-sensitive and near-frozen; attendance will change constantly |
| Different data | Personal data (photos, IC numbers) should not share a database with customer orders |

The only seam is a nullable link from an employee to a login user, for the few
people who need both.

---

## The three things that are easy to get wrong

1. **Worked time is not the elapsed span.** A shift with an hour's lunch is 8
   hours worked, not 9. Overtime is measured on worked time — otherwise every
   long shift pays an extra hour.

2. **A business day comes from when a shift started.** A 22:00–02:00 shift is one
   day. Deriving it from the end time moves the tail into tomorrow and misfires
   overtime thresholds.

3. **At most one open time entry per employee**, enforced by the database, not
   only by application code. Two simultaneous taps pass an application-level
   check; the database does not.

---

## Stack

Same as the ordering system, so conventions, CI and the deployment guide carry
over:

- PHP 8.3, Laravel 13, MySQL 8.4
- Vue 3 + Pinia + Vue Router, Vite, Tailwind 4
- Pest (tests), Pint (style)
- Sanctum (console auth), device keys (display devices), punch sessions (PWA)
- cPanel shared hosting: **no Redis, no WebSockets, no worker processes**

---

## Local setup

```bash
# 1. Dependencies
composer install
npm install

# 2. Environment (already created; adjust DB_* for your machine)
php artisan key:generate

# 3. Create the database, then migrate
php artisan migrate --force

# 4. Seed the three outlets and an owner account
php artisan db:seed --force

# 5. Build frontend assets
npm run build
```

Run it:

```bash
php artisan serve        # http://127.0.0.1:8000
```

---

## Plan

| Phase | Delivers |
|---|---|
| 1 | Foundation: outlets, employees, PINs, roles with per-outlet scoping, QR generate/print/revoke |
| 2 | Punch: work/break segments, QR + PIN + photo, punch PWA, anomaly flags |
| 3 | Reports and corrections with audit — **deploy here** |
| 4 | Manager shift scheduling |
| 5 | Planned vs actual variance, adhoc tasks |
| 6 | Overtime, versioned rates, pay-period locking, payroll export |
| 7 | Offline queue, PDPA consent and retention |

Phase 3 is the deployment point: hours capture plus corrections is useful on its
own, and living with it for a few weeks reveals the real shift and pay rules far
better than guessing them up front.

---

## Before implementation

Five decisions are still open — see `docs/blueprint.md` §10. The two that change
what gets built first:

1. **Printed code lifetime** — permanent until revoked, or single-day reprint?
2. **Does a manager's rate change need owner approval?**
