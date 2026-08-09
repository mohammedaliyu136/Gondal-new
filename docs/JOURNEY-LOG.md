# Journey log — end-to-end walk of the Gondal ERP

Every line below was produced by driving the real application in Chrome against
the seeded database, not by reading code. Each role has a recording beside it;
the frame number in the last column points into that recording.

**Recordings.** In `journey-recordings/`, three ways to watch:

| | |
| --- | --- |
| `full-walk-narrated.mp4` | all nine roles, **with voice-over**, 13m 25s |
| `<role>-narrated.mp4` | one narrated video per role |
| `<role>.mp4` / `.gif` | silent, 2.5s a frame, for skimming |

The narration explains what is being done and why it matters, and says the
verdict out loud — including the failures. Each frame is held for exactly as long
as its line takes to speak, so the picture never moves mid-sentence. Voice is the
macOS `say` engine (Daniel, en_GB); nothing is sent anywhere to produce it.

The PNG frames are under `journey-recordings/frames/<role>/`, and the encoders are
`driver/encode.py` (silent) and `driver/narrate.py` (narrated). Both read the
frames already on disk, so pace, wording and format can all be changed without
walking the application again.

**Signing in.** Demo accounts use `GondalDemo!2026`, pilot accounts
`GondalPilot!2026`. Two-factor is ON for all 46 accounts — `migrate:fresh --seed`
turns it back on. The code is never stored in plaintext (`login_codes.code_hash`
is a hash), so the walk reads the delivered message out of `storage/logs/laravel.log`,
which is where `MAIL_MAILER=log` puts it. It is never guessed.

**Verdicts.** ✅ works · ❌ broken · ⚠️ missing · 🛡️ refuses correctly.
A 🛡️ is a pass: it means a "cannot" story genuinely could not.


## Summary

| Verdict | Count |
| --- | --- |
| ✅ works | 64 |
| 🛡️ refuses correctly | 13 |
| ⚠️ missing | 0 |
| ❌ broken | 0 |

9 roles walked, 77 checks.


Nothing outstanding: every check either passed or refused correctly.


## Defects found and fixed

| # | Defect | Root cause | Fix | Test |
| --- | --- | --- | --- | --- |
| 1 | **Recording a delivery threw a 500 on any day after the data was seeded.** Same for the first shop sale. | `deliveries` and `sales` reset their counter DAILY but rendered `{prefix}-{number}`, carrying no date — so day two's `DEL-0001` was byte-identical to day one's, and the column is unique. Every test runs against a freshly seeded database, which is always day one, so the whole suite missed it. | The reference now carries the date: `DEL-20260803-0001`. Migration `009600` fixes deployed databases; existing references are left untouched. | `test_a_resetting_sequence_carries_its_period_in_the_reference`, `test_a_daily_sequence_does_not_repeat_itself_the_next_day` |
| 2 | **A collection agent enrolled a farmer and was immediately refused sight of it** (`DENY-0004`, scope). | A point-scoped user's farmer scope is `default_collection_point_id IN (their points)`. The form let the point be blank, and NULL is in no list, so the farmer was created and instantly invisible to its own enroller. | Where the enroller covers exactly one point it is filled in for them; where they cover several the form asks, because guessing would put the farmer at the wrong point. | `test_an_agent_who_enrols_a_farmer_can_still_see_them` and two others |
| 3 | **The Batches screen offered no way to dispatch a batch** and no pointer to where — to a role holding `milk.batch.dispatch.create`. | A batch is dispatched from a centre, so there is correctly no form here; but the screen said nothing, and the existing hint was prose with no link. | A "Dispatch from a center" action for holders of the permission, and the hint is now a link. | `test_the_batches_screen_leads_a_dispatcher_to_where_batches_are_dispatched` |
| 4 | **A throttled sign-in showed a bare white "429 Too Many Requests"** — no branding, no reason, no indication of whether the wait was a minute or permanent. | NFR-8 rate-limits auth correctly, but no `429` view existed, so Laravel's fallback was what an operator saw. | `resources/views/errors/429.blade.php`, in the same voice as the access-denied screen: what happened, that nothing is locked, and when to retry. | `test_nfr8_a_throttled_sign_in_explains_itself` |
| 5 | **Searching the audit log for a quotable `DENY-####` reference found nothing.** | The search box matched `summary` only. A separate `reference` filter existed, but nobody reading a reference off a screenshot knows to use a different field — and AUDIT-5's whole point is that quoting it finds the entry. | The general search now matches the reference too; the placeholder says so. | `test_audit5_the_audit_search_finds_a_quotable_reference` |
| 6 | `php artisan db:seed` could not be run twice. | A date-format mismatch made the grade-rate lookup always miss, and `DemoDataSeeder` died on a unique constraint partway through, leaving the database half seeded. | The lookup matches the stored format; the demo seeder now says it has already run and leaves the data alone. | verified by `migrate:fresh --seed` and a repeat `db:seed` |

Also confirmed fixed in a real browser: **HR Manager can now open the approvals queue** (story 11.8), which `USER-STORIES.md` still lists as a known Phase B defect. That line in the stories file is now out of date.

## What the walk got wrong

Worth recording, because these cost time and would cost it again:

- The harness first searched the whole page for outcome text, and matched a modal's own *subtitle* ("A sale that would take stock below zero is refused") as if it were an error. Outcomes are now read from `.alert` boxes only.
- `canSee()` counted buttons inside hidden `:target` modals, so every modal's own submit button read as an offered action. It now requires the element to be visible.
- `submit()` clicked the *first* submit button; "Save & add another" sits before "Record delivery", so a correctly-reopened form looked like a modal that would not close. It now clicks the primary action.
- Several "missing" verdicts were the walk guessing a URL or a button's wording — `/community/farmers` for `/farmers`, `/admin/audit` for `/admin/audit-log`, "Add user" for "+ Create User". Read the route list and the blade, do not guess.
- Invented an enum value (`walk_in` for `walkin`) and the browser silently refused to submit the form. The real vocabulary is in the view.


---

## 01-collection-agent

Recording: [`01-collection-agent.mp4`](journey-recordings/01-collection-agent.mp4) · [gif](journey-recordings/01-collection-agent.gif) · captions: [`01-collection-agent.md`](journey-recordings/01-collection-agent.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 1.0 | Sign in (with two-factor) | email + password + emailed code | reached the dashboard | ✅ works | 5 |
| 1.1a | A way to record a delivery exists | looked for the action on /milk-flow/deliveries | the + Record Delivery action is offered | ✅ works | 6 |
| 1.1b | Record a delivery (22 L) | submitted the record-delivery form | ✅ DEL-20260803-0019 recorded — 22 L accepted from Amina Sale. | ✅ works | 9 |
| 1.1c | The modal closes after saving | checked for .modal.open after the redirect | the modal closed | ✅ works | 9 |
| 1.2 | Save & add another returns to the open form | pressed Save & add another | returned to the open form | ✅ works | 11 |
| 1.3 | Record a rejection (20 presented, 5 rejected → 15 accepted) | submitted with a rejection reason | ✅ DEL-20260803-0021 recorded — 15 L accepted from Amina Sale. | ✅ works | 14 |
| 1.4 | Cannot record after the cut-off | submitted a delivery timed 09:45 | refused: ❌ This delivery is after the 07:00 cut-off. Reject it in full for "Failure to meet delivery time", or record a supervisor override with a reason. | 🛡️ refuses correctly | 17 |
| 1.4b | The refusal carries no rule code | looked for BR-xx in the page | plain language, no rule code | ✅ works | 17 |
| 1.5 | Typed work survives the refusal | checked the reopened form | modal open=true, presented="31", notes kept=true | ✅ works | 17 |
| 1.6 | Enrol a farmer | Community → Farmers, then looked for an add action | nav link present=true; the add action is offered | ✅ works | 18 |
| 1.6b | The enrol-farmer form opens | followed the + Enrol Farmer action | modal modal-enrol opened | ✅ works | 19 |
| 1.6c | Enrol a farmer actually creates one, and the enroller can see it | submitted the enrol form and followed the redirect | landed on the new farmer record: http://127.0.0.1:8008/farmers/1847# | ✅ works | 21 |
| 1.7 | Dispatch a consignment | looked for the dispatch action | the dispatch action is offered | ✅ works | 22 |
| 1.7b | Dispatch actually creates a consignment | ticked the morning deliveries and dispatched | ✅ CNS-0461 dispatched — 55 L. | ✅ works | 25 |
| 1.8 | CANNOT grade milk | looked for any grade control | no grade control offered | 🛡️ refuses correctly | 26 |
| 1.9 | CANNOT adjust a volume | looked for an adjust control | no adjust control offered | 🛡️ refuses correctly | 26 |
| 1.10 | CANNOT see another point | read the delivery list and its scope note | scope note: "Farmer deliveries at collection points · Mon, 3 Aug 2026 \| After the point’s cut-off, reject the delivery in full or record a supervisor override below. \| Accepted litres are worked out for you as pre"; points visible: ["Tudun Wada"] | ✅ works | 27 |
| 1.11 | CANNOT open payroll | navigated to /payroll | status 403, quotable reference DENY-0016 | 🛡️ refuses correctly | 28 |

Browser console errors: `['Failed to load resource: the server responded with a status of 403 (Forbidden)']`


---

## 02-collection-officer

Recording: [`02-collection-officer.mp4`](journey-recordings/02-collection-officer.mp4) · [gif](journey-recordings/02-collection-officer.gif) · captions: [`02-collection-officer.md`](journey-recordings/02-collection-officer.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 2.0 | Sign in | email + password + emailed code | reached the dashboard | ✅ works | 5 |
| 2.1 | See what is waiting | opened the consignments screen | an awaiting-confirmation queue is shown | ✅ works | 6 |
| 2.5a | An adjust control is offered | looked for Adjust on the list | Adjust is offered | ✅ works | 7 |
| 2.5b | Adjust before confirming (−2.5 L) | submitted the adjustment | ✅ Adjustment recorded. It takes effect when the consignment is confirmed. | ✅ works | 10 |
| 2.3 | Blocked from grading before the required tests | opened Confirm and inspected the grade control | grade list disabled; it says: "Record Density (lactometer), Intake temperature, Alcohol test first." | 🛡️ refuses correctly | 11 |
| 2.2a | Quality tests are offered on the confirm form | opened Confirm | 3 recordable tests | ✅ works | 11 |
| 2.2b | Record every quality test | pressed Record on each test in turn | 3 of 3 recorded without error | ✅ works | 15 |
| 2.3b | The grade control unlocks once the tests are recorded | re-opened Confirm | grade list is now enabled | ✅ works | 16 |
| 2.4 | Confirm with a grade | submitted the confirm form with a grade | ✅ CNS-0461 confirmed at 52.50 L — Grade A. | ✅ works | 18 |
| 2.7 | Grade a consignment confirmed without one | looked for Assign grade | Assign grade is offered | ✅ works | 19 |
| 2.8a | The Batches screen leads somewhere you can dispatch | looked at /milk-flow/batches | the screen points at the centre where batches are dispatched | ✅ works | 20 |
| 2.8b | Dispatch a batch from the centre | opened the centre and looked for Dispatch batch | Dispatch batch is offered | ✅ works | 22 |
| 2.8c | Dispatching a batch creates one | ticked the eligible consignments and dispatched | ✅ BATCH-0097 dispatched — 52.50 L. | ✅ works | 25 |
| 2.9 | CANNOT reconcile at the factory | navigated to /reconciliation | status 403, ref DENY-0017 | 🛡️ refuses correctly | 26 |
| 2.10 | CANNOT see another centre | read the centres list | centres visible: ["Kumbotso\nCTR-KUMB"] | ✅ works | 27 |

Browser console errors: `['Failed to load resource: the server responded with a status of 403 (Forbidden)']`


---

## 03-milk-supervisor

Recording: [`03-milk-supervisor.mp4`](journey-recordings/03-milk-supervisor.mp4) · [gif](journey-recordings/03-milk-supervisor.gif) · captions: [`03-milk-supervisor.md`](journey-recordings/03-milk-supervisor.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 3.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 3.1a | Reconciliation is reachable and offers work | opened /reconciliation | a batch is offered for reconciliation | ✅ works | 6 |
| 3.2 | Forced to explain a variance beyond tolerance | submitted ~50% short with no cause or note | refused: ❌ The variance is 50.00% against a tolerance of 1.0%. Select the cause of the discrepancy. | 🛡️ refuses correctly | 9 |
| 3.1b | Reconcile a batch | entered litres received equal to dispatched | ✅ BATCH-0088 reconciled — 0.00 L variance (0.00%) against a 1.0% tolerance. | ✅ works | 12 |
| 3.3 | Release a batch | looked for a Release action | Release is offered | ✅ works | 13 |
| 3.4 | Create a collection point | looked for the add action | the add action is offered | ✅ works | 14 |
| 3.5 | See network totals | read the dashboard scope banner | ℹ️ Figures cover Network-wide (unrestricted) · Own records only. | ✅ works | 15 |

---

## 04-sales-officer

Recording: [`04-sales-officer.mp4`](journey-recordings/04-sales-officer.mp4) · [gif](journey-recordings/04-sales-officer.gif) · captions: [`04-sales-officer.md`](journey-recordings/04-sales-officer.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 4.0 | Sign in | email + password + emailed code | reached the dashboard | ✅ works | 5 |
| 4.1a | A way to record a sale exists | looked for the action | Record Sale is offered | ✅ works | 6 |
| 4.2 | See the price before committing | opened the product picker | price shown in the option: "Aluminium milk can 40L — ₦34,000.00 (17 unit in stock)" | ✅ works | 7 |
| 4.1b | Sell ONE item | submitted a single-line cash sale | ✅ Sale RCP-20260803-00003 recorded — ₦34,000.00. | ✅ works | 9 |
| 4.6 | CANNOT sell beyond stock | asked for 999,999 units | refused: ❌ Only 16 unit of Aluminium milk can 40L in stock — the sale would take it negative. | 🛡️ refuses correctly | 12 |
| 4.7 | CANNOT see revenue or margin | read the money tiles on the sales screen | every money tile is withheld: REVENUE TODAY — not shown to your role \| MARGIN — not shown to your role \| CREDIT OUTSTANDING — not shown to your role | 🛡️ refuses correctly | 13 |
| 4.8 | CANNOT see another officer's sales | read the sales list | 3 distinct rows sampled; scope is own transactions | ✅ works | 13 |
| 4.9 | CANNOT void a sale | opened a sale and looked for Void | no void control — manager only | 🛡️ refuses correctly | 14 |

---

## 05-shop-manager

Recording: [`05-shop-manager.mp4`](journey-recordings/05-shop-manager.mp4) · [gif](journey-recordings/05-shop-manager.gif) · captions: [`05-shop-manager.md`](journey-recordings/05-shop-manager.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 5.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 5.1 | See the money | read the money tiles | visible: REVENUE TODAY ₦68k across the shop \| MARGIN TODAY ₦12k against the cost recorded at the time of sale \| CREDIT OUTSTANDING ₦125k credit sales to date | ✅ works | 6 |
| 5.2a | A void control is offered to the manager | opened a sale | Void is offered | ✅ works | 7 |
| 5.2b | Void a wrong sale | submitted the void with a reason | ✅ RCP-20260803-00003 voided. Stock has been returned. | ✅ works | 10 |
| 5.5 | Receive stock | opened a product and looked for Receive stock | Receive stock is offered | ✅ works | 12 |
| 5.6 | Create a product category | looked for the add action | the add action is offered | ✅ works | 13 |

---

## 08-department-head

Recording: [`08-department-head.mp4`](journey-recordings/08-department-head.mp4) · [gif](journey-recordings/08-department-head.gif) · captions: [`08-department-head.md`](journey-recordings/08-department-head.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 8.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 8.1 | Raise a requisition | looked for the raise action | a raise action is offered | ✅ works | 6 |
| 8.2 | Approve one from your department | opened the approvals queue | 4 items waiting | ✅ works | 7 |
| 8.5 | CANNOT see another department's requisitions | read the requisitions list | departments visible: Logistics | ✅ works | 8 |

---

## 09-internal-audit

Recording: [`09-internal-audit.mp4`](journey-recordings/09-internal-audit.mp4) · [gif](journey-recordings/09-internal-audit.gif) · captions: [`09-internal-audit.md`](journey-recordings/09-internal-audit.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 9.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 9.1 | Read the audit log | opened Admin → Audit Log | 25 entries listed | ✅ works | 6 |
| 9.2 | Trace a refusal by its DENY reference | filtered the log by DENY-0001 | the refusal is found and shown | ✅ works | 7 |
| 9.4 | See every module | opened each operational module in turn | every module readable | ✅ works | 12 |
| 9.5 | CANNOT record anything operational | swept the operational screens for create actions | no create action offered anywhere | 🛡️ refuses correctly | 15 |

---

## 11-hr-manager

Recording: [`11-hr-manager.mp4`](journey-recordings/11-hr-manager.mp4) · [gif](journey-recordings/11-hr-manager.gif) · captions: [`11-hr-manager.md`](journey-recordings/11-hr-manager.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 11.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 11.1 | Add an employee | looked for the add action | the add action is offered | ✅ works | 6 |
| 11.2 | Manage departments | opened /departments and looked for an add action | the add action is offered | ✅ works | 7 |
| 11.3 | Manage positions | opened /positions and looked for an add action | the add action is offered | ✅ works | 8 |
| 11.6 | Raise leave for someone else | opened Request Leave | an employee picker is offered | ✅ works | 9 |
| 11.7 | CANNOT see milk or shop operations | read the navigation links | no milk or shop links in the navigation | 🛡️ refuses correctly | 9 |
| 11.8 | Open the approvals queue (was a known defect) | navigated to /approvals | the queue opens | ✅ works | 10 |

---

## 13-system-administrator

Recording: [`13-system-administrator.mp4`](journey-recordings/13-system-administrator.mp4) · [gif](journey-recordings/13-system-administrator.gif) · captions: [`13-system-administrator.md`](journey-recordings/13-system-administrator.md)

| # | Story | What was done | What happened | Verdict | Frame |
| --- | --- | --- | --- | --- | --- |
| 13.0 | Sign in | credentials + emailed code | reached the dashboard | ✅ works | 5 |
| 13.1a | Create a user | looked for the add action | the add action is offered | ✅ works | 6 |
| 13.1b | BR-31 — no password field on the admin form | inspected every input on the users screen | no password field — activation is emailed | ✅ works | 6 |
| 13.3 | Assign a role with a scope | opened the assign-role form | target picker present, multiple=true, 63 targets | ✅ works | 7 |
| 13.6 | Run a permission test | opened the permission-test register | the register opens | ✅ works | 8 |
| 13.8 | CANNOT target production | read the environment list | offered: development, staging | 🛡️ refuses correctly | 8 |
| 13.7 | Change a grade rate | opened Settings | settings opens | ✅ works | 9 |
