<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * §9 — the single door to reference-data settings.
 *
 * Read through here rather than touching the model, so that:
 *   REF-1  every change writes an audit entry with before AND after values
 *   ROLE-6-style freshness holds — values are memoised per request only
 *
 * The fallbacks in config('gondal.setting_fallbacks') exist purely so a fresh
 * install cannot divide by zero before the Phase 2 seeders have run. They are
 * not the source of truth and must never be edited to change behaviour.
 */
final class Settings
{
    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    /** @return array<string, mixed> */
    private static function all(): array
    {
        return self::$memo ??= Setting::query()
            ->pluck('value', 'key')
            ->map(static fn ($value) => is_array($value) && array_key_exists('v', $value) ? $value['v'] : $value)
            ->all();
    }

    public static function flush(): void
    {
        self::$memo = null;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::all();

        if (array_key_exists($key, $values) && $values[$key] !== null) {
            return $values[$key];
        }

        return config('gondal.setting_fallbacks.'.$key, $default);
    }

    public static function string(string $key, ?string $default = ''): string
    {
        $default = $default ?? '';
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function integer(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Percentages and tolerances are kept as strings so comparisons stay exact
     * (NFR-5). Callers that need arithmetic convert with Volume/Money helpers.
     */
    public static function decimalString(string $key, string $default = '0'): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function boolean(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int|string, mixed> */
    public static function array(string $key, array $default = []): array
    {
        $value = self::get($key, $default);

        return is_array($value) ? $value : $default;
    }

    /**
     * ARCH-6 — money settings are stored in minor units, like everything else.
     */
    public static function moneyMinor(string $key, int $default = 0): int
    {
        return self::integer($key, $default);
    }

    /**
     * REF-1 — "Changing reference data is audited with before and after values."
     *
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values, ?User $actor = null, string $group = 'general'): void
    {
        $before = [];
        $after = [];

        DB::transaction(function () use ($values, $actor, $group, &$before, &$after): void {
            foreach ($values as $key => $value) {
                $setting = Setting::query()->firstOrNew(['key' => $key]);

                $before[$key] = $setting->exists ? self::unwrap($setting->value) : null;
                $after[$key] = $value;

                $setting->fill([
                    'value' => ['v' => $value],
                    'group' => $setting->exists ? $setting->group : $group,
                    'updated_by_user_id' => $actor?->getKey(),
                ])->save();
            }
        });

        self::flush();

        $changed = array_keys(array_filter(
            $after,
            static fn ($value, $key) => ($before[$key] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed === []) {
            return;
        }

        app(AuditLogger::class)->settingsChanged($changed, $before, $after, $actor);
    }

    private static function unwrap(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('v', $value) ? $value['v'] : $value;
    }
}
