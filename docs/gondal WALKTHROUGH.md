# Gondal ERP — Review Walkthroughs

**URL** `http://localhost:8000` · **Password for every account below:** `GondalDemo!2026`
Two-factor is **off** for the whole cast — sign-in is one step. Sign out between roles
(bottom of the sidebar).

Every account here is seeded by `DemoChainCastSeeder` and pinned by `SeedIntegrityTest`,
so these walkthroughs survive a fresh `migrate:fresh --seed` (with `GONDAL_SEED_DEMO_DATA=true`).

---

## 1 · Milk collection — farmer to factory

| Stage | Sign in as | Role |
|---|---|---|
| 1 | `musa.ibrahim@gondalfulbe.ng` | Collection Agent — PT-001 Tudun Wada |
| 2 | `maryam.yakubu@gondalfulbe.ng` | Milk Collection Officer — Kumbotso |
| 3 | `salisu.adamu@gondalfulbe.ng` | Logistics Officer — Kumbotso |
| 4 | `bashir.danladi@gondalfulbe.ng` | Milk Collection Supervisor — network |

**As Musa (agent):**
1. Note the dashboard banner: *"Figures cover Tudun Wada Point only"*.
2. **+ Record Milk Intake** → pick a farmer, set a pre-07:00 time, e.g. 34 L presented,
   6 L rejected, reason *Adulteration*. Accepted litres are computed for you.
3. Milk Flow → **Consignments** → **+ Dispatch Consignment** → tick the waiting
   deliveries, set containers, dispatch. Note the reference (CNS-…).
4. **Boundary:** open `http://localhost:8000/collection-points/2` — access denied,
   with the missing permission and your scope explained.

**As Maryam (officer):**
5. Collection Centers → **Kumbotso** → the consignment is in *Awaiting Confirmation* → **Confirm**.
6. ⚠️ **Record the three quality tests FIRST** (density e.g. `1.030`, temperature `18`,
   alcohol *No coagulation*), **then** pick the grade and confirm. *(Known bug: confirming
   with tests unrecorded silently drops your grade and strands the consignment —
   see the open fix task.)*
7. Scroll to *Confirmed Today* → **Dispatch batch** → tick the consignment, containers, dispatch.
8. **Boundary:** the grade dropdown shows the ₦/L rate; confirming snapshots it — later
   rate changes cannot move this payment.

**As Bashir (supervisor):**
9. Milk Collection → **Factory Reconciliation** → find the batch → **Reconcile**.
10. **Boundary:** enter 1–2 L less than dispatched with *no cause* → refused
    ("variance exceeds tolerance"). Add a cause + supervisor note → reconciled.
11. Open the batch → **Release** → *"released to production and payment."*

---

## 2 · Community engagement — enrolment to oversight

| Stage | Sign in as | Role |
|---|---|---|
| 1 | `jamila.usman@gondalfulbe.ng` | Extension Agent — Tudun Wada, Kumbotso Town, Chiranci |
| 2 | `aminu.jibril@gondalfulbe.ng` | Community Engagement Officer — all communities |
| 3 | `hafsat.bello@gondalfulbe.ng` | Delivery Lead — all communities |

**As Jamila:** enrol a farmer in one of her three communities; log a field activity.
**Boundary:** open a farmer in Danbare or Dawakin Tofa (another agent's patch) → denied.
**Boundary:** try to open any cooperative → denied — agents hold no cooperative permission at all
(different failure from the scope one: the nav item isn't even shown).

**As Aminu:** assign a validation task for Jamila's new farmer (she can *perform*
validations but cannot *create* them — the four-eyes split); record a cooperative
savings entry.

**As Hafsat:** review activity across all 26 communities; cooperative balances are
**read-only** at this level; raise a programme requisition.

---

## 3 · Requisition — six approvals, two routes

| Stage | Sign in as | Role |
|---|---|---|
| Raise | `tijjani.usman@gondalfulbe.ng` | Logistics Officer |
| 2 | `lawal.ibrahim@gondalfulbe.ng` | Dept Head — Logistics |
| 2 | `hauwa.abdullahi@gondalfulbe.ng` | Dept Head — Milk Collection |
| 3 | `saudat.bello@gondalfulbe.ng` | Internal Audit |
| 4 | `haruna.gambo@gondalfulbe.ng` | Executive Director *(only above ₦500k)* |
| 5 | `fauziya.sani@gondalfulbe.ng` | Accounts |
| 6 | `abdulkadir.tanko@gondalfulbe.ng` | General Manager *(only above ₦500k)* |

1. As **Tijjani**, raise two requisitions: one **under ₦500,000** and one **over**.
2. Walk each through My Approvals as the approvers in order. The small one must
   **skip ED and GM** entirely; the big one visits all six stages.
3. **Boundary (BR-18):** as Tijjani, try to approve your own requisition → refused.
4. **Boundary (scope):** as Lawal (Logistics head), try to approve a Milk Collection
   requisition → refused; that stage belongs to Hauwa Abdullahi.
5. At any stage, try **reject** (returns it to Tijjani to revise) or **reduce the amount**
   (allowed; increasing is not).

---

## 4 · One-Stop Shop — catalogue to counter

| Stage | Sign in as | Role |
|---|---|---|
| Catalogue | `nafisa.garba@gondalfulbe.ng` | Shop Manager — sees revenue |
| Stock | `shehu.mainasara@gondalfulbe.ng` | Inventory Officer — quantities only |
| Sell | `usman.lawal@gondalfulbe.ng` | Sales Officer — own sales only |
| Sell | `halima.abubakar@gondalfulbe.ng` | Sales Officer — own sales only |

1. As **Nafisa**, add a product category (no code change needed) and a product in it.
2. As **Shehu**, receive a stock batch; raise a stock adjustment (routes to Nafisa for
   approval; above ₦50,000 it also visits Accounts).
3. As **Usman**, record a sale. Selling from *Veterinary drugs* demands a prescription
   reference. Try payment method *deduct from milk payment* — it ties back to the
   farmer's next milk run.
4. **Boundary (G-6):** as Usman, look for any revenue total, margin or stock value —
   there is none anywhere. As Shehu, try to record a sale → refused.
5. **Boundary (`own` scope):** record a sale as Usman, then sign in as **Halima
   Abubakar** — his sale is invisible to her. Only Nafisa sees both.

---

## 5 · HRM — leave and payroll

| Stage | Sign in as | Role |
|---|---|---|
| Staff | `nuraini.sabo@gondalfulbe.ng` | Staff only — Logistics |
| Staff | `yakubu.hamza@gondalfulbe.ng` | Staff only — Milk Collection |
| HR | `binta.yusuf@gondalfulbe.ng` | HR Manager |
| Payroll 2 | `fauziya.sani@gondalfulbe.ng` | Accounts |
| Payroll 3 | `abdulkadir.tanko@gondalfulbe.ng` | General Manager |

1. As **Nuraini**, note how little the nav shows — three permissions total. Request
   **4 days** leave → it completes at her department head (**Lawal**); HR never sees it.
2. As **Yakubu**, request **10 days** → after his head (**Hauwa Abdullahi**) it
   continues to **Binta** — the over-5-days band adds the HR stage.
3. **Boundary:** as Nuraini, open your own payslip (fine), then try any other
   employee's record → refused.
4. As **Binta**, submit the processing payroll run → **Fauziya** approves →
   **Abdulkadir** authorises. Binta cannot complete it alone.

---

## Administration (not a chain, but worth seeing)

`sadiq.ahmed@gondalfulbe.ng` — System Administrator. Roles & permission matrix,
permission testing protocol, audit log — where every refusal you triggered above
is recorded with its rule ID. Also `superadmin@gondalfulbe.ng` / `Gondal#Super2026`
(local convenience account) and `m.kabir@gondalfulbe.ng` (activate via emailed code
in `storage/logs/laravel.log`).

**Tip:** review with the *narrow* accounts, not the admin — the admin sees everything,
so none of the boundaries above are visible from that seat.
