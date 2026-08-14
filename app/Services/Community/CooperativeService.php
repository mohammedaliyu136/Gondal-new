<?php

namespace App\Services\Community;

use App\Models\Community;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\CooperativeRate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §6.6 — the cooperative register, and the one place its deduction percentages
 * are written.
 *
 * BR-15 is the reason this is a service rather than controller code. The
 * percentages decide what a member is paid, and they were plain columns updated
 * in place: a committee that moved its levy in September left no evidence of
 * what August's members were owed, while both cooperative screens told the
 * reader that "past payables keep the rate that was in force at the time".
 *
 * So every change writes a `cooperative_rates` row and never edits one, exactly
 * as BR-13 requires of grade_rates, and the columns on `cooperatives` are kept
 * as the live copy the rest of the application already reads.
 *
 * §15.1 / BR-15 — this makes the history reconstructable. It does not make the
 * SNAPSHOT exist: there is no payable-amount calculation in the system to
 * snapshot at, because where the payment module lives is still open. Phase 7
 * must copy the percentages in force onto the payment line as it computes it.
 */
class CooperativeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, Community $community, User $actor): Cooperative
    {
        $rates = $this->ratesFrom($data);

        $cooperative = DB::transaction(function () use ($data, $community, $rates, $actor): Cooperative {
            $cooperative = Cooperative::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'registered_on' => $data['registered_on'] ?? null,
                'community_id' => $community->getKey(),
                'lga_id' => $community->lga_id,
                'chairman_name' => $data['chairman_name'] ?? null,
                'secretary_name' => $data['secretary_name'] ?? null,
                'treasurer_name' => $data['treasurer_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'collection_point_id' => $data['collection_point_id'] ?? null,
                'savings_deduction_pct' => $rates['savings_deduction_pct'],
                'levy_pct' => $rates['levy_pct'],
                'social_contribution_minor' => $rates['social_contribution_minor'],
                'status' => 'active',
            ]);

            /*
             * Three accounts. NG-1 still defers the loan book.
             *
             * Savings joined general and social in Phase 7, when farmer payment
             * finally had somewhere to put the money it had been deducting.
             */
            foreach ([Cooperative::ACCOUNT_GENERAL, Cooperative::ACCOUNT_SOCIAL, Cooperative::ACCOUNT_SAVINGS] as $kind) {
                CooperativeAccount::query()->create([
                    'cooperative_id' => $cooperative->getKey(),
                    'kind' => $kind,
                    'balance_minor' => 0,
                ]);
            }

            // The opening row of the history — dated to registration, not to
            // today, so the record says when these percentages started applying.
            $this->recordRates(
                $cooperative,
                $rates,
                $data['registered_on'] ?? Wat::today()->toDateString(),
                $actor,
            );

            return $cooperative;
        });

        $this->audit->created(
            $cooperative,
            sprintf('Cooperative %s (%s) onboarded in %s', $cooperative->name, $cooperative->code, $community->name),
            'Community Engagement',
            [
                'savings_pct' => (string) $cooperative->savings_deduction_pct,
                'levy_pct' => (string) $cooperative->levy_pct,
                'accounts' => ['general', 'social'],
                'note' => 'NG-1 — loans and investments deferred',
            ],
            $actor,
        );

        return $cooperative;
    }

    /**
     * Committees turn over annually and the officials, the contact phone, the
     * collection point and the status were all frozen at registration — the only
     * correction path was a database UPDATE.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Cooperative $cooperative, array $data, User $actor): Cooperative
    {
        $before = $cooperative->only([
            'name', 'chairman_name', 'secretary_name', 'treasurer_name',
            'contact_phone', 'collection_point_id', 'status',
            'savings_deduction_pct', 'levy_pct', 'social_contribution_minor',
        ]);

        $rates = $this->ratesFrom($data, $cooperative);
        $effectiveFrom = $data['effective_from'] ?? Wat::today()->toDateString();
        $ratesChanged = $this->differ($before, $rates);

        DB::transaction(function () use ($cooperative, $data, $rates, $effectiveFrom, $ratesChanged, $actor): void {
            $cooperative->fill([
                'name' => $data['name'] ?? $cooperative->name,
                'chairman_name' => $data['chairman_name'] ?? null,
                'secretary_name' => $data['secretary_name'] ?? null,
                'treasurer_name' => $data['treasurer_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'collection_point_id' => $data['collection_point_id'] ?? null,
                'status' => $data['status'] ?? $cooperative->status,
            ] + $rates)->save();

            /*
             * Only a real change writes a history row. Saving the officials
             * without touching a percentage must not plant a new effective date,
             * or the history stops meaning "when the rate changed" and starts
             * meaning "when somebody opened the form".
             */
            if ($ratesChanged) {
                $this->recordRates($cooperative, $rates, $effectiveFrom, $actor);
            }
        });

        $after = $cooperative->only(array_keys($before));

        $this->audit->edited(
            $cooperative,
            $ratesChanged
                ? sprintf(
                    '%s deductions set to %s%% savings and %s%% levy from %s (was %s%% and %s%%)',
                    $cooperative->name,
                    $rates['savings_deduction_pct'],
                    $rates['levy_pct'],
                    Wat::date($effectiveFrom),
                    $before['savings_deduction_pct'],
                    $before['levy_pct'],
                )
                : sprintf('%s (%s) updated', $cooperative->name, $cooperative->code),
            'Community Engagement',
            $before,
            $after + ($ratesChanged ? [
                'effective_from' => $effectiveFrom,
                'rule' => 'BR-15',
                'note' => 'Prospective only — a payable already calculated keeps the percentages in force when it was.',
            ] : []),
            $actor,
        );

        return $cooperative;
    }

    /**
     * BR-13's shape applied to BR-15: one row per cooperative per effective
     * date, inserted and never edited afterwards. updateOrCreate covers the
     * same-day correction only — a typo fixed an hour later is not a second
     * rate change, and two rows for one date would leave "the rate in force"
     * with two answers.
     *
     * @param  array<string, mixed>  $rates
     */
    public function recordRates(Cooperative $cooperative, array $rates, string $effectiveFrom, User $actor): CooperativeRate
    {
        return CooperativeRate::query()->updateOrCreate(
            ['cooperative_id' => $cooperative->getKey(), 'effective_from' => $effectiveFrom],
            $rates + ['created_by_user_id' => $actor->getKey()],
        );
    }

    /**
     * The three percentages, from the form or from the §9 defaults.
     *
     * @param  array<string, mixed>  $data
     * @return array{savings_deduction_pct: string, levy_pct: string, social_contribution_minor: int}
     */
    private function ratesFrom(array $data, ?Cooperative $existing = null): array
    {
        return [
            'savings_deduction_pct' => (string) ($data['savings_deduction_pct']
                ?? $existing?->savings_deduction_pct
                ?? Settings::decimalString('cooperative.default_savings_deduction_pct', '5')),
            'levy_pct' => (string) ($data['levy_pct']
                ?? $existing?->levy_pct
                ?? Settings::decimalString('cooperative.default_levy_pct', '2')),
            // ARCH-6 — naira in, kobo stored.
            'social_contribution_minor' => Money::fromMajor($data['social_contribution'] ?? null)
                ?? (int) ($existing?->social_contribution_minor
                    ?? Settings::moneyMinor('cooperative.default_social_contribution_minor', 25_000)),
        ];
    }

    /**
     * Decimal columns come back as strings with a fixed scale ("5.00") and the
     * form sends "5" — a strict comparison would call every save a rate change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $rates
     */
    private function differ(array $before, array $rates): bool
    {
        return (float) $before['savings_deduction_pct'] !== (float) $rates['savings_deduction_pct']
            || (float) $before['levy_pct'] !== (float) $rates['levy_pct']
            || (int) $before['social_contribution_minor'] !== (int) $rates['social_contribution_minor'];
    }
}
