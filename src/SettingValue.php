<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;
use Wagnersnetz\ReservationControl\Models\Settings;

/**
 * Validating readers for the settings that are not large-party rules.
 *
 * Every reader checks what it got and falls back to the default it is given.
 * Nothing is coerced: (int) '25:00' would be 25 and (int) 'abc' would be 0, and
 * a zero threshold or length limit is worse than a wrong one.
 */
final class SettingValue
{
    /**
     * Settings::get() is typed by Eloquent as a Collection, so the result is
     * only ever checked, never trusted. A missing settings table (fresh
     * installation, migrations not yet run) counts as "nothing stored" - boot()
     * reads settings, and it must not take `artisan migrate` down with it.
     */
    public static function stored(string $key): mixed
    {
        try {
            return Settings::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /** A whole number of at least $min, written as int or as digits only. */
    public static function int(string $key, int $default, int $min = 1): int
    {
        $value = self::stored($key);

        if (is_string($value) && preg_match('/^\s*\d{1,9}\s*$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $min && $value <= 999_999_999 ? $value : $default;
    }

    public static function string(string $key, string $default): string
    {
        $value = self::stored($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = self::stored($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A switch with an explicit default for "never stored". */
    public static function flag(string $key, bool $default): bool
    {
        $value = self::stored($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * A list of IP addresses and CIDR ranges, one per line or comma separated.
     * One malformed entry rejects the whole list: a silently shortened list of
     * trusted networks is a different security statement than the one written.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function networks(string $key, array $default): array
    {
        $value = self::stored($key);
        if (! is_string($value)) {
            return $default;
        }

        $entries = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($entries === []) {
            return $default;
        }

        foreach ($entries as $entry) {
            if (! self::isNetwork($entry)) {
                return $default;
            }
        }

        return array_values(array_unique($entries));
    }

    private static function isNetwork(string $entry): bool
    {
        [$address, $mask] = array_pad(explode('/', $entry, 2), 2, null);

        $isV6 = str_contains($address, ':');
        if (filter_var($address, FILTER_VALIDATE_IP, $isV6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        return preg_match('/^\d{1,3}$/', $mask) === 1 && (int) $mask <= ($isV6 ? 128 : 32) && IpUtils::checkIp($address, $entry);
    }

    /** A PCRE pattern that compiles, otherwise the default. */
    public static function pattern(string $key, string $default): string
    {
        $value = self::stored($key);

        return is_string($value) && self::compiles($value) ? $value : $default;
    }

    private static function compiles(string $pattern): bool
    {
        return $pattern !== '' && @preg_match($pattern, '') !== false;
    }
}
