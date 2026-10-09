<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Table assignment: a single table first, a combination only when needed.
 *
 * TastyIgniter's own assignment is no good for that:
 *   - whereIsReservable() only admits root elements. Tables 1, 2, 4 and 7 hang
 *     as children off the combinations and are therefore never assigned - two
 *     guests get the twelve-seat combination instead of a small table.
 *   - Parents and children are not linked in terms of availability. Occupy
 *     "Tisch 1/Tisch 2" and "Tisch 1" still counts as free. Measured.
 *
 * This class therefore takes over selection and occupancy check entirely. The
 * rooms (barn, cellar, hall) stay out of it, they are only assigned by hand.
 */
class TableAllocator
{
    /** Used whenever the settings hold no usable value. */
    public const int DEFAULT_TURNOVER_BUFFER_MINUTES = 0;

    public const int DEFAULT_MAX_TABLES_PER_RESERVATION = 1;

    /** Minutes a table stays blocked after a reservation ends (cleaning, laying up). */
    public static function turnoverBufferMinutes(): int
    {
        return SettingValue::int('turnover_buffer_minutes', self::DEFAULT_TURNOVER_BUFFER_MINUTES, min: 0);
    }

    /** How many single tables one reservation may be spread over. */
    public static function maxTablesPerReservation(): int
    {
        return SettingValue::int('max_tables_per_reservation', self::DEFAULT_MAX_TABLES_PER_RESERVATION);
    }

    /** All assignable tables of a location, children included. */
    public static function candidates(int $locationId): Collection
    {
        return DiningTable::query()
            ->select('dining_tables.*')
            ->join('dining_areas', function ($join) use ($locationId): void {
                $join->on('dining_areas.id', '=', 'dining_tables.dining_area_id')
                    ->where('dining_areas.location_id', $locationId);
            })
            ->where('dining_tables.is_enabled', 1)
            ->whereNot('dining_areas.name', Rooms::areaName())
            ->get();
    }

    /**
     * Tables that are occupied in the given period - parents and children
     * included. Whoever books "Tisch 1/Tisch 2" thereby occupies Tisch 1 and
     * Tisch 2 as well.
     */
    public static function busyIds(Carbon $from, Carbon $to, Collection $reservations): Collection
    {
        $ids = collect();
        $buffer = self::turnoverBufferMinutes();

        foreach ($reservations as $r) {
            // Overlap of two periods: the start of one lies before the end of
            // the other and vice versa. The turnover buffer extends the end of
            // the existing reservation.
            if ($from->lt($r->reservation_end_datetime->copy()->addMinutes($buffer)) && $to->gt($r->reservation_datetime)) {
                $ids = $ids->merge($r->tables->pluck('id'));
            }
        }

        return self::withRelatives($ids->unique());
    }

    /** Extends a set of table ids by their parents and children. */
    public static function withRelatives(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return $ids;
        }

        $tables = DiningTable::query()->select('dining_tables.*')->get();
        $result = $ids->all();

        foreach ($ids as $id) {
            $self = $tables->firstWhere('id', $id);
            if (! $self) {
                continue;
            }

            if ($self->parent_id) {
                $result[] = (int) $self->parent_id;
            }

            foreach ($tables->where('parent_id', $id) as $child) {
                $result[] = (int) $child->id;
            }
        }

        return collect($result)->unique()->values();
    }

    /** Free tables at a given moment. */
    public static function freeAt(Collection $candidates, Carbon $at, int $duration, Collection $reservations): Collection
    {
        $busy = self::busyIds($at, $at->copy()->addMinutes(max(1, $duration)), $reservations);

        return $candidates->reject(fn ($t): bool => $busy->contains($t->id));
    }

    /**
     * Best choice for a guest count: the smallest fitting single table, and
     * only when none suffices, the smallest fitting combination.
     */
    public static function pick(Collection $free, int $guests): ?DiningTable
    {
        $fitting = $free->filter(
            fn ($t): bool => $t->min_capacity <= $guests && ($t->max_capacity + $t->extra_capacity) >= $guests,
        );

        $single = $fitting->where('is_combo', 0)->sortBy('max_capacity');
        if ($single->isNotEmpty()) {
            return $single->first();
        }

        return $fitting->where('is_combo', 1)->sortBy('max_capacity')->first();
    }

    /**
     * Table choice for a reservation: smallest fitting single table, otherwise
     * the smallest fitting combination. The reservation itself does not count
     * as occupancy - it may already have tables attached while being saved.
     */
    public static function allocate(Reservation $reservation): ?DiningTable
    {
        $locationId = (int) $reservation->location_id;
        $at = $reservation->reservation_datetime;

        $free = self::freeAt(
            self::candidates($locationId),
            $at,
            (int) $reservation->duration,
            self::reservationsOn($locationId, $at, (int) $reservation->getKey()),
        );

        return self::pick($free, max(1, (int) $reservation->guest_num));
    }

    /**
     * Ids of the tables for a reservation. One table (or one combination) as
     * before; only when none suffices and more than one table is allowed, free
     * single tables are added, largest first, until the party fits.
     *
     * @return list<int>
     */
    public static function allocateTables(Reservation $reservation, int $maxTables): array
    {
        $table = self::allocate($reservation);
        if ($table) {
            return [$table->getKey()];
        }

        if ($maxTables < 2) {
            return [];
        }

        $locationId = (int) $reservation->location_id;
        $at = $reservation->reservation_datetime;
        $guests = max(1, (int) $reservation->guest_num);

        $free = self::freeAt(
            self::candidates($locationId),
            $at,
            (int) $reservation->duration,
            self::reservationsOn($locationId, $at, (int) $reservation->getKey()),
        )->where('is_combo', 0)->sortByDesc('max_capacity')->values();

        $chosen = [];
        $seats = 0;
        foreach ($free as $candidate) {
            if (count($chosen) >= $maxTables) {
                break;
            }
            $chosen[] = $candidate->getKey();
            $seats += (int) $candidate->max_capacity;
            if ($seats >= $guests) {
                return $chosen;
            }
        }

        // Not enough seats within the limit: leave the reservation unassigned.
        return [];
    }

    /** Reservations of a day that count towards the occupancy. */
    public static function reservationsOn(int $locationId, Carbon $date, ?int $ignore = null): Collection
    {
        return Reservation::query()
            ->with('tables')
            ->where('location_id', $locationId)
            ->whereDate('reserve_date', $date->toDateString())
            ->whereNotIn('status_id', [0, (int) setting('canceled_reservation_status')])
            ->when($ignore, fn ($q) => $q->where('reservation_id', '!=', $ignore))
            ->orderBy('reserve_time')
            ->get();
    }
}
