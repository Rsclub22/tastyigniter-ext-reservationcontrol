<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Controllers;

use Carbon\Carbon;
use Igniter\Api\Classes\ApiController;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wagnersnetz\ReservationControl\Annahme;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\Sperrvermerke;
use Wagnersnetz\ReservationControl\Tagesblatt;
use Wagnersnetz\ReservationControl\Tagesdaten;

/**
 * Die Telefonannahme als API - dieselben Daten, die die interne Weboberflaeche
 * unter /intern anzeigt, damit die Desktop-Fassung der App sie ersetzen kann.
 *
 * Zur Abgrenzung: /intern ist ohne Login erreichbar und dafuer auf das lokale
 * Netz begrenzt (Middleware InternalNetworkOnly, eigener Port 8002, nicht im
 * Reverse Proxy). Diese Endpunkte haengen am oeffentlichen API und sind dafuer
 * durch ein Token abgesichert - das Token tritt an die Stelle der Netzgrenze.
 * Mit der eigenen Ability "intern:*" darf ein Token fuer die Telefonannahme
 * nicht automatisch alles andere im API.
 *
 * Die Berechnungen stehen bewusst NICHT hier, sondern in Tagesdaten,
 * Sperrvermerke, Rooms, BlockedDates und TableAllocator - dieselben Klassen, die
 * die Weboberflaeche benutzt. Der Controller formt nur um.
 */
class InternApi extends ApiController
{
    protected string|array $requiredAbilities = ['intern:*'];

    /**
     * ApiController::checkAction() laesst nur durch, was hier steht - eine leere
     * Liste heisst 404 fuer jede Aktion, und zwar als leeres JSON, was beim
     * Suchen erst wie ein Serialisierungsfehler aussieht. Bei den REST-Ressourcen
     * fuellt der RestController das aus restConfig; eigene Aktionen muessen sich
     * selbst eintragen.
     */
    public array $allowedActions = [
        'tag' => [],
        'annehmen' => [],
        'tagesblatt' => [],
        'monat' => [],
        'offen' => [],
        'sperrtage' => [],
        'sperren' => [],
        'freigeben' => [],
    ];

    /** Alles, was die Annahme fuer einen Tag braucht. */
    public function tag(Request $request): JsonResponse
    {
        $datum = $this->datum($request->query('datum'));
        $gaeste = max(1, (int)($request->query('gaeste') ?? 2));
        $raum = Rooms::find($request->query('raum'));

        $daten = Tagesdaten::fuer($datum, $gaeste, $raum);

        return response()->json([
            'datum' => $datum->toDateString(),
            'gaeste' => $gaeste,
            'raum' => $raum ? $this->raum($raum) : null,

            // Gesperrter Tag: fuer den Gast liest sich das als "geschlossen".
            'gesperrt' => (bool)$daten['gesperrt'],
            'grund' => (string)$daten['grund'],
            'sperren' => $daten['sperren'],

            'trennzeit' => $daten['trennzeit'],
            'tische_gesamt' => (int)$daten['tischeGesamt'],
            'plaetze_gesamt' => (int)$daten['plaetzeGesamt'],

            // Schon fertige Listen aus Tagesdaten, Feld fuer Feld wie dort:
            // zeit, frei, gesamt, freie_plaetze, passt, groesster, raum,
            // ohne_tisch, pax_max, pax_belegt.
            'belegung' => array_values($daten['belegung']),

            'raeume' => $daten['raeume']->map(fn(DiningTable $r): array => $this->raum($r))->values(),

            // Sperrvermerke sind gewoehnliche Reservierungen mit mehr Gaesten als
            // das Haus Plaetze hat. Was darin als Uhrzeit oder Hoechstzahl gilt,
            // entscheidet die Freitext-Auswertung in Sperrvermerke - deshalb
            // kommen die Ergebnisse mit und werden nicht in der App nachgebaut.
            // ganztags: nimmt der Vermerk den Tag ein, oder sperrt er nur seine
            // eigene Zeit? Ein Maerchenabend um 17 Uhr laesst den Mittagstisch
            // offen, ein Weihnachtsvermerk ueber der Mittagszeit nicht.
            'vermerke' => $daten['vermerke']->map(fn(Reservation $v): array => [
                'id' => (int)$v->reservation_id,
                'zeit' => substr((string)$v->reserve_time, 0, 5),
                'gaeste' => (int)$v->guest_num,
                'kommentar' => (string)($v->comment ?? ''),
                'ganztags' => Sperrvermerke::ganztags($v, $datum),
            ])->values(),
            'ganztags' => $daten['ganztags']->isNotEmpty(),
            'vermerk_zeiten' => array_values($daten['vermerkZeiten']),
            'max_pax' => $daten['maxPax'],
            'pax_je_zeit' => $daten['paxJeZeit'],
            'hausgroesse' => Sperrvermerke::hausgroesse(),

            'reservierungen' => $daten['reservierungen']
                ->map(fn(Reservation $r): array => $this->alsListe($r))->values(),
        ]);
    }

    /** Die Sperrtage, an denen online nicht gebucht werden kann. */
    public function sperrtage(): JsonResponse
    {
        return response()->json([
            'alle' => BlockedDates::all(),
            'kommende' => BlockedDates::upcoming(),
        ]);
    }

    /**
     * Reservierung annehmen - dieselben gelockerten Pflichtfelder wie am Telefon:
     * Nachname und Telefonnummer genuegen, Vorname und E-Mail entfallen.
     *
     * Die Hoechstzahlpruefung laeuft hier serverseitig und nicht in der App:
     * sonst umgeht sie ein zweites Geraet, das die Grenze nicht kennt oder eine
     * veraltete Belegung anzeigt.
     */
    public function annehmen(Request $request): JsonResponse
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
            // Ausdruecklich ohne Tisch und ohne Raum - der Notausgang, wenn die
            // Automatik nicht passt. Gewinnt gegen eine Raumauswahl.
            'ohne_tisch' => ['nullable', 'boolean'],
        ]);

        $datum = Carbon::parse($data['datum']);

        if ($meldung = Annahme::hoechstzahlVerletzt($datum, $data['zeit'], (int)$data['gaeste'])) {
            // 422 wie bei einem Validierungsfehler, damit die App die Meldung an
            // das Feld haengen kann statt einen Serverfehler zu melden.
            return response()->json([
                'message' => $meldung,
                'errors' => ['gaeste' => [$meldung]],
            ], 422);
        }

        $reservation = Annahme::anlegen($data);

        return response()->json([
            'reservierung' => $this->alsListe($reservation->refresh()),
        ], 201);
    }

    /**
     * Das Tagesblatt - dieselbe Zusammenstellung, die /intern/druck aufs Papier
     * bringt, nur als Daten. Gesetzt wird in der App.
     *
     * Entweder ein einzelner Tag (datum) oder ein Zeitraum (von/bis). Beim
     * Zeitraum bleiben leere Tage draussen: Montag und Dienstag ist zu, ein
     * Monat brauchte sonst ein Dutzend Blaetter mit nichts darauf. Ein Tag mit
     * Sperrvermerk kommt trotzdem mit - der Vermerk ist ja gerade die Ansage.
     */
    public function tagesblatt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datum' => ['nullable', 'date'],
            'von' => ['nullable', 'date'],
            'bis' => ['nullable', 'date'],
            'trennzeit' => ['nullable', 'string', 'max:8'],
        ]);

        $trennzeit = Tagesdaten::trennzeit($data['trennzeit'] ?? null);
        $location = Tagesdaten::standort();

        $von = $this->datum($data['von'] ?? $data['datum'] ?? null);
        $bis = $this->datum($data['bis'] ?? $data['datum'] ?? null);

        // Verdrehte Eingabe nicht abweisen, sondern verstehen.
        if ($bis->lt($von)) {
            [$von, $bis] = [$bis, $von];
        }

        if ($von->diffInDays($bis) >= Tagesblatt::MAX_TAGE) {
            $bis = $von->copy()->addDays(Tagesblatt::MAX_TAGE - 1);
        }

        $zeitraum = $von->ne($bis);
        $tage = [];

        for ($tag = $von->copy(); $tag->lte($bis); $tag->addDay()) {
            $blatt = Tagesblatt::fuerTag($location, $tag->copy(), $trennzeit);

            if ($zeitraum && $blatt['blaetter'] === [] && $blatt['sperrvermerke']->isEmpty()) {
                continue;
            }

            $tage[] = [
                'datum' => $tag->toDateString(),
                'gesperrt' => (bool)$blatt['gesperrt'],
                'grund' => (string)$blatt['grund'],
                'max_pax' => $blatt['maxPax'],
                'pax_je_zeit' => $blatt['paxJeZeit'],
                'sperrvermerke' => $blatt['sperrvermerke']->map(fn(Reservation $v): array => [
                    'id' => (int)$v->reservation_id,
                    'zeit' => substr((string)$v->reserve_time, 0, 5),
                    'kommentar' => (string)($v->comment ?? ''),
                ])->values(),
                'blaetter' => array_map(fn(array $b): array => [
                    'titel' => (string)$b['titel'],
                    'gaeste' => (int)$b['reservierungen']->sum('guest_num'),
                    'reservierungen' => $b['reservierungen']
                        ->map(fn(Reservation $r): array => $this->alsListe($r))->values(),
                ], $blatt['blaetter']),
            ];
        }

        return response()->json([
            'von' => $von->toDateString(),
            'bis' => $bis->toDateString(),
            'zeitraum' => $zeitraum,
            'trennzeit' => $trennzeit,
            'tage' => $tage,
        ]);
    }

    /**
     * Monatsuebersicht: je Tag, wie voll es ist.
     *
     * Ein eigener Endpunkt und nicht dreissig Aufrufe von tag - der Kalender
     * braucht nur Summen, nicht die Zeitfenster. Geliefert werden alle Tage des
     * Monats, auch die leeren, damit das Raster ohne Luecken gezeichnet werden
     * kann.
     *
     * Sperrvermerke zaehlen nicht als Gaeste - sie sind ein Riegel, keine
     * Gesellschaft -, werden aber je Tag gemeldet, weil sie fuer die Annahme die
     * wichtigste Angabe sind.
     */
    public function monat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jahr' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'monat' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $heute = Carbon::today();
        $von = Carbon::create(
            (int)($data['jahr'] ?? $heute->year),
            (int)($data['monat'] ?? $heute->month),
            1,
        )->startOfDay();
        $bis = $von->copy()->endOfMonth();

        $location = Tagesdaten::standort();
        $hausgroesse = Sperrvermerke::hausgroesse();

        $alle = Reservation::query()
            ->where('location_id', $location->getKey())
            ->whereBetween('reserve_date', [$von->toDateString(), $bis->toDateString()])
            ->where('status_id', '!=', (int)setting('canceled_reservation_status'))
            ->get()
            ->groupBy(fn(Reservation $r): string => Carbon::parse($r->reserve_date)->toDateString());

        $sperren = BlockedDates::all();
        $tage = [];
        $hoechstwert = 0;

        for ($tag = $von->copy(); $tag->lte($bis); $tag->addDay()) {
            $schluessel = $tag->toDateString();
            $desTages = $alle->get($schluessel) ?? collect();

            $vermerke = $desTages->filter(fn(Reservation $r): bool => Sperrvermerke::istVermerk($r, $hausgroesse));
            $gaeste = $desTages->reject(fn(Reservation $r): bool => Sperrvermerke::istVermerk($r, $hausgroesse));

            $summe = (int)$gaeste->sum('guest_num');
            $hoechstwert = max($hoechstwert, $summe);

            $tage[] = [
                'datum' => $schluessel,
                'reservierungen' => $gaeste->count(),
                'gaeste' => $summe,
                'gesperrt' => array_key_exists($schluessel, $sperren),
                'grund' => (string)($sperren[$schluessel] ?? ''),
                'vermerk' => $vermerke->isNotEmpty(),
                'vermerk_text' => (string)($vermerke->first()?->comment ?? ''),
                'max_pax' => Sperrvermerke::maxPax($vermerke),
            ];
        }

        return response()->json([
            'jahr' => $von->year,
            'monat' => $von->month,
            'von' => $von->toDateString(),
            'bis' => $bis->toDateString(),
            // Groesste Gaestezahl des Monats. Die App faerbt daran ein, statt eine
            // Kapazitaet zu erfinden: die Raeume fassen ein Vielfaches der Tische,
            // eine feste Obergrenze waere an den meisten Tagen irrefuehrend.
            'hoechstwert' => $hoechstwert,
            'tage' => $tage,
        ]);
    }

    /**
     * Unbestaetigte Reservierungen - die, die ueber das oeffentliche Formular kamen
     * und die noch jemand bestaetigen muss.
     *
     * Gedacht zum regelmaessigen Nachfragen, deshalb bewusst schlank. Zwei Zahlen
     * mit verschiedenem Zweck: `anzahl` sind die seit `seit` hinzugekommenen - dafuer
     * wird gemeldet -, `offen_gesamt` sind alle unbestaetigten - dafuer steht die
     * Markierung in der Liste.
     *
     * Die Abgrenzung laeuft ueber den Status und nicht ueber den User-Agent: eine
     * Annahme ueber /intern traegt den Browser der Person am Tresen und saehe damit
     * aus wie eine Online-Buchung. Was das Haus selbst eintraegt, ist sofort
     * bestaetigt.
     *
     * `seit` ist eine Reservierungsnummer, keine Uhrzeit. Das Model setzt
     * $dateFormat = 'Y-m-d', wodurch created_at auf das Datum gekuerzt wird - nach
     * der Zeit liesse sich "neu seit zuletzt" gar nicht beantworten.
     */
    public function offen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seit' => ['nullable', 'integer', 'min:0'],
        ]);

        $seit = (int)($data['seit'] ?? 0);
        $offenerStatus = (int)setting('default_reservation_status');
        $location = Tagesdaten::standort();

        $offen = Reservation::query()
            ->with('tables')
            ->where('location_id', $location->getKey())
            ->where('status_id', $offenerStatus)
            ->orderBy('reservation_id')
            ->get();

        $neue = $offen->filter(fn(Reservation $r): bool => (int)$r->reservation_id > $seit);

        return response()->json([
            'seit' => $seit,
            // Die hoechste vergebene Nummer, nicht die der neuesten unbestaetigten:
            // sonst ruecke der Merker nie vor, wenn zwischendurch nur bestaetigte
            // Reservierungen dazukommen, und dieselbe Meldung kaeme immer wieder.
            'hoechste_id' => (int)Reservation::query()
                ->where('location_id', $location->getKey())
                ->max('reservation_id'),
            'anzahl' => $neue->count(),
            'offen_gesamt' => $offen->count(),
            // Damit die App Sperrvermerke auch in Listen erkennt, die ueber das
            // gewoehnliche /api/reservations kommen: dort fehlt das Kennzeichen,
            // und die Regel ist allein "mehr Gaeste als Plaetze im Haus".
            'hausgroesse' => Sperrvermerke::hausgroesse(),
            'reservierungen' => $neue->values()
                ->map(fn(Reservation $r): array => $this->alsListe($r))->values(),
        ]);
    }

    /** Einen Tag gegen die Online-Buchung sperren. */
    public function sperren(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'grund' => ['nullable', 'string', 'max:190'],
        ]);

        $datum = Carbon::parse($data['datum'])->toDateString();
        BlockedDates::block($datum, (string)($data['grund'] ?? ''));

        return response()->json(['gesperrt' => $datum, 'alle' => BlockedDates::all()]);
    }

    /** Sperre wieder aufheben. */
    public function freigeben(Request $request): JsonResponse
    {
        $data = $request->validate(['datum' => ['required', 'date']]);

        $datum = Carbon::parse($data['datum'])->toDateString();
        BlockedDates::unblock($datum);

        return response()->json(['freigegeben' => $datum, 'alle' => BlockedDates::all()]);
    }

    private function datum(mixed $roh): Carbon
    {
        try {
            return $roh ? Carbon::parse((string)$roh)->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    private function raum(DiningTable $raum): array
    {
        return [
            'id' => (int)$raum->getKey(),
            'name' => (string)$raum->name,
            'min_plaetze' => (int)$raum->min_capacity,
            'max_plaetze' => (int)$raum->max_capacity + (int)$raum->extra_capacity,
        ];
    }

    private function alsListe(Reservation $r): array
    {
        return [
            'id' => (int)$r->reservation_id,
            // Im Tages-Zusammenhang ueberfluessig, fuer Meldungen aber noetig:
            // dort steht die Reservierung ohne ihren Tag da.
            'datum' => Carbon::parse($r->reserve_date)->toDateString(),
            'zeit' => substr((string)$r->reserve_time, 0, 5),
            'dauer' => (int)$r->duration,
            'gaeste' => (int)$r->guest_num,
            'name' => trim($r->first_name.' '.$r->last_name),
            'telefon' => (string)($r->telephone ?? ''),
            'kommentar' => (string)($r->comment ?? ''),
            'status_id' => (int)$r->status_id,
            'status' => (string)($r->status_name ?? ''),
            'tische' => $r->tables->map(fn(DiningTable $t): array => [
                'id' => (int)$t->getKey(),
                'name' => (string)$t->name,
            ])->values(),
            // Kennzeichnet die Pseudo-Reservierungen, die einen Tag verriegeln.
            'ist_vermerk' => Sperrvermerke::istVermerk($r),
        ];
    }
}
