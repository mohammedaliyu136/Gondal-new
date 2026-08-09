# The mobile surface — `/api/v1`

The contract between the Gondal ERP and **AgentConnect** (`agents_app/`).

ARCH-2 promised this: *"when [mobile applications] arrive a token guard is added
to `config/auth.php` and [the routes gain] it — nothing below has to change."*
That is exactly what happened. The session-authenticated `/api/*` routes the web
UI consumes are untouched; `/api/v1/*` is a second door into the same building.

**There is no second permission system.** A bearer token says *who* is calling.
Roles, scopes and §5 say what they may do — the same rows, the same
`Access::authorize()`, the same audit trail. A rule that holds in a browser holds
on a phone, and if it ever does not, that is a bug in one of them.

---

## 1. Sign-in (§10)

AUTH-1 makes this two steps, and the API says which step you are on rather than
leaving the client to infer it from a status code.

### `POST /api/v1/auth/login`

```json
{ "email": "s.bello@gondalfulbe.ng", "password": "…",
  "device_token": "…optional…", "device_name": "Pixel 6a · AgentConnect" }
```

Three possible answers:

| `status` | HTTP | Meaning |
|---|---|---|
| `signed_in` | 200 | Token issued. AUTH-2 device trust matched, or the account has 2FA off. |
| `code_required` | 200 | Credentials correct; a 6-digit code is in the user's inbox. Hold `data.challenge`. |
| `failed` | 422 | `reason` is `credentials`, `locked` (AUTH-6) or `deactivated` (BR-32). |

`is_success` is kept alongside `status` for the existing client build; it means
exactly `status === "signed_in"`.

### `POST /api/v1/auth/verify`

```json
{ "challenge": "…", "code": "418320", "remember_device": true }
```

Returns the `signed_in` payload. When `remember_device` is set, `data.device_token`
comes back too — AUTH-2's 30-day trust, to be stored in the OS keystore and sent
as `device_token` on the next login so the code step is skipped.

The half-finished sign-in lives in the **cache**, not a session: a phone that
gets closed between the password and the code must still be able to finish. The
handle expires in 15 minutes; the code itself in 10 (AUTH-3).

### `POST /api/v1/auth/logout`

Revokes **this** token only. Handing a phone to a colleague must not knock the
user's other devices, or their web session, offline.

### The token

`<id>|<secret>`, sent as `Authorization: Bearer …`. Stored hashed (NFR-9),
expires in 30 days (`gondal.auth.api_token_days`), and dies early if the AUTH-2
device it was issued alongside is revoked, or if the account is deactivated
(BR-32 — the middleware revokes it on the refused request).

---

## 2. Who am I, and what is my job? — `GET /api/v1/agent/permissions`

Also served at `/api/v1/me`. **This is the endpoint the whole role-aware client
is built on.** No permission gates it: a user may always read their own job.

```jsonc
{
  "data": {
    "user":  { "id": 12, "name": "Sani Bello", "email": "…", "initials": "SB",
               "role": "Collection Agent", "department": null, "is_test": false },

    "roles": [
      {
        "name": "Collection Agent",
        "description": "Point intake and the three rejection reasons",
        "scope": "Tudun Wada Point",          // this ASSIGNMENT's scope (SCOPE-1)
        "scope_type": "point",
        "accent": "info",                      // the ERP's own colour hint
        "is_automatic": false,                 // true for Staff (self-service)
        "mobile_home": "milk_collection",
        "responsibilities": [                  // §16 "Their day"
          "Meets farmers at the point from 05:30 and records each delivery in litres",
          "Runs the lactometer check and rejects milk using one of three reasons only"
        ],
        "restrictions": [                      // §16 "Cannot see"
          "No other point's deliveries and no network total"
        ]
      }
    ],

    "home":  "milk_collection",                // which surface the app opens on
    "scope": "Tudun Wada Point only",          // SCR-1 "Your Data Scope"

    "permissions":     { "can_record_milk_intake": true, "can_grade_milk": false, … },
    "permission_keys": ["milk.deliveries.create", "community.farmers.view", …],

    "assigned_communities": [], "assigned_points": ["Tudun Wada"], "assigned_centers": [],

    "metrics": { "volume_collected": 128.5, "farmers_under_care": 62 },

    "server_time_wat": "2026-08-05T06:12:00+01:00"
  }
}
```

Three things about this payload are deliberate:

**Responsibilities travel with the grant set.** They live in `roles.responsibilities`
and `roles.restrictions` (added by `2026_01_01_009700_add_persona_fields_to_roles`,
seeded by `RoleSeeder` from `personas.html`). The app renders them; it does not
know what a Collection Agent does. Reshape a role in the ERP and the phone
reshapes on its next refresh — which is the only arrangement in which the two
cannot drift, and drift is what the 30 Jul 2026 role clean-up was cleaning up.

**A missing metric is not a zero.** Figures are gated on the permission that
governs them, and withheld — not zeroed — when the user may not see them. An
Extension Agent gets no `volume_collected` key at all (§16: *"No volumes or
payment figures for the farmers they visit"*). `0 L` would be a claim about
production; absence is the truth. The client renders a dash.

**Capabilities and keys both.** `permissions` answers questions a phone actually
asks (`can_record_milk_intake`); `permission_keys` is the underlying truth
(`milk.deliveries.create`). The mapping is one-to-one and lives in
`App\Authorization\MobileCapabilities` — never a union of two permissions,
because "can_do_milk_things" is precisely the bundled authority ROLE-4 retired
two roles for. `agents_app/test/permission_contract_test.dart` fails if the two
lists ever diverge.

### ROLE-6 on a phone

*"Editing a role takes effect on the assigned users' NEXT REQUEST. No re-login
required."* A 30-day token is not a snapshot of permissions — it identifies the
user, and permissions are resolved fresh per request. An administrator disabling
a role reaches the field on the next call, with no re-issue.

---

## 3. Field data

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /api/v1/agent/form-options` | any of farmers / extension / deliveries `view` | Communities, cooperatives, points **with their BR-3 cut-off times**, the three BR-1 rejection reasons, activity types. One round trip, because a client on 2G should pay for one. |
| `GET /api/v1/farmers/search?q=` | `community.farmers.view` | Paginated (NFR-2), scoped (SCOPE-2). |
| `GET /api/v1/validations` | `community.farmers.validate` | BR-36 — the revalidation queue M&E assigned to this field worker: farmer, reason, due date, whether it is overdue, and whether the farmer's payment is on hold pending the visit. Cached, so a morning's list survives a day with no signal. Also returns the outcome vocabulary, so the phone's picker and the server's guard cannot drift. |
| `GET /api/v1/oss/catalog` | `shop.inventory.view` \| `shop.sales.view` | Price only for a caller who may sell; quantity only for one who may see stock (§16 — the Inventory Officer sees quantities, never values). |
| `GET /api/reference-data` | (session or token) | §9 — grades, reasons, thresholds. The client never ships its own copy. |

---

## 4. The offline queue — `POST /api/v1/sync/batch`

```jsonc
{
  "farmer_registrations": [ { "client_uuid": "…", "name": "…", "community_id": 3, … } ],
  "farmer_validations":   [ { "client_uuid": "…", "validation_id": 42,
                              "outcome": "corrected", "phone": "…",
                              "findings": "Number changed last year." } ],
  "milk_collections":     [ { "client_uuid": "…", "farmer_db_id": 88,
                              "collection_point_id": 1, "volume": "22.0",
                              "litres_rejected": "2.0", "rejection_reason_id": 2,
                              "delivered_at": "2026-08-05 06:15:00" } ],
  "oss_sales":            [ { "client_uuid": "…", "items": [ { "product_id": 4, "quantity": 2 } ], … } ],
  "field_visits":         [ { "client_uuid": "…", "community_id": 3, "topics": [ … ], … } ]
}
```

Response:

```jsonc
{
  "is_success": false,           // false when anything was rejected
  "accepted": 4, "rejected": 1,
  "results": {
    "milk_collections": [ { "client_uuid": "…", "db_id": 501 },
                          { "client_uuid": "…", "db_id": 498, "duplicate": true } ],
    "field_visits":     [ … ],
    "errors": [ { "type": "milk_collections", "client_uuid": "…",
                  "error": "BR-3 — This delivery is after the 07:00 cut-off…" } ]
  }
}
```

Every record the client sent appears in exactly one of the two lists.

**One record's failure is one record's failure.** Each is committed in its own
transaction. A batch that rolled back wholesale would mean one farmer's mistyped
litre count silently discarding a morning's collection from six others.

**Authorisation is per record, not per request.** The route carries no
permission middleware, because a batch is mixed by nature: gating it on one
permission would reject a valid delivery because the same batch carried a sale.
`MobileSyncService` calls `Access::authorize()` with the record in hand
(ARCH-4 layer 2), so an Extension Agent's phone cannot record a delivery
whatever it sends — and the error says *"Not permitted"* rather than something
that reads like a malformed field, because the field worker reading it hours
later needs to know to ask an administrator, not to re-type the record.

**Idempotency is per record** (`mobile_sync_records`), not per request. A retry
is rarely the batch it retries — records captured in the meantime join it — so
the `Idempotency-Key` replay cache correctly declines to help, and a `client_uuid`
ledger takes over. It has no expiry, because a phone can be days from a signal
and an expiry shorter than the disconnection it exists to survive is not
idempotency.

**A revalidation answers an assignment.** `farmer_validations` carries the `validation_id` the queue gave
it and closes that check; the permission is `community.farmers.validate`, which is narrower than `edit` so
a Collection Agent can confirm the person in front of them without gaining the run of the register. A
submission with no assignment behind it is **refused** — an unrequested "validation" is an edit wearing a
costume, and under BR-36 it would release a held payment. Only `confirmed` and `corrected` mark the farmer
verified: `not_found` and `refused` close the task honestly and leave the hold in place, which is exactly
what the hold is for.

**The rules are the same rules.** Deliveries go through `DeliveryService`
(BR-1's three reasons, BR-3's cut-off judged on the *captured* time not the sync
time, BR-6's stored arithmetic); sales through `SaleService` (BR-26, BR-27,
BR-30). Nothing is re-implemented here.

---

## 5. Errors

| Status | When |
|---|---|
| 401 | No token, or a token that is expired, revoked or unknown. |
| 403 | A permission or scope refusal (BR-34 logs it), or BR-32 / AUTH-5 on the account. |
| 422 | A rule violation (`ST-1` — carries the rule ID) or a validation failure. |
| 429 | NFR-8 — sign-in is 10/min per IP, sync 60/min. |

`AUTH-5` (password past 90 days) answers `{"code": "password_expired"}` and
tells the user to sign in on the web: password change is a web flow, and putting
one on a field device would mean handling password entry and history checks on
the least trusted surface.

---

## 6. Running the two together

```bash
# Backend, reachable from a physical phone on the LAN
php artisan migrate
php artisan db:seed --class=Database\\Seeders\\PermissionSeeder
php artisan db:seed --class=Database\\Seeders\\RoleSeeder   # writes the personas
php artisan serve --host=0.0.0.0 --port=8005
```

```bash
# App — emulator
flutter run --dart-define=ENV=dev --dart-define=BACKEND_BASE_URL=http://10.0.2.2:8005

# App — physical phone on the same wifi
flutter run --dart-define=ENV=dev --dart-define=BACKEND_BASE_URL=http://<mac-lan-ip>:8005
```

The emailed 2FA code goes wherever `MAIL_MAILER` points. In development with
`MAIL_MAILER=log` it is in `storage/logs/laravel.log`; a user with
`two_factor_enabled = false` skips the step entirely.

Tests: `php artisan test --filter=MobileApiRulesTest` on this side,
`flutter test` on the other.
