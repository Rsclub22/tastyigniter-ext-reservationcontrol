<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Erfassung;

use Carbon\Carbon;
use Igniter\Reservation\Models\DiningTable;
use Illuminate\Support\Collection;
use Wagnersnetz\ReservationControl\TableAllocator;

/**
 * Tischauswahl fuer die Erfassung von Hand.
 *
 * Die Belegungspruefung kommt bewusst aus TableAllocator: dort steckt schon,
 * dass ein Kombitisch seine Einzeltische mitbelegt und umgekehrt. Eine zweite
 * Fassung davon wuerde auseinanderlaufen, und zwar unbemerkt - der Fehler faellt
 * erst auf, wenn zwei Gesellschaften am selben Tisch sitzen.
 */
class Tischwahl
{
    /** Alle Tische, nach ID. Raeume sind dabei - von Hand darf man sie vergeben. */
    public static function alle(): Collection
    {
        return DiningTable::query()->orderBy('id')->get()->keyBy('id');
    }

    /**
     * Freitext in Tisch-IDs umsetzen: "3", "T3", "Tisch 3", "1,2", "Saal",
     * und "Tisch 1/Tisch 2" als Name einer Kombination.
     *
     * @return array{0: int[], 1: string[]} [IDs, unverstandene Angaben]
     */
    public static function ausText(string $roh, Collection $tische): array
    {
        $nachName = $tische->mapWithKeys(
            fn(DiningTable $t): array => [Eingabe::normalisiert((string)$t->name) => (int)$t->getKey()],
        );

        // Erst der ganze Text als ein Name: "Tisch 1/Tisch 2" ist eine
        // Kombination und darf nicht am Schraegstrich zerlegt werden.
        $teile = isset($nachName[Eingabe::normalisiert($roh)])
            ? [$roh]
            : (preg_split('#\s*[,;+/&]\s*|\s+und\s+|\s+#i', trim($roh)) ?: []);

        $ids = [];
        $unbekannt = [];

        foreach ($teile as $teil) {
            if (($teil = trim($teil)) === '') {
                continue;
            }

            $schluessel = Eingabe::normalisiert($teil);

            // Zuerst der Name selbst, dann "3" und "T3" als Kurzform fuer
            // "Tisch 3" - am Telefon wird nur die Nummer genannt.
            $id = $nachName[$schluessel]
                ?? $nachName['tisch'.ltrim($schluessel, 't')]
                ?? null;

            if ($id !== null) {
                $ids[] = $id;

                continue;
            }

            $unbekannt[] = $teil;
        }

        return [array_values(array_unique($ids)), $unbekannt];
    }

    /**
     * Wer ist im Zeitfenster schon auf welchem Tisch?
     *
     * @return array<int, array{reservierung: int, name: string, zeit: string, gaeste: int}>
     */
    public static function belegung(
        int $locationId,
        string $datum,
        string $zeit,
        int $dauer,
        ?int $ausser = null,
    ): array {
        $von = Carbon::parse($datum.' '.$zeit);
        $bis = $von->copy()->addMinutes(max(1, $dauer));

        $belegt = [];

        foreach (TableAllocator::reservationsOn($locationId, $von, $ausser) as $r) {
            if (!$von->lt($r->reservation_end_datetime) || !$bis->gt($r->reservation_datetime)) {
                continue;
            }

            $wer = [
                'reservierung' => (int)$r->reservation_id,
                'name' => trim($r->first_name.' '.$r->last_name),
                'zeit' => substr((string)$r->reserve_time, 0, 5),
                'gaeste' => (int)$r->guest_num,
            ];

            // Eltern und Kinder mitsperren - sonst gilt Tisch 1 als frei,
            // waehrend die Kombination Tisch 1/Tisch 2 vergeben ist.
            foreach (TableAllocator::withRelatives($r->tables->pluck('id')) as $id) {
                $belegt[(int)$id] ??= $wer;
            }
        }

        return $belegt;
    }

    /** Summe der Plaetze - Zusatzplaetze eingerechnet. */
    public static function kapazitaet(array $ids, Collection $tische): int
    {
        $summe = 0;

        foreach ($ids as $id) {
            if ($tisch = $tische->get($id)) {
                $summe += (int)$tisch->max_capacity + (int)$tisch->extra_capacity;
            }
        }

        return $summe;
    }

    /** "Tisch 1 + Tisch 2", oder der Hinweis, dass keiner gesetzt ist. */
    public static function namen(array $ids, Collection $tische, string $leer = 'ohne Tisch'): string
    {
        if ($ids === []) {
            return $leer;
        }

        return implode(' + ', array_map(
            fn(int $id): string => (string)($tische->get($id)->name ?? '#'.$id),
            $ids,
        ));
    }
}
