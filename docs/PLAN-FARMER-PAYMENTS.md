# Plan — paying farmers for their milk (Phase 7)

> **Status: increments 1–6 are built, plus the six accounting gaps found after
> them.** Valuation, payment runs with approval, disbursement, reversal with
> capped clawback, the printed farmer statement and per-farmer payout details
> all work end to end. Since then: transport payment runs for riders and
> drivers, cooperative-ledger posting of farmer deductions, the cash book,
> five finance reports, cost per litre, and requisition spend against a
> department. 514/514 suite green.
>
> Built under the recommendations in §1.1 (pooled grading, instrumented), §1.2
> (cash at the point, channel as a column) and §1.6 (debt recovery capped at
> 50% of gross, a settings row) — all three still reversible, and all three
> still yours to confirm.
>
> Not built: per-farmer savings accounts (7). The savings pool is now a real
> account with real entries, so the money is somewhere; what a member still
> cannot be told is what THEIR share of it is. Also not modelled: what the
> factory pays for a litre, which is why the cost report is a cost and not a
> margin.

*Written when nothing in this system paid a farmer.* The data a payment needed
was captured almost completely; what was missing was the arithmetic, the run, the
approval and the record of money actually changing hands. The rest of this
document is the plan as argued, kept as written so the reasoning behind what was
built stays readable — the status block above is what is true now.

This plan is the result of three independently-argued designs — a ledger-first
one, a payroll-twin one, and a deliberately minimal one — scored by three judges
on correctness, field reality, and fit against this codebase. The minimal one
won two of the three lenses and is the base here; the sections marked *grafted*
take a specific idea from one of the others.

---

## Part 1 — Decisions that are yours, not the code's

Six. The first two block the first migration. The rest can be decided during
build but will be decided *by default* if nobody decides them, which is how a
system ends up enforcing a policy nobody agreed to.

### 1.1 The apportionment rule — **blocks everything**

A consignment pools many farmers' deliveries from one point and carries **one**
grade and **one** rate. A farmer's own milk is never graded.

| Option | What it means | Cost |
| --- | --- | --- |
| **A. Pooled grade** *(recommended)* | Every farmer in a consignment is paid at that consignment's grade | A farmer with clean milk is paid at the grade of the worst milk in the can. On a 12 L morning with a ₦35/L A→B gap, that is ₦420 |
| **B. Individual grading** | Each farmer's milk tested at intake | Needs a lactometer, reagents and training at *every point*, and adds minutes per farmer to a queue at 06:00 |
| **C. Pooled, with a quality bonus** | Pay pooled, then pay a periodic bonus to points whose consignments grade A consistently | Rewards the point, not the farmer — blunt, but cheap |

**Recommend A, and instrument it.** B is the just answer and the cooperative
almost certainly cannot fund it at fourteen points. What A must not do is hide
itself: every payment line should record the consignment and grade it was priced
at, so "why was I paid B rates?" has an answer, and so the cost of pooling is
*measurable* when someone later asks whether to fund B.

Be clear-eyed that this is the single most consequential decision here. It is
the reason a careful farmer starts selling to a middleman, and no schema choice
recovers from it.

### 1.2 The disbursement channel — **blocks the disbursement table**

**`farmers` has no payout details at all** — no bank account, no mobile-money
number. Only `phone`. Whatever is chosen needs new columns.

| Option | Reality in Adamawa | Evidence the system can keep |
| --- | --- | --- |
| **Cash at the collection point** *(recommended for v1)* | Works for everyone, needs no bank | Signed/thumbprinted sheet, payer's user id, timestamp, optional photo + GPS |
| Bank transfer | Many smallholders have no account | Bank reference, hand-keyed from a statement |
| Mobile money | Coverage is real but uneven; needs a phone in the farmer's name | Transaction reference |
| Via the cooperative | Network pays the cooperative; treasurer pays members | Two legs — and the second leg is outside the system |

**Recommend cash at the point for v1, with the channel modelled as a column from
day one** so bank and MoMo are additive later. Support "via cooperative" only if
the business insists, and if so record it as **two legs** so an unreconciled
second leg is visible as an ageing balance rather than invisible.

> The judges were unanimous that this is the largest fraud surface in the whole
> ERP, and that software mitigates rather than closes it. See §7.

### 1.3 Who may disburse

If the Collection Agent both records deliveries and hands out cash, the same
person produces both sides of the reconciliation. **Recommend a separate payer**
— the Collection Officer or a named Payments Officer. This costs headcount and
the business may refuse; if it does, that refusal should be written down.

### 1.4 The levy base

Is the 2% levy taken on **gross**, or on **gross less savings**? Payroll does
the sequential thing, which is why it is tempting. Nobody has confirmed the
cooperative's bye-laws. On ₦100,000 gross the difference is ₦100. Small per
farmer, real across a network, and an assumption sitting in the middle of the
money. **Ask; do not infer.**

### 1.5 Who absorbs milk rejected at the centre

A consignment can be part-rejected *after* it leaves the point (`litres_rejected_at_center`).
Options: the whole point shares it pro-rata, or it is traced to a farmer where
possible. **Recommend pro-rata** — tracing is usually impossible once cans are
pooled — but say so out loud, because it means a farmer pays for someone else's
spoilage twice over.

### 1.6 A debt-recovery cap

A farmer with a large shop debt (BR-30) can net **₦0** several fortnights
running while the carry-forward grows, and there is no channel to warn them —
they find out standing at the point. **Recommend a cap**: recover no more than
*X%* of gross per cycle. Pick X.

### Not a decision — a number to go and get

3 of 19 farmers in the current test database have no cooperative. **That figure
is an artefact of my own seeder, not evidence about the real register.** Before
the first migration, count it for real. If unaffiliated farmers are common, the
payment run must be keyed on **collection centre**, not cooperative, and the
schema changes shape.

---

## Part 2 — The shape

**Delivery-anchored payment runs.** Pay the *delivery*, not the consignment.

Every payment line is built from `deliveries.litres_payable` — which
`AdjustmentService` already calls *"the FARMER's payment unit"* — priced at the
rate already snapshotted on the consignment that delivery rode in. A
`UNIQUE` constraint on the claim row makes **"a litre is paid exactly once,
ever"** a database fact rather than a service guard somebody can bypass.

Why this over the payroll twin: the double-payment guard moves off *the period*
and onto *the delivery*. That means weekly, fortnightly, ragged and catch-up
runs all work, and a consignment confirmed three days late is swept into the
next run automatically instead of falling down the gap between two months.

**Grafted from the payroll twin (Design 2):** the run's lifecycle, its states,
and riding the existing `WorkflowEngine` rather than inventing an approval flow.

**Grafted from the ledger design (Design 1):** a single derived figure —
*"what does the network owe this farmer right now?"* — which exists nowhere
today and is the first question anyone asks. Derived from unclaimed deliveries,
not a stored balance; a real per-farmer ledger is deferred (§8).

---

## Part 3 — Data model

Conventions followed: integer kobo, `created_by_user_id`, soft deletes,
effective-dated snapshots, `Scopeable` where a screen must narrow by centre.

```
payment_runs
  id, reference                       -- PRUN sequence, new row in `sequences`
  scope_type, scope_id                -- 'collection_center' | 'cooperative'
  period_start, period_end            -- date; a label, NOT the double-pay guard
  status                              -- draft|processing|approved|paid|cancelled
  gross_total_minor, deductions_total_minor, net_total_minor
  held_net_minor                      -- BR-36 money inside net_total, not payable yet
  cash_required_minor                 -- net minus held. THE number Accounts sends.
  farmer_count, held_count
  workflow_instance_id                -- nullable FK, like payroll_runs
  run_by_user_id, approved_by_user_id, approved_at, paid_at
  is_test, created_by_user_id, timestamps, deleted_at

farmer_payments                        -- one per farmer per run
  id, payment_run_id, farmer_id
  litres_paid                          -- decimal(10,2)
  gross_minor
  savings_minor, levy_minor, social_minor, shop_deduction_minor
  net_minor
  savings_pct_snapshot, levy_pct_snapshot, social_minor_snapshot   -- BR-15
  status                               -- payable|held|paid|reversed
  hold_reason                          -- 'unvalidated' (BR-36)
  breakdown                            -- json: the audit of how net was reached
  is_test, created_by_user_id, timestamps, deleted_at
  UNIQUE (payment_run_id, farmer_id)

farmer_payment_deliveries              -- the claim ledger. The heart of it.
  id, farmer_payment_id, delivery_id
  litres_payable, rate_per_litre_minor, grade_id, consignment_id  -- all snapshotted
  line_gross_minor
  UNIQUE (delivery_id)                 -- ← a litre is paid exactly once, ever

farmer_payment_disbursements           -- money actually handed over
  id, farmer_payment_id
  method                               -- cash|bank|mobile_money|via_cooperative
  amount_minor, disbursed_at
  paid_by_user_id                      -- who handed it over
  received_by                          -- farmer, or a named proxy
  received_by_relation, proxy_authority_ref
  external_reference                   -- bank/MoMo ref, hand-keyed
  signature_evidence                   -- attachment id: sheet photo / thumbprint
  latitude, longitude, location_accuracy_m, located_at   -- reuse the field-capture columns
  is_test, created_by_user_id, timestamps
```

Also: `farmers` gains `payout_method`, `bank_name`, `bank_account_masked`,
`mobile_money_number` — **nullable**, because most farmers will have none.

**Two corrections the design agents caught, both load-bearing:**

1. **`farmers` has no `is_test` column.** BR-35 exclusion cannot be done on the
   farmer. It must be done on `deliveries.is_test` (which exists). The run
   population is built from *deliveries*, so the generic `excludingTestData()`
   works — but only because the population is delivery-shaped. Building it from
   farmers would fatal.
2. `trips.payment_run_id` already exists as an empty, unconstrained placeholder
   pointing at this table. Either constrain it now or delete it; leaving a third
   half-built column is how the first two happened.

---

## Part 4 — The calculation

Ordered, with rounding stated at each step. All money is integer kobo;
`Money::percentageOf` is `(minor × basisPoints) / 10000` rounded **half-up**,
and `Money::valueVolume` works in centilitres so the product stays integral.

```
For each unclaimed delivery D of farmer F in scope:
  1. line_gross = Money::valueVolume(D.litres_payable, D.consignment.rate_per_litre_minor)
     → rounded half-up to the kobo, PER DELIVERY

  2. gross = Σ line_gross                      ← exact integer sum, no further rounding

  3. savings = Money::percentageOf(gross, coop.savings_deduction_pct)      half-up
  4. levy    = Money::percentageOf(BASE,  coop.levy_pct)                   half-up
     BASE is decision 1.4 — gross, or gross − savings
  5. social  = coop.social_contribution_minor, once per calendar month, not per run
  6. shop    = Σ pending_farmer_deductions, oldest first,
               capped by decision 1.6, whole-debt-or-skip

  7. net = max(0, gross − savings − levy − social − shop)
     Any shortfall stays as a pending deduction and carries forward.

  8. Snapshot savings_pct, levy_pct and social_minor onto the row.   ← BR-15
```

Rounding is **per delivery**, not per farmer. That is the only defensible choice
when rates differ per consignment — but it means two farmers with identical
monthly litres can differ by a few kobo. Not intuitive at a collection point.
The stored `breakdown` is what makes the difference arguable rather than
mysterious.

### Worked example — seeded values, Grade A ₦250/L, 5% savings, 2% levy, ₦250 social

Adamu Bobbo, one fortnight, three deliveries:

| Delivery | Payable | Rate | Line gross |
| --- | ---: | ---: | ---: |
| DEL-…-0001 | 23.00 L | ₦250.00 | ₦5,750.00 |
| DEL-…-0007 | 20.00 L | ₦250.00 | ₦5,000.00 |
| DEL-…-0014 | 18.50 L | ₦215.00 *(pooled B)* | ₦3,977.50 |
| **Gross** | **61.50 L** | | **₦14,727.50** |

```
savings  5% of 14,727.50                      =   736.38   (half-up from 736.375)
levy     2% of (14,727.50 − 736.38) = 13,991.12 =   279.82
social   fortnight 1 of the month              =   250.00
shop     one pending deduction                 =   1,680.00
net      14,727.50 − 736.38 − 279.82 − 250 − 1,680 = ₦11,781.30
```

That third delivery is the pooling cost made visible: 18.5 L graded B because of
someone else's milk, **₦647.50 less** than at Grade A. The line records the
consignment and the grade, so the question can be answered.

---

## Part 5 — Lifecycle and approvals

Copy `PayrollRun` exactly: `draft → processing → approved → paid`, plus
`cancelled`. It already has a `workflowInstance()` and is already a registered
workflow subject type, so this is a well-worn path, not a new one.

Add a workflow to `WorkflowSeeder` alongside WF-004 (Payroll Run):

| Stage | Role | Permission |
| --- | --- | --- |
| 1 · Prepared | — | `finance.farmer_payments.create` |
| 2 · Accounts | Accounts | `finance.farmer_payments.approve` |
| 3 · General Manager | General Manager | `finance.farmer_payments.approve` |

with `requester_may_not_approve_own => true`, which is how **BR-18** is enforced
— the engine already does this, it does not need reimplementing.

Generation is idempotent by construction: `UNIQUE(delivery_id)` means a second
run over the same period claims nothing. Run it twice and the second is empty.

---

## Part 6 — Permissions

```
finance.farmer_payments.view      Accounts · GM · Executive Director · Internal Audit · External Audit
finance.farmer_payments.create    Accounts
finance.farmer_payments.approve   Accounts · General Manager                     [sensitive]
finance.farmer_payments.disburse  Collection Officer · Payments Officer          [sensitive]
finance.farmer_payments.reverse   Accounts · General Manager                     [sensitive]
```

Introduced by migration (PERM-1) **and** added to `RoleSeeder`'s catalogue.
That second half is not optional: the catalogue rewrites `permission_role` on
every seed, so a migration-only grant is removed at the next reseed. This
session already hit exactly that bug with `milk.deliveries.cutoff_override` —
a sensitive permission that existed with nobody holding it. `SeedIntegrityTest`
now fails the build if it recurs.

`PermissionKey::module()` has no `finance` arm and `AuditLogger::approval()`
hardcodes module `'Purchases'`. Both need fixing *before* this ships, or the
most sensitive money movement in the system files its audit trail where Internal
Audit does not look — silently, with no test failing.

---

## Part 7 — Disbursement and evidence

For cash, the strongest thing the software can do is make the *paper* good and
the *reconciliation* automatic:

- A pre-printed payout sheet per point: farmer, net, signature box. Generated
  from the approved run, so the amounts cannot be written by the payer.
- On recording: payer's user id, timestamp, GPS, and an attachment (photo of the
  signed sheet). The attachment machinery already exists — `POST /api/v1/attachments`
  and the `attachments` table.
- Reconciliation: cash issued to a point vs sum disbursed vs sum returned.
- A spot-check: Extension Agents verify a sample against the payment lines on
  their next visit. Social control, but it is the only one that actually bites.

**Be honest about the limit.** A collusive payer and an absent farmer defeat all
of it: the sheet can be signed for someone who never came, and GPS proves the
payer was at the point, not that anyone was paid. Separating who records
deliveries from who disburses (decision 1.3) is the real control. Software makes
theft *attributable after someone notices*; it does not prevent it.

**Disputes.** There is no dispute path in this plan and there should be: a
`disputed` line status, a hold pending investigation, and somewhere to record
"the farmer says 14 L, the sheet says 12". In a scheme paying hundreds of
smallholders that happens in the first quarter.

---

## Part 8 — Phasing

Each increment is independently useful and independently shippable.

1. **Valuation, read-only.** `FarmerPaymentCalculator` + a "what is owed" figure
   on the farmer screen and in a report. No tables, no money moves. Lets the
   cooperative check the arithmetic against their own books *before* trusting it.
2. **Runs and approval.** `payment_runs`, `farmer_payments`,
   `farmer_payment_deliveries`, the workflow, the permissions. Produces an
   approved, snapshotted payable and a payout sheet. Money still moves by hand.
3. **Disbursement recording.** `farmer_payment_disbursements`, cash channel only,
   with evidence and reconciliation.
4. **Reversal and corrections.** *Built.* Void a line, reverse a run, clawback
   as a pending deduction with the §1.6 cap. Two shapes, deliberately not one:
   a payment nobody was paid yet is *erased* — the claim rows are deleted, so the
   milk reappears on the next run and any shop debt it settled goes back to
   outstanding. A payment already handed over cannot be un-handed, so it becomes
   a `PendingFarmerDeduction` the farmer carries, recovered from later milk under
   the cap. The reason is stored in words a farmer could be read.
5. **Farmer statements.** *Built.* `GET /farmers/{farmer}/statement`, permission
   `finance.farmer_payments.view` plus the record scope on the farmer, so an
   Extension Agent who may open the farmer still cannot open their money. Note
   USER-2 — farmers are records, not accounts; a statement is something an
   officer *prints for* a farmer, not something the farmer logs in to see.
   It keeps two "owed" figures apart on purpose: unclaimed milk, and net already
   approved on a run but not yet handed over. Adding them tells a farmer they
   are owed the same naira twice.
6. **Additional channels.** *Built.* `farmers` now carries `payout_method`,
   `bank_name`, `bank_account_masked` and `mobile_money_number`, the payer sees
   them on the payout modal, and the disbursement's `method` and
   `external_reference` record which channel actually moved the money. There is
   no bank API and the plan never promised one — a transfer is made in the
   bank's own app and its reference hand-keyed back, exactly as §1.2 describes.
   Two things are deliberate: the account number is stored **masked to its last
   four digits**, because a full register of account numbers is a liability and
   four digits is all a payer needs to check they have the right one; and
   editing payout details is gated on `finance.farmer_payments.create`, **not**
   on `community.farmers.edit`, so a field worker who may correct a herd size
   cannot redirect a farmer's money. That second key is the §7 fraud surface
   narrowed as far as software can narrow it.
7. **Per-farmer savings accounts**, if the cooperative wants savings to be a
   balance a farmer can draw on rather than a pooled deduction.

**Deferring 7 is the deferral most likely to be regretted.** Until it exists, a
farmer's savings is a reconstruction from payment lines, not a position anyone
can point at — and a deduction into an unaccountable pool is indistinguishable,
from the household's side, from a fee.

---

## Part 9 — Risks

**The judges called these out and this plan does not fully solve them.**

- **Pooled grading is unfair by construction** (1.1). Instrumented, not fixed.
- **Cash is where money will actually go missing** (§7). Mitigated, not closed.
- **BR-36 holds have no backpressure.** Validation is a field visit; holds are a
  clock. Held balances will grow faster than M&E can clear them. There is a
  visible count and an ageing report — a tile is not a process. Consider an
  automatic release after N months, or partial payment under hold.
- **`community.withhold_payment_when_unvalidated` is one Settings boolean that
  releases every hold in the network at once** — a large, quiet lever with no
  approval on it.
- **Generation is a long transaction.** Walking every unpaid delivery, locking
  rows, writing three tables, while collection agents are recording deliveries.
  `PayrollService` runs synchronously for ~50 employees; this is a different
  order of magnitude and will need chunking. There is no queued-money precedent
  in this codebase, so the first person to build one is inventing conventions in
  the most dangerous module in the system.
- **A farmer who changes cooperative mid-period** is handled by accident —
  their litres land wherever they belong at generation time. Not modelled, not
  detected.
- **A farmer who dies, exits or disputes** has no path at all.
- **Every electronic leg is hand-keyed reconciliation.** No bank or MoMo API.
  The field proving a specific farmer got a specific amount is typed by the same
  department that raised the payment.

---

## What I would do first

Decide **1.1** and **1.2**, count the unaffiliated farmers for real, then build
increment 1 — valuation with no money moving. It is small, it is safe, and it
puts a number in front of the cooperative that they can check against their own
books. Everything after that is easier to argue about once both sides are
looking at the same figure.
