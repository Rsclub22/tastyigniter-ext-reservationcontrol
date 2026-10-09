<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;

/**
 * Alles, was die Telefonannahme ueber einen Tag wissen muss: Zeitfenster mit
 * Belegung, Sperrtage, Sperrvermerke samt Hoechstzahlen, Raeume, Tischzahlen.
 *
 * Stand vorher als pageData() im Controller der internen Seite. Herausgezogen,
 * weil dieselbe Berechnung jetzt zweimal gebraucht wird - von der Weboberflaeche
 * und vom API, das die Desktop-Fassung der App bedient. Zwei Fassungen davon
 * wuerden auseinanderlaufen, und der Fehler faellt erst auf, wenn an einem
 * Weihnachtstag zwei Gesellschaften dieselbe Zeit bekommen.
 */
class Tagesdaten
{
    /** Vorgabe, ab wann das Tagesblatt auf ein zweites Blatt wechselt. */
    public const string TRENNZEIT = '15:00';

    public static function fuer(Carbon $date, int $guests, ?DiningTable $room = null, ?Reservation $neu = null): array
    {
        $location = self::standort();

        /** @var LargePartyBookingManager $manager */
        $manager = resolve(BookingManager::class);
        $manager->useLocation($location);
        if ($manager instanceof LargePartyBookingManager) {
            $manager->forceGuestCount($guests)->allowSameDay();
        }

        $slots = collect($manager->makeTimeSlots($date))->map(fn($t): Carbon => Carbon::parse($t));

        $vermerke = Sperrvermerke::onDate($date);
        $vermerkZeiten = Sperrvermerke::zeiten($vermerke);
        $maxPax = Sperrvermerke::maxPax($vermerke);
        $paxJeZeit = Sperrvermerke::maxPaxJeZeit($vermerke);

        // Alle vergebbaren Tische, Kombinationen und ihre Einzeltische.
        $tables = TableAllocator::candidates($location->getKey());

        // Fuer Zaehlungen nur die echten Tische: eine Kombination sind dieselben
        // Plaetze noch einmal, sie wuerde die Kapazitaet doppelt ausweisen.
        $echte = $tables->where('is_combo', false);

        $reservations = TableAllocator::reservationsOn($location->getKey(), $date);

        $roomReservations = Rooms::reservationsOn($date);

        $dauer = (int)$location->getReservationStayTime();

        $ganztags = Sperrvermerke::ganztaegige($vermerke, $date);

        // Liegt ein Sperrvermerk ueber dem Tag, sind alle Tische belegt. Die
        // gewohnte Pruefung meldet dann jede Zeit als voll und die
        // Telefonannahme waere zu - dafuer ist der Vermerk aber nicht da. Am
        // Telefon wird weiter angenommen, nur ohne Tisch: die Verteilung macht
        // an solchen Tagen der Tischplan auf Papier, nicht das System.
        //
        // Nennt der Vermerk Uhrzeiten ("2 Gaenge: 11 Uhr und 13 Uhr"), werden
        // nur die angeboten - auch wenn sie ausserhalb der Oeffnungszeiten
        // liegen, denn an solchen Tagen gelten sie und nicht der Wochenplan.
        // Steht keine Zeit im Text, bleibt es bei den gewohnten Zeitfenstern:
        // lieber zu viel anbieten als den Tag versehentlich dichtmachen.
        //
        // Ein ausgewaehlter Raum geht vor: Scheune, Keller und Saal haengen
        // nicht an den Tischen und sind auch an solchen Tagen frei vergebbar.
        if (!$room && $ganztags->isNotEmpty()) {
            $zeiten = $vermerkZeiten ?: $slots->map(fn(Carbon $s): string => $s->format('H:i'))->all();

            // Nennt der Vermerk eine Hoechstzahl, gilt sie je Zeitfenster.
            // Gezaehlt wird alles, was zu dieser Uhrzeit schon dasteht - auch
            // Raeume: die Kueche unterscheidet nicht, wo die Leute sitzen.
            $belegt = $maxPax === null ? [] : Sperrvermerke::belegungJeZeit($date);

            $belegung = array_map(static function(string $zeit) use ($maxPax, $paxJeZeit, $belegt, $guests): array {
                $schon = (int)($belegt[$zeit] ?? 0);

                // Steht fuer diesen Gang eine eigene Zahl im Text, gilt sie;
                // sonst die eine Zahl fuer alle Gaenge.
                $maxPax = $paxJeZeit[$zeit] ?? $maxPax;

                return [
                    'zeit' => $zeit,
                    'frei' => $maxPax === null ? 0 : max(0, $maxPax - $schon),
                    'gesamt' => $maxPax ?? 0,
                    'freie_plaetze' => $maxPax === null ? 0 : max(0, $maxPax - $schon),
                    'passt' => $maxPax === null || $schon + $guests <= $maxPax,
                    'groesster' => 0,
                    'raum' => null,
                    'ohne_tisch' => true,
                    'pax_max' => $maxPax,
                    'pax_belegt' => $schon,
                ];
            }, $zeiten);
        } else {
            $belegung = $slots->map(function(Carbon $slot) use ($date, $tables, $echte, $dauer, $reservations, $guests, $room, $roomReservations): array {
                $at = $date->copy()->setTimeFromTimeString($slot->format('H:i'));

                // Ist ein Raum gewaehlt, zaehlt allein dessen Belegung - die Tische
                // sind dann unerheblich, und die Personenzahl begrenzt nichts mehr.
                if ($room) {
                    $frei = Rooms::isFreeAt($room, $at, $roomReservations);

                    return [
                        'zeit' => $slot->format('H:i'),
                        'frei' => $frei ? 1 : 0,
                        'gesamt' => 1,
                        'freie_plaetze' => 0,
                        'passt' => $frei,
                        'groesster' => 0,
                        'raum' => $room->name,
                        'ohne_tisch' => false,
                        'pax_max' => null,
                        'pax_belegt' => 0,
                    ];
                }

                $frei = TableAllocator::freeAt($tables, $at, $dauer, $reservations);
                $freieEchte = $frei->where('is_combo', false);

                return [
                    'zeit' => $slot->format('H:i'),
                    'frei' => $freieEchte->count(),
                    'gesamt' => $echte->count(),
                    'freie_plaetze' => (int)$freieEchte->sum('max_capacity'),
                    'passt' => TableAllocator::pick($frei, $guests) !== null,
                    'groesster' => (int)$frei->max(fn($t): int => $t->max_capacity + $t->extra_capacity) ?: 0,
                    'raum' => null,
                    'ohne_tisch' => false,
                    'pax_max' => null,
                    'pax_belegt' => 0,
                ];
            })->all();

            // Ein Vermerk neben den Oeffnungszeiten nimmt den Tag nicht ein, hat
            // aber eigene Zeiten: der Maerchenabend um 17 Uhr steht am Telefon
            // zur Annahme, ohne dass er den Mittagstisch zumacht. Ohne Tisch,
            // denn die Tische sind zu der Zeit vom Vermerk belegt.
            if (!$room && $vermerke->isNotEmpty()) {
                $bekannt = array_column($belegung, 'zeit');
                $belegt = Sperrvermerke::belegungJeZeit($date);

                foreach ($vermerkZeiten as $zeit) {
                    if (in_array($zeit, $bekannt, true)) {
                        continue;
                    }

                    $grenze = $paxJeZeit[$zeit] ?? $maxPax;
                    $schon = (int)($belegt[$zeit] ?? 0);

                    $belegung[] = [
                        'zeit' => $zeit,
                        'frei' => $grenze === null ? 0 : max(0, $grenze - $schon),
                        'gesamt' => $grenze ?? 0,
                        'freie_plaetze' => $grenze === null ? 0 : max(0, $grenze - $schon),
                        'passt' => $grenze === null || $schon + $guests <= $grenze,
                        'groesster' => 0,
                        'raum' => null,
                        'ohne_tisch' => true,
                        'pax_max' => $grenze,
                        'pax_belegt' => $schon,
                    ];
                }

                usort($belegung, static fn(array $a, array $b): int => strcmp($a['zeit'], $b['zeit']));
            }
        }

        return [
            'datum' => $date,
            'gaeste' => $guests,
            'gesperrt' => BlockedDates::isBlocked($date->toDateString()),
            'grund' => BlockedDates::all()[$date->toDateString()] ?? '',
            'sperren' => BlockedDates::upcoming(),
            'raeume' => Rooms::all(),
            'raum' => $room,
            'raumBelegung' => $roomReservations,
            'belegung' => $belegung,
            'neu' => $neu,
            'reservierungen' => $reservations,
            'tischeGesamt' => $echte->count(),
            'plaetzeGesamt' => (int)$echte->sum('max_capacity'),
            'standort' => $location,
            'vermerke' => $vermerke,
            'ganztags' => $ganztags,
            'vermerkZeiten' => $vermerkZeiten,
            'maxPax' => $maxPax,
            'paxJeZeit' => $paxJeZeit,
            'trennzeit' => self::trennzeit(null),
        ];
    }

    /** Trennzeit als HH:MM, oder null fuer ein einziges Blatt. */
    public static function trennzeit(?string $raw): ?string
    {
        $raw = trim((string)($raw ?? env('INTERN_DRUCK_TRENNZEIT', self::TRENNZEIT)));

        if ($raw === '' || strtolower($raw) === 'aus') {
            return null;
        }

        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $treffer)) {
            return self::TRENNZEIT;
        }

        $stunde = (int)$treffer[1];
        $minute = (int)$treffer[2];

        return $stunde <= 23 && $minute <= 59
            ? sprintf('%02d:%02d', $stunde, $minute)
            : self::TRENNZEIT;
    }

    public static function standort(): Location
    {
        $location = Location::query()->whereIsEnabled()->first();
        abort_if(!$location, 500, 'Kein aktiver Standort vorhanden.');

        return $location;
    }
}
