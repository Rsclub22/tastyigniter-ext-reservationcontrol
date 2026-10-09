<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\Reservation;

/**
 * Die Annahme einer Reservierung am Telefon.
 *
 * Stand vorher in InternalBooking::store(). Herausgezogen, weil die Annahme jetzt
 * zweimal gebraucht wird - von der internen Weboberflaeche und vom API, das die
 * Desktop-Fassung der App bedient. Vor allem die Hoechstzahlpruefung muss an
 * einer Stelle stehen: sie ist der Grund, warum an einem vollen Weihnachtstag
 * nicht zwei Geraete gleichzeitig ueberbuchen.
 *
 * Kein Mailversand, an niemanden: hier wird NICHT BookingManager::saveReservation()
 * benutzt, das wuerde "igniter.reservation.confirmed" ausloesen und drei Mails
 * verschicken. Die Statushistorie wird mit notify=false angelegt, damit auch die
 * Statusmail unterbleibt.
 */
class Annahme
{
    /**
     * Steht der Annahme eine Hoechstzahl aus einem Sperrvermerk entgegen?
     *
     * @return string|null die Meldung im Klartext, oder null wenn nichts entgegensteht
     */
    public static function hoechstzahlVerletzt(Carbon $datum, string $zeit, int $gaeste): ?string
    {
        // Nur die Vermerke, die fuer diese Uhrzeit ueberhaupt gelten: ein
        // Abendvermerk deckelt nicht den Mittagstisch.
        $vermerke = Sperrvermerke::fuerZeit(Sperrvermerke::onDate($datum), $datum, $zeit);

        // Steht fuer dieses Zeitfenster eine eigene Zahl im Text, gilt sie;
        // sonst die eine Zahl fuer alle Gaenge.
        $maxPax = Sperrvermerke::maxPaxJeZeit($vermerke)[$zeit]
            ?? Sperrvermerke::maxPax($vermerke);

        if ($maxPax === null) {
            return null;
        }

        // Gezaehlt wird alles, was zu dieser Uhrzeit schon dasteht - auch
        // Raeume: die Kueche unterscheidet nicht, wo die Leute sitzen.
        $schon = (int)(Sperrvermerke::belegungJeZeit($datum)[$zeit] ?? 0);

        if ($schon + $gaeste <= $maxPax) {
            return null;
        }

        return sprintf(
            'Um %s Uhr sind bereits %d von %d Plätzen vergeben – %d weitere passen nicht mehr. Frei sind noch %d.',
            $zeit, $schon, $maxPax, $gaeste, max(0, $maxPax - $schon),
        );
    }

    /**
     * Legt die Reservierung an.
     *
     * @param array{datum: string, zeit: string, gaeste: int, nachname: string,
     *     telefon: string, email?: ?string, notiz?: ?string, raum?: mixed,
     *     ohne_tisch?: bool} $data
     */
    public static function anlegen(array $data): Reservation
    {
        $location = Tagesdaten::standort();
        $datum = Carbon::parse($data['datum']);
        $vermerke = Sperrvermerke::fuerZeit(Sperrvermerke::onDate($datum), $datum, $data['zeit']);

        $reservation = new Reservation;
        $reservation->location_id = $location->getKey();
        $reservation->guest_num = (int)$data['gaeste'];
        // Am Telefon wird nur der Nachname erfragt. Die Spalte ist NOT NULL,
        // deshalb leerer String statt einer Wiederholung des Nachnamens.
        $reservation->first_name = '';
        $reservation->last_name = $data['nachname'];
        $reservation->telephone = $data['telefon'];
        // Spalte ist NOT NULL; ohne Angabe bleibt sie leer statt eine
        // Scheinadresse zu erfinden, an die spaeter jemand zu senden versucht.
        $reservation->email = $data['email'] ?? '';
        $reservation->comment = $data['notiz'] ?? null;
        $reservation->reserve_date = $data['datum'];
        $reservation->reserve_time = $data['zeit'].':00';
        $reservation->duration = $location->getReservationStayTime();
        $reservation->status_id = (int)setting('confirmed_reservation_status');

        $room = Rooms::find($data['raum'] ?? null);

        // Ausdruecklich ohne Zuordnung: kein Tisch, kein Raum. Das ist der
        // Notausgang fuer die Faelle, in denen die Automatik nicht passt - etwa
        // wenn der Gast erst noch anruft, wo er sitzen will, oder wenn die
        // Verteilung an diesem Tag ohnehin von Hand gemacht wird. Gewinnt gegen
        // eine Raumauswahl, damit die Angabe eindeutig bleibt.
        if (!empty($data['ohne_tisch'])) {
            $reservation->tables = [];
        } elseif (!$room && $vermerke->isNotEmpty()) {
            // Zu einer Zeit, fuer die ein Sperrvermerk gilt, verteilt der Tischplan auf Papier,
            // nicht das System. Der leere Wert ist dabei kein Versehen, sondern
            // die Ansage an den Beobachter in Extension.php: Finger weg, hier
            // ist die Zuteilung von Hand gemacht. (Er wuerde ohnehin nichts
            // finden, der Vermerk belegt alle Tische - aber Verlassen auf einen
            // Nebeneffekt ist keine Absicht.)
            $reservation->tables = [];
        } elseif ($room) {
            // Der Observer weist nur zu, wenn noch kein Tisch haengt. Den Raum
            // deshalb gleich mitgeben, dann bleibt die Automatik aussen vor.
            $reservation->tables = [$room->getKey()];
        }

        $reservation->save();

        // notify=false: keine Statusmail an den Gast.
        $reservation->addStatusHistory(
            (int)setting('confirmed_reservation_status'),
            ['notify' => false, 'comment' => 'Telefonisch angenommen'],
        );

        return $reservation;
    }
}
