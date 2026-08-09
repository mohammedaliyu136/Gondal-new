<?php

/*
|--------------------------------------------------------------------------
| Gondal ERP application configuration
|--------------------------------------------------------------------------
|
| IMPORTANT (PRD §9 / §18.7): nothing in this file may be operational
| reference data. Grades, rates, rejection reasons, cut-off times, tolerances,
| tariffs, numbering formats, cooperative percentages and workflow definitions
| are DATABASE ROWS an administrator edits through Settings. They are read via
| App\Support\Settings and the reference-data models, never from here.
|
| What lives here: infrastructure knobs, presentation constants and the
| *fallback of last resort* used only when the settings table has not been
| seeded yet (fresh install, before Phase 2 seeders run).
|
*/

return [

    // ARCH-9 — store UTC, present WAT.
    'display_timezone' => env('GONDAL_DISPLAY_TIMEZONE', 'Africa/Lagos'),

    // ARCH-10 — currency presentation. Amounts are always integer minor units (kobo).
    'currency' => [
        'code' => 'NGN',
        'symbol' => '₦',
        'minor_units' => 100,
        'locale' => 'en-NG',
    ],

    // ARCH-6 — volume presentation. Stored as decimal(10,2) litres.
    'volume' => [
        'unit' => 'L',
        'decimals' => 2,
    ],

    // NFR-2 — pagination.
    'pagination' => [
        'per_page' => 25,
        'max_per_page' => 100,
    ],

    // ARCH-7 — idempotency of write endpoints.
    'idempotency' => [
        'header' => 'Idempotency-Key',
        'ttl_hours' => 24,
    ],

    // AUTH-* — authentication behaviour. These are security policy, not
    // operational reference data, so they are deliberately code-level.
    'auth' => [
        'code_length' => 6,
        'signin_code_ttl_minutes' => 10,     // AUTH-3
        'reset_code_ttl_minutes' => 15,
        /*
         * Activation is not a password reset. A reset code answers "I forgot, I
         * am at the keyboard now" — 15 minutes fits. An activation code sits in
         * a new hire's inbox until they get to a machine, which in rural Adamawa
         * can be days. 72 hours.
         */
        'activation_code_ttl_minutes' => 4320,      // AUTH-4
        'code_max_attempts' => 5,            // AUTH-3
        'device_trust_days' => 30,           // AUTH-2
        'password_min_length' => 10,         // AUTH-5
        'password_history' => 3,             // AUTH-5
        'password_max_age_days' => 90,       // AUTH-5
        'lockout_failures' => 5,             // AUTH-6
        'lockout_window_minutes' => 15,      // AUTH-6
        'lockout_minutes' => 30,             // AUTH-6

        /*
         * ARCH-2 — how long a mobile bearer token lives before the phone has to
         * sign in again. Matched to AUTH-2's device trust window on purpose: a
         * field agent should meet the emailed-code step on the same rhythm
         * whichever surface they use, and a token that outlived device trust
         * would quietly become the weaker of the two credentials.
         */
        'api_token_days' => 30,
    ],

    // TEST-3 — production must never be offerable as a permission-test target.
    'permission_test_environments' => ['development', 'staging'],

    // AUDIT-1 — retention floor in months.
    'audit_retention_months' => 24,

    // NFR-12 — the prototype demo dataset is seeded only behind this flag.
    'seed_demo_data' => (bool) env('GONDAL_SEED_DEMO_DATA', false),

    /*
     * The named colleagues from the review meeting, as real accounts. Separate
     * from the demo flag so a pilot environment can carry real people without the
     * invented 1,842-farmer dataset, and vice versa.
     */
    'seed_pilot_users' => env('GONDAL_SEED_PILOT_USERS', false),

    /*
    | Fallbacks used ONLY when the corresponding settings row is absent.
    | Every consumer reads Settings::get() first; these keep a fresh install
    | from dividing by zero before Phase 2 seeders have run.
    */
    'setting_fallbacks' => [
        'milk.batch_discrepancy_tolerance_pct' => '1.0',
        'milk.delivery_cutoff_default' => '07:00',
        'milk.delivery_cutoff_latest_override' => '08:00',
    ],

    // NG-1 / NG-2 — modules disabled by decision. Read by the nav builder and
    // the module gate; rows in `settings` override this list at runtime.
    'disabled_modules' => ['projects', 'cooperative_loans'],
];
