<?php

namespace App\Support;

/**
 * ARCH-6 / NFR-5 — volumes are decimal(10,2) litres. Arithmetic is performed in
 * centilitres (integer hundredths of a litre) so that BR-6 through BR-10 never
 * accumulate float error.
 */
final class Volume
{
    public static function toCentilitres(string|float|int|null $litres): int
    {
        if ($litres === null || $litres === '') {
            return 0;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $litres) ?? '';

        if ($clean === '' || $clean === '-') {
            return 0;
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $centilitres = (int) $whole * 100 + (int) $fraction;

        return $negative ? -$centilitres : $centilitres;
    }

    /**
     * Back to the decimal(10,2) string the database column expects.
     */
    public static function fromCentilitres(int $centilitres): string
    {
        $negative = $centilitres < 0;
        $centilitres = abs($centilitres);

        return ($negative ? '-' : '')
            .intdiv($centilitres, 100)
            .'.'
            .str_pad((string) ($centilitres % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, string|float|int|null>  $litres
     */
    public static function sum(array $litres): string
    {
        return self::fromCentilitres(array_sum(array_map(
            static fn ($value) => self::toCentilitres($value),
            $litres,
        )));
    }

    public static function subtract(string|float|int|null $a, string|float|int|null $b): string
    {
        return self::fromCentilitres(self::toCentilitres($a) - self::toCentilitres($b));
    }

    public static function add(string|float|int|null $a, string|float|int|null $b): string
    {
        return self::fromCentilitres(self::toCentilitres($a) + self::toCentilitres($b));
    }

    public static function equals(string|float|int|null $a, string|float|int|null $b): bool
    {
        return self::toCentilitres($a) === self::toCentilitres($b);
    }

    public static function compare(string|float|int|null $a, string|float|int|null $b): int
    {
        return self::toCentilitres($a) <=> self::toCentilitres($b);
    }

    public static function isNegative(string|float|int|null $value): bool
    {
        return self::toCentilitres($value) < 0;
    }

    /**
     * |part| / whole as a percentage with two decimals, for display.
     *
     * Rounded half-up rather than truncated, so 0.2353% reads as 0.24% and not
     * 0.23%. Comparisons against a tolerance do NOT go through here — see
     * exceedsPercentage(), which compares the exact ratio.
     */
    public static function percentageOf(string|float|int|null $part, string|float|int|null $whole): ?string
    {
        $wholeCl = self::toCentilitres($whole);

        if ($wholeCl === 0) {
            return null;
        }

        // Scale by 1,000,000 so two decimal places of a percentage survive as
        // integers with a digit left over to round on.
        $scaled = intdiv(abs(self::toCentilitres($part)) * 1_000_000, abs($wholeCl));
        $rounded = intdiv($scaled + 50, 100);

        return number_format($rounded / 100, 2, '.', '');
    }

    /**
     * BR-11 — does |part| / whole EXCEED $percent?
     *
     * Compared on the exact ratio in integer arithmetic, not on the two-decimal
     * string percentageOf() produces for the screen. Rounding first would let a
     * 1.004% variance read as exactly 1.00% and slip past a 1% tolerance — a small
     * error, but on the wrong side of a rule whose whole point is that a
     * discrepancy gets explained.
     */
    public static function exceedsPercentage(
        string|float|int|null $part,
        string|float|int|null $whole,
        string|float|int $percent,
    ): bool {
        $wholeCl = abs(self::toCentilitres($whole));

        if ($wholeCl === 0) {
            return false;
        }

        $partCl = abs(self::toCentilitres($part));

        // percent is given to two decimals; work in hundredths of a percent.
        $threshold = (int) round(((float) $percent) * 100);

        // |part| / whole > threshold / 10000   ⇔   |part| * 10000 > threshold * whole
        return $partCl * 10_000 > $threshold * $wholeCl;
    }

    /**
     * "3,400.00 L" — the prototype drops the decimals on whole litres.
     */
    public static function format(string|float|int|null $litres, bool $withUnit = true): string
    {
        if ($litres === null) {
            return '—';
        }

        $centilitres = self::toCentilitres($litres);
        $decimals = $centilitres % 100 === 0 ? 0 : 2;
        $formatted = number_format($centilitres / 100, $decimals);

        return $withUnit ? $formatted.' '.config('gondal.volume.unit', 'L') : $formatted;
    }
}
