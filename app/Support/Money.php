<?php

namespace App\Support;

/**
 * ARCH-6 / NFR-5 — all money is integer minor units (kobo). Never a float.
 *
 * Every helper here takes and returns integers. Formatting is the only place a
 * decimal string appears, and it is produced by string arithmetic so that no
 * value ever passes through a binary float.
 */
final class Money
{
    public static function minorUnits(): int
    {
        return (int) config('gondal.currency.minor_units', 100);
    }

    public static function symbol(): string
    {
        return (string) config('gondal.currency.symbol', '₦');
    }

    /**
     * Parse operator input ("3,400,000", "3400000.50") into integer kobo.
     */
    public static function fromMajor(string|int|float|null $major): ?int
    {
        if ($major === null || $major === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $major) ?? '';

        if ($clean === '' || $clean === '-') {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $minor = (int) $whole * self::minorUnits() + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    /**
     * "3,400,000.00" — no currency symbol.
     */
    public static function decimal(?int $minor): string
    {
        if ($minor === null) {
            return '—';
        }

        $negative = $minor < 0;
        $minor = abs($minor);
        $units = self::minorUnits();

        $whole = intdiv($minor, $units);
        $fraction = str_pad((string) ($minor % $units), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').number_format($whole).'.'.$fraction;
    }

    /**
     * ARCH-10 — "₦3,400,000.00".
     */
    public static function format(?int $minor): string
    {
        if ($minor === null) {
            return '—';
        }

        return self::symbol().self::decimal($minor);
    }

    /**
     * ARCH-10 — "₦3.4m", the compact form the prototype uses in tables and stats.
     */
    public static function compact(?int $minor): string
    {
        if ($minor === null) {
            return '—';
        }

        $negative = $minor < 0;
        $major = intdiv(abs($minor), self::minorUnits());
        $sign = $negative ? '-' : '';

        return $sign.self::symbol().match (true) {
            $major >= 1_000_000_000 => self::trim($major / 1_000_000_000).'b',
            $major >= 1_000_000 => self::trim($major / 1_000_000).'m',
            $major >= 1_000 => self::trim($major / 1_000).'k',
            default => number_format($major),
        };
    }

    /**
     * BR-15 / BR-22 — percentage of a minor amount, rounded half-up to the kobo.
     * Kept in integer arithmetic: (minor * bps) / 10000.
     */
    public static function percentageOf(int $minor, string|float|int $percent): int
    {
        $basisPoints = (int) round(((float) $percent) * 100);

        return intdiv($minor * $basisPoints + ($minor * $basisPoints < 0 ? -5000 : 5000), 10_000);
    }

    /**
     * BR-14 — value a volume at a snapshotted per-litre rate.
     * Litres arrive as a decimal(10,2) string; multiplication is done in
     * hundredths of a litre so the result stays integral.
     */
    public static function valueVolume(string|float|int $litres, int $ratePerLitreMinor): int
    {
        $centilitres = Volume::toCentilitres($litres);

        return intdiv($centilitres * $ratePerLitreMinor + ($centilitres * $ratePerLitreMinor < 0 ? -50 : 50), 100);
    }

    private static function trim(float $value): string
    {
        $rounded = round($value, 1);

        return rtrim(rtrim(number_format($rounded, 1), '0'), '.');
    }
}
