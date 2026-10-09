<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Tischvergabe: Einzeltisch zuerst, Kombination nur wenn noetig.
 *
 * TastyIgniters eigene Vergabe taugt dafuer nicht:
 *   - whereIsReservable() laesst nur Wurzelelemente zu. Tisch 1, 2, 4 und 7
 *     haengen als Kinder an den Kombinationen und werden daher nie vergeben -
 *     zwei Gaeste bekommen die Zwoelfer-Kombination statt eines kleinen Tisches.
 *   - Eltern und Kinder sind bei der Verfuegbarkeit nicht verknuepft. Belegt man
 *     "Tisch 1/Tisch 2", gilt "Tisch 1" weiterhin als frei. Nachgemessen.
 *
 * Deshalb uebernimmt diese Klasse Auswahl und Belegungspruefung vollstaendig.
 * Die Raeume (Scheune, Keller, Saal) bleiben aussen vor, sie werden nur von Hand
 * vergeben.
 */
class TableAllocator
{
    /** Alle vergebbaren Tische eines Standorts, Kinder eingeschlossen. */
    public static function candidates(int $locationId): Collection
    {
        return DiningTable::query()
            ->select('dining_tables.*')
            ->join('dining_areas', function($join) use ($locationId): void {
                $join->on('dining_areas.id', '=', 'dining_tables.dining_area_id')
                    ->where('dining_areas.location_id', $locationId);
            })
            ->where('dining_tables.is_enabled', 1)
            ->whereNot('dining_areas.name', Rooms::AREA)
            ->get();
    }

    /**
     * Tische, die im genannten Zeitraum belegt sind - samt Eltern und Kindern.
     * Wer "Tisch 1/Tisch 2" bucht, belegt damit auch Tisch 1 und Tisch 2.
     */
    public static function busyIds(Carbon $from, Carbon $to, Collection $reservations): Collection
    {
        $ids = collect();

        foreach ($reservations as $r) {
            // Ueberschneidung zweier Zeitraeume: Start des einen liegt vor dem
            // Ende des anderen und umgekehrt.
            if ($from->lt($r->reservation_end_datetime) && $to->gt($r->reservation_datetime)) {
                $ids = $ids->merge($r->tables->pluck('id'));
            }
        }

        return self::withRelatives($ids->unique());
    }

    /** Erweitert eine Menge von Tisch-IDs um deren Eltern und Kinder. */
    public static function withRelatives(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return $ids;
        }

        $tables = DiningTable::query()->select('dining_tables.*')->get();
        $result = $ids->all();

        foreach ($ids as $id) {
            $self = $tables->firstWhere('id', $id);
            if (!$self) {
                continue;
            }

            if ($self->parent_id) {
                $result[] = (int)$self->parent_id;
            }

            foreach ($tables->where('parent_id', $id) as $child) {
                $result[] = (int)$child->id;
            }
        }

        return collect($result)->unique()->values();
    }

    /** Freie Tische zu einem Zeitpunkt. */
    public static function freeAt(Collection $candidates, Carbon $at, int $duration, Collection $reservations): Collection
    {
        $busy = self::busyIds($at, $at->copy()->addMinutes(max(1, $duration)), $reservations);

        return $candidates->reject(fn($t): bool => $busy->contains($t->id));
    }

    /**
     * Beste Wahl fuer eine Gaestezahl: der kleinste passende Einzeltisch, und
     * erst wenn keiner reicht, die kleinste passende Kombination.
     */
    public static function pick(Collection $free, int $guests): ?DiningTable
    {
        $passend = $free->filter(
            fn($t): bool => $t->min_capacity <= $guests && ($t->max_capacity + $t->extra_capacity) >= $guests,
        );

        $einzeln = $passend->where('is_combo', 0)->sortBy('max_capacity');
        if ($einzeln->isNotEmpty()) {
            return $einzeln->first();
        }

        return $passend->where('is_combo', 1)->sortBy('max_capacity')->first();
    }

    /**
     * Tischwahl fuer eine Reservierung: kleinster passender Einzeltisch, sonst
     * die kleinste passende Kombination. Die Reservierung selbst zaehlt nicht
     * als Belegung - sie kann beim Speichern bereits Tische haengen haben.
     */
    public static function allocate(Reservation $reservation): ?DiningTable
    {
        $locationId = (int)$reservation->location_id;
        $at = $reservation->reservation_datetime;

        $frei = self::freeAt(
            self::candidates($locationId),
            $at,
            (int)$reservation->duration,
            self::reservationsOn($locationId, $at, (int)$reservation->getKey()),
        );

        return self::pick($frei, max(1, (int)$reservation->guest_num));
    }

    /** Reservierungen eines Tages, die fuer die Belegung zaehlen. */
    public static function reservationsOn(int $locationId, Carbon $date, ?int $ignore = null): Collection
    {
        return Reservation::query()
            ->with('tables')
            ->where('location_id', $locationId)
            ->whereDate('reserve_date', $date->toDateString())
            ->whereNotIn('status_id', [0, (int)setting('canceled_reservation_status')])
            ->when($ignore, fn($q) => $q->where('reservation_id', '!=', $ignore))
            ->orderBy('reserve_time')
            ->get();
    }
}
