<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Entry;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Illuminate\Support\Collection;
use Wagnersnetz\ReservationControl\TableAllocator;

/**
 * Table selection for entry by hand.
 *
 * The occupancy check deliberately comes from TableAllocator: that already
 * holds the knowledge that a combined table occupies its individual tables and
 * vice versa. A second version of it would drift apart, and unnoticed at that -
 * the mistake only shows up once two parties sit at the same table.
 */
class TableChoice
{
    /** All tables, keyed by id. Rooms are included - by hand they may be assigned. */
    public static function all(): Collection
    {
        return DiningTable::query()->orderBy('id')->get()->keyBy('id');
    }

    /**
     * Turn free text into table ids: "3", "T3", "Tisch 3", "1,2", "Saal", and
     * "Tisch 1/Tisch 2" as the name of a combination.
     *
     * @return array{0: int[], 1: string[]} [ids, entries not understood]
     */
    public static function fromText(string $raw, Collection $tables): array
    {
        $byName = $tables->mapWithKeys(
            fn (DiningTable $t): array => [Prompt::normalized((string) $t->name) => (int) $t->getKey()],
        );

        // First the whole text as one name: "Tisch 1/Tisch 2" is a combination
        // and must not be split at the slash.
        //
        // The German "und" in the split pattern stays: it is what a person
        // types when naming two tables.
        $parts = isset($byName[Prompt::normalized($raw)])
            ? [$raw]
            : (preg_split('#\s*[,;+/&]\s*|\s+und\s+|\s+#i', trim($raw)) ?: []);

        $ids = [];
        $unknown = [];

        foreach ($parts as $part) {
            if (($part = trim($part)) === '') {
                continue;
            }

            $key = Prompt::normalized($part);

            // First the name itself, then "3" and "T3" as a short form for
            // "Tisch 3" - on the phone only the number is named. The "tisch"
            // prefix is the German table name as it stands in the database.
            $id = $byName[$key]
                ?? $byName['tisch'.ltrim($key, 't')]
                ?? null;

            if ($id !== null) {
                $ids[] = $id;

                continue;
            }

            $unknown[] = $part;
        }

        return [array_values(array_unique($ids)), $unknown];
    }

    /**
     * Who is already at which table within the time window?
     *
     * @return array<int, array{reservation: int, name: string, time: string, guests: int}>
     */
    public static function occupancy(
        int $locationId,
        string $date,
        string $time,
        int $duration,
        ?int $except = null,
    ): array {
        $from = Carbon::parse($date.' '.$time);
        $to = $from->copy()->addMinutes(max(1, $duration));

        $taken = [];

        foreach (TableAllocator::reservationsOn($locationId, $from, $except) as $r) {
            if (! $from->lt($r->reservation_end_datetime) || ! $to->gt($r->reservation_datetime)) {
                continue;
            }

            $who = [
                'reservation' => (int) $r->reservation_id,
                'name' => trim($r->first_name.' '.$r->last_name),
                'time' => substr((string) $r->reserve_time, 0, 5),
                'guests' => (int) $r->guest_num,
            ];

            // Lock parents and children along with it - otherwise Tisch 1
            // counts as free while the combination Tisch 1/Tisch 2 is taken.
            foreach (TableAllocator::withRelatives($r->tables->pluck('id')) as $id) {
                $taken[(int) $id] ??= $who;
            }
        }

        return $taken;
    }

    /** Sum of the seats - extra seats included. */
    public static function capacity(array $ids, Collection $tables): int
    {
        $sum = 0;

        foreach ($ids as $id) {
            if ($table = $tables->get($id)) {
                $sum += (int) $table->max_capacity + (int) $table->extra_capacity;
            }
        }

        return $sum;
    }

    /** "Tisch 1 + Tisch 2", or the note that none is set. */
    public static function names(array $ids, Collection $tables, string $empty = 'no table'): string
    {
        if ($ids === []) {
            return $empty;
        }

        return implode(' + ', array_map(
            fn (int $id): string => (string) ($tables->get($id)->name ?? '#'.$id),
            $ids,
        ));
    }
}
