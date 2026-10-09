<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * Das Tagesblatt: was fuer einen Tag aufs Papier gehoert.
 *
 * Stand vorher als tagesblaetter()/blaetter() im Controller der internen Seite.
 * Herausgezogen, weil dieselbe Zusammenstellung jetzt zweimal gebraucht wird -
 * von der Druckansicht und vom API, ueber das die Desktop-Fassung der App die
 * interne Seite ersetzt.
 */
class Tagesblatt
{
    /**
     * Obergrenze fuer den Sammeldruck. Ohne sie waere ein vertippter Zeitraum
     * ein Auftrag ueber Jahre.
     */
    public const int MAX_TAGE = 92;

    /** Alles, was ein einzelner Tag aufs Papier bringt. */
    public static function fuerTag(Location $location, Carbon $datum, ?string $trennzeit): array
    {
        // Bewusst NICHT TableAllocator::reservationsOn(): das schliesst
        // status_id = 0 aus, weil ein solcher Datensatz fuer die Belegung nicht
        // zaehlt. Auf dem Papier muss er trotzdem stehen - eine Reservierung,
        // die niemand sieht, ist genau der Fall, den dieses Blatt verhindern
        // soll. Tische werden nur geladen, nicht vorausgesetzt: ein Teil der
        // Reservierungen hat absichtlich keinen.
        $alle = Reservation::query()
            ->with('tables')
            ->where('location_id', $location->getKey())
            ->whereDate('reserve_date', $datum->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->orderBy('reserve_time')
            ->orderBy('reservation_id')
            ->get();

        // Sperrvermerke aussortieren: Eintraege, die mehr Gaeste fuehren als
        // ueberhaupt ins Haus passen, sind keine Gesellschaft, sondern ein
        // Riegel gegen die Online-Buchung. Als Gastzeile waeren sie unbrauchbar
        // (eine Zeile mit allen vierzehn Tischen) und wuerden die Gaestezahl im
        // Kopf unbrauchbar machen. Ihr Text ist aber oft die wichtigste Angabe
        // des Tages - er kommt deshalb als Band ueber die Tabelle.
        $hausgroesse = Sperrvermerke::hausgroesse();

        $istVermerk = static fn (Reservation $r): bool => Sperrvermerke::istVermerk($r, $hausgroesse);

        return [
            'datum' => $datum,
            'blaetter' => self::blaetter($alle->reject($istVermerk)->values(), $trennzeit),
            'sperrvermerke' => $alle->filter($istVermerk)->values(),
            'maxPax' => Sperrvermerke::maxPax($alle->filter($istVermerk)),
            'paxJeZeit' => Sperrvermerke::maxPaxJeZeit($alle->filter($istVermerk)),
            'gesperrt' => BlockedDates::isBlocked($datum->toDateString()),
            'grund' => BlockedDates::all()[$datum->toDateString()] ?? '',
        ];
    }

    /** Reservierungen eines Tages auf die Blaetter verteilen. */
    private static function blaetter(Collection $alle, ?string $trennzeit): array
    {
        $uhrzeit = static fn (Reservation $r): string => Carbon::parse($r->reserve_time)->format('H:i');

        if ($trennzeit === null) {
            return $alle->isEmpty() ? [] : [['titel' => 'Ganzer Tag', 'reservierungen' => $alle]];
        }

        $abschnitte = [
            ['titel' => 'Bis '.$trennzeit.' Uhr', 'reservierungen' => $alle->filter(
                static fn (Reservation $r): bool => $uhrzeit($r) < $trennzeit,
            )->values()],
            ['titel' => 'Ab '.$trennzeit.' Uhr', 'reservierungen' => $alle->filter(
                static fn (Reservation $r): bool => $uhrzeit($r) >= $trennzeit,
            )->values()],
        ];

        return array_values(array_filter(
            $abschnitte,
            static fn (array $a): bool => $a['reservierungen']->isNotEmpty(),
        ));
    }
}
