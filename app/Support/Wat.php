<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ARCH-9 — "Timezone Africa/Lagos (WAT). Store UTC, present WAT."
 *
 * Those are two different jobs, and conflating them is how an application ends up
 * an hour out. This class keeps them apart, deliberately and visibly:
 *
 *   INSTANTS — what gets stored. Always UTC.
 *       now()            the current instant
 *       instant($value)  any value as a UTC instant
 *       todayAt(6, 20)   a WAT wall-clock time today, as a UTC instant
 *       toUtc($value)    operator input read as WAT, returned as UTC
 *
 *   WALL CLOCK — what a person in Kano sees, and what business rules about
 *   times of day (BR-3's cut-off) reason over. Always WAT.
 *       local()          the current instant, seen in WAT
 *       of($value)       any value, seen in WAT
 *       today()          the start of the current WAT day
 *       date/time/dateTime/relative(...)  formatting
 *
 *   RANGES — how to ask a UTC instant column for a WAT calendar day.
 *       dayStart($d)     the UTC instant a WAT day begins
 *       dayRange($d)     [start, end) bounding one WAT day
 *       daysRange($f,$t) [start, end) bounding an inclusive span of WAT days
 *       monthRange($d)   [start, end) bounding one WAT month
 *
 * The rule for callers is simple: if it is going into the database, it comes from
 * the first group; if a human is going to read it or a rule cares what hour of the
 * day it is, it comes from the second.
 *
 * Why this is not left to config('app.timezone'): Eloquent's datetime cast
 * formats whatever Carbon it is handed using that Carbon's OWN timezone. Hand it a
 * WAT-zoned instant and it writes WAT wall-clock into a column everything else
 * reads as UTC. So the discipline has to live at the point the value is made.
 */
final class Wat
{
    public static function zone(): string
    {
        return (string) config('gondal.display_timezone', 'Africa/Lagos');
    }

    /* ---------------------------------------------------------------------
     | Instants — UTC. These are what gets stored.
     * ------------------------------------------------------------------ */

    /** The current instant, in UTC. Safe to persist. */
    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /** Any value as a UTC instant. Safe to persist. */
    public static function instant(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }

        // Same reasoning as of(): a naive string off a form is WAT wall-clock,
        // and reading it as UTC stored every form-entered time an hour late.
        return self::parseInDisplayZone($value)->utc();
    }

    /**
     * A WAT wall-clock time on today's WAT date, returned as a UTC instant.
     *
     * "06:20 at the collection point this morning" is a wall-clock statement; what
     * gets stored is the instant it names.
     */
    public static function todayAt(int $hour, int $minute = 0, int $second = 0): Carbon
    {
        return self::today()->setTime($hour, $minute, $second)->utc();
    }

    /**
     * Interpret an operator-entered wall-clock value as WAT and return the UTC
     * instant. A value that already carries an offset keeps it.
     */
    public static function toUtc(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value->toDateTime())->utc();
        }

        return Carbon::parse($value, self::zone())->utc();
    }

    /* ---------------------------------------------------------------------
     | Wall clock — WAT. Presentation, and rules about times of day.
     * ------------------------------------------------------------------ */

    /** The current instant seen in WAT. For wall-clock reasoning and form inputs. */
    public static function local(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /** The start of the current WAT day. Use ->toDateString() for `date` columns. */
    public static function today(): Carbon
    {
        return self::local()->startOfDay();
    }

    /** Any value seen in WAT. For display, and for comparing times of day. */
    public static function of(CarbonInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->setTimezone(self::zone());
        }

        return self::parseInDisplayZone($value)->setTimezone(self::zone());
    }

    /**
     * Parse a string, treating a NAIVE one as a West Africa wall-clock reading.
     *
     * This is the input half of ARCH-9, and it was wrong. Every `datetime-local`
     * field in the application posts a naive string — "2026-08-01T06:05" — which
     * is what the operator's own clock said. Carbon::parse() with no zone reads
     * that in the application default (UTC), so the value silently gained an
     * hour: a delivery keyed at 06:05 was stored as 07:05 WAT and judged against
     * the 07:00 cut-off as LATE. Agents would have been recording overrides for
     * milk that arrived on time.
     *
     * A string that carries its own offset or a Z keeps it — PHP ignores the
     * timezone argument when the input is explicit — so API clients sending
     * ISO-8601 with an offset are unaffected.
     */
    private static function parseInDisplayZone(string $value): Carbon
    {
        return Carbon::parse($value, self::zone());
    }

    /* ---------------------------------------------------------------------
     | Ranges — the sanctioned way to ask a UTC column for a WAT day.
     * ------------------------------------------------------------------ */

    /**
     * The UTC instant at which a WAT calendar day begins. Null means today.
     *
     * A bare `date` column takes ->toDateString(); an instant column takes this.
     */
    public static function dayStart(CarbonInterface|string|null $watDate = null): Carbon
    {
        $local = $watDate === null || $watDate === ''
            ? self::today()
            : self::of($watDate)->startOfDay();

        return $local->copy()->utc();
    }

    /**
     * The half-open UTC interval bounding a WAT calendar day: [D 00:00, D+1 00:00).
     *
     * ARCH-9 stores instants in UTC and a WAT day is not a UTC day — it opens at
     * 23:00 UTC on D-1. `whereDate('delivered_at', Wat::today())` therefore asked
     * the database for 01:00 WAT on D through 00:59 WAT on D+1: a delivery keyed
     * in the first hour of a WAT day disappeared from its own day's list and its
     * litres were added to the day before, which is the day that had already been
     * reported. Half-open so the boundary instant belongs to exactly one day and
     * consecutive days neither overlap nor leave a gap.
     *
     * It is also the fast form. `whereDate` compiles to `"delivered_at"::date = ?`
     * on PostgreSQL, and a function on the column means the b-tree index NFR-3
     * names by hand — deliveries(delivered_at, collection_point_id) — can never be
     * used. Comparing the column to two constants uses it.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayRange(CarbonInterface|string|null $watDate = null): array
    {
        $start = self::dayStart($watDate);

        return [$start, $start->copy()->addDay()];
    }

    /**
     * The half-open UTC interval covering an INCLUSIVE span of WAT days, so
     * `daysRange('1 Jul', '31 Jul')` contains the whole of the 31st.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function daysRange(
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
    ): array {
        return [self::dayStart($from), self::dayStart($to)->addDay()];
    }

    /**
     * The half-open UTC interval bounding the WAT month a date falls in.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(CarbonInterface|string|null $watDate = null): array
    {
        $local = ($watDate === null || $watDate === '' ? self::local() : self::of($watDate))
            ->copy()
            ->startOfMonth();

        return [$local->copy()->utc(), $local->copy()->addMonth()->utc()];
    }

    /* ---------------------------------------------------------------------
     | Formatting — always WAT, per ARCH-9's "present WAT".
     * ------------------------------------------------------------------ */

    /** "31 Jul 2026" */
    public static function date(CarbonInterface|string|null $value): string
    {
        return self::of($value)?->format('j M Y') ?? '—';
    }

    /** "Thu, 31 Jul 2026" */
    public static function longDate(CarbonInterface|string|null $value): string
    {
        return self::of($value)?->format('D, j M Y') ?? '—';
    }

    /** "09:40" */
    public static function time(CarbonInterface|string|null $value): string
    {
        return self::of($value)?->format('H:i') ?? '—';
    }

    /** "31 Jul 2026, 09:40" */
    public static function dateTime(CarbonInterface|string|null $value): string
    {
        return self::of($value)?->format('j M Y, H:i') ?? '—';
    }

    /**
     * The prototype's relative wording: "Today, 09:40", "Yesterday, 17:12",
     * "Mon, 09:20" inside the current week, otherwise an absolute date.
     */
    public static function relative(CarbonInterface|string|null $value): string
    {
        $moment = self::of($value);

        if ($moment === null) {
            return '—';
        }

        $today = self::today();

        return match (true) {
            $moment->isSameDay($today) => 'Today, '.$moment->format('H:i'),
            $moment->isSameDay($today->copy()->subDay()) => 'Yesterday, '.$moment->format('H:i'),
            $moment->greaterThan($today->copy()->subWeek()) => $moment->format('D, H:i'),
            default => $moment->format('j M Y, H:i'),
        };
    }

    /** The value for an <input type="datetime-local">, which is wall clock. */
    public static function forInput(CarbonInterface|string|null $value = null): string
    {
        return ($value === null ? self::local() : self::of($value))?->format('Y-m-d\TH:i') ?? '';
    }
}
