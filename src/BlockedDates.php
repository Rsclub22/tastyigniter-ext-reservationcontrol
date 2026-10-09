<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\System\Models\Settings;

/**
 * Individual blocked days (a closing day outside the weekly rhythm, company
 * holidays, a private function).
 *
 * TastyIgniter already knows exceptions in the schedule -
 * WorkingSchedule::forDate() checks the exceptions first and then falls back to
 * the weekday. There is, however, neither a table nor an interface for them.
 * The data therefore lives as a setting; for a handful of dates per year that
 * is enough and it saves a migration of our own.
 */
class BlockedDates
{
    /**
     * The key under which live installations already store their blocked days.
     * It keeps the old "reservetweaks" name on purpose - renaming it would
     * silently drop the blocked days of every existing installation.
     */
    private const string SETTING = 'reservetweaks_blocked_dates';

    private const string GROUP = 'prefs';

    /** @return array<string, string> date (Y-m-d) => reason */
    public static function all(): array
    {
        // Deliberately JSON instead of an array: Settings::set() does serialise
        // arrays, but it does not set the "serialized" column - reading would
        // then return a string. And state the group explicitly, because the
        // setting() helper only reads "config".
        $raw = Settings::get(self::SETTING, '', self::GROUP);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $dates = json_decode($raw, true);

        return is_array($dates) ? $dates : [];
    }

    /** Only today's and future blocks, sorted ascending. */
    public static function upcoming(): array
    {
        $today = Carbon::today()->toDateString();
        $dates = array_filter(self::all(), fn ($reason, $date): bool => $date >= $today, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        return $dates;
    }

    public static function isBlocked(string $date): bool
    {
        return array_key_exists($date, self::all());
    }

    public static function block(string $date, string $reason = ''): void
    {
        $dates = self::all();
        $dates[$date] = $reason;
        self::store($dates);
    }

    public static function unblock(string $date): void
    {
        $dates = self::all();
        unset($dates[$date]);
        self::store($dates);
    }

    /**
     * The shape WorkingSchedule::setExceptions() expects: an empty period per
     * date means "closed on this day".
     */
    public static function asScheduleExceptions(): array
    {
        return array_map(static fn (): array => [], self::all());
    }

    private static function store(array $dates): void
    {
        // Drop what is past, otherwise the setting grows without end.
        $today = Carbon::today()->toDateString();
        $dates = array_filter($dates, fn ($reason, $date): bool => $date >= $today, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        Settings::set(self::SETTING, json_encode($dates, JSON_UNESCAPED_UNICODE), self::GROUP);
    }
}
