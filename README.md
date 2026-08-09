# Gondal ERP

The backend for Gondal Fulbe Dairy's milk collection, purchasing, community engagement, one-stop shop
and HR operations, built to [`PRD.md`](../PRD.md) against the 50-screen prototype in
[`frontend ui components/`](../frontend%20ui%20components).

Laravel 13 · PHP 8.4 · PostgreSQL · server-rendered Blade (ARCH-3 — no SPA in v1).

---

## Getting started

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

Point `DB_*` at a database, then:

```bash
php artisan migrate --seed
```

The last lines of the seed output are the bootstrap administrator's email and a random password, printed
once and stored nowhere. AUTH-8 means there is no self-registration, so this is the only way in; AUTH-5
then forces a password change on first sign-in, which is how BR-31 ("administrators never see or set a
user's password") survives having to create a first account at all.

Run it:

```bash
php artisan serve
```

There is no front-end build step. The prototype's stylesheet is served as-is from
`public/css/styles.css`, because §4 treats the prototype's markup as the contract — running it through a
bundler would be a chance to change it silently. Vite and Tailwind are installed for whenever that stops
being true; `npm install && npm run build` is not needed to run the application today.

### The demo dataset

NFR-12 puts §17's dataset behind a flag so it can never reach production by accident:

```bash
GONDAL_SEED_DEMO_DATA=true php artisan migrate:fresh --seed
```

The seeder finishes by printing a reconciliation report against every figure §17 requires — 12,480 L
confirmed, 514 deliveries, 142 L of rejections, Kumbotso's 3,444 → 3,400 L, `DEL-0009`'s ₦6,615 net,
`BATCH-0087`, `REQ-2026-0142` at stage 3 of 6. None of those numbers is asserted; they are derived by
running the real services, so if a rule changes the report stops reconciling.

Demo accounts sign in with `GondalDemo!2026` — for example `sadiq.ahmed@gondalfulbe.ng` (System
Administrator), `halima.yusuf@gondalfulbe.ng` (Milk Collection Officer), `sani.bello@gondalfulbe.ng`
(Collection Agent), `mohammed.aliyu@gondalfulbe.ng` (Executive Director). `/admin/personas` lists all 14
personas with the account that plays each one.

### Signing in locally

AUTH-1 puts a 6-digit emailed code in front of every account, and NOTIF-5 queues the send. With
`MAIL_MAILER=log` the code reaches `storage/logs/laravel.log` **only once a worker picks the job up**, so
local `.env` wants:

```
QUEUE_CONNECTION=sync
```

(or run `php artisan queue:work` alongside the server, which is closer to production). Without one of the
two, the sign-in screen keeps asking for a code that was never delivered.

Read the latest code out of the log with:

```bash
grep -oE '^\*\*[0-9]{6}\*\*$' storage/logs/laravel.log | tail -1 | tr -d '*'
```

The code is stored only as a SHA-256 hash (AUTH-3), so the log is the one place it exists in the clear —
which is also why `MAIL_MAILER=log` belongs nowhere but a developer's machine.

AUTH-4 then remembers the browser for 30 days if you tick "trust this device", so the code is a
once-a-month interruption rather than a per-sign-in one. `/profile` lists trusted devices and revokes
them.

### Databases

ARCH-1 names PostgreSQL, and that is what `.env.example` targets. SQLite works for local development and
is what the test suite uses, with two differences worth knowing:

- **DECIMAL is not exact.** SQLite gives a `decimal(10,2)` column NUMERIC affinity, i.e. a float. DM-1's
  `litres_accepted = litres_presented − litres_rejected` check is therefore written as
  `round(difference, 2) = 0` on SQLite and as a plain equality on MySQL/PostgreSQL. See
  `2026_01_01_001000_create_milk_flow_tables.php`.
- **A misspelt column silently matches nothing.** SQLite falls back to treating an unknown double-quoted
  identifier as a string literal, so `where('no_such_column', true)` returns zero rows instead of raising.
  PostgreSQL raises. Run the suite against PostgreSQL before a release; a green SQLite run can hide a typo
  that is fatal in production.

---

## How it is put together

| Path | What lives there |
| --- | --- |
| `app/Authorization/` | The two-layer access model (ARCH-4): `Access`, `ScopeSet`, `ScopeType`, `Denials`, and the `DataScope` global scope |
| `app/Policies/` | One policy per scopeable model, all extending `BasePolicy` |
| `app/Services/` | Every business rule. Controllers validate and delegate; nothing decides in a controller |
| `app/Support/` | `Money` (kobo), `Volume` (centilitres), `Wat` (UTC versus West Africa Time), `Settings`, `Sequences`, `Navigation` |
| `database/migrations/` | 43 migrations — §6's schema plus the columns needed to keep §9 out of code |
| `database/seeders/` | 97 live permissions, 22 active roles (plus Farm Manager as draft and the two retired ones), §9's reference data, 6 workflows, the bootstrap admin, and the demo dataset |
| `resources/views/` | 70 Blade views mirroring the prototype's markup |
| `app/Http/Controllers/Api/Mobile/` | The `/api/v1` surface the AgentConnect field app talks to (ARCH-2) |
| `tests/Feature/Rules/` | One class per rule family; `tests/Feature/Acceptance/` holds §14's phase criteria |
| `docs/RULE-INDEX.md` | Every numbered requirement and the test that proves it |
| `docs/API-MOBILE.md` | The mobile contract: sign-in, the persona payload, and the offline sync batch |
| `app/Services/Reporting/` | `DashboardMetrics` (today's tiles) and `PeriodReports` (§15.5's reporting layer, with CSV export) |
| `app/Http/Controllers/Admin/ReferenceDataController.php` | §9's registers, edited through one registry-driven screen rather than six controllers |
| `app/Http/Controllers/Milk/FleetController.php` | Routes, vehicles and riders — the register `/logistics` reads and nothing could fill |

A few decisions are load-bearing enough to state here.

**Authorisation is two questions, not one.** ARCH-4 splits "may this role do X?" from "may this user do X
to *this* record?", and ARCH-5 notes that no package does the second one, so it is hand-built. A
permission gate runs in middleware; a data scope runs as an Eloquent global scope on lists and as a policy
check on single records. The scope that applies is the one attached to *the permission being exercised* —
not to the model — because a Collection Agent creating a delivery is scoped by `milk.deliveries.create`,
and looking the scope up from the `CollectionPoint` would ask about `milk.points.create` and deny them
their own job. An out-of-scope id resolves to a populated 403 rather than a 404, so the user has a
reference to quote to their administrator (AUDIT-5).

**Money and volume are integers.** ARCH-6 and NFR-5: money is kobo, volume is centilitres, and every
computation goes through `Money` or `Volume`. Percentage comparisons against a tolerance use
`Volume::exceedsPercentage()`, which compares the exact ratio — rounding first would let a 1.004% variance
read as 1.00% and slip past BR-11's 1% tolerance.

**Time is stored in UTC and shown in WAT.** ARCH-9. `Wat::now()`, `Wat::instant()` and `Wat::todayAt()`
produce instants safe to persist; `Wat::local()`, `Wat::today()` and the formatters produce wall-clock
values for the screen and for BR-3's cut-off. The distinction is not cosmetic: Eloquent formats a datetime
using the Carbon's *own* timezone, so a WAT-flavoured Carbon is written to the column verbatim and read
back an hour wrong.

**Nothing from §9 is an enum, a constant or a config value.** Grades, rejection reasons, adjustment
reasons, discrepancy causes, quality tests, leave types, tariffs, thresholds and the cut-off are all rows.
Rules find them by their meaning — the cut-off breach reason by `is_cutoff_breach`, the unpayable grade by
`is_rejection`, a follow-up reason by having both a threshold and a window — so renaming or re-coding a
row changes nothing, and `InfrastructureRulesTest::test_no_reference_data_is_hardcoded` fails the build if
a seeded value reappears as a literal under `app/`.

**The audit log cannot be edited.** DM-3 is enforced by database triggers as well as by model guards, so
even a direct `UPDATE` fails.

---

## Tests

```bash
php artisan test
```

163 tests, 1,233 assertions. They run against a real seeded database — the actual permission catalogue,
the actual 22 active roles, the actual reference data — because a permission test against invented fixtures
proves nothing about the system anyone will use.

Each test names the rule it proves, and the mapping is generated rather than claimed:

```bash
php artisan gondal:rule-index
```

writes [`docs/RULE-INDEX.md`](docs/RULE-INDEX.md), and

```bash
php artisan gondal:rule-index --check
```

exits non-zero if any of the PRD's 83 numbered requirements has no referencing test. All 83 are covered
today; wire the `--check` form into CI and §18.2 stays true rather than becoming true once.

Two production defects were found by writing these tests rather than by reading the code: every stored
timestamp was an hour ahead (ARCH-9), and the data scope for an action was being looked up from the model
instead of from the permission, which denied Collection Agents their core task.

---

## Open decisions

§15's open questions are **surfaced, not guessed**. Where a screen would consume a decision nobody has
made, it says so on the screen instead of inventing an answer:

| Decision | Where it shows | Consequence today |
| --- | --- | --- |
| §15.1 Where the payment module lives | `/payroll`, `/logistics`, farmer detail | Phase 7 is not built. `trips.payment_run_id` exists with no foreign key; `pending_farmer_deductions` accumulates and is never settled |
| §15.2 What a Farm Manager does | `/admin/roles`, `/admin/personas` | Seeded as a draft role with zero permissions; assigning it is refused |
| §15.3 Cooperative forms | `/cooperatives` | §6.6's schema is built; extend when the forms arrive |
| §15.4 One-Stop Shop detail | shop migrations | Built to §6.7; the shape is deliberately extensible |

[`docs/OPEN-DECISIONS.md`](docs/OPEN-DECISIONS.md) states each question in the form it needs to be
answered in, and what changes once it is.

BR-13 to BR-16 mean §15.1 costs little to defer: every consignment snapshots the grade rate that applied
at confirmation, so the data a payment run will need is already captured correctly wherever the module
ends up.

---

## Deliberate deviations

Three places where this build does not match a number in the PRD, each on purpose:

- **39 active staff holding 105 role assignments**, against §16's 38 and 103. §16 summarises the
  prototype's demo data, while §5 and §16's own persona table require all 19 roles to be *held* — the
  extra account and assignments exist so every persona has a holder and `/admin/personas` is not a list of
  vacancies. The three test accounts and four deactivated accounts match §16 exactly.
- **97 live permissions**, against the prototype's demo figure of 96, plus 31 retired ones kept so
  PERM-3's retirement path is visible rather than hypothetical. The catalogue is generated from §5.1's
  matrix and §4's screen requirements; the prototype's figure predates several screens it also ships.
- **Payroll runs 42 employees**, not 39. Employee records and user accounts are separate things (USER-1),
  and BR-35 excludes only accounts flagged `is_test`.

---

## Not built

Still out of v1: vendor registry, purchase orders and goods-received notes (§15.5 — they need their own
specification, not a guess); role-specific dashboards; attendance; recruitment applicants; global search;
the supervisor "my team" view; cooperative loans and investments (NG-1); the project module (NG-2).

**Reporting and analytics are now built** — `/reports` aggregates over a user-chosen span of WAT days and
exports CSV, each report gated on the permission that governs its data and narrowed by SCOPE-4. What
remains unspecified is which MANAGEMENT reports the business wants (NG-7); adding one is a method and a
catalogue entry.

NG-3 deferred mobile applications; ARCH-2 and ARCH-7 were honoured anyway so that it stayed possible
without rework. It did. The AgentConnect field app (`agents_app/`) now signs in against `/api/v1` with the
`api` token guard added to `config/auth.php`, and drains its offline queue through `POST /api/v1/sync/batch`
— reaching the same services, the same two authorisation layers and the same audit trail as the web
screens, with no controller or service above it changed. See [`docs/API-MOBILE.md`](docs/API-MOBILE.md).
What the mobile client does NOT have is a screen for work that is not field capture: consignment
confirmation, grading, batch dispatch, reconciliation and approvals stay on the web, and the app says so
on the home screen of a user who holds them rather than pretending their role has nothing to do.

Phase 7 (payments) is blocked on §15.1 and is documented as blocked in
`PhaseAcceptanceTest::test_phase7_payments_is_blocked_but_its_data_is_captured` rather than quietly skipped.
