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
| `docs/deployment.md` | **Go-live guide** — cPanel, cron, the proxy trap, post-deploy checks |
| `docs/hardening.md` | Retention, consent, the offline queue, backup drill, load check |
| `docs/sso.md` | Why Attendance is the identity source and deploys first |

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

## Decisions settled

| Question | Decision |
|---|---|
| Printed code lifetime | **No expiry** — valid until reprinted. Reprinting is ad hoc, when the manager wants, and is the revoke mechanism. |
| Manager rate changes | **No owner approval.** The audit record (mandatory reason, previous value kept) is the control. |
| Payroll export | Record-keeping for now; shape agreed in Phase 6 |
| Photo retention | 90 days, configurable |

> **Printed codes carry a known trade-off.** They identify the outlet, not the
> person's presence, so a photographed sheet works until someone reprints. The
> controls that carry the load are the punch photo, the shift window, the anomaly
> queue, and reprinting — which is why **reprinting must stay a one-click action**.

See `docs/blueprint.md` §10 for the reasoning.

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
