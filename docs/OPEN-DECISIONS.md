# Open decisions

§15 of the PRD lists four questions the review meeting did not settle, and instructs: **do not guess;
ask.** This file states each one in the form it needs to be answered in, says exactly what is built and
what is not, and lists what changes once an answer arrives. §5 was added afterwards, on the same terms.

Nothing here is a blocker on the rest of the system. Every screen that would consume one of these
decisions says on the screen that it is open, so a reviewer can tell "undecided" apart from "unbuilt".

---

## 1. Where does the payment module live? (§15.1)

**Blocking Phase 7 only.**

Farmer payments and transport payments are designed nowhere. The review meeting debated placement and did
not conclude.

### The question

Which of these owns payment runs?

| Option | Implication |
| --- | --- |
| Inside Milk Collection | Payment inherits milk's data scope — a Center Officer could see the runs for their own center. Keeps the payable volume and the run in one module |
| A separate Finance module beside Payroll | New permission group (`finance.*`), new personas, its own scope type. Cleanest boundary; most new surface |
| Owned by Accounts | Reuses the existing Accounts role and `purchase.approve.accounts.*` authority. Smallest change; conflates approving a purchase with paying a farmer |

Whichever is chosen, three sub-questions follow and are worth answering in the same conversation:

1. **What is a payment run's approval chain?** The requisition chain (§6.5) is amount-banded across six
   stages. A farmer payment run is a different shape — many small amounts, one large total — and it is not
   obvious that the same bands apply.
2. **Who may see a farmer's net figure?** BR-29 already withholds cost and margin from anyone without
   `shop.revenue.view`. A payable amount per farmer is at least as sensitive.
3. **What settles a transport payment?** `trips` records the journey and the tariff, but nothing marks a
   trip as paid.

### What is built

BR-13 to BR-16 make deferring this cheap. The data a payment run needs is captured correctly already:

- every consignment snapshots `grade_rate_id` and `rate_per_litre_minor` at confirmation, so a rate change
  tomorrow cannot rewrite what was payable yesterday (BR-14);
- `litres_payable` is accepted volume plus signed adjustments, and rejected-grade volume is excluded
  (BR-15, BR-16);
- `trips` carries its tariff and a `payment_run_id` column with **no foreign key** — deliberately, because
  the table it would point at does not exist yet (§6.3);
- `pending_farmer_deductions` accumulates milk-deduction sales against the farmer's next payment (BR-30)
  and nothing ever settles them.

### What is not built

No payment run, no payment screen, no settlement of `pending_farmer_deductions`, no `payment_runs` table.
`PhaseAcceptanceTest::test_phase7_payments_is_blocked_but_its_data_is_captured` asserts both halves: that
the data is captured, and that the module is absent.

### Where it is surfaced

`/payroll` (a banner and a note that this is staff payroll only), `/logistics` (payment runs are Phase 7),
the farmer detail screen (pending deductions cannot be settled), and `/admin/personas` (no persona owns
payments).

---

## 2. What does a Farm Manager do? (§15.2)

**Non-blocking.**

### The question

What are a Farm Manager's responsibilities, and what data may they see? Concretely, the answer needs to
fill in one row of §5's matrix: a set of permissions, a data-scope type, and the restriction that explains
the boundary.

The scope type is the part that cannot be inferred. "Farm" is not one of the scope types in §5.3
(`network`, `center`, `point`, `department`, `communities`, `own`), so either the role fits an existing
one or a seventh scope type is needed — which is a schema and engine change, not a configuration change.

### What is built

The role exists, seeded as **draft with zero permissions**, held by nobody. ROLE-5 refuses to activate a
role with no permissions, and `UserAdminService` refuses to assign a draft role at all, with the message
*"{role} is still a draft — its scope has not been agreed (§15.2). Define it before assigning it."*

### Where it is surfaced

`/admin/roles` and the role detail screen explain why it is draft; `/admin/personas` lists it as a persona
with no holder and no landing screen.

---

## 3. Cooperative forms (§15.3)

**Blocking Phase 5 field detail only.**

### The question

The manual forms currently used to capture cooperative details are outstanding from Muhammad Bello. What
fields do they carry, and which are required at registration versus later?

### What is built

§6.6's schema in full: cooperatives with their LGA, community and collection point; officials; members
with their membership numbers; two account kinds (general and social) with an append-style ledger where
each entry stores `balance_after_minor` rather than recomputing a running total on read — so a later
correction cannot silently rewrite a past balance.

### What changes when the forms arrive

New columns on `cooperatives`, and additional fields on the registration form. Nothing structural: the
record is already the thing the forms describe, and the ledger is independent of them.

### Where it is surfaced

`/cooperatives` and the cooperative detail screen both state that the forms are outstanding.

---

## 4. One-Stop Shop detail (§15.4)

**Blocking Phase 6 refinement only.**

### The question

Additional module detail is outstanding from Muhammad Bello. The gaps visible from §6.7 and the prototype:

1. **Where does stock come from?** `product_batches` references a goods-received-note concept that has no
   screen and no table (§15.5 lists GRNs as out of v1). Today a batch is created directly.
2. **What are the prescription categories?** BR-27 requires a prescription reference for products in a
   category that needs one. Which categories those are is reference data (§9) and is seeded from the
   prototype; a real list is wanted.
3. **How is a credit sale to a cooperative settled?** The sale posts to the cooperative's ledger; nothing
   closes the balance.

### What is built

§6.7 in full — categories, products, batches with expiry, sales with four customer types and four payment
methods, stock movements, and the low-stock threshold. Every BR-25 to BR-30 rule has a test.

### Where it is surfaced

The shop migrations record the open detail; the sale flow works end to end for the cases the PRD does
specify.

---

## 5. What may Monitoring & Evaluation see? (raised after the review)

**Not blocking anything. The role exists and works; the question is its edges.**

Both halves of the job are now reachable: `/reports` serves its read grants, and `/validations` is the web
screen for its write grant (BR-36). Neither existed when the role was first seeded.

M&E was not in §5.1, was not discussed on 30 Jul, and appears nowhere in the prototype. It was raised
afterwards as a post the organisation actually holds, so the role is seeded — active, network-scoped, and
read-only in every grant.

### What is built

`Monitoring & Evaluation` in `RoleSeeder`, holding `view` on farmers, extension, deliveries, rejections,
grades and the network production total. Its responsibilities and restrictions are seeded alongside like
every other role, so they reach the web and the field app from the same rows.

The data an indicator needs is already captured: `extension_agents.visit_target_monthly` and
`enrolment_target_monthly` hold the targets, `field_activities` holds the actuals with `farmers_reached`,
and `quality_followups` holds the raise-and-close trail (BR-5).

M&E also owns **farmer revalidation** (BR-36): they decide which farmers need re-checking and who
carries it out, and field staff — Collection Agents, Extension Agents, Milk Collection Officers — do the
checking. That gave the role its only write authority. It writes the SCHEDULE, never the record: M&E
holds `community.validation` create/edit/approve and does **not** hold `community.farmers.validate`, so
they cannot close a check they scheduled. `AccessAndAccountRulesTest::test_monitoring_and_evaluation_schedules_checks_but_never_records_them`
walks the whole grant set and fails if that ever slips.

### The question

Four grants were deliberately withheld. Each is a yes/no the business owns, not a technical call:

| Grant | The case for | The case against |
| --- | --- | --- |
| `milk.points.view` | Rejection rates are only meaningful per point; without the register M&E reads codes, not places | It is the operational register, and M&E is not operations |
| `community.cooperatives.view` | Cooperatives are how the programme is structured; Board already holds this | Nothing yet says an indicator is reported per cooperative |
| `community.coop.savings.view` | Savings uptake is a plausible programme outcome | §5.1 marks it **sensitive** — it is members' money, and no named indicator needs it |
| `admin.audit.view` | "Verify that what was recorded is what happened" is in the role's own responsibilities | That verification is Internal Audit's, and duplicating it duplicates the authority |

A fifth question sits behind all four: **is M&E internal or does it report to a funder?** An M&E function
writing donor reports needs a defined retention and export story that nothing in v1 provides.

### What is not built

**Role-specific dashboards** (§15.5) remain out: every role's landing page is the same one.

~~**Reporting.**~~ **Built.** `/reports` (App\Services\Reporting\PeriodReports) now aggregates over a
user-chosen span of WAT days — production, quality, enrolment, extension and shop sales — each gated on
the permission that governs its data, each narrowed by SCOPE-4, each excluding test activity (BR-35), and
each exportable as CSV. M&E can run four of the five; `sales` needs `shop.revenue.view`, which the role
deliberately does not hold. The reports it can run are exactly the ones its responsibilities name.

What is still unspecified is which MANAGEMENT reports the business wants — NG-7's "requirements not yet
gathered" is unchanged. The layer exists and adding a report to it is a method and a catalogue entry.

The mobile app gives this role no field actions: every grant is `view`, and the app has no read-only
surface. A user holding only M&E sees their role, their scope and their responsibilities, and is told
their work lives in the web ERP.

---

## 6. Farmer revalidation — the edges (BR-36)

**Not blocking. The loop is built and tested; four smaller questions are open.**

### What is built

M&E assign a farmer (or a whole round) to a field worker with a reason, a due date, and their call on
whether the result needs review. The worker sees the queue on their phone — offline — and submits what
they found: confirmed, corrected, farmer not found, or declined. A correction lands on the record
immediately; `not_found` and `refused` close the task honestly without marking anybody verified.

**BR-36's teeth: an overdue farmer's milk is still collected; their payment waits.** The asymmetry is
deliberate. Refusing a delivery at 05:30 destroys milk already in the churn and costs the farmer a day's
income over a back-office lapse the agent standing there cannot fix. Holding the payment costs nothing
that verifying the record does not immediately return.

> **Phase 7 must honour this.** §15.1 means the payment module does not exist, so
> `Farmer::paymentIsHeldPendingValidation()` is a flag that is shown on screen and in the API and settles
> nothing today, because there is nothing to settle. Whoever builds the payment run has to read it before
> paying anybody. `FarmerRevalidationRulesTest::test_br36_an_overdue_farmer_is_still_collected_from_but_not_paid`
> pins both halves in the meantime.

### The questions

1. **Who else may carry out a check?** Today: Collection Agent, Extension Agent, Milk Collection Officer,
   and the two community leads. Logistics Officers are at the points daily and were not included.
2. **Pool assignments.** Supported (a null assignee means "anyone covering this farmer"), but named
   assignment is the default, because an unclaimed task is nobody's and the farmers hardest to reach are
   exactly the ones that stay unclaimed. Confirm that is the right default.
3. **The checklist.** A field worker may currently correct phone, gender, year of birth, herd size and
   lactating count — what they can verify standing with the farmer. Cooperative, community and collection
   point are deliberately excluded: those move money and belong to `community.farmers.edit`. If the check
   should be a formal list of items rather than a free correction, that list is reference data and needs
   defining.
4. **The cycle.** `community.revalidation_interval_months` defaults to 12; zero switches periodic
   revalidation off entirely and leaves the schedule to M&E's judgement.

---

## Not open — decided against

For completeness, so these are not re-litigated as if they were open questions:

- **§15.5** vendor registry, purchase orders, goods-received notes, reporting and analytics,
  role-specific dashboards, attendance, recruitment applicants, global search and the supervisor "my team"
  view are out of v1. Each needs its own specification.
- **§15.6** cooperative loans and investments (NG-1), the project module (NG-2) and mobile applications
  (NG-3) are deferred by decision. ARCH-2 (API-first) and ARCH-7 (idempotent writes) are honoured so NG-3
  remains possible without rework.
