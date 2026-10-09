<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Controllers;

use Carbon\Carbon;
use Igniter\Admin\Models\Status;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Wagnersnetz\ReservationControl\Annahme;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\Tagesblatt;
use Wagnersnetz\ReservationControl\Tagesdaten;

/**
 * Telefonannahme fuer Reservierungen, nur im lokalen Netz erreichbar.
 *
 * Bewusst anders als die oeffentliche Seite:
 *   - keine E-Mail-Pflicht (am Telefon will das niemand abfragen)
 *   - nur Nachname und Telefonnummer, kein Vorname
 *   - Status sofort "Bestaetigt" statt "Ausstehend"
 *   - kein Mailversand, an niemanden
 *   - Belegung des Tages auf einen Blick
 *
 * Der Mailversand unterbleibt, weil hier NICHT BookingManager::saveReservation()
 * benutzt wird: das wuerde "igniter.reservation.confirmed" ausloesen, worauf
 * SendReservationConfirmation drei Mails verschickt. Die Statushistorie wird mit
 * notify=false angelegt, damit auch die Statusmail unterbleibt.
 */
class InternalBooking extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->resolveDate($request->query('datum'));
        $guests = max(1, (int) $request->query('gaeste', 2));
        $room = Rooms::find($request->query('raum'));

        return view('reservationcontrol::intern', $this->pageData($date, $guests, $room, $this->angenommen($request, $date)));
    }

    /** Voreinstellung, wann der Tag aufs zweite Blatt wechselt. */

    /**
     * Tagesblatt zum Abheften.
     *
     * Der Tag wird an einer Uhrzeit in zwei Blaetter geteilt - Mittag und
     * Abend liegen im Hefter sonst durcheinander. Die Trennzeit steht per
     * INTERN_DRUCK_TRENNZEIT in der .env und laesst sich beim Drucken
     * ueberschreiben: an Weihnachten gibt es nur zwei Sitzungen, und deren
     * Grenze liegt nicht bei 15:00. "aus" druckt den Tag am Stueck.
     *
     * Ein Abschnitt ohne Reservierungen wird nicht gedruckt.
     */
    /** Obergrenze fuer den Sammeldruck - schuetzt vor einem versehentlichen Jahr. */
    public function printDay(Request $request): View
    {
        $trennzeit = $this->trennzeit($request->query('trennzeit'));
        $location = $this->location();

        [$von, $bis] = $this->zeitraum($request);
        $sammeldruck = $von->ne($bis);

        $tage = [];
        for ($tag = $von->copy(); $tag->lte($bis); $tag->addDay()) {
            $blatt = $this->tagesblaetter($location, $tag->copy(), $trennzeit);

            // Beim Sammeldruck leere Tage ueberspringen: Mo und Di ist zu, ein
            // Monat brauchte sonst ein Dutzend Blaetter mit nichts darauf. Ein
            // Tag mit Sperrvermerk, aber ohne Gaeste, wird trotzdem gedruckt -
            // der Vermerk ist ja gerade die Ansage fuer diesen Tag.
            if ($sammeldruck && $blatt['blaetter'] === [] && $blatt['sperrvermerke']->isEmpty()) {
                continue;
            }

            $tage[] = $blatt;
        }

        return view('reservationcontrol::intern-druck', [
            'tage' => $tage,
            'von' => $von,
            'bis' => $bis,
            'sammeldruck' => $sammeldruck,
            'standort' => $location,
            'trennzeit' => $trennzeit,
            'bestaetigt' => (int) setting('confirmed_reservation_status'),
            'status' => Status::query()->where('status_for', 'reservation')
                ->pluck('status_name', 'status_id')->all(),
            'gedruckt' => Carbon::now(),
        ]);
    }

    /** Zu druckender Zeitraum. Ohne von/bis bleibt es beim einzelnen Tag. */
    private function zeitraum(Request $request): array
    {
        if ($request->query('modus') !== 'zeitraum') {
            $tag = $this->resolveDate($request->query('datum'));

            return [$tag, $tag->copy()];
        }

        $von = $this->resolveDate($request->query('von') ?: $request->query('datum'));
        $bis = $this->resolveDate($request->query('bis') ?: $request->query('datum'));

        // Verdrehte Eingabe nicht abweisen, sondern verstehen.
        if ($bis->lt($von)) {
            [$von, $bis] = [$bis, $von];
        }

        if ($von->diffInDays($bis) >= Tagesblatt::MAX_TAGE) {
            $bis = $von->copy()->addDays(Tagesblatt::MAX_TAGE - 1);
        }

        return [$von, $bis];
    }

    /** Alles, was ein einzelner Tag aufs Papier bringt. */
    private function tagesblaetter(Location $location, Carbon $datum, ?string $trennzeit): array
    {
        return Tagesblatt::fuerTag($location, $datum, $trennzeit);
    }

    /** Trennzeit als HH:MM, oder null fuer ein einziges Blatt. */
    private function trennzeit(?string $raw): ?string
    {
        return Tagesdaten::trennzeit($raw);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'zeit' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'gaeste' => ['required', 'integer', 'min:1', 'max:200'],
            'nachname' => ['required', 'string', 'max:48'],
            'telefon' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email:filter', 'max:96'],
            'notiz' => ['nullable', 'string', 'max:500'],
            'raum' => ['nullable', 'integer'],
        ], [], [
            'datum' => 'Datum', 'zeit' => 'Uhrzeit', 'gaeste' => 'Personenzahl',
            'nachname' => 'Nachname', 'telefon' => 'Telefon',
            'email' => 'E-Mail', 'notiz' => 'Notiz',
        ]);

        $datum = Carbon::parse($data['datum']);

        // Die Pruefung steckt in Annahme, damit sie fuer Weboberflaeche und API
        // dieselbe ist. Zwei offene Browserfenster an einem vollen
        // Weihnachtstag sind genau der Fall, in dem eine reine Anzeigegrenze
        // zu spaet kommt.
        if ($meldung = Annahme::hoechstzahlVerletzt($datum, $data['zeit'], (int) $data['gaeste'])) {
            return $this->zurueck($request, $datum->toDateString())
                ->withErrors(['gaeste' => $meldung]);
        }

        $reservation = Annahme::anlegen($data);

        // Weder route() noch ein relativer Pfad: TastyIgniter erzwingt APP_URL
        // (urlPolicy=force), Laravel wuerde daraus die oeffentliche Adresse
        // bauen - und dort ist /intern gesperrt. Deshalb ausdruecklich der Host
        // der aktuellen Anfrage, damit die Annahme auf dem LAN-Port bleibt.
        // Die Bestaetigung haengt an der Nummer in der Adresse, nicht an einer
        // Flash-Nachricht: die waere nach dem naechsten Klick oder einem
        // Neuladen weg. Am Telefon will man sie aber noch sehen, waehrend man
        // dem Gast die Zeit wiederholt.
        $ziel = $request->getSchemeAndHttpHost().'/intern?'.http_build_query(array_filter([
            'datum' => $data['datum'],
            'gaeste' => $data['gaeste'],
            'raum' => $data['raum'] ?? null,
            'neu' => $reservation->getKey(),
        ]));

        return redirect()->to($ziel);
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'grund' => ['nullable', 'string', 'max:120'],
        ], [], ['datum' => 'Datum', 'grund' => 'Grund']);

        $datum = Carbon::parse($data['datum'])->toDateString();
        $offen = Reservation::query()
            ->whereDate('reserve_date', $datum)
            ->whereNotIn('status_id', [0, (int) setting('canceled_reservation_status')])
            ->count();

        // Einen Tag zu sperren, an dem schon Gaeste erwartet werden, ist fast
        // immer ein Versehen - deshalb abweisen statt stillschweigend sperren.
        if ($offen > 0) {
            return $this->zurueck($request, $datum)->withErrors([
                'datum' => sprintf(
                    'Am %s liegen bereits %d Reservierungen. Erst absagen, dann sperren.',
                    Carbon::parse($datum)->locale('de')->isoFormat('D. MMMM'), $offen,
                ),
            ]);
        }

        BlockedDates::block($datum, trim((string) ($data['grund'] ?? '')));

        return $this->zurueck($request, $datum)->with('hinweis', sprintf(
            '%s ist gesperrt – an diesem Tag sind keine Reservierungen mehr möglich.',
            Carbon::parse($datum)->locale('de')->isoFormat('dddd, D. MMMM'),
        ));
    }

    public function unblock(Request $request): RedirectResponse
    {
        $data = $request->validate(['datum' => ['required', 'date']], [], ['datum' => 'Datum']);
        $datum = Carbon::parse($data['datum'])->toDateString();

        BlockedDates::unblock($datum);

        return $this->zurueck($request, $datum)->with('hinweis', sprintf(
            'Sperre für %s aufgehoben.',
            Carbon::parse($datum)->locale('de')->isoFormat('dddd, D. MMMM'),
        ));
    }

    private function zurueck(Request $request, string $datum): RedirectResponse
    {
        return redirect()->to(
            $request->getSchemeAndHttpHost().'/intern?'.http_build_query(['datum' => $datum]),
        );
    }

    /** Gerade angenommene Reservierung aus ?neu=... - nur vom gezeigten Tag. */
    private function angenommen(Request $request, Carbon $date): ?Reservation
    {
        if (! $id = (int) $request->query('neu')) {
            return null;
        }

        return Reservation::query()
            ->with('tables')
            ->whereDate('reserve_date', $date->toDateString())
            ->find($id);
    }

    private function pageData(Carbon $date, int $guests, ?DiningTable $room = null, ?Reservation $neu = null): array
    {
        return Tagesdaten::fuer($date, $guests, $room, $neu);
    }

    private function resolveDate(?string $raw): Carbon
    {
        try {
            $date = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        return $date->startOfDay();
    }

    private function location(): Location
    {
        return Tagesdaten::standort();
    }
}
