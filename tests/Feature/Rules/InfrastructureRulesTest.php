<?php

namespace Tests\Feature\Rules;

use App\Authorization\Access;
use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Models\AuditEntry;
use App\Models\Delivery;
use App\Models\Grade;
use App\Models\RejectionReason;
use App\Models\Sequence;
use App\Services\Auth\DeviceTrustService;
use App\Support\Sequences;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\GondalTestCase;

/** §12 audit, §13 non-functional requirements, and §17 seeding. */
class InfrastructureRulesTest extends GondalTestCase
{
    /**
     * NFR-3 — "Index every foreign key, plus deliveries(delivered_at,
     * collection_point_id), consignments(status, collection_center_id),
     * audit_entries(occurred_at, actor_user_id)."
     */
    public function test_nfr3_the_named_composite_indexes_exist(): void
    {
        $expected = [
            'deliveries' => ['delivered_at', 'collection_point_id'],
            'consignments' => ['status', 'collection_center_id'],
            'audit_entries' => ['occurred_at', 'actor_user_id'],
        ];

        foreach ($expected as $table => $columns) {
            $this->assertTrue(
                $this->hasCompositeIndex($table, $columns),
                sprintf('NFR-3 names an index on %s(%s).', $table, implode(', ', $columns)),
            );
        }
    }

    /** NFR-3 — every foreign key is indexed. */
    public function test_nfr3_every_foreign_key_is_indexed(): void
    {
        $unindexed = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'http_sessions'], true)) {
                continue;
            }

            $indexedFirstColumns = collect(Schema::getIndexes($name))
                ->map(fn (array $index) => $index['columns'][0] ?? null)
                ->filter()
                ->all();

            foreach (Schema::getForeignKeys($name) as $foreignKey) {
                $column = $foreignKey['columns'][0] ?? null;

                if ($column !== null && ! in_array($column, $indexedFirstColumns, true)) {
                    $unindexed[] = $name.'.'.$column;
                }
            }
        }

        $this->assertSame([], $unindexed, 'Every foreign key must lead an index (NFR-3).');
    }

    /** AUDIT-2 — the captured event vocabulary is exactly what §12 lists. */
    public function test_audit2_the_event_vocabulary_matches_the_specification(): void
    {
        $this->assertSame([
            'permission_change',
            'role_change',
            'data_create',
            'data_edit',
            'data_delete',
            'approval',
            'rejection',
            'blocked_access',
            'signin',
            'failed_signin',
            'test_run',
        ], AuditEntry::EVENTS);
    }

    /** AUDIT-4 — every entry records its source and whether it is test activity. */
    public function test_audit4_entries_record_source_and_test_flag(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Source Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        // A web write.
        $this->post(route('deliveries.store'), [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '10.00',
            'delivered_at' => Wat::forInput(Wat::todayAt(6, 0)),
        ])->assertRedirect();

        $web = AuditEntry::query()->where('module', 'Milk Collection')->latest('id')->firstOrFail();

        $this->assertSame('web', $web->source);
        $this->assertFalse((bool) $web->is_test);
        $this->assertNotNull($web->request_id);
        $this->assertNotNull($web->ip);

        // An API write on the same rule.
        $this->postJson('/api/deliveries', [
            'collection_point_id' => $world['pointA']->id,
            'farmer_id' => $world['farmer']->id,
            'litres_presented' => '12.00',
            'delivered_at' => Wat::todayAt(6, 5)->toIso8601String(),
        ])->assertCreated();

        $api = AuditEntry::query()->where('module', 'Milk Collection')->latest('id')->firstOrFail();

        $this->assertSame('api', $api->source);
    }

    /** AUDIT-1 — retention is configured at the floor §12 sets, and nothing prunes. */
    public function test_audit1_retention_is_at_least_24_months_and_nothing_prunes(): void
    {
        $this->assertGreaterThanOrEqual(24, (int) config('gondal.audit_retention_months'));

        // There is no command, route or scheduled job that deletes audit entries —
        // DM-3 makes one impossible, and this asserts none is attempted.
        $sources = collect(File::allFiles(app_path()))
            ->merge(File::allFiles(base_path('routes')))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getContents())
            ->implode("\n");

        foreach ([
            "audit_entries')->delete(",
            'AuditEntry::query()->delete(',
            "audit_entries')->truncate(",
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sources);
        }
    }

    /** NFR-7 — CSRF protection applies to every web write, with no exclusions. */
    public function test_nfr7_csrf_applies_to_every_web_write(): void
    {
        $middleware = app(VerifyCsrfToken::class);

        $reflection = new \ReflectionClass($middleware);
        $property = $reflection->getProperty('except');
        $property->setAccessible(true);

        $this->assertSame([], $property->getValue($middleware), 'No route is exempt from CSRF.');
    }

    /** NFR-7 — session cookies are configured secure and http-only. */
    public function test_nfr7_session_cookies_are_hardened(): void
    {
        // The template that ships to production is what matters here, since the
        // test environment deliberately relaxes some of this.
        $template = file_get_contents(base_path('.env.example'));

        foreach ([
            'SESSION_ENCRYPT=true',
            'SESSION_SECURE_COOKIE=true',
            'SESSION_HTTP_ONLY=true',
            'SESSION_SAME_SITE=lax',
        ] as $setting) {
            $this->assertStringContainsString($setting, $template, 'NFR-7 — '.$setting);
        }

        // And the device trust cookie is http-only and same-site by construction.
        $reflection = new \ReflectionMethod(DeviceTrustService::class, 'remember');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('httpOnly: true', $source);
        $this->assertStringContainsString("sameSite: 'lax'", $source);
    }

    /** NFR-8 — "Rate-limit auth endpoints per IP and per account." */
    public function test_nfr8_auth_endpoints_are_rate_limited(): void
    {
        $limited = [
            'login.attempt', 'login.verify.store', 'login.verify.resend',
            'password.forgot.store', 'password.verify.store',
        ];

        foreach ($limited as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' must exist.');
            $this->assertTrue(
                collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
                'NFR-8 — '.$name.' must be rate limited per IP.',
            );
        }

        // The per-account half of the rule is SigninThrottle (AUTH-6), which records
        // failures rather than only counting them in a cache.
        $this->assertTrue(Schema::hasTable('failed_signins'));
        $this->assertTrue(Schema::hasColumn('users', 'locked_until'));
    }

    /** NFR-12 — the demo dataset is behind a flag and off by default. */
    public function test_nfr12_demo_data_is_behind_a_flag(): void
    {
        // The flag exists, and the template ships with it off.
        $this->assertStringContainsString(
            'GONDAL_SEED_DEMO_DATA=false',
            file_get_contents(base_path('.env.example')),
        );

        // The database seeder only calls it when the flag is set.
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString("config('gondal.seed_demo_data')", $source);
        $this->assertStringContainsString('DemoDataSeeder::class', $source);
    }

    /** ARCH-3 — server-rendered HTML, with no SPA framework introduced for v1. */
    public function test_arch3_no_spa_framework_is_installed(): void
    {
        $package = json_decode(file_get_contents(base_path('package.json')), true);

        $dependencies = array_keys(array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? [],
        ));

        foreach (['react', 'vue', 'svelte', '@angular/core', 'inertia'] as $spa) {
            foreach ($dependencies as $dependency) {
                $this->assertStringNotContainsString(
                    $spa,
                    strtolower($dependency),
                    'ARCH-3 — no SPA framework for v1.',
                );
            }
        }
    }

    /**
     * §9 / §18.7 — no reference data from §9 appears as an enum, constant or config
     * value. This is the acceptance test for the rule that shaped the whole build.
     */
    public function test_no_reference_data_is_hardcoded(): void
    {
        $sources = collect(File::allFiles(app_path()))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->mapWithKeys(fn ($file) => [$file->getRelativePathname() => $file->getContents()]);

        /*
         * The seeded values from §9. If any of these appears as a literal in the
         * application layer, something is deciding in code what an administrator is
         * supposed to decide in Settings.
         *
         * Seeders are excluded on purpose: they exist precisely to put these values
         * into the database, which is where §9 wants them.
         */
        $forbidden = [
            'GRD-A', 'GRD-B', 'GRD-R',              // grade codes
            'REJ-ADU', 'REJ-SPO', 'REJ-LATE',        // rejection reason codes
            '1.028', '1.034',                        // density thresholds
            '25000', '21500',                        // Grade A / B rates in kobo
        ];

        $offenders = [];

        foreach ($sources as $path => $contents) {
            foreach ($forbidden as $literal) {
                if (str_contains($contents, "'".$literal."'")) {
                    $offenders[] = $path.' → '.$literal;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "§18.7 — reference data must not appear as a literal in application code.\n"
                .'Found: '.implode(', ', $offenders),
        );
    }

    /** §9 — and the rules read those values from the database instead. */
    public function test_rules_read_reference_data_from_the_database(): void
    {
        // BR-3's "late" reason is found by its flag, not its code.
        $late = RejectionReason::cutoffBreach();
        $this->assertNotNull($late);
        $this->assertSame('REJ-LATE', $late->code, 'The seeded row happens to be REJ-LATE...');

        // ...but renaming and re-coding it changes nothing, because nothing matches
        // on the code.
        $late->forceFill(['code' => 'REJ-TARDY', 'name' => 'Arrived late'])->save();

        $this->assertSame('REJ-TARDY', RejectionReason::cutoffBreach()->code);

        // BR-16's "rejected" grade is likewise found by its flag.
        $rejected = Grade::query()->where('is_rejection', true)->firstOrFail();
        $rejected->forceFill(['code' => 'GRD-NOPAY'])->save();

        $this->assertSame(
            'GRD-NOPAY',
            Grade::query()->where('is_rejection', true)->value('code'),
        );

        // And the cut-off comes from Settings, so changing it changes behaviour.
        Settings::put(['milk.delivery_cutoff_default' => '05:30']);

        $world = $this->makeMilkWorld();
        $this->assertSame('05:30', $world['pointA']->effectiveCutoff());
    }

    /* ------------------------------------------------------------------ */

    /** @param array<int, string> $columns */
    private function hasCompositeIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * A resetting sequence must carry its period in the reference it renders.
     *
     * `deliveries` and `sales` reset daily but rendered `{prefix}-{number}`, so
     * the second day's DEL-0001 was byte-identical to the first day's — and the
     * column is unique. Recording the first delivery of any day after the data
     * was seeded threw a 500, and so did the first shop sale.
     *
     * The whole suite missed it because every test runs against a freshly seeded
     * database, which is always day one. This test asserts the SHAPE instead, so
     * it does not depend on what day it is run.
     */
    public function test_a_resetting_sequence_carries_its_period_in_the_reference(): void
    {
        $required = [
            Sequence::RESET_DAILY => ['{day}'],
            Sequence::RESET_MONTHLY => ['{month}'],
            Sequence::RESET_YEARLY => ['{year}'],
        ];

        $problems = [];

        foreach (Sequence::query()->get() as $sequence) {
            $tokens = $required[$sequence->reset_period] ?? null;

            if ($tokens === null) {
                continue;
            }

            foreach ($tokens as $token) {
                if (! str_contains((string) $sequence->reference_format, $token)) {
                    $problems[] = sprintf(
                        '%s resets %s but its format %s carries no %s — day two collides with day one',
                        $sequence->key, $sequence->reset_period, $sequence->reference_format, $token,
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The same defect, proved end to end: roll the clock forward a day and take
     * another number. It must not repeat one already issued.
     */
    public function test_a_daily_sequence_does_not_repeat_itself_the_next_day(): void
    {
        $issued = [];

        foreach (['deliveries', 'sales'] as $key) {
            $this->travelTo(Carbon::parse('2026-08-03 06:00:00', 'UTC'));
            $issued[] = Sequences::next($key);
            $issued[] = Sequences::next($key);

            // The next morning. The counter resets; the reference must not.
            $this->travelTo(Carbon::parse('2026-08-04 06:00:00', 'UTC'));
            $issued[] = Sequences::next($key);
        }

        $this->travelBack();

        $this->assertSame(
            count($issued),
            count(array_unique($issued)),
            'A reference was issued twice: '.implode(', ', $issued),
        );
    }

    /**
     * AUDIT-5 — searching the audit log by a quotable reference finds it.
     *
     * A refused user is shown "DENY-0004" and told to quote it. The auditor then
     * pastes that into the search box on the audit screen — and found nothing,
     * because the box matched only `summary`. A separate `reference` filter
     * existed, but nobody reading a reference off a screenshot knows to use a
     * different field, and a quotable reference that cannot be found by quoting
     * it is not doing its job.
     */
    public function test_audit5_the_audit_search_finds_a_quotable_reference(): void
    {
        $world = $this->makeMilkWorld();

        /*
         * Produce a real denial through the authorisation service rather than a
         * second HTTP request: signing two different people in inside one test
         * leaves the guard on the first, and the assertion then fails for a
         * reason that has nothing to do with the search.
         */
        $agent = $this->makeUser('Refused Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);

        try {
            app(Access::class)->authorize($agent->fresh(), 'hr.payroll.view', null, 'Payroll');
            $this->fail('The agent should have been refused payroll.');
        } catch (AccessDeniedException) {
            // The refusal is the point; the audit entry is the artefact.
        }

        $entry = AuditEntry::query()
            ->where('event_type', AuditEntry::EVENT_BLOCKED_ACCESS)
            ->latest('id')->firstOrFail();

        $this->assertStringStartsWith('DENY-', $entry->reference);

        $auditor = $this->makeUser('Searching Auditor');
        $this->assignRole($auditor, 'Internal Audit');
        $this->actingAs($auditor);

        // The general search box — the one an auditor actually uses.
        $this->get(route('admin.audit-log', ['q' => $entry->reference]))
            ->assertOk()
            ->assertSee($entry->reference);
    }
}
