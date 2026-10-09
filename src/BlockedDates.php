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

    /**
     * Every special day with its reason, whether guests may book online or not.
     * This is what the internal pages show.
     *
     * @return array<string, string> date (Y-m-d) => reason
     */
    public static function all(): array
    {
        return array_map(static fn (array $entry): string => $entry['grund'], self::entries());
    }

    /**
     * The stored days in one shape. A plain string is the old format ("date =>
     * reason") and means "not bookable online" - the blocked days of installations
     * that predate the per-day switch must keep blocking. Anything else that is
     * not a well-formed entry blocks as well: when in doubt, stay closed.
     *
     * @return array<string, array{grund: string, online: bool}>
     */
    public static function entries(): array
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
        if (! is_array($dates)) {
            return [];
        }

        $entries = [];
        foreach ($dates as $date => $value) {
            $entries[(string) $date] = is_array($value)
                ? [
                    'grund' => is_string($value['grund'] ?? null) ? $value['grund'] : '',
                    'online' => ($value['online'] ?? false) === true,
                ]
                : ['grund' => is_string($value) ? $value : '', 'online' => false];
        }

        return $entries;
    }

    /** Only today's and future blocks, sorted ascending. */
    public static function upcoming(): array
    {
        $today = Carbon::today()->toDateString();
        $dates = array_filter(self::all(), fn ($reason, $date): bool => $date >= $today, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        return $dates;
    }

    /** Is this a special day (blocked or open for online booking)? For display. */
    public static function isBlocked(string $date): bool
    {
        return array_key_exists($date, self::entries());
    }

    /** May guests book this day online although it is marked? */
    public static function isOnlineBookable(string $date): bool
    {
        return (self::entries()[$date]['online'] ?? false) === true;
    }

    public static function block(string $date, string $reason = '', bool $online = false): void
    {
        $dates = self::entries();
        $dates[$date] = ['grund' => $reason, 'online' => $online];
        self::store($dates);
    }

    public static function unblock(string $date): void
    {
        $dates = self::entries();
        unset($dates[$date]);
        self::store($dates);
    }

    /**
     * The shape WorkingSchedule::setExceptions() expects: an empty period per
     * date means "closed on this day". A day that stays open for online
     * booking is not an exception.
     */
    public static function asScheduleExceptions(): array
    {
        $closed = array_filter(self::entries(), static fn (array $entry): bool => ! $entry['online']);

        return array_map(static fn (): array => [], $closed);
    }

    private static function store(array $dates): void
    {
        // Drop what is past, otherwise the setting grows without end.
        $today = Carbon::today()->toDateString();
        $dates = array_filter($dates, fn ($entry, $date): bool => $date >= $today, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        Settings::set(self::SETTING, json_encode($dates, JSON_UNESCAPED_UNICODE), self::GROUP);
    }
}
