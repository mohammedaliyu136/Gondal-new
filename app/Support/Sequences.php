<?php

namespace App\Support;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * §9 Reference numbering — DEL (daily reset), CNS, BATCH, TRP, REQ (yearly), ACT.
 *
 * Every reference in the system is allocated here. The prefix, width, reset
 * period and the shape of the reference itself are all rows in `sequences`, so
 * an administrator can change DEL-0009 to INTAKE-000009 without a deployment.
 *
 * Allocation takes a row lock and does the reset check inside the same
 * transaction, so two agents recording deliveries at 07:00 cannot collide.
 */
final class Sequences
{
    /**
     * @param  array<string, mixed>  $defaults  used only if the sequence row is
     *                                          absent, e.g. on a fresh install
     *                                          before the reference-data seeder
     *                                          has run
     */
    public static function next(string $key, array $defaults = []): string
    {
        return DB::transaction(function () use ($key, $defaults): string {
            $sequence = Sequence::query()->where('key', $key)->lockForUpdate()->first();

            if ($sequence === null) {
                $sequence = Sequence::query()->create(array_merge([
                    'key' => $key,
                    'label' => str($key)->headline()->toString(),
                    'prefix' => strtoupper(substr($key, 0, 3)),
                    'digits' => 4,
                    'reset_period' => Sequence::RESET_NEVER,
                    'reference_format' => '{prefix}-{number}',
                    'current_value' => 0,
                ], $defaults));
            }

            /*
             * ARCH-9 — the reset clock is WAT, because "a new day" is a statement
             * about the day at the collection point. Sequence::render() stamps the
             * {day} token from the same WAT clock; if the two ever diverge again a
             * daily reference repeats itself and the unique constraint on
             * `deliveries.reference` turns the first intake of the day into a 500.
             */
            $today = Wat::today();
            $lastReset = $sequence->last_reset_on;

            $shouldReset = match ($sequence->reset_period) {
                Sequence::RESET_DAILY => $lastReset === null || ! $lastReset->isSameDay($today),
                Sequence::RESET_MONTHLY => $lastReset === null
                    || $lastReset->format('Y-m') !== $today->format('Y-m'),
                Sequence::RESET_YEARLY => $lastReset === null
                    || $lastReset->format('Y') !== $today->format('Y'),
                default => false,
            };

            $number = $shouldReset ? 1 : (int) $sequence->current_value + 1;

            $sequence->forceFill([
                'current_value' => $number,
                'last_reset_on' => $shouldReset || $lastReset === null ? $today->toDateString() : $lastReset,
            ])->save();

            return $sequence->render($number);
        });
    }

    /** The `Example` column on settings.html, without consuming a number. */
    public static function preview(string $key): ?string
    {
        return Sequence::query()->where('key', $key)->first()?->preview();
    }
}
