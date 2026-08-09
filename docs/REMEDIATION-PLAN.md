# Remediation plan

The consolidated plan from every analysis pass run against this system: the ten-agent audit
(screens, roles, navigation, geography, functionality inventory, ERP conventions), the
adversarially-reviewed role-catalogue design, the specification sweep and its check pass, and
five of eight end-to-end journey walks. Everything here cites work that was verified against
the code, not inferred from it.

**Standing constraint, set by the client: no functionality may be lost.** Every item that
moves or removes something says where it goes.

---

## Already done (not in this plan)

Six shipped defects fixed and regression-locked (rule-violation 500s, unrecordable quality
tests, four-line-only sales, centre-detail gate, unreachable consignment adjustments, the
ungraded-consignment dead end). Specification text stripped from every operational screen and
enforced by test. Self-service leave/payslip scope bug. Journey batch 1: sales-officer
customer scope, delivery-detail crash, lockout exits, activation flow, modal state
preservation. 187 tests green.

---

## Phase A — Journey fixes, batch 2 — **COMPLETE**

All ten items landed, plus one defect found while doing them (see below). 198 tests
green; demo reseeded and every screen walked.

**A seventh shipped defect, found by A5's test:** `Wat::of()` and `Wat::instant()`
read a naive datetime string as UTC, but every `datetime-local` field in the system
posts a WAT wall-clock reading. Every time entered through the interface was stored
an hour late — and judged against the collection cut-off an hour late, so milk that
arrived at 06:05 against an 07:00 cut-off was flagged as needing a supervisor
override. This is the input-side twin of the ARCH-9 output bug fixed earlier; both
halves are now covered by tests.

### Original scope, for the record

## Phase A — Journey fixes, batch 2 *(small–medium · ~3–4 days)*

The rest of the verified journey breaks. Each is one screen or one service; none blocks the
phases below, so this lands first for daily-user impact.

| # | Fix | Where the evidence came from |
| --- | --- | --- |
| A1 | Post credit sales to the cooperative ledger (`CooperativeEntry` debit + balance), and show the running balance beside the cooperative picker before more credit is extended | shop walk — credit sales currently touch no ledger |
| A2 | Expiry cutoff in stock rotation: never dispense an expired batch; refuse the sale honestly when only expired stock remains; add an "expiring within 30 days" list on inventory | shop walk — FEFO currently serves *expired first, silently* |
| A3 | Sale detail page (lines, payment, officer, linked deduction) reachable from the receipt number; then a void/return action on it, manager-gated, reversing stock and any pending deduction, audited | shop walk — receipt lives only in a vanishing flash; wrong sales are permanent |
| A4 | Selling price in the product picker's option label; small running total beside "Amount received" | shop walk — officer commits a sale before ever seeing the total |
| A5 | "Save & add another" on the delivery form: redirect back to the open modal with point pre-selected; stop redirecting to the detail page after every save | agent walk — ~180 extra navigations per 60-delivery morning |
| A6 | Low-stock notification fires only when the balance *crosses* the threshold, not on every sale while low | shop walk — one product low = a notification per sale |
| A7 | DENY-#### trail: render the denied user's captured roles in the audit-log row, link the actor to their user page | admin walk — remediation is reconstructed by hand |
| A8 | Admin per-row revoke for a user's trusted devices and open sessions ("stolen phone" lever); reuses `Device::revoke` / `SessionRegistry::endAllFor` | admin walk — AUTH-2 promises it; only the self-service half exists |
| A9 | "Pending activation" badge on user list/detail (`password_changed_at` null + active), with the code's expiry; show *Resend activation* only in that state | admin walk — a stuck new hire is indistinguishable from a quiet one |
| A10 | Apply the `_modal` reopen pattern to the remaining create/edit modals (requisition, leave, batch dispatch, receive-stock, confirm form) | agent walk — pattern built in batch 1; two forms covered so far |

Definition of done: each fix has a browser-path test that replays the journey, not a service
call. Full suite green.

---

## Phase B — Role & permission reshape *(large · ~1–1.5 weeks)* — **mostly delivered**

**Delivered (263/263 tests green):**

| Item | State |
|---|---|
| 1. Multi-target scope | **Done.** Every targeted scope type takes a list, read as a union of `role_user.scope_target_id` and `role_user_scope_targets` — no data migration, no existing assignment changed meaning. Admin UI takes several targets; validation refuses targets from the wrong table. Scope probes generalised to all five scope types and now use `whereNotIn` over the whole target set. |
| 2. Catalogue | **Done.** 20 active substantive roles (added Quality Officer, Board, External Audit); 20 operational `delete` permissions retired via `retired_at` and stripped from live roles (91 live / 31 retired, 0 retired grants on live roles). Workflow stages verified rather than repointed — none pointed at a retired role. Guard test asserts every active stage has a live, held, correctly-permissioned approver. |
| 3. Enforcement in services | **Partly done.** `guardGrading` now uses scoped `Access::authorize` instead of unscoped `hasPermission` — an officer scoped to one centre could previously grade at another. Re-grade break shipped: `milk.grade.edit` held by Quality Officer + Supervisor only, mandatory reason, `/milk-flow/regrades` exceptions list. *Remaining: the other newly-wired permissions.* |
| 4. Co-holding rules | **Done.** Three incompatible pairs refused in `UserAdminService::assignRole`, symmetric, with the reason in the message. |
| 5. Approvals gate | **Done.** `/approvals` admits any workflow-stage approver, derived from `workflow_stages` so a new stage cannot lock out its own approver. Nav link and API route use the same rule. HR Manager can now reach their own queue. |
| 6. Self-assignment guard | **Done.** An administrator granting themselves a role is audited as such and announced to Internal Audit and the General Manager. `valid_until` added to assignments and enforced in both resolution paths, so External Audit access ends with the engagement. |
| 7. Landing screens | **Not started** — folded into Phase D. |
| 8. Permission test run per role | **Not started.** |

Also fixed in passing: `db:seed` was not repeatable (a date-format mismatch made the grade-rate lookup always miss, and `DemoDataSeeder` died on a unique constraint instead of saying it had already run).

**Remaining in this phase:** wire the rest of the 38 dead permissions (the `edit`/`view`/`create` ones listed below), delivery capture-on-behalf attribution, counter-confirmation queue, and item 8.

### Original specification



Implements the settled specification that survived the three-way adversarial review (external
auditor / lost-capability / centre-manager lenses). The design decisions are recorded in the
workflow output and are **already resolved** — do not relitigate them during implementation:

- Clerk keeps `milk.grade.create` (blocking the morning is worse than the fraud it prevents);
  the **break is on re-grading**: new-wired `milk.grade.edit` held by Quality Officer and
  Centre Supervisor only, with every re-grade audited onto a weekly exceptions list.
- Counter-confirmation above tolerance is a **queue, not a gate**: milk moves, money waits —
  enforced at release, measured on gross positive adjustment plus a rolling 7-day point total
  so it cannot be evaded by splitting.
- Delivery capture at the centre is **permitted and attributed** (`captured_on_behalf_of_user_id`
  + mandatory reason + daily exceptions list), not prohibited into password-sharing.
- Board and External Audit are **separate read-only roles**; Board never sees payroll or the
  employee register and cannot bulk-export; External Audit carries `valid_until` expiry.
- `milk.reconciliation.approve` gets a **named second holder** (General Manager) and WF-003 /
  WF-005 get `allow_delegation => true` — one name on the lever that pays 1,842 farmers is a
  hostage, not a control.

Work items, in dependency order:

1. **Multi-target scope completion.** `role_user_scope_targets` already exists; finish the
   engine per the settled spec: fail-closed `DataScope::constrain()` for every scope type,
   `whereNotIn` scope probes in the permission test runner, admin UI to assign several named
   centres/points. Existing single-target assignments continue unchanged.
2. **Catalogue migration.** 22 roles (20 active substantive + automatic + draft); 16
   permissions retired via `retired_at` (never deleted — historical grants must resolve); 26
   wired up where the capability existed unguarded; 6 added. **Repoint the five live workflow
   stages in the same migration** — stages bind to `approving_role_id`, and retiring Accounts /
   ED / Milk Collection Supervisor without repointing silently freezes every requisition and
   the whole payroll. Guard test asserts every active stage's role is active and held.
3. **Enforcement in services, not controllers.** The newly wired checks go into
   `ConsignmentService` / `DeliveryService` choke points so the API cannot bypass what the web
   enforces; replace the unscoped `hasPermission('milk.grade.create')` in `guardGrading` with
   scoped `Access::authorize`. One API-path test per newly wired permission.
4. **Co-holding rules.** Hard incompatibility pairs (recording role + Milk Operations Manager;
   Clerk + Quality Officer) checked in `UserAdminService::assignRole`, asserted in tests —
   headcounts drift, rules do not. Also fixes the `staff1` demo account, which currently holds
   Collection Agent + Logistics Officer + System Administrator un-flagged.
5. **Approvals gate.** `/approvals` admits any workflow-stage approver (HR Manager is named on
   leave and payroll stages today and cannot open the queue).
6. **Self-assignment guard** on role grants; real-time notification to Internal Audit + GM when
   an administrator grants themselves an operational role.
7. **Landing screens** per role (data feeds Phase C).
8. **Permission test run per reshaped role** (TEST-5) before any of it is called done.

Risks: retiring permissions changes the runner's expected-set arithmetic (retire via
`retired_at`, re-run `PermissionTestingProtocolTest`); every reshaped role must have a holder
in the demo seed or `/admin/personas` becomes a list of vacancies again.

---

## Phase C — Adamawa reseed *(large · ~4–5 days · after B)*

Implements the re-derived dataset (client chose re-derivation over relabelling). Runs after B
because the demo staff must be seeded into the *new* catalogue once, not twice.

1. **Reference geography:** all 21 Adamawa LGAs; six centres — Girei (~15 km to the Yola
   factory), Fufore (~40), Numan (~80), Song (~90), Gombi (~130), Mubi North (~200); 42 points
   from real ward/settlement names; 26 Fulbe communities (*Wuro*/*Ruga* naming); transport
   tariffs that reflect the distance spread.
2. **Test fixtures move in the same commit** — `GondalTestCase::makeMilkWorld()` feeds 42 call
   sites and currently binds to Kano LGA names by string; changing seeder and fixtures
   separately leaves the suite red in between.
3. **Demo dataset, re-derived bottom-up:** 1,842 registered farmers, ~622 presenting on the
   seeded wet-season morning (~34% participation), household volumes inside the 5–40 L band;
   the chain reconciles to the litre at every level; **one batch lands just inside tolerance
   and one just outside** (the old fixture only exercised BR-11 in one direction); one
   traceable delivery carrying rejection + negative adjustment + grade + levy + savings
   deduction; a consignment rejected at centre and litres rejected at factory.
4. **Staff renamed** to Adamawa Fulbe names; Adamawa vehicle plates and routes.
5. **Acceptance assertions and the seeder's reconciliation report rewritten** to the new
   targets; PRD deviation documented in `docs/` (the PRD's §17 stays Kano — the deviation note
   says why).
6. Full reseed + reconciliation readout + screen walk before the phase closes.

Risk: `DemoDataSeeder` is not executed by any test, so its half of the geography can drift
silently — the reconciliation report must be read line-by-line at the end of every commit that
touches it.

---

## Phase D — Navigation, dashboard, landing *(medium · ~3 days · after B)*

1. Sidebar ordered by daily frequency, not build phase; nav entries for the four orphaned
   screens (Consignments, Batches, Sales, Product Categories); remaining gate/route mismatches.
2. Dashboard leads with **work**: "awaiting you" queue (approvals, confirmations, follow-ups)
   and today's collections above the stat tiles; every existing card stays, reordered.
3. Role landing redirects from `/` using Phase B's landing map, plus a "start me on" override
   in Profile. Dashboard keeps its own route ("Overview") so nothing disappears.
4. Notification `actionUrl`s deep-link to the filtered queue they announce, not to unfiltered
   lists or `/profile`.
5. First-run card for new users naming their role and landing screen (persona text already
   exists in `PersonaController`).

---

## Phase E — Declutter *(large · ~1 week · after D)*

The structural half of the client's original complaint. The copy half is done.

1. **Consignment detail page** — the 11-column list exists because there is nowhere else for
   any consignment fact to live; the new page absorbs the columns the list sheds (list keeps:
   reference, point→centre, litres, status, grade, batch, actions).
2. **Real tabs** (`?tab=`) on the three longest detail pages: collection centre
   (Overview / Consignments / Batches), user (Profile / Roles / Sessions & Devices),
   requisition (Items / Approval / Discussion). Content moves one click away, never removed.
3. **Settings split into real sections** — the current page renders 18 zones and 15 modals,
   and one `<form>` spans the milk, cooperative and shop panels, so saving one submits the
   others. Controller change first: `update()` accepts a partial payload keyed by section
   (keeping the milk cut-off cross-field rule inside one section), then one screen per section
   with the tabs as links. The workflows tab already works this way and is the template.
4. **Every figure rendered once, where it is owned.** Stat tiles that restate the table below
   them go; the duplicates that *disagree* (two different "deliveries" counts on one screen,
   an Employees tile that ignores the active filter) are corrected, not just deduplicated.
5. **Table diet:** deliveries 10 → 7 columns, requisitions 9 → 7, employees 9 → 7 — shed
   columns land on the detail views. Roles matrix stays (it is the content there).
6. **Mobile:** stacked-card reflow (`td::before content: attr(data-label)`) for the three
   field-facing lists (deliveries, collection points, farmers) under 640 px.
7. Empty states gain their action ("Record the first delivery"); per-page selector (25/50/100 —
   server already honours it); unread-only toggle on notifications; one `Number` helper
   replacing ten copy-pasted `rtrim` chains.

---

## Phase F — ERP affordances *(medium–large · ~1 week · parallel with E after D)*

The client approved export/print for the lists that matter.

1. **CSV export** honouring current filters + scope on deliveries, audit log, payslips, sales —
   swap `paginate()` for a cursor behind `streamDownload`, gated on the permission the screen
   already requires.
2. **Print:** payslip (drop nothing but the chrome — print CSS exists), batch note for the
   driver, **farmer delivery receipt** (the best fraud control in the whole plan: a farmer who
   can read their own litres and rejection is a better auditor than any staff account), and the
   shop sale receipt anchored on A3's sale detail page.
3. **Record history panel** — one `partials.record-history` fed by the existing
   `AuditEntry::forSubject()`, dropped onto the six busiest detail pages.
4. **Attachments** — the model is fully built and rendered nowhere; one upload route (private
   disk), one guarded download route, one partial on requisitions (the ₦3.4 m quotation
   problem), leave requests, and field activities.
5. **Exceptions reviews** from Phase B's design: the weekly adjustment/re-grade/on-behalf/
   override report for centre supervisors and Internal Audit.

---

## Phase G — Verification and the unfinished analysis *(small · ~2 days · last)*

1. **Walk the three journeys the spend limit killed** — requisitions, HR, community/extension —
   one workflow resume when agent capacity returns; the five finished walks replay from cache.
   Fold any new finds into a batch-3 list.
2. Browser-path tests extended to every remaining create/edit form (the suite's blind spot that
   hid five of the six defects).
3. Full suite against **PostgreSQL** as well as SQLite (the silent-unknown-column hazard is
   documented; CI should run both).
4. Fresh reseed → reconciliation readout → 51-screen walk → `gondal:rule-index --check` →
   permission-test run per role → README/docs updated.

---

## Sequencing summary

```
A (journey batch 2)
B (roles) ──────────► C (Adamawa)
   └──────► D (nav/dashboard) ──► E (declutter)
                                └► F (affordances)   E ∥ F
A..F ─────────────────────────────────────────────► G (verify)
```

Total: roughly **4–5 weeks** solo; materially less with agent capacity for E and F.

## Decisions still open (not scheduled — need the client)

| Decision | What unblocks |
| --- | --- |
| §15.1 payments placement | Farmer/transport payment runs; settling `pending_farmer_deductions`; ~2–3 weeks once decided |
| SMS gateway provider + budget | Farmer receipt by SMS; real 2FA delivery; needs Termii / Africa's Talking choice |
| Sanctum API tokens | Any mobile/offline client — the API currently has only the web session guard |
| Offline capture client | A real project on top of the existing idempotent API; scope separately |
| Hausa/Fulfulde interface | Mechanical once Phase E stabilises the copy |
| Global search | Recommended, unscheduled |
| Vendor → PO → GRN chain | §15.5 exclusion; shop stock currently appears from nowhere |
