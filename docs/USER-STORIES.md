# User stories for testing

Written from the live permission catalogue, not from the specification — every
"can" and "cannot" below was read out of the running system, so a story that
fails is a real finding rather than a documentation error.

## Before you start

All demo accounts: **`GondalDemo!2026`** · pilot accounts: **`GondalPilot!2026`**
Two-factor is currently **off** for every account. A `migrate:fresh --seed` turns
it back on and resets these passwords.

Each story is written as **do this → expect this**. The *cannot* stories matter as
much as the *can* ones: they are what proves the permission model, and a "cannot"
that succeeds is a security finding.

**Known to fail today** — do not raise these, they are already scheduled:

| Story | Why | Fixed in |
| --- | --- | --- |
| ~~HR Manager opening Approvals~~ | ~~gated on `purchase.approve.*`~~ | **Fixed** — the queue now admits any workflow-stage approver, read from `workflow_stages`. Verified in a browser, see JOURNEY-LOG.md 11.8 |
| Anything ending "and the farmer/driver is paid" | the payments module does not exist — an open decision, §15.1 | blocked on you |
| An approved requisition becoming a purchase order | no PO or goods-received exists in v1 | §15.5 |
| A collection agent learning their consignment was confirmed | no notification flows back | journey batch 3 |

---

## 1. Collection Agent — the morning round

**Account:** `sani.bello@gondalfulbe.ng` · scope: **one collection point**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 1.1 | Record a delivery | Milk Flow → Deliveries → **+ Record Delivery** → pick a farmer, 22 L presented → Save | `DEL-####` created; accepted litres = presented − rejected |
| 1.2 | Record several without re-navigating | Same, but press **Save & add another** | Returns to the open form with the point still chosen |
| 1.3 | Record a rejection | 20 L presented, 5 L rejected, reason "Adulteration" | Accepted shows 15 L; the reason is stored |
| 1.4 | Be stopped after the cut-off | Set the delivery time after the point's cut-off (07:00) | Refused, with a plain message — no rule code |
| 1.5 | Keep typed work on a refusal | Cause 1.4 with several fields filled | Form reopens with everything still typed |
| 1.6 | Enrol a farmer | Community → Farmers → add | Farmer created, enrolled-by = you |
| 1.7 | Dispatch a consignment | Deliveries → Consignments → tick the morning's deliveries → Dispatch | `CNS-####`; litres = sum of the deliveries |
| **1.8** | **Cannot grade milk** | Look for a grade control anywhere | None offered — grading is the centre's job |
| **1.9** | **Cannot adjust a volume** | Open a delivery, look for "Record adjustment" | Not offered — you lack `milk.adjustment.create` |
| **1.10** | **Cannot see another point** | Deliveries list | Only your own point's deliveries |
| **1.11** | **Cannot open payroll** | Go to `/payroll` | 403 with a quotable `DENY-####` reference |

---

## 2. Milk Collection Officer — the centre

**Account:** `halima.yusuf@gondalfulbe.ng` · scope: **one centre**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 2.1 | See what is waiting | Open your centre | "Awaiting confirmation" queue |
| 2.2 | Record quality tests | Open Confirm on a consignment → enter each reading → **Record** | Pass/fail shown per test |
| 2.3 | Be blocked from grading early | Try to pick a grade before the required tests | Grade list disabled, naming what is missing |
| 2.4 | Confirm with a grade | Complete tests → grade → Confirm | Confirmed; rate saved onto the consignment |
| 2.5 | Adjust before confirming | Consignments → **Adjust** → −2.5 L with a reason | Applied when you confirm |
| 2.6 | Be stopped adjusting after | Adjust an already-confirmed consignment | Refused with an explanation |
| 2.7 | Grade one confirmed without a grade | Confirm with no grade → **Assign grade** | Graded; rate is the *confirmation day's*, not today's |
| 2.8 | Dispatch a batch | Centre → Dispatch batch | `BATCH-####`; only confirmed+graded consignments join |
| **2.9** | **Cannot reconcile at the factory** | Go to `/reconciliation` | 403 — that is the supervisor's |
| **2.10** | **Cannot see another centre** | Try another centre from the list | 403 naming your scope |

---

## 3. Milk Collection Supervisor — network oversight

**Account:** `muhammad.bello@gondalfulbe.ng` · scope: **network**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 3.1 | Reconcile a batch | Reconciliation → enter litres received | Variance computed and shown as a percentage |
| 3.2 | Be forced to explain a big variance | Enter a variance beyond the 1% tolerance | Refused until a cause and a supervisor note are given |
| 3.3 | Release a batch | Reconcile within tolerance → Release | Status becomes released |
| 3.4 | Create a collection point | Collection Points → **+ Add Collection Point** | Created; assignable to a centre |
| 3.5 | See network totals | Dashboard | Whole-network litres, not one centre's |

---

## 4. Sales Officer — the shop counter

**Account:** `hauwa.ibrahim@gondalfulbe.ng` · scope: **own transactions**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 4.1 | Sell one item | Shop → Sales → Record Sale → one product, cash | Receipt `RCP-####`; **a one-item sale must work** |
| 4.2 | See the price before committing | Open the product picker | Selling price shown in the option |
| 4.3 | Sell against a farmer's milk | Customer = farmer, payment = milk deduction | Deduction recorded against that farmer |
| 4.4 | Find a farmer quickly | Type part of a name in the picker | Filters as you type |
| 4.5 | Be stopped without a prescription | Sell a prescription-category product with no reference | Refused, naming what is needed |
| 4.6 | Be stopped beyond stock | Sell more than is on hand | Refused; stock never goes negative |
| **4.7** | **Cannot see revenue or margin** | Sales screen tiles | Show "—" and "not shown to your role" |
| **4.8** | **Cannot see another officer's sales** | Sales list | Only your own |
| **4.9** | **Cannot void a sale** | Open a sale | No void control — manager only |

---

## 5. One-Stop Shop Manager — the shop

**Account:** `amina.kabir@gondalfulbe.ng` · scope: **network**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 5.1 | See the money | Sales screen | Revenue, margin and credit outstanding all visible |
| 5.2 | Void a wrong sale | Open a sale → **Void** with a reason | Stock returns; any deduction is cancelled; revenue drops |
| 5.3 | Sell on credit to a cooperative | Customer = cooperative, payment = credit | Posts to that cooperative's ledger |
| 5.4 | Be stopped selling expired stock | Sell from a product whose only stock is out of date | Refused, telling you how much has expired |
| 5.5 | Receive stock | Inventory → product → Receive stock | Batch recorded with expiry |
| 5.6 | Create a product category | Shop → Categories | Created; drives prescription and credit rules |

---

## 6. Inventory Officer — the stock room

**Account:** `ibrahim.sale@gondalfulbe.ng`

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 6.1 | Adjust a stock count | Inventory → product → adjust with a reason | Recorded; reason is mandatory |
| 6.2 | Get a low-stock alert | Sell a product down past its reorder level | One notification on the crossing — **not one per sale** |
| **6.3** | **Cannot sell** | Look for the Sales screen | Not in your navigation |
| **6.4** | **Cannot see revenue** | Anywhere in the shop | No money figures at all |

---

## 7. Extension Agent — the field

**Account:** `yusuf.garba@gondalfulbe.ng` · scope: **assigned communities**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 7.1 | Log a field activity | Field Activities → add | Recorded against your community |
| 7.2 | Enrol a farmer | Farmers → add | Created in your community |
| 7.3 | See a quality follow-up | A farmer with three rejections in the window | Follow-up opened automatically |
| **7.4** | **Cannot see another community's farmers** | Farmers list | Only your assigned communities |
| **7.5** | **Cannot see milk volumes or money** | Navigation | Neither is offered |

---

## 8. Department Head — approvals

**Account:** `staff8@gondalfulbe.ng` · scope: **own department**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 8.1 | Raise a requisition | Requisitions → new | Draft, then submitted |
| 8.2 | Approve one from your department | Approvals | Moves to the next stage |
| 8.3 | Approve leave | Leave | Approved, or escalated above 5 days |
| **8.4** | **Cannot approve your own requisition** | Raise one, then try to approve it | Refused — the requester is never the approver |
| **8.5** | **Cannot see another department's requisitions** | Requisitions list | Only your own department |

---

## 9. Internal Audit — read everything, change nothing

**Account:** `umar.muduru@gondalfulbe.ng` · scope: **network**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 9.1 | Read the audit log | Admin → Audit Log | Every event, with actor and time |
| 9.2 | Trace a refusal | Filter by a `DENY-####` reference | Shows the missing permission and the roles the user held |
| 9.3 | Approve a requisition at your stage | Approvals | Moves on |
| 9.4 | See every module | Navigation | Milk, shop, community, HR, logistics — all readable |
| **9.5** | **Cannot record, edit or delete anything operational** | Try any create button | None offered anywhere |

---

## 10. Accounts — the money

**Account:** `aliyu.danjuma@gondalfulbe.ng` · scope: **network**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 10.1 | Approve a requisition at the Accounts stage | Approvals | Moves on |
| 10.2 | Generate payroll | Payroll → run | Test accounts excluded from the run |
| 10.3 | See shop revenue | Shop | Full money figures |
| **10.4** | **Cannot record a delivery or a sale** | Milk, shop | Read-only |

---

## 11. HR Manager — people

**Account:** `rahma.sule@gondalfulbe.ng` · scope: **network**

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 11.1 | Add an employee | Employees → **+ Add employee** | Created and on payroll |
| 11.2 | Add a department | Departments → **+ Add department** | Created; immediately selectable on the employee form |
| 11.3 | Open a position | Positions → **+ Open a position** | Vacancy recorded |
| 11.4 | Change someone's pay | Employee → edit gross monthly | Audit log names the old and new figures |
| 11.5 | See only masked bank details | After entering an account number | Only the last four digits are kept |
| 11.6 | Raise leave for someone else | Leave → Request leave → pick the employee | Recorded against them, not you |
| **11.7** | **Cannot see milk or shop operations** | Navigation | Not offered |
| 11.8 | **Approvals queue** | Go to Approvals | The queue opens, carrying the leave and payroll items HR is named on |

---

## 12. Executive Director / General Manager

**Accounts:** `mohammed.aliyu@gondalfulbe.ng` (ED) · `musa.abdulhamid@gondalfulbe.ng` (GM)

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 12.1 | Approve at your stage | Approvals | ED at stage 4, GM at stage 6 |
| 12.2 | See the whole network | Dashboard | Network totals, all modules readable |
| 12.3 | GM approves payroll | Payroll | Approved |
| **12.4** | **Cannot record operational data** | Any module | Read-only — you approve, you do not enter |
| **12.5** | **Cannot create users or roles** | Admin | Not offered — that is the administrator's |

---

## 13. System Administrator

**Account:** `s.ahmed@gondalfulbe.ng` (pilot) or `sadiq.ahmed@gondalfulbe.ng` (demo)

| # | Story | Steps | Expect |
| --- | --- | --- | --- |
| 13.1 | Create a user | Admin → Users → add | **No password field** — an activation code is emailed |
| 13.2 | Activate as that new person | Open the welcome email's link → enter the code → choose a password | Signs in |
| 13.3 | Assign a role with a scope | User → Assign role → pick a centre | Access limited to that centre |
| 13.4 | Unlock a locked account | Fail sign-in 5 times as someone → **Unlock now** | Unlocked and audited |
| 13.5 | Revoke a stolen device | User → Trusted devices → **Revoke** | That device needs a code again |
| 13.6 | Run a permission test | Admin → Permission Tests → pick a role and a test account | Every permission checked, plus scope probes |
| 13.7 | Change a grade rate | Settings → rates | New rate applies from its date; past consignments keep theirs |
| **13.8** | **Cannot target production** | Permission test environment list | Only development and staging offered |

---

## 14. Cross-role journeys — the highest-value tests

These are where the system either holds together or does not. Each needs two or
three sign-ins.

**14.1 Milk from farmer to factory**
Agent records deliveries → agent dispatches a consignment → officer confirms and
grades it → officer dispatches a batch → supervisor reconciles it at the factory
→ supervisor releases it.
*Watch for:* the litres surviving every step unchanged, and the rate being the
one in force on the confirmation day.

**14.2 A requisition through six stages**
Logistics Officer raises ₦3.4m → Department Head → Internal Audit → ED →
Accounts → GM.
*Watch for:* each approver seeing it only at their stage, the requester never
being able to approve their own, and the amount band deciding how many stages
apply.

**14.3 Leave, both sides**
An employee requests leave → their Department Head approves → over 5 days it
escalates to HR Manager.
*Watch for:* the employee being able to see their own request — self-service is
the most-used path in the system.

**14.4 A wrong sale, corrected**
Sales Officer rings up the wrong product on a farmer's milk deduction → Shop
Manager voids it.
*Watch for:* stock returning, the deduction cancelled, and revenue falling back.

**14.5 The permission model itself**
Administrator creates a test user → assigns a role → runs a permission test →
approves the configuration.
*Watch for:* the run reporting any over-permission; this is the sign-off gate for
any role change.

---

## Reporting what you find

Useful bug report: **which account, which screen, what you did, what happened,
what you expected.** The account matters most — nearly every surprise in this
system is a permission boundary working correctly, and the account is what tells
the two apart.
