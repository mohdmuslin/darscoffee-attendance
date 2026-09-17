# Single Sign-On with the Table Ordering System

How staff sign in once and reach both applications.

Companion documents: `architecture.md`, `blueprint.md`, `database-design.md`.

---

## 1. What was asked for

> "Have function for the manager to login, admin can create staff, manager — this
> will be synced with our earlier module, the table ordering. Single sign on."

So: one account, one sign-in, both apps. The owner also noted attendance will be
deployed **first**, and the ordering system is still pre-UAT.

Those two facts pull in opposite directions, and the tension is the whole design
problem. This document resolves it deliberately rather than by accident.

---

## 2. The tension

| Requirement | Consequence |
|---|---|
| Shared accounts and single sign-on | The two apps must share identity, so they are **coupled** |
| Attendance deploys first, standalone | The two apps must **not** depend on each other at deploy time |

A naive implementation satisfies the first and breaks the second: if Attendance
validates logins by calling the ordering system, then Attendance is down whenever
the ordering system is down — and Attendance cannot be deployed until the ordering
system is reachable. That defeats the entire reason for separating them.

> **The rule that resolves it: share identity, never availability.**
>
> Attendance owns a complete, working login of its own. The shared part is the
> *account record*, not a live dependency on the other application.

---

## 3. Options considered

| | Approach | Deploy independently? | Verdict |
|---|---|---|---|
| A | **Shared accounts table** (one database, two apps) | No — a shared database is the tightest coupling there is | Rejected |
| B | **OAuth2 / OIDC provider** (Laravel Passport or a small identity service) | Yes | **Chosen for later** |
| C | **Attendance calls the ordering system to validate logins** | **No** — Attendance breaks when ordering is down, and cannot deploy first | Rejected |
| D | **Attendance as the identity provider**, ordering redirects to it | Yes | **Chosen for now** |

Option D is option B with the roles assigned, and it is the direction of travel
anyway:

| Why D fits | Detail |
|---|---|
| Attendance deploys first | It already exists, so it becomes the issuer |
| No new moving part | No third service to stand up before Phase 3 |
| Honest about reality | There is one staff list, one owner, one business |
| Upgrades cleanly | When the ordering system needs it, the same trust relationship becomes a proper OIDC provider without changing the data model |

---

## 4. The design

### 4.1 Accounts live in Attendance

Attendance becomes the **identity source**. The ordering system will consume it.

```
                    ┌──────────────────────────────┐
                    │  ATTENDANCE  (identity source)│
                    │  users: owner, manager, staff │
                    └──────────────────────────────┘
                                 │
                    one-time signed token (60s)
                                 │
                                 ▼
                    ┌──────────────────────────────┐
                    │  ORDERING  (identity consumer)│
                    │  keeps a local mirror of users │
                    └──────────────────────────────┘
```

### 4.2 The account model

Attendance's `users` table gains a `staff` role, so all three kinds of account live
in one place:

| Role | Clocks in? | Console access | Notes |
|---|---|---|---|
| `owner` | no | Everything, all outlets | |
| `manager` | sometimes | Their outlet(s) only | May also be an employee |
| `staff` | yes | **Own hours only** | Was previously "employee only, no login" |

> **This is a change from the earlier decision.** Attendance originally held that
> most staff never log in. Single sign-on reverses that: if accounts are shared,
> every staff member needs one. That is a real simplification for identity and a
> real addition to scope — staff can now sign in and see their own timesheet, which
> is a feature they did not have before.

`employees` remains a **separate table**, and this is not redundancy:

| | `users` | `employees` |
|---|---|---|
| Meaning | Can sign in | Works shifts, gets paid |
| Has | email, password, role | employee_code, PIN, pay basis, photo |
| Needed for | access to screens | attendance records, payroll |
| Linked by | — | `employees.user_id` (nullable) |

A person may be one, the other, or both. This separation is what lets someone clock
in without an email address, and lets an owner exist who never clocks in.

### 4.3 The SSO flow

```
Ordering app                         Attendance (issuer)
     │                                        │
     │  user hits "Sign in"                   │
     │  redirect ─────────────────────────────►│
     │                           (already signed in? issue token
     │                            else show login, then issue)
     │◄──── redirect back with ?sso_token=… ──│
     │                                        │
     │  POST /sso/exchange { token }          │
     │  (server-to-server, shared secret) ───►│
     │◄──── signed claims:                    │
     │       { sub, email, name, role,         │
     │         outlets[], email_verified }     │
     │                                        │
     │  upsert local user, start session      │
     │  redirect to intended page             │
```

**How to implement it properly**

The token is a **one-time, short-lived, signed assertion** — not a session id and
not a password. Attendance issues it; the ordering system redeems it exactly once,
directly against Attendance (not via the browser, so it cannot be replayed by the
user).

| Property | Value | Why |
|---|---|---|
| Lifetime | 60 seconds | Long enough for a redirect, short enough to be useless if leaked |
| Reuse | **Single use** | Consumed atomically; a second redemption fails |
| Transport | Browser redirect, then server-to-server redemption | The browser never sees a credential it could reuse |
| Signing | HMAC with a shared secret | Both apps already run on the same host family, so no key infrastructure yet |
| Claims | Minimal: id, email, name, role, outlet ids | No secrets, no PINs, no pay data ever cross |

> **Why not just share the session cookie?** Different subdomains *can* share a
> cookie, and it is less code. But it would mean one application can forge a
> session for the other, and that logout is coupled — signing out of Attendance
> would sign you out of the till mid-order. The token exchange keeps them
> independent enough to fail separately.

### 4.4 The ordering side keeps a local mirror

The ordering system will store its own `users` rows, upserted from the claims:

- On SSO login: create or update the local row from the claims
- **Never** store that user's password — they have no password there
- Local `role` decides what they can do *in the ordering app*, not in Attendance

This mirrors how the ordering system already treats Loyverse: **the remote system
is the source of truth; the local copy exists so the app works without a live
call.** It is a pattern already proven in this codebase.

**Consequence to accept:** the ordering app's copy can go stale — a user disabled
in Attendance still has a local row. Handling: re-check on each SSO login, and
treat a disabled claim as a refusal.

### 4.5 Attendance must not require the ordering system

Critical, and easy to get wrong:

| Attendance's behaviour | Required |
|---|---|
| Login | **Local only.** No call to the ordering system, ever. |
| Clock in/out | **Local only.** Staff clock in at 07:00 regardless. |
| Runs if ordering is down | **Yes** — must be fully functional |
| Runs if the ordering repo is unconfigured | **Yes** — SSO is additive |

So in Attendance, SSO is an **outbound** concern only: it can *issue* tokens for the
ordering system to consume. It never depends on the ordering system being there.

---

## 5. Deployment order and why it matters

```
1. Attendance deployed          ← its own logins work. SSO not yet needed.
2. Attendance stable (Phase 3)
3. Ordering system UAT passes
4. Add SSO consumption to ordering  ← Attendance as issuer
5. Retire the ordering system's own login for staff
```

Step 4 is deliberately late. Until the ordering system consumes SSO, neither app
depends on the other, which is what makes deploying Attendance first safe.

---

## 6. Security notes

| Concern | Control |
|---|---|
| Token replay | Single-use, consumed atomically, 60s TTL |
| Token forgery | HMAC-SHA256 with a shared secret, compared with `hash_equals` |
| Interception | HTTPS on both ends; the token travels in a `POST` body, never a query string on the redemption call |
| Open redirect | The `return_to` is validated against an allow-list of known hosts |
| Privilege inflation | The ordering app decides its own permissions. Claims say *who*, not *what they may do there*. |
| Disabled user bypass | The `is_active` claim is checked at every exchange, not just at first login |
| Clock skew | Claims carry `iat`/`exp`; a minute of tolerance, as with punch timestamps |

**Never in a claim:** passwords, PIN hashes, IC numbers, pay rates, or photos. The
ordering system has no business knowing what anyone is paid.

---

## 7. What this changes in the build

| Change | Where |
|---|---|
| Add `staff` to `UserRole` | `app/Enums/UserRole.php` |
| `users` becomes the identity source, so expose a stable `sub` | `users.id` |
| Add an SSO token table (single-use, hashed, with expiry) | new migration |
| A token-issuing service | `app/Services/SsoTokenService.php` |
| An exchange endpoint for the ordering system to redeem | `routes/api.php` |
| A `staff` console scope: own hours only | `ScopesToOutlets` + policies |

**Not in scope for Phase 1:** building the ordering system's half. That comes at
step 4 above. What Phase 1 must get right is the *shape* — the account model, the
role, and the token table — so the other half can be added without a migration to
existing accounts.

---

## 8. Fallback if the ordering system's login is replaced later

The current ordering system authenticates with its own email + password and a
Sanctum token (`dars.staff_token`). When SSO lands, staff accounts there become
SSO-managed and the password column goes unused for them.

The owner keeps a password in both, deliberately. If SSO is broken — a bad secret, a
misconfigured redirect — the owner can still sign in directly and fix it. **A
single point of failure that locks the owner out of everything is worse than a
little duplication.**
