<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Raeume fuer groessere Gesellschaften (Scheune, Keller, Saal).
 *
 * Sie liegen als Tische im Bereich "Raeume" und sind bewusst is_enabled = 0.
 * Damit fallen sie aus whereIsReservable() heraus - also aus der automatischen
 * Tischzuweisung, aus der Belegungspruefung und aus dem oeffentlichen Formular.
 * Die manuelle Zuweisung schreibt dagegen direkt in reservation_tables und
 * kuemmert sich nicht um dieses Kennzeichen; genau das wird hier genutzt.
 */
class Rooms
{
    public const string AREA = 'Räume';

    public static function all(): Collection
    {
        return DiningTable::query()
            ->select('dining_tables.*')
            ->whereHas('dining_area', fn ($q) => $q->where('name', self::AREA))
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
     * Belegung eines Raumes an einem Tag: Datum/Uhrzeit-Paare, zu denen er
     * bereits vergeben ist, samt zugehoeriger Reservierung.
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

    /** Ist der Raum zum genannten Zeitpunkt frei? */
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
