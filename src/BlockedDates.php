<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\System\Models\Settings;

/**
 * Einzelne gesperrte Tage (Ruhetag ausserhalb des Wochenrhythmus, Betriebsferien,
 * geschlossene Gesellschaft).
 *
 * TastyIgniter kennt Ausnahmen im Zeitplan bereits - WorkingSchedule::forDate()
 * prueft erst die Ausnahmen und faellt dann auf den Wochentag zurueck. Es gibt
 * dafuer aber weder eine Tabelle noch eine Oberflaeche. Die Daten liegen deshalb
 * als Einstellung; fuer eine Handvoll Termine im Jahr reicht das und erspart eine
 * eigene Migration.
 */
class BlockedDates
{
    private const string SETTING = 'reservetweaks_blocked_dates';

    private const string GROUP = 'prefs';

    /** @return array<string, string> Datum (Y-m-d) => Grund */
    public static function all(): array
    {
        // Bewusst JSON statt eines Arrays: Settings::set() serialisiert Arrays
        // zwar, setzt aber die Spalte "serialized" nicht - beim Lesen kaeme dann
        // eine Zeichenkette zurueck. Und ausdruecklich die Gruppe angeben, denn
        // der setting()-Helfer liest nur "config".
        $raw = Settings::get(self::SETTING, '', self::GROUP);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $dates = json_decode($raw, true);

        return is_array($dates) ? $dates : [];
    }

    /** Nur heutige und kuenftige Sperren, aufsteigend sortiert. */
    public static function upcoming(): array
    {
        $heute = Carbon::today()->toDateString();
        $dates = array_filter(self::all(), fn($grund, $datum): bool => $datum >= $heute, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        return $dates;
    }

    public static function isBlocked(string $date): bool
    {
        return array_key_exists($date, self::all());
    }

    public static function block(string $date, string $grund = ''): void
    {
        $dates = self::all();
        $dates[$date] = $grund;
        self::store($dates);
    }

    public static function unblock(string $date): void
    {
        $dates = self::all();
        unset($dates[$date]);
        self::store($dates);
    }

    /**
     * Form fuer WorkingSchedule::setExceptions(): ein leerer Zeitraum je Datum
     * bedeutet "an diesem Tag geschlossen".
     */
    public static function asScheduleExceptions(): array
    {
        return array_map(static fn(): array => [], self::all());
    }

    private static function store(array $dates): void
    {
        // Vergangenes mitnehmen, sonst waechst die Einstellung endlos.
        $heute = Carbon::today()->toDateString();
        $dates = array_filter($dates, fn($grund, $datum): bool => $datum >= $heute, ARRAY_FILTER_USE_BOTH);
        ksort($dates);

        Settings::set(self::SETTING, json_encode($dates, JSON_UNESCAPED_UNICODE), self::GROUP);
    }
}
