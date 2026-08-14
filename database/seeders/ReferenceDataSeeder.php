<?php

namespace Database\Seeders;

use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\Community;
use App\Models\DiscrepancyCause;
use App\Models\Grade;
use App\Models\GradeRate;
use App\Models\LeaveType;
use App\Models\Lga;
use App\Models\NotificationEvent;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Sequence;
use App\Models\Setting;
use App\Models\ValidationReason;
use Illuminate\Database\Seeder;

/**
 * §9 — every value in this seeder is a ROW an administrator edits through
 * Settings. §18.7 is the acceptance test: "No reference data from §9 appears as
 * an enum, constant or config value anywhere in the codebase."
 *
 * This seeder therefore establishes the initial state; it is not the source of
 * truth at runtime. Changing the Grade A rate in production means inserting a
 * new grade_rates row (BR-13), not editing this file.
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * §17 — 6 LGAs, 26 communities.
     *
     * Adamawa State. The network operates out of Yola, which is why
     * config/gondal.php reasons about "rural Adamawa" when it sets the
     * activation window; the six LGAs below are the dairy belt around the
     * capital, and the order matters — the first community seeded is Tudun
     * Wada, the point PT-001 serves.
     *
     * @return array<string, array<int, string>>
     */
    private function locations(): array
    {
        return [
            'Yola North' => ['Tudun Wada', 'Jimeta', 'Karewa', 'Luggere', 'Yelwa'],
            'Girei' => ['Girei', 'Damare', 'Vinikilang', 'Gereng', 'Sangere'],
            'Fufore' => ['Fufore', 'Gurin', 'Malabu', 'Ribadu'],
            'Song' => ['Song', 'Dumne', 'Zumo', 'Waltandi'],
            'Mayo-Belwa' => ['Mayo-Belwa', 'Ndikong', 'Gengle', 'Nassarawo Jereng'],
            'Numan' => ['Numan', 'Imburu', 'Gamadio', 'Kodomti'],
        ];
    }

    public function run(): void
    {
        $this->seedLocations();
        $this->seedGrades();
        $this->seedRejectionReasons();
        $this->seedAdjustmentReasons();
        $this->seedDiscrepancyCauses();
        $this->seedActivityTypes();
        $this->seedValidationReasons();
        $this->seedQualityTests();
        $this->seedSequences();
        $this->seedSettings();
        $this->seedLeaveTypes();
        $this->seedNotificationEvents();
    }

    private function seedLocations(): void
    {
        foreach ($this->locations() as $lgaName => $communities) {
            $lga = Lga::query()->updateOrCreate(['name' => $lgaName], []);

            foreach ($communities as $community) {
                Community::query()->updateOrCreate(
                    ['lga_id' => $lga->getKey(), 'name' => $community],
                    [],
                );
            }
        }
    }

    /**
     * §9 — "Grade A ₦250/L, Grade B ₦215/L, Rejected ₦0."
     * BR-13 — the rate is an effective-dated row, not a column on the grade.
     */
    private function seedGrades(): void
    {
        $grades = [
            [
                'code' => 'GRD-A',
                'name' => 'Grade A',
                'criteria' => 'Density 1.028–1.034, alcohol test negative, intake temperature below 20 °C',
                'status' => 'active',
                'rate_minor' => 25_000,          // ₦250.00
                'is_rejection' => false,
                'is_system' => false,
                'position' => 1,
            ],
            [
                'code' => 'GRD-B',
                'name' => 'Grade B',
                'criteria' => 'Density in range, minor organoleptic variance',
                'status' => 'active',
                'rate_minor' => 21_500,          // ₦215.00
                'is_rejection' => false,
                'is_system' => false,
                'position' => 2,
            ],
            [
                'code' => 'GRD-R',
                'name' => 'Rejected',
                'criteria' => 'Fails one of the configured rejection reasons — never payable',
                // The prototype badges this one "System": it may not be deleted
                // and it is not assignable as a quality outcome.
                'status' => 'system',
                'rate_minor' => 0,               // BR-16 — valued at zero
                'is_rejection' => true,
                'is_system' => true,
                'position' => 3,
            ],
        ];

        foreach ($grades as $definition) {
            $grade = Grade::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'criteria' => $definition['criteria'],
                    'status' => $definition['status'],
                    'is_rejection' => $definition['is_rejection'],
                    'is_system' => $definition['is_system'],
                    'position' => $definition['position'],
                ],
            );

            /*
             * The date must be written the way the column stores it. With a bare
             * '2026-04-01' the lookup never matched the stored
             * '2026-04-01 00:00:00', so every re-run tried to INSERT a row that
             * was already there and `db:seed` died on the unique constraint —
             * seeding was a one-shot operation that could not be repeated.
             */
            GradeRate::query()->updateOrCreate(
                ['grade_id' => $grade->getKey(), 'effective_from' => '2026-04-01 00:00:00'],
                ['rate_per_litre_minor' => $definition['rate_minor']],
            );
        }
    }

    /**
     * BR-1 — the three seeded reasons are adulteration, spoilage and failure to
     * meet delivery time. BR-5 defaults: adulteration 3-in-30, spoilage 3-in-30,
     * late 2-in-30.
     * BR-3 — the "late" reason is the one marked is_cutoff_breach.
     */
    private function seedRejectionReasons(): void
    {
        $reasons = [
            [
                'code' => 'REJ-ADU',
                'name' => 'Adulteration',
                'help_text' => 'e.g. added water',
                'available_at_point' => true,
                'available_at_center' => true,
                'available_at_factory' => true,
                'followup_threshold' => 3,
                'followup_window_days' => 30,
                'is_cutoff_breach' => false,
                'position' => 1,
            ],
            [
                'code' => 'REJ-SPO',
                'name' => 'Spoilage',
                'help_text' => 'souring, coagulation',
                'available_at_point' => true,
                'available_at_center' => true,
                'available_at_factory' => true,
                'followup_threshold' => 3,
                'followup_window_days' => 30,
                'is_cutoff_breach' => false,
                'position' => 2,
            ],
            [
                'code' => 'REJ-LATE',
                'name' => 'Failure to meet delivery time',
                'help_text' => 'arrival after the cut-off',
                'available_at_point' => true,
                'available_at_center' => true,
                // The prototype leaves "At Factory" unticked for this reason.
                'available_at_factory' => false,
                'followup_threshold' => 2,
                'followup_window_days' => 30,
                'is_cutoff_breach' => true,
                'position' => 3,
            ],
        ];

        foreach ($reasons as $reason) {
            RejectionReason::query()->updateOrCreate(
                ['code' => $reason['code']],
                array_merge($reason, [
                    // BR-2 — rejected volume never reaches payment or transport.
                    'excluded_from_payment' => true,
                    'status' => 'active',
                ]),
            );
        }
    }

    /** BR-12 / BR-28 — every adjustment cites one of these. */
    private function seedAdjustmentReasons(): void
    {
        $reasons = [
            ['code' => 'ADJ-MEAS', 'name' => 'Measurement correction', 'help_text' => 'Re-measured at the center', 'applies_to' => 'consignment'],
            ['code' => 'ADJ-SPILL', 'name' => 'Spillage in transit', 'help_text' => 'Volume lost between point and center', 'applies_to' => 'consignment'],
            ['code' => 'ADJ-CONT', 'name' => 'Container change', 'help_text' => 'Decanted into different containers', 'applies_to' => 'any'],
            ['code' => 'ADJ-ENTRY', 'name' => 'Data entry error', 'help_text' => 'Corrects a mistyped figure', 'applies_to' => 'any'],
            ['code' => 'ADJ-DAMAGE', 'name' => 'Damaged stock write-off', 'help_text' => 'Shop stock unfit for sale', 'applies_to' => 'stock'],
            ['code' => 'ADJ-EXPIRY', 'name' => 'Expired stock write-off', 'help_text' => 'Past expiry date', 'applies_to' => 'stock'],
            ['code' => 'ADJ-COUNT', 'name' => 'Stock count correction', 'help_text' => 'Physical count differs from the system', 'applies_to' => 'stock'],
        ];

        foreach ($reasons as $index => $reason) {
            AdjustmentReason::query()->updateOrCreate(
                ['code' => $reason['code']],
                array_merge($reason, ['status' => 'active', 'position' => $index + 1]),
            );
        }
    }

    /** BR-10 / BR-11 — the cause a supervisor selects for a factory variance. */
    private function seedDiscrepancyCauses(): void
    {
        $causes = [
            ['code' => 'DIS-CONT', 'name' => 'Container change at intake', 'help_text' => 'Different containers used at the factory'],
            ['code' => 'DIS-SPILL', 'name' => 'Spillage in transit', 'help_text' => 'Loss between center and factory'],
            ['code' => 'DIS-MEAS', 'name' => 'Measurement difference', 'help_text' => 'Factory scale differs from center measure'],
            ['code' => 'DIS-TEMP', 'name' => 'Temperature loss', 'help_text' => 'Volume affected by cold-chain failure'],
            ['code' => 'DIS-COUNT', 'name' => 'Counting error at dispatch', 'help_text' => 'Center over- or under-recorded'],
        ];

        foreach ($causes as $index => $cause) {
            DiscrepancyCause::query()->updateOrCreate(
                ['code' => $cause['code']],
                array_merge($cause, ['status' => 'active', 'position' => $index + 1]),
            );
        }
    }

    /**
     * §6.9 activity_types. Phase 5 acceptance — closing a quality follow-up
     * requires a logged field activity, and only the types flagged here can do it.
     */
    private function seedActivityTypes(): void
    {
        $types = [
            ['code' => 'VISIT', 'name' => 'Household visit', 'closes_quality_followup' => true],
            ['code' => 'TRAINING', 'name' => 'Training session', 'closes_quality_followup' => true],
            ['code' => 'ENROLMENT', 'name' => 'Farmer enrolment', 'closes_quality_followup' => false],
            ['code' => 'QUALITY', 'name' => 'Quality follow-up visit', 'closes_quality_followup' => true],
            ['code' => 'DEMO', 'name' => 'Field demonstration', 'closes_quality_followup' => false],
            ['code' => 'COOP', 'name' => 'Cooperative meeting', 'closes_quality_followup' => false],
        ];

        foreach ($types as $index => $type) {
            ActivityType::query()->updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['status' => 'active', 'position' => $index + 1]),
            );
        }
    }

    /**
     * §9 — why a farmer was put on the revalidation list.
     *
     * Reference data rather than an enum for the usual reason and one specific
     * one: the reasons an organisation re-checks its register change with what
     * has just gone wrong. "Post-flood verification" is a real reason in a real
     * October, and it should cost M&E a Settings edit, not a release.
     */
    private function seedValidationReasons(): void
    {
        $reasons = [
            ['code' => 'PERIODIC', 'name' => 'Periodic revalidation', 'help_text' => 'The farmer is due under the standing revalidation cycle.', 'is_automatic' => true],
            ['code' => 'DATA_GAP', 'name' => 'Missing or doubtful details', 'help_text' => 'Phone, herd size or cooperative membership is missing or looks wrong.', 'is_automatic' => false],
            ['code' => 'REJECTION', 'name' => 'Rejection pattern', 'help_text' => 'Repeated rejections suggest the record may not describe the farmer accurately.', 'is_automatic' => false],
            ['code' => 'DORMANT', 'name' => 'No deliveries recently', 'help_text' => 'The farmer has stopped delivering; confirm they are still active.', 'is_automatic' => false],
            ['code' => 'NEW', 'name' => 'Newly enrolled', 'help_text' => 'First verification after enrolment by a field agent.', 'is_automatic' => false],
            ['code' => 'MANUAL', 'name' => 'Requested by M&E', 'help_text' => 'Picked by hand for a reason recorded on the round.', 'is_automatic' => false],
        ];

        foreach ($reasons as $index => $reason) {
            ValidationReason::query()->updateOrCreate(
                ['code' => $reason['code']],
                array_merge($reason, ['status' => 'active', 'position' => $index + 1]),
            );
        }
    }

    /**
     * §9 / BR-4 — "density 1.028–1.034, max 20 °C, alcohol test required".
     * These are rows so BR-4's "all configured quality tests" is genuinely
     * configurable.
     */
    private function seedQualityTests(): void
    {
        $tests = [
            [
                'code' => 'DENSITY',
                'name' => 'Density (lactometer)',
                'kind' => QualityTestDefinition::KIND_RANGE,
                'min_value' => 1.028,
                'max_value' => 1.034,
                'unit' => 'g/cm³',
                'is_required' => true,
                'position' => 1,
            ],
            [
                'code' => 'TEMPERATURE',
                'name' => 'Intake temperature',
                'kind' => QualityTestDefinition::KIND_MAXIMUM,
                'min_value' => null,
                'max_value' => 20,
                'unit' => '°C',
                'is_required' => true,
                'position' => 2,
            ],
            [
                'code' => 'ALCOHOL',
                'name' => 'Alcohol test',
                'kind' => QualityTestDefinition::KIND_BOOLEAN,
                'min_value' => null,
                'max_value' => null,
                'unit' => null,
                'expected_boolean_label' => 'No coagulation',
                'is_required' => true,
                'position' => 3,
            ],
        ];

        foreach ($tests as $test) {
            QualityTestDefinition::query()->updateOrCreate(
                ['code' => $test['code']],
                array_merge($test, ['status' => 'active']),
            );
        }
    }

    /**
     * §9 — "DEL (daily reset), CNS, BATCH, TRP, REQ (yearly), ACT".
     * §17 — the shapes must reproduce DEL-0009, CNS-0438, BATCH-0087, TRP-1052,
     * REQ-2026-0142, ACT-2241.
     */
    private function seedSequences(): void
    {
        $sequences = [
            // A sequence that RESETS on a period must carry that period in its
            // reference, or the second day's DEL-0001 collides with the first
            // day's under the unique constraint. Payslips and requisitions were
            // already written this way; these two were not, and the system broke
            // on its second day of use for its two highest-volume operations.
            ['key' => 'deliveries', 'label' => 'Farmer delivery at a point', 'prefix' => 'DEL', 'digits' => 4, 'reset_period' => Sequence::RESET_DAILY, 'reference_format' => '{prefix}-{year}{month}{day}-{number}'],
            ['key' => 'consignments', 'label' => 'Consignment point → center', 'prefix' => 'CNS', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            ['key' => 'batches', 'label' => 'Batch center → factory', 'prefix' => 'BATCH', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            ['key' => 'trips', 'label' => 'Transport trip', 'prefix' => 'TRP', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            ['key' => 'requisitions', 'label' => 'Requisition', 'prefix' => 'REQ', 'digits' => 4, 'reset_period' => Sequence::RESET_YEARLY, 'reference_format' => '{prefix}-{year}-{number}'],
            ['key' => 'field_activities', 'label' => 'Field activity', 'prefix' => 'ACT', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            ['key' => 'farmer_validations', 'label' => 'Farmer revalidation', 'prefix' => 'VAL', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            ['key' => 'validation_rounds', 'label' => 'Revalidation round', 'prefix' => 'VRND', 'digits' => 3, 'reset_period' => Sequence::RESET_YEARLY, 'reference_format' => '{prefix}-{year}-{number}'],
            ['key' => 'sales', 'label' => 'Shop receipt', 'prefix' => 'RCP', 'digits' => 5, 'reset_period' => Sequence::RESET_DAILY, 'reference_format' => '{prefix}-{year}{month}{day}-{number}'],
            ['key' => 'payslips', 'label' => 'Payslip', 'prefix' => 'PS', 'digits' => 4, 'reset_period' => Sequence::RESET_MONTHLY, 'reference_format' => '{prefix}-{year}{month}-{number}'],
            ['key' => 'permission_tests', 'label' => 'Permission test run', 'prefix' => 'TEST', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
            // AUDIT-5 — the quotable blocked-access reference (DENY-2291).
            ['key' => 'denials', 'label' => 'Blocked access reference', 'prefix' => 'DENY', 'digits' => 4, 'reset_period' => Sequence::RESET_NEVER, 'reference_format' => '{prefix}-{number}'],
        ];

        foreach ($sequences as $sequence) {
            Sequence::query()->updateOrCreate(
                ['key' => $sequence['key']],
                array_merge($sequence, ['current_value' => 0]),
            );
        }
    }

    /**
     * §9 — the scalar knobs: cut-offs, tolerance, cooperative defaults, disabled
     * modules. All read through App\Support\Settings.
     */
    private function seedSettings(): void
    {
        $settings = [
            // Milk & Quality
            ['key' => 'milk.delivery_cutoff_default', 'value' => '07:00', 'group' => 'milk', 'label' => 'Default delivery cut-off', 'value_type' => 'time', 'help_text' => 'Individual points may override this on their own record.'],
            ['key' => 'milk.delivery_cutoff_latest_override', 'value' => '08:00', 'group' => 'milk', 'label' => 'Latest permitted cut-off override', 'value_type' => 'time'],
            ['key' => 'milk.batch_discrepancy_tolerance_pct', 'value' => '1.0', 'group' => 'milk', 'label' => 'Discrepancy tolerance on a factory batch (%)', 'value_type' => 'decimal', 'help_text' => 'Beyond this, the supervisor must record an explanatory note before the batch can be released.'],

            // Cooperatives — §9: 5% savings, 2% levy, ₦250/member/month social
            ['key' => 'cooperative.default_savings_deduction_pct', 'value' => '5', 'group' => 'cooperatives', 'label' => 'Savings deduction (% of milk payment)', 'value_type' => 'decimal'],
            ['key' => 'cooperative.default_levy_pct', 'value' => '2', 'group' => 'cooperatives', 'label' => 'Cooperative levy (% of milk payment)', 'value_type' => 'decimal'],
            ['key' => 'cooperative.default_social_contribution_minor', 'value' => 25_000, 'group' => 'cooperatives', 'label' => 'Social fund contribution (₦ / member / month)', 'value_type' => 'integer'],
            // NG-1 — deferred by decision, so the loan book stays off.
            /*
             * §14 Phase 7 — the levy base. Decision 1.4 of
             * docs/PLAN-FARMER-PAYMENTS.md and NOT a settled question: taking the
             * levy on gross-less-savings is what payroll does for its sequential
             * deductions, which is the only reason it is the default. Nobody has
             * confirmed the cooperative's bye-laws say so. A row, not a constant,
             * so answering it is an edit rather than a release.
             */
            /*
             * §1.6 of docs/PLAN-FARMER-PAYMENTS.md — the most of one milk
             * payment that may be taken to recover an old debt. Empty means
             * recover everything, which is what the code did before this row
             * existed and is also how a farmer ends up with three fortnights of
             * nothing and no way to have been warned.
             */
            ['key' => 'cooperative.max_debt_recovery_pct', 'value' => '50', 'group' => 'cooperatives', 'label' => 'Most of a milk payment recoverable as debt (%)', 'value_type' => 'decimal', 'help_text' => 'A farmer always takes home at least the remainder. Leave blank to recover a debt in full, which can leave somebody with nothing on payout day.'],
            ['key' => 'cooperative.levy_on_net_of_savings', 'value' => true, 'group' => 'cooperatives', 'label' => 'Take the levy after savings', 'value_type' => 'boolean', 'help_text' => 'On: levy is charged on gross less savings. Off: on gross. Confirm against the cooperative bye-laws.'],
            ['key' => 'cooperative.loan_book_enabled', 'value' => false, 'group' => 'cooperatives', 'label' => 'Loan book', 'value_type' => 'boolean', 'help_text' => 'Cooperative loans and investments are deferred to a future phase.'],

            // Shop
            ['key' => 'shop.low_stock_warning_enabled', 'value' => true, 'group' => 'shop', 'label' => 'Warn on low stock', 'value_type' => 'boolean'],

            /*
             * Farmer revalidation. All three are M&E's to change, which is why
             * none of them is a constant.
             */
            ['key' => 'community.revalidation_interval_months', 'value' => 12, 'group' => 'community', 'label' => 'Revalidate a farmer every (months)', 'value_type' => 'integer', 'help_text' => 'Set to 0 to switch off periodic revalidation and leave the schedule entirely to Monitoring & Evaluation.'],
            ['key' => 'community.validation_auto_approve', 'value' => true, 'group' => 'community', 'label' => 'Revalidations are approved automatically', 'value_type' => 'boolean', 'help_text' => 'The default offered when a round is opened. M&E may override it per round — a periodic sweep rarely needs a second pair of eyes; a round raised by a rejection pattern usually does.'],
            ['key' => 'community.withhold_payment_when_unvalidated', 'value' => true, 'group' => 'community', 'label' => 'Hold payment for an overdue farmer', 'value_type' => 'boolean', 'help_text' => 'BR-36. Milk is still collected — only the payment waits. Refusing a delivery at the point would destroy milk over a back-office lapse the agent cannot fix.'],

            // NG-1 / NG-2 — disabled modules.
            ['key' => 'modules.disabled', 'value' => ['projects', 'cooperative_loans'], 'group' => 'modules', 'label' => 'Disabled modules', 'value_type' => 'json', 'help_text' => 'Disabling a module hides its screens and retires its permissions without deleting historical data.'],
            ['key' => 'modules.projects_disabled_on', 'value' => '2026-07-12', 'group' => 'modules', 'label' => 'Project Management disabled on', 'value_type' => 'string'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => ['v' => $setting['value']],
                    'group' => $setting['group'],
                    'label' => $setting['label'],
                    'value_type' => $setting['value_type'],
                    'help_text' => $setting['help_text'] ?? null,
                ],
            );
        }
    }

    /** §6.8 — leave types are reference data too. */
    private function seedLeaveTypes(): void
    {
        $types = [
            ['code' => 'ANNUAL', 'name' => 'Annual leave', 'annual_entitlement_days' => 21, 'requires_document' => false],
            ['code' => 'SICK', 'name' => 'Sick leave', 'annual_entitlement_days' => 14, 'requires_document' => true],
            ['code' => 'MATERNITY', 'name' => 'Maternity leave', 'annual_entitlement_days' => 90, 'requires_document' => true],
            ['code' => 'COMPASSIONATE', 'name' => 'Compassionate leave', 'annual_entitlement_days' => 7, 'requires_document' => false],
            ['code' => 'STUDY', 'name' => 'Study leave', 'annual_entitlement_days' => 10, 'requires_document' => true],
        ];

        foreach ($types as $index => $type) {
            LeaveType::query()->updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['status' => 'active', 'position' => $index + 1]),
            );
        }
    }

    /**
     * NOTIF-3 — the seeded events, each with the permission that gates it
     * (NOTIF-2: a user is never notified about something they could not open).
     */
    private function seedNotificationEvents(): void
    {
        $events = [
            ['code' => 'approval.queued', 'name' => 'Item enters my approval queue', 'module' => 'Purchases', 'required_permission' => 'purchase.requisitions.view', 'default_email' => true],
            ['code' => 'requisition.decided', 'name' => 'My requisition approved or rejected', 'module' => 'Purchases', 'required_permission' => 'purchase.requisitions.view', 'default_email' => true],
            ['code' => 'approval.overdue', 'name' => 'Item overdue in my queue', 'module' => 'Purchases', 'required_permission' => 'purchase.requisitions.view', 'default_email' => true],
            ['code' => 'consignment.awaiting_confirmation', 'name' => 'Consignment awaiting confirmation', 'module' => 'Milk Collection', 'required_permission' => 'milk.consignment.confirm.view'],
            ['code' => 'batch.discrepancy', 'name' => 'Batch discrepancy', 'module' => 'Milk Collection', 'required_permission' => 'milk.reconciliation.view', 'default_email' => true],
            ['code' => 'rejection.at_point', 'name' => 'Rejection at a point I supervise', 'module' => 'Milk Collection', 'required_permission' => 'milk.rejection.view'],
            ['code' => 'quality.followup_opened', 'name' => 'Quality follow-up opened', 'module' => 'Community Engagement', 'required_permission' => 'community.extension.view', 'default_email' => true],
            // Revalidation. NOTIF-2 filters each to the people who could act on
            // it: the field worker who was asked, and M&E who must review.
            ['code' => 'validation.assigned', 'name' => 'A farmer revalidation was assigned to me', 'module' => 'Community Engagement', 'required_permission' => 'community.farmers.validate', 'default_email' => true],
            ['code' => 'validation.returned', 'name' => 'My revalidation was sent back', 'module' => 'Community Engagement', 'required_permission' => 'community.farmers.validate', 'default_email' => true],
            ['code' => 'validation.awaiting_review', 'name' => 'A revalidation is waiting for my review', 'module' => 'Community Engagement', 'required_permission' => 'community.validation.approve'],
            ['code' => 'role.changed', 'name' => 'Role or permission changed', 'module' => 'Administration', 'required_permission' => null, 'default_email' => true],
            ['code' => 'signin.new_device', 'name' => 'Sign-in from a new device', 'module' => 'Account', 'required_permission' => null, 'default_email' => true],
            ['code' => 'shop.low_stock', 'name' => 'Low stock', 'module' => 'One-Stop Shop', 'required_permission' => 'shop.inventory.view'],
            ['code' => 'leave.decided', 'name' => 'My leave request approved or rejected', 'module' => 'Human Resources', 'required_permission' => 'hr.leave.own.view', 'default_email' => true],
        ];

        foreach ($events as $index => $event) {
            NotificationEvent::query()->updateOrCreate(
                ['code' => $event['code']],
                [
                    'name' => $event['name'],
                    'module' => $event['module'],
                    'required_permission' => $event['required_permission'],
                    'default_in_app' => true,
                    'default_email' => $event['default_email'] ?? false,
                    'default_sms' => false,
                    'status' => 'active',
                    'position' => $index + 1,
                ],
            );
        }
    }
}
