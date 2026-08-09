# User guide — Milk Collection

How milk gets from a farmer's churn to a released factory batch, who does each
step, and what the system will refuse to let you do.

Everything below describes the application as it is actually built. Where a
figure is configurable it says so, and gives the value in force today — those
live in **Administration → Settings**, not in the code.

---

## The chain at a glance

Milk moves through five records. Each one is created by a different person, and
each carries the volume forward from the last.

| # | Record | Reference | Who creates it | Where |
| --- | --- | --- | --- | --- |
| 1 | **Delivery** — one farmer, one morning | `DEL-20260808-0001` | Collection Agent | Milk Collection → Milk Flow |
| 2 | **Consignment** — a point's deliveries, sent to the centre | `CNS-0001` | Collection Agent or Officer | Milk Flow → Consignments |
| 3 | **Trip** — the transport leg and its fee | `TRP-0001` | Logistics Officer | Logistics & Transport |
| 4 | **Batch** — a centre's consignments, sent to the factory | `BATCH-0001` | Collection Officer or Supervisor | Milk Flow → Batches |
| 5 | **Reconciliation** — what the factory actually received | on the batch | Milk Collection Supervisor | Factory Reconciliation |

The delivery reference resets daily, so the first delivery each morning is
`-0001`. The others run continuously.

---

## Before you start

**Your data scope decides what you can see.** Every screen is filtered to the
part of the network you hold. A Collection Agent scoped to Tudun Wada sees Tudun
Wada's deliveries and Tudun Wada's farmers — opening another point's record
returns the access-denied screen, and that is the system working, not a fault.
Your scope is shown on your profile as *"Your Data Scope"*.

Scopes used in this module:

- **Point** — one collection point (Collection Agent)
- **Centre** — one collection centre and every point feeding it (Collection Officer, Logistics Officer, Quality Officer)
- **Network** — everything (Milk Collection Supervisor)

An agent named on no point in the register sees **nothing**. That is deliberate:
the scope fails closed rather than falling back to network-wide.

---

## Stage 1 — Record a delivery

**Who:** Collection Agent · **Screen:** Milk Collection → Milk Flow → **+ Record Delivery**

Pick the farmer, enter what they presented, and save.

| Field | Notes |
| --- | --- |
| Collection point | Yours, pre-selected |
| Farmer | Only farmers assigned to your point |
| Litres presented | Must be more than zero |
| Litres rejected | Optional; needs a reason if non-zero |
| Rejection reason | From the configured list — never free text |
| Delivered at | Defaults to now |

**Accepted litres are calculated, not typed:**
`accepted = presented − rejected`.

Use **Save & add another** to keep the form open with the point still chosen —
the morning queue is usually several farmers back to back.

### Rejecting part of a delivery

Enter the rejected litres and pick a reason. The three configured reasons are:

| Reason | Meaning |
| --- | --- |
| **Adulteration** | e.g. added water |
| **Spoilage** | souring, coagulation |
| **Failure to meet delivery time** | arrival after the cut-off |

Rejected volume is **excluded from the farmer's payment and from the transport
fee**, and is valued at zero. A partly rejected delivery still records the
accepted litres and carries them forward.

### The cut-off

Each point has a cut-off time — **07:00** by default, and individual points may
override it on their own record. A delivery presented after the cut-off cannot
simply be accepted. You have two options:

1. **Reject it in full** for "failure to meet delivery time", or
2. Have someone with override authority accept it, with a written reason that is logged.

If you try to accept late milk without either, the system says:

> This delivery is after the 07:00 cut-off. Reject it in full for "Failure to meet delivery time", or record a supervisor override with a reason.

> [!IMPORTANT]
> **The override is not yours to give.** It sits behind a separate, sensitive
> permission (`milk.deliveries.cutoff_override`) precisely so that the person
> holding the late milk cannot authorise accepting it. It is held by the
> **Milk Collection Supervisor** and the **Milk Collection Officer** — the two
> roles §5.1 puts above the point — and by nobody else.

### Dating a delivery

A delivery cannot be dated on a future day, and cannot be backdated more than
**7 days** — by then that day has been dispatched, reconciled and reported.
Corrections to older days go in as an adjustment instead, not as a backdated
delivery.

---

## Stage 2 — Dispatch a consignment

**Who:** Collection Agent or Collection Officer · **Screen:** Milk Flow → Consignments → **Dispatch**

Tick the morning's deliveries and dispatch them to the centre. Select the
containers, and the trip if one is already logged.

**Dispatched litres = the sum of the accepted litres of the deliveries you
ticked.** You do not type the volume.

A delivery can only ever be on one consignment. If someone else dispatched part
of your selection while you were on the screen, nothing is dispatched and you
are told to reload:

> 2 of the 5 selected deliveries are already dispatched on another consignment. Nothing was dispatched — reload the list and try again.

The consignment arrives at the centre as **awaiting confirmation**.

---

## Stage 3 — Confirm, test and grade at the centre

**Who:** Milk Collection Officer · **Screen:** Milk Flow → Consignments

This is the quality gate, and it happens in a set order.

### 3a. Record the quality tests

All three configured tests must be recorded before a grade can be assigned:

| Test | Type | Acceptable |
| --- | --- | --- |
| Density (lactometer) | range | 1.028 – 1.034 g/cm³ |
| Intake temperature | maximum | 20 °C |
| Alcohol test | pass/fail | No coagulation |

Try to grade before they are all in and the system refuses:

> Record every required quality test before assigning a grade. Missing: …

### 3b. Confirm the volume

Enter what the centre actually measured, and any volume rejected at the centre
with its reason.

**Confirmed litres = dispatched + adjustments − rejected at centre.**

Adjustments and rejection together cannot take the confirmed volume below zero.
A consignment cannot be confirmed at a time earlier than it was dispatched.

### 3c. Assign the grade

| Grade | Criteria | Rate |
| --- | --- | --- |
| **Grade A** | Density in range, alcohol negative, intake below 20 °C | ₦250.00 / L |
| **Grade B** | Density in range, minor organoleptic variance | ₦215.00 / L |
| **Rejected** | Fails a configured rejection reason | ₦0.00 |

Rates are **effective-dated**. When you grade a consignment, the rate in force
at that moment is copied onto the record and stays there. Changing the rate in
Settings later never alters a figure that has already been priced — it applies
to new gradings only.

### Re-grading

Changing a grade after it has been assigned needs a **supervisor** — it is a
different permission from assigning the first grade, because it changes what a
farmer is paid. A re-grade requires a written reason and appears on the
**Re-grades** exceptions list.

> A re-grade needs a reason. It changes what a farmer is paid, and the reason is what the exceptions list is read for.

---

## Stage 4 — Log the trip

**Who:** Logistics Officer · **Screen:** Milk Collection → Logistics & Transport → **Log trip**

| Field | Notes |
| --- | --- |
| Route | From the Fleet & Routes register — carries the tariff |
| From / to | Point and/or centre |
| Vehicle, driver | From the register |
| Departed / arrived | Arrival cannot precede departure |
| Litres carried | Optional |

The **fee comes from the route's tariff**, so the register is what decides what
transport costs — not a number typed per trip. Maintain routes under
**Fleet & Routes**. Rejected volume is excluded from fee calculation.

---

## Stage 5 — Build and dispatch the batch

**Who:** Collection Officer or Supervisor · **Screen:** Milk Flow → Batches → **Dispatch batch**

Select the centre, tick the consignments, set containers and the trip.

**Only confirmed and graded consignments may join a batch.** An unconfirmed one
is refused by name, and a batch with no volume is refused outright:

> The selected consignments have no confirmed volume to send to the factory.

**Batch litres = the sum of the confirmed litres of its consignments.**

As with consignments, a consignment can only be on one batch — a concurrent
dispatch is refused whole rather than splitting the volume.

The batch leaves as **in transit**.

---

## Stage 6 — Reconcile at the factory and release

**Who:** Milk Collection Supervisor · **Screen:** Milk Collection → Factory Reconciliation

### 6a. Record what arrived

| Field | Notes |
| --- | --- |
| Litres received | Required; cannot be negative |
| Containers received | Optional |
| Litres rejected at factory | Needs a reason enabled *at factory* |
| Discrepancy cause | Required once the variance is beyond tolerance |
| Supervisor notes | Required to release beyond tolerance |

**Discrepancy = received − dispatched.** It is signed: negative is a shortfall.

### 6b. Explain the variance

The tolerance is **1.0%** of the dispatched volume. Within it, the batch
reconciles quietly. Beyond it you must pick a cause from the configured list —
container change at intake, spillage in transit, measurement difference,
temperature loss, counting error at dispatch:

> The variance is 3.4% against a tolerance of 1.0%. Select the cause of the discrepancy.

### 6c. Release

A batch can only be released from **reconciled** or **discrepancy**. Beyond
tolerance, a written supervisor note is required first:

> The variance on BATCH-0001 is 3.4%, beyond the 1.0% tolerance. Record a supervisor note before releasing it.

Released is the end of the chain. The volumes and the snapshotted rates are then
what payment is calculated from.

---

## Adjustments — correcting a volume

**Who:** Collection Officer or Supervisor · Available on a delivery or a consignment

An adjustment moves the payable volume up or down without editing the original
figure. **Every adjustment needs a reason and a written explanation — there is
no silent correction.** The configured reasons are measurement correction,
spillage in transit, container change, and data entry error.

An adjustment cannot take a volume below zero.

Use an adjustment rather than editing history when the day has already been
dispatched or reported.

---

## Rejections and quality follow-ups

When a farmer accumulates repeated rejections of the **same reason** inside the
configured window, the system opens a **quality follow-up** automatically and
notifies the extension team. Nobody has to spot the pattern by hand.

| Reason | Opens a follow-up after |
| --- | --- |
| Adulteration | 3 in 30 days |
| Spoilage | 3 in 30 days |
| Failure to meet delivery time | 2 in 30 days |

A follow-up is closed by an extension agent logging a qualifying field activity
— a household visit, a training session, or a quality follow-up visit. It cannot
be closed by simply marking it done.

---

## Who can do what

| Action | Roles |
| --- | --- |
| Record a delivery | Collection Agent |
| Record a rejection | Collection Agent · Collection Officer · Quality Officer · Supervisor |
| Override the cut-off | *(sensitive)* Milk Collection Supervisor · Collection Officer |
| Dispatch a consignment | Collection Agent · Collection Officer · Supervisor |
| Confirm a consignment | Collection Officer · Supervisor |
| Record quality tests / assign grade | Collection Officer · Quality Officer · Supervisor |
| Re-grade | Quality Officer · Supervisor |
| Record an adjustment | Collection Officer · Supervisor |
| Log a trip | Logistics Officer |
| Dispatch a batch | Collection Officer · Supervisor |
| Reconcile and release | Milk Collection Supervisor |
| Network-wide totals | Supervisor · Internal Audit · Executive Director · Accounts · GM · M&E · Board |

Viewing is consistently wider than doing — audit and executive roles can read
the whole chain without being able to change any of it.

---

## The arithmetic, in one place

```
delivery.accepted    = presented − rejected
consignment.dispatched = Σ accepted litres of its deliveries
consignment.confirmed  = dispatched + Σ adjustments − rejected at centre
batch.dispatched       = Σ confirmed litres of its consignments
batch.discrepancy      = received − dispatched          (negative = shortfall)
```

Rejected volume is excluded from payment and from transport fees, and valued at
zero. Grade rates and cooperative deduction percentages are snapshotted when the
amount is calculated, so a later rate change never rewrites a historical figure.

---

## Common refusals and what they mean

| Message | What to do |
| --- | --- |
| "This delivery is after the 07:00 cut-off…" | Reject it in full for late delivery, or get an override |
| "A supervisor override needs a written reason — it is logged." | Type the reason; it is attributed to you |
| "A delivery cannot be dated on a future day." | Check the device clock |
| "A delivery cannot be dated more than 7 days back…" | Record it as an adjustment instead |
| "…already dispatched on another consignment…" | Reload the list; someone dispatched first |
| "Record every required quality test before assigning a grade." | Enter density, temperature and alcohol first |
| "The selected consignments have no confirmed volume…" | Confirm and grade them before batching |
| "Select the cause of the discrepancy." | The variance is beyond 1.0% |
| "Record a supervisor note before releasing it." | Explain the variance in writing |
| Access-denied screen on another point or centre | Working as intended — that record is outside your scope |

No refusal message quotes a rule code at you. If you need the rule behind a
refusal, it is in `docs/RULE-INDEX.md`.

---

## Configurable values

Nothing below is hard-coded. All of it is edited in **Administration → Settings**
or in the reference-data screens, and changes take effect without a release.

| Setting | Value today |
| --- | --- |
| Default delivery cut-off | 07:00 |
| Latest permitted cut-off override | 08:00 |
| Furthest a delivery may be backdated | 7 days |
| Batch discrepancy tolerance | 1.0% |
| Cooperative savings deduction | 5% |
| Cooperative levy | 2% |

Grades and rates, rejection reasons and their follow-up thresholds, adjustment
reasons, discrepancy causes, quality tests and their ranges, and the reference
number formats are all rows you can edit too.

---

## Known gaps

- **Payments are out of scope.** The chain captures everything payment needs —
  snapshotted rates, deduction percentages, accepted volumes — but the payment
  run itself is a later phase.

## Fixed since this guide was first written

- **The cut-off override had no ordinary holder.**
  `milk.deliveries.cutoff_override` was created by migration and granted there
  to the Supervisor and the Officer, but the grant was missing from
  `RoleSeeder`'s catalogue — and that catalogue rewrites `permission_role` on
  every seed, so each reseed took it straight back off. A supervisor asked to
  authorise late milk was refused, and every BR-3 test passed through it because
  each granted the permission to itself first. Both roles now carry it in the
  catalogue, and `SeedIntegrityTest` fails the build if any sensitive
  permission is ever left with no holder but an administrator.
