<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Erfassung;

use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Reservierung von der Konsole aus anlegen und wieder zuruecknehmen.
 *
 * Bewusst ueber save() und nicht saveQuietly(): an saved() haengt die
 * Tischvergabe der Erweiterung, an saving() das Auffuellen der NOT-NULL-Spalten.
 * Eine stille Speicherung ginge daran vorbei und erzeugte Datensaetze, die sich
 * anders verhalten als alles, was ueber /intern oder das Backend hereinkommt.
 */
class Anlegen
{
    /**
     * Steht im user_agent und ist die einzige Handhabe fuer --zurueck: nur was
     * dieses Kennzeichen traegt, darf wieder geloescht werden.
     */
    public const string MARKER = 'reservetweaks-cli';

    /**
     * Auf der Konsole gibt es keine Anfrage. Der Beobachter der
     * Reservierungs-Erweiterung fuellt ip_address und user_agent aber aus
     * request() - beide Spalten sind NOT NULL, ohne das Folgende bricht das
     * Einfuegen ab. Gleichzeitig setzt das den Marker genau dorthin, wo der
     * Beobachter ihn ohnehin hinschreibt, statt ihn hinterher zu ueberschreiben.
     */
    public static function konsoleVorbereiten(): void
    {
        request()->server->set('REMOTE_ADDR', '127.0.0.1');
        request()->headers->set('User-Agent', self::MARKER);
    }

    /**
     * @param array{
     *     location_id: int, reserve_date: string, reserve_time: string,
     *     guest_num: int, first_name?: string, last_name?: string,
     *     email?: string, telephone?: string, comment?: ?string,
     *     duration?: ?int, status_id: int, occasion_id?: ?int, table_ids: int[]
     * } $daten
     */
    public static function reservierung(array $daten, string $verlaufskommentar): Reservation
    {
        self::konsoleVorbereiten();

        return DB::transaction(function() use ($daten, $verlaufskommentar): Reservation {
            $r = new Reservation;
            $r->location_id = $daten['location_id'];
            $r->guest_num = $daten['guest_num'];
            $r->occasion_id = $daten['occasion_id'] ?? null;
            $r->first_name = $daten['first_name'] ?? '';
            $r->last_name = $daten['last_name'] ?? '';
            // Spalten sind NOT NULL. Leerer String statt einer erfundenen
            // Adresse - an eine solche wuerde spaeter jemand zu senden versuchen.
            $r->email = $daten['email'] ?? '';
            $r->telephone = $daten['telephone'] ?? '';
            $r->comment = ($daten['comment'] ?? '') !== '' ? $daten['comment'] : null;
            $r->reserve_date = $daten['reserve_date'];
            $r->reserve_time = $daten['reserve_time'].':00';
            // Leer lassen ist erlaubt: setDurationAttribute() setzt dann die
            // Aufenthaltsdauer des Standorts ein.
            $r->duration = $daten['duration'] ?? null;
            $r->notify = false;
            $r->status_id = $daten['status_id'];

            // Immer setzen, auch leer. Die Erweiterung liest genau daran ab, ob
            // die Tische von Hand bestimmt wurden, und haelt ihre eigene
            // Vergabe dann heraus - ein leeres Feld heisst also ausdruecklich
            // "ohne Tisch" und nicht "such dir einen aus".
            $r->tables = $daten['table_ids'];

            $r->save();

            // notify=false: der Gast bekommt keine Mail. Bei einer nachtraeglich
            // erfassten Reservierung waere sie verwirrend, den Tisch hat er
            // schon am Telefon bestaetigt bekommen.
            $r->addStatusHistory($daten['status_id'], [
                'notify' => false,
                'comment' => $verlaufskommentar,
            ]);

            return $r->refresh();
        });
    }

    /**
     * Zuruecknehmen. Fasst nur eigene Datensaetze an - wer die Nummer einer von
     * Hand im Backend angelegten Reservierung in eine Protokolldatei schreibt,
     * soll sie damit nicht loeschen koennen.
     */
    public static function zuruecknehmen(int $id): bool
    {
        $r = Reservation::find($id);

        if (!$r || $r->user_agent !== self::MARKER) {
            return false;
        }

        DB::transaction(function() use ($r, $id): void {
            DB::table('reservation_tables')->where('reservation_id', $id)->delete();
            DB::table('status_history')
                ->where('object_type', $r->getMorphClass())
                ->where('object_id', $id)
                ->delete();
            $r->deleteQuietly();
        });

        return true;
    }
}
