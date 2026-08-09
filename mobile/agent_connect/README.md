# Gondal AgentConnect

The field app for extension agents in Adamawa. Revalidations (BR-36) and field
visits are captured **offline**, with a photograph and a coordinate, and sync
when a connection appears.

**Verified on Flutter 3.44.9 stable:** `flutter analyze` clean, `flutter test`
31/31 passing, `flutter build apk --debug` produces an APK carrying the right
permissions and the `ng.gondalfulbe.agentconnect` identity. Every endpoint it
calls has been exercised against a running server with a real token, including
the full two-step sign-in with 2FA on — see *What was checked against the real
API* below.

Not yet run on a handset: the UI itself has not been driven on a device.

## Running it

```bash
cd mobile/agent_connect && flutter pub get
```

Point it at your backend. `10.0.2.2` is the host machine as seen from an Android
emulator; use your LAN IP for a real handset.

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
```

Sign in with a seeded agent — `jamila.usman@gondalfulbe.ng`. The demo password
is in `DemoChainCastSeeder`.

## How offline works

The design rule is that **nothing in the UI ever awaits the network**. A capture
is written to a local SQLite outbox in the same gesture that saves the form, and
the screen closes on that.

```
capture ──▶ outbox (sqlite)  ──sync──▶ POST /api/v1/sync/batch
                │                              │
                └── photo_outbox ──────────────┴──▶ POST /api/v1/attachments
                    (only once the record has a server id)
```

Three things carry the whole design:

**`client_uuid` is minted once, at capture, and never regenerated.** The server
keys idempotency on it (`mobile_sync_records`), so a batch delivered twice
because the response was lost writes one row, not two. Regenerating it on retry
is the single change that would turn this app into a duplicate factory.

**Records go before photographs.** The server resolves a photo to its record
*through* `client_uuid`, so a photo whose record has not arrived is refused.
`LocalDb.sendablePhotos()` enforces the order with a SQL join rather than a
hope. Photos are a separate queue so a 2 MB image can fail all afternoon without
holding up the twenty text records behind it.

**Three failure modes, treated differently.** A dead network marks nothing and
retries. A per-record refusal (a permission the agent lacks, a farmer the server
does not have) marks *that record* `failed` with the server's own wording and
leaves the rest of the batch alone — the endpoint returns per-record results
precisely so one bad record cannot poison a morning. A 401 stops everything and
signs the agent out; the queue survives untouched and goes on the next sign-in.

## Sign-in in a place with no inbox

AUTH-1 is password-then-emailed-code, which an agent in Gengle cannot complete.
AUTH-2's device token is the backend's own answer: the phone proves it is the
same phone that completed a code once and is not asked again. The token is saved
on the first successful verify and replayed on every later sign-in, so the code
screen appears **once per device**, not once per morning.

Two details carry it, and both were wrong in the first build:

**Step 2 is keyed on the `challenge`, not on the email.** The login response
returns a single-use random token; the server caches the pending sign-in under a
hash of it and forgets it once consumed, so it cannot be reconstructed on the
phone. Posting `{email, code}` to `/auth/verify` is a 422 every time.

**`remember_device: true` is what mints the device token.** Omit it and the
server never issues one, so the code screen returns every morning — the exact
failure the design exists to prevent.

The `code_required` branch is answered with HTTP **422**, so it must not be
treated as an error. `ApiException` carries the decoded body for this reason.

## GPS and photographs are optional, deliberately

Both are evidence for a human reviewing an exception — neither is a gate. A
valley with no sky, a denied permission, a broken radio: the visit must still be
recordable, or the work goes back to paper, which is the failure this system
exists to end. `LocationService` returns `null` on every failure path rather
than throwing, and the backend stores no coordinate rather than `0,0` — which is
a real place in the Gulf of Guinea and would read as somewhere the agent
demonstrably was not.

Photos are shrunk to ~1600px/q70 (200–400 KB) **at capture**, not at upload: a
raw 6 MB frame would fill the phone over a week in the field and take four times
as long on the worst connection it will ever meet. They are written to the app's
documents directory, not the OS cache, which can be reclaimed under storage
pressure.

## Layout

| Path | What it is |
| --- | --- |
| `lib/data/local_db.dart` | SQLite schema, the outbox, the photo queue, per-agent ownership |
| `lib/core/api_client.dart` | HTTP, token storage, the permanent-vs-retryable distinction |
| `lib/services/sync_engine.dart` | Drains the queues; the ordering rules live here |
| `lib/services/capture_services.dart` | GPS fix and camera + compression |
| `lib/data/repositories.dart` | Auth, cached reference data, capture |
| `lib/ui/` | Login, home, milk collection, revalidations, field visit, capture panel, farmer picker, unsent |

### Milk collection

BR-6's sum — accepted = presented − rejected — is shown live on the form, so the
agent and the farmer see the number before it is saved rather than after.

BR-3's cut-off is explained on the form instead of refusing at sync time. The
phone knows the point's cut-off from `form-options`, so late milk that is not
rejected in full disables Save with the reason and the remedy, rather than
letting the agent finish the round and discover the refusal hours later in
another village. The server still enforces it — this only saves the walk.

The **override is deliberately not offered**. It sits behind
`milk.deliveries.cutoff_override`, which the Supervisor and Collection Officer
hold and the agent does not, exactly so the person carrying the late milk cannot
authorise accepting it.

Volumes are sent as **strings**. The server stores `decimal(10,2)` and subtracts
them to get what a farmer is paid; a double arrives as `22.399999999999999`.
| `test/outbox_test.dart` | The queue state machine (17) |
| `test/sync_engine_test.dart` | The engine against a scripted server (10) |
| `test/farmer_picker_test.dart` | The picker that decides `farmers_reached` (4) |

### Whose queue is it

A field phone gets handed between agents at a collection point. Every outbox row
carries an `owner` — the capturing agent's email — and `pendingBatch` only ever
returns the signed-in agent's own rows. Without it, agent A captures eleven
visits, hands the phone over, and agent B syncs them: the records are not lost,
they are **misattributed**, under B's scope and B's name in the audit trail,
which nobody notices.

## Backend endpoints used

All of these already existed except the last, which was added for this app.

| Endpoint | Use |
| --- | --- |
| `POST /api/v1/auth/login` · `/verify` · `/logout` | Two-step sign-in with device trust |
| `GET /api/v1/me` | Who the agent is |
| `GET /api/v1/agent/form-options` | Communities, points, reasons — cached |
| `GET /api/v1/validations` | The BR-36 queue assigned to this worker |
| `POST /api/v1/sync/batch` | The outbox, per-record results |
| `POST /api/v1/attachments` | **New.** One photograph, matched by `client_uuid` |

## What was checked against the real API

Run against `php artisan serve` with a token issued to
`jamila.usman@gondalfulbe.ng`. Three of these found bugs.

| Checked | Result |
| --- | --- |
| **Two-step login, 2FA ON** | `code_required` → challenge + masked email → verify → token **and** device token |
| **Re-login with the device token** | signed in, code step skipped |
| **Wrong password** | `Those details do not match an account.` shown verbatim |
| Two-step login, 2FA off | token issued, code step skipped |
| `/me` | `Tudun Wada, Jimeta, Karewa only · Own records only` |
| `/agent/form-options` | 26 communities, parsed by the visit form |
| `/validations` | **found a bug** — payload is `data.assignments`; the app read `data.validations` and rendered an empty queue |
| `/farmers/search?community=1` | 6 farmers, shape matches the picker |
| `/sync/batch` field visit, in scope | accepted, GPS stored at 7 decimals |
| `/sync/batch` field visit, out of scope | **found a bug** — accepted before the fix, now `Not permitted: Outside your data scope.` |
| `/sync/batch` revalidation with correction | accepted; farmer's herd and phone updated; coordinate stored |
| **Delivery, on time, part rejected** | accepted — 22.40 − 2.40 = 20.00, status `partial`, reason Adulteration |
| **Delivery after the cut-off, not rejected** | refused — `BR-3 — This delivery is after the 07:00 cut-off…` |
| **Same, rejected in full for late** | accepted — status `rejected`, 0 L payable, `was_after_cutoff` set |
| **Delivery at another agent's point** | refused — `Not permitted: Outside your data scope.` |
| `/attachments` photo upload | 201, stored on the private disk |
| `/attachments` same photo ×3 | **found a bug** — duplicated when captioned; now returns the same id every time |
| `/attachments` for an unsynced record | 422, "Sync it first, then send the photo." |

## Not built yet

Honest list, so nobody discovers these in a field:

- **Farmer enrolment.** `sync/batch` accepts `farmer_registrations` and the app
  does not send them yet.
- **A photo on a delivery.** Milk collection captures no photograph; the
  attachment endpoint would take one, but a rejection dispute is argued over
  litres and a reason, not a picture of a churn. Add it if the field says
  otherwise.
- **Background sync with the app closed.** Sync runs on connectivity changes and
  a 5-minute timer while the app is alive. `workmanager` was declared for this
  and never registered — and 0.5.2 still calls the v1 Android embedding, so it
  broke `flutter build apk` outright; it has been removed. Doing this properly
  needs ^0.10 plus its platform setup.
- **Driving the UI on a handset.** The screens compile and the endpoints they
  call are verified, but nobody has tapped through them on a phone.
- **Widget tests for the sqflite-backed screens.** `FarmerPicker` is covered
  because it is a pure widget. The Unsent screen is not: `testWidgets` runs its
  body in a fake-async zone, sqflite_ffi does its work on a real isolate whose
  timers that clock never advances, so the screen's query never completes, its
  spinner animates forever and `pumpAndSettle` waits out a ten-minute timeout.
  `runAsync` gets one test through and then leaks real timers into the rest of
  the file. The behaviour those tests would cover — retry, discard, ordering,
  the stored refusal text — is already covered against the real schema in
  `outbox_test.dart`. Making the screen testable means having it take a loader
  callback rather than a `LocalDb`.
- **`/api/v1/me` is fetched but its permissions are unused.** The home screen
  offers "Log a field visit" to anyone signed in; an agent without
  `community.extension.create` only discovers the refusal at sync time. The
  payload already carries `can_log_field_visits`.
