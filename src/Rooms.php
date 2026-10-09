<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Rooms for larger parties (barn, cellar, hall).
 *
 * They sit as tables in the dining area "Räume" and are deliberately
 * is_enabled = 0. That drops them out of whereIsReservable() - and thus out of
 * the automatic table assignment, out of the occupancy check and out of the
 * public form. Manual assignment, by contrast, writes straight into
 * reservation_tables and does not care about that flag; which is exactly what
 * is used here.
 */
class Rooms
{
    /**
     * Default name of the dining area in the database. German, because that is
     * how the area is named in a running installation.
     */
    public const string DEFAULT_AREA = 'Räume';

    /** Name of the dining area that holds the rooms. */
    public static function areaName(): string
    {
        return SettingValue::string('rooms_area_name', self::DEFAULT_AREA);
    }

    public static function all(): Collection
    {
        return DiningTable::query()
            ->select('dining_tables.*')
            ->whereHas('dining_area', fn ($q) => $q->where('name', self::areaName()))
            ->orderBy('dining_tables.name')
            ->get();
    }

    public static function find(int|string|null $id): ?DiningTable
    {
        if (! $id) {
            return null;
        }

        return self::all()->firstWhere('id', (int) $id);
    }

    /**
     * Occupancy of a room on a day: the date/time pairs at which it is already
     * taken, together with the reservation that takes it.
     */
    public static function reservationsOn(Carbon $date): Collection
    {
        $roomIds = self::all()->pluck('id');

        if ($roomIds->isEmpty()) {
            return collect();
        }

        return Reservation::query()
            ->with('tables')
            ->whereDate('reserve_date', $date->toDateString())
            ->whereNotIn('status_id', [0, (int) setting('canceled_reservation_status')])
            ->whereHas('tables', fn ($q) => $q->whereIn('dining_tables.id', $roomIds))
            ->orderBy('reserve_time')
            ->get();
    }

    /** Is the room free at the given moment? */
    public static function isFreeAt(DiningTable $room, Carbon $at, Collection $reservations): bool
    {
        foreach ($reservations as $r) {
            if (! $r->tables->pluck('id')->contains($room->id)) {
                continue;
            }

            if ($at->gte($r->reservation_datetime) && $at->lt($r->reservation_end_datetime)) {
                return false;
            }
        }

        return true;
    }
}
