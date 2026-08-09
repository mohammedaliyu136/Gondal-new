<?php

namespace App\Models;

use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;

/**
 * §6.9 / §9 — reference numbering. Prefix, digits, reset period and the shape of
 * the reference itself are all data, so DEL-0009 and REQ-2026-0142 can differ
 * without a code change.
 *
 * Allocation is done by App\Support\Sequences under a row lock; this model is
 * just the record.
 */
class Sequence extends Model
{
    public const RESET_DAILY = 'daily';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_YEARLY = 'yearly';

    public const RESET_NEVER = 'never';

    protected $fillable = [
        'key', 'label', 'prefix', 'digits', 'reset_period', 'reference_format',
        'current_value', 'last_reset_on', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'digits' => 'integer',
            'current_value' => 'integer',
            'last_reset_on' => 'date',
        ];
    }

    /** The settings screen's "Example" column. */
    public function preview(): string
    {
        return $this->render(max(1, (int) $this->current_value));
    }

    public function render(int $number): string
    {
        /*
         * ARCH-9 — the STAMP clock must be the same clock as the RESET clock.
         *
         * Sequences::next() decides whether to reset on Wat::today(), which is WAT.
         * This method stamped {year}/{month}/{day} from Wat::now(), which is UTC.
         * For the first hour of every WAT day the two disagreed: the counter went
         * back to 1 for the new day while the reference still carried yesterday's
         * date, so DEL-20260805-0001 was issued twice — once at 21:00 WAT on the
         * 5th and again at 00:30 WAT on the 6th. `deliveries.reference` is unique,
         * so the first delivery recorded in that hour threw a 500 and an agent at a
         * collection point could not record milk at all.
         */
        $now = Wat::local();

        return str_replace(
            ['{prefix}', '{year}', '{month}', '{day}', '{number}'],
            [
                (string) $this->prefix,
                $now->format('Y'),
                $now->format('m'),
                $now->format('d'),
                str_pad((string) $number, (int) $this->digits, '0', STR_PAD_LEFT),
            ],
            (string) $this->reference_format,
        );
    }
}
