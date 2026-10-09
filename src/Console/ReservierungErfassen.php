<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Console;

use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Wagnersnetz\ReservationControl\Erfassung\Anlegen;
use Wagnersnetz\ReservationControl\Erfassung\Eingabe;
use Wagnersnetz\ReservationControl\Erfassung\Tischwahl;

/**
 * Reservierungen am Rechner erfassen, wenn /intern nicht in Frage kommt -
 * Nacherfassung aus dem Buch, mehrere Tische je Reservierung, Felder auch
 * unvollstaendig. Braucht ein Terminal.
 */
class ReservierungErfassen extends Command
{
    protected $signature = 'reservierung:erfassen {--location=1 : Standort-ID}';

    protected $description = 'Reservierungen im Dialog erfassen und Tische zuweisen';

    private int $locationId;

    private Collection $tische;

    public function handle(): int
    {
        $this->locationId = (int) $this->option('location');

        if (! Location::find($this->locationId)) {
            $this->error(sprintf('Standort %d gibt es nicht.', $this->locationId));

            return self::FAILURE;
        }

        $this->tische = Tischwahl::alle();

        $this->newLine();
        $this->line('  <options=bold>Reservierungen erfassen</>');

        while (true) {
            $this->newLine();

            $wahl = $this->choice('  Was möchtest du tun?', [
                'neu' => 'Neue Reservierung erfassen',
                'tische' => 'Tische einer bestehenden Reservierung ändern',
                'liste' => 'Kommende Reservierungen anzeigen',
                'ende' => 'Beenden',
            ], 'neu');

            match ($wahl) {
                'neu' => $this->neu(),
                'tische' => $this->tischeAendern(),
                'liste' => $this->liste(),
                'ende' => null,
            };

            if ($wahl === 'ende') {
                return self::SUCCESS;
            }
        }
    }

    // ------------------------------------------------------------ Neuanlage

    private function neu(): void
    {
        $daten = [
            'location_id' => $this->locationId,
            'reserve_date' => $this->fragDatum(date('d.m.Y')),
            'reserve_time' => $this->fragZeit(),
            'guest_num' => $this->fragPersonen(),
        ];

        [$daten['first_name'], $daten['last_name']] = $this->fragName();
        $daten['telephone'] = (string) $this->ask('  Telefon', '');
        $daten['email'] = (string) $this->ask('  E-Mail', '');
        $daten['duration'] = Eingabe::zahl((string) $this->ask('  Dauer in Minuten (leer = Standard)', ''));
        $daten['table_ids'] = $this->fragTische($daten);
        $daten['status_id'] = $this->fragStatus();
        $daten['comment'] = (string) $this->ask('  Kommentar', '');
        $daten['occasion_id'] = null;

        while (true) {
            $this->zusammenfassung($daten);

            $wahl = $this->choice('  ', [
                'speichern' => 'Speichern',
                'aendern' => 'Ein Feld ändern',
                'verwerfen' => 'Verwerfen',
            ], 'speichern');

            if ($wahl === 'verwerfen') {
                $this->warn('  Verworfen, nichts gespeichert.');

                return;
            }

            if ($wahl === 'aendern') {
                $daten = $this->feldAendern($daten);

                continue;
            }

            $r = Anlegen::reservierung($daten, 'Im Dialog nachträglich erfasst');

            $this->info(sprintf(
                '  Gespeichert als #%d – %s %s, %s, %d Personen, %s',
                $r->reservation_id,
                Eingabe::datumLang($daten['reserve_date']),
                $daten['reserve_time'],
                trim($daten['first_name'].' '.$daten['last_name']),
                $daten['guest_num'],
                Tischwahl::namen($daten['table_ids'], $this->tische),
            ));

            return;
        }
    }

    private function feldAendern(array $daten): array
    {
        $felder = [
            'datum' => 'Datum', 'zeit' => 'Uhrzeit', 'personen' => 'Personen',
            'name' => 'Name', 'telefon' => 'Telefon', 'email' => 'E-Mail',
            'tische' => 'Tische', 'dauer' => 'Dauer', 'status' => 'Status',
            'kommentar' => 'Kommentar',
        ];

        switch ($this->choice('  Welches Feld?', $felder, 'tische')) {
            case 'Datum':
                $daten['reserve_date'] = $this->fragDatum(date('d.m.Y', strtotime($daten['reserve_date'])));
                break;
            case 'Uhrzeit':
                $daten['reserve_time'] = $this->fragZeit($daten['reserve_time']);
                break;
            case 'Personen':
                $daten['guest_num'] = $this->fragPersonen((string) $daten['guest_num']);
                break;
            case 'Name':
                [$daten['first_name'], $daten['last_name']] = $this->fragName($daten);
                break;
            case 'Telefon':
                $daten['telephone'] = (string) $this->ask('  Telefon', $daten['telephone']);
                break;
            case 'E-Mail':
                $daten['email'] = (string) $this->ask('  E-Mail', $daten['email']);
                break;
            case 'Tische':
                $daten['table_ids'] = $this->fragTische($daten, $daten['table_ids']);
                break;
            case 'Dauer':
                $daten['duration'] = Eingabe::zahl((string) $this->ask('  Dauer in Minuten (leer = Standard)', (string) ($daten['duration'] ?? '')));
                break;
            case 'Status':
                $daten['status_id'] = $this->fragStatus();
                break;
            case 'Kommentar':
                $daten['comment'] = (string) $this->ask('  Kommentar', (string) ($daten['comment'] ?? ''));
                break;
        }

        return $daten;
    }

    // ------------------------------------------------------------ Tische

    /** @return int[] */
    private function fragTische(array $daten, array $vorauswahl = []): array
    {
        $dauer = $daten['duration'] ?: 60;
        $belegt = Tischwahl::belegung(
            $this->locationId,
            $daten['reserve_date'],
            $daten['reserve_time'],
            $dauer,
            $daten['reservation_id'] ?? null,
        );

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>Tische am %s, %s – %s Uhr</>',
            Eingabe::datumLang($daten['reserve_date']),
            $daten['reserve_time'],
            date('H:i', strtotime($daten['reserve_date'].' '.$daten['reserve_time'].' +'.$dauer.' minutes')),
        ));

        $zeilen = [];
        $nummern = [];
        $nr = 0;

        foreach ($this->tische as $id => $t) {
            $nummern[++$nr] = (int) $id;

            $zeilen[] = [
                (in_array((int) $id, $vorauswahl, true) ? '»' : ' ').$nr,
                $t->name,
                $t->min_capacity.'–'.($t->max_capacity + $t->extra_capacity),
                match (true) {
                    isset($belegt[(int) $id]) => sprintf(
                        'belegt: %s %s (%d P.)',
                        $belegt[(int) $id]['name'] ?: '?',
                        $belegt[(int) $id]['zeit'],
                        $belegt[(int) $id]['gaeste'],
                    ),
                    ! $t->is_enabled => 'deaktiviert',
                    default => 'frei',
                },
            ];
        }

        $this->table(['Nr', 'Tisch', 'Plätze', 'Status'], $zeilen);

        $vorgabe = $vorauswahl === []
            ? ''
            : implode(',', array_keys(array_intersect($nummern, $vorauswahl)));

        while (true) {
            $eingabe = (string) $this->ask('  Tische (Nummern oder Namen, mehrere mit Komma; "-" = ohne Tisch)', $vorgabe);

            if ($eingabe === '' || $eingabe === '-'
                || in_array(Eingabe::normalisiert($eingabe), ['ohne', 'kein', 'keine', 'keiner', 'nein'], true)) {
                $this->warn('  Keine Tischzuordnung – die Reservierung wird ohne Tisch gespeichert.');

                return [];
            }

            // Nummern aus der angezeigten Liste haben Vorrang; alles andere
            // laeuft ueber die Namensaufloesung.
            $ids = [];
            $rest = [];

            foreach (preg_split('/\s*[,;+]\s*|\s+/', trim($eingabe)) ?: [] as $teil) {
                if (ctype_digit($teil) && isset($nummern[(int) $teil])) {
                    $ids[] = $nummern[(int) $teil];
                } elseif ($teil !== '') {
                    $rest[] = $teil;
                }
            }

            if ($rest !== []) {
                [$weitere, $unbekannt] = Tischwahl::ausText(implode(',', $rest), $this->tische);

                if ($unbekannt !== []) {
                    $this->error('  Unbekannt: '.implode(', ', $unbekannt));

                    continue;
                }

                $ids = array_merge($ids, $weitere);
            }

            if (($ids = array_values(array_unique($ids))) === []) {
                $this->error('  Keine gültige Auswahl.');

                continue;
            }

            $kapazitaet = Tischwahl::kapazitaet($ids, $this->tische);

            $this->newLine();
            $this->info(sprintf(
                '  %s  =  %d Plätze für %d Gäste',
                Tischwahl::namen($ids, $this->tische),
                $kapazitaet,
                $daten['guest_num'],
            ));

            // Alles Folgende hindert nicht, es sagt nur Bescheid. Genau dafuer
            // gibt es diesen Weg: das Backend laesst solche Faelle nicht zu.
            if ($kapazitaet < $daten['guest_num']) {
                $this->warn(sprintf('  Kapazität reicht rechnerisch nicht (%d < %d).', $kapazitaet, $daten['guest_num']));
            }

            foreach ($ids as $id) {
                if (isset($belegt[$id])) {
                    $this->warn(sprintf(
                        '  %s ist zu der Zeit schon an %s vergeben (#%d).',
                        $this->tische->get($id)->name,
                        $belegt[$id]['name'] ?: '?',
                        $belegt[$id]['reservierung'],
                    ));
                }

                if (! $this->tische->get($id)->is_enabled) {
                    $this->warn(sprintf('  %s ist im Tischplan deaktiviert.', $this->tische->get($id)->name));
                }
            }

            if ($this->confirm('  Übernehmen?', true)) {
                return $ids;
            }

            $vorgabe = '';
        }
    }

    private function tischeAendern(): void
    {
        if (! $zuordnung = $this->liste()) {
            return;
        }

        $eingabe = trim((string) $this->ask('  Welche Reservierung? (Nr oder #ID)', ''));
        if ($eingabe === '') {
            return;
        }

        $id = str_starts_with($eingabe, '#')
            ? (int) substr($eingabe, 1)
            : ($zuordnung[(int) $eingabe] ?? (int) $eingabe);

        if (! $r = Reservation::with('tables')->find($id)) {
            $this->error(sprintf('  Reservierung #%d nicht gefunden.', $id));

            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>#%d  %s %s  %s  %d Personen</>',
            $r->reservation_id,
            Eingabe::datumLang($r->reserve_date->format('Y-m-d')),
            substr((string) $r->reserve_time, 0, 5),
            trim($r->first_name.' '.$r->last_name),
            $r->guest_num,
        ));
        $this->line('  aktuell: '.Tischwahl::namen(
            $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all(),
            $this->tische,
        ));

        $neu = $this->fragTische([
            'reserve_date' => $r->reserve_date->format('Y-m-d'),
            'reserve_time' => substr((string) $r->reserve_time, 0, 5),
            'duration' => (int) $r->duration,
            'guest_num' => (int) $r->guest_num,
            'reservation_id' => (int) $r->reservation_id,
        ], $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all());

        $r->addReservationTables($neu);

        $this->info(sprintf('  #%d: Tische jetzt %s', $r->reservation_id, Tischwahl::namen($neu, $this->tische)));
    }

    // ------------------------------------------------------------ Liste

    /** @return array<int, int> Listennummer => Reservierungs-ID */
    private function liste(): array
    {
        $reservierungen = Reservation::query()
            ->with('tables')
            ->where('location_id', $this->locationId)
            ->where('reserve_date', '>=', date('Y-m-d', strtotime('-1 day')))
            ->orderBy('reserve_date')->orderBy('reserve_time')
            ->limit(50)->get();

        if ($reservierungen->isEmpty()) {
            $this->warn('  Keine kommenden Reservierungen.');

            return [];
        }

        $namen = array_flip(Eingabe::statusListe());
        $zuordnung = [];
        $zeilen = [];

        foreach ($reservierungen as $i => $r) {
            $zuordnung[$i + 1] = (int) $r->reservation_id;

            $zeilen[] = [
                $i + 1,
                Eingabe::datumLang($r->reserve_date->format('Y-m-d')),
                substr((string) $r->reserve_time, 0, 5),
                $r->guest_num,
                trim($r->first_name.' '.$r->last_name),
                // Eine Gesellschaft kann neun Tische haben - ungekuerzt zieht
                // eine einzige Zeile die ganze Tabelle in die Breite.
                mb_strimwidth(
                    Tischwahl::namen($r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all(), $this->tische),
                    0, 38, '…',
                ),
                $namen[(int) $r->status_id] ?? '',
            ];
        }

        $this->newLine();
        $this->table(['Nr', 'Datum', 'Zeit', 'Pers', 'Name', 'Tische', 'Status'], $zeilen);

        return $zuordnung;
    }

    // ------------------------------------------------------------ Abfragen

    private function fragDatum(string $vorgabe = ''): string
    {
        while (true) {
            $eingabe = (string) $this->ask('  Datum (TT.MM.JJJJ, "heute", "morgen")', $vorgabe);

            if ($datum = Eingabe::datum($eingabe)) {
                $this->line('       <fg=gray>'.Eingabe::datumLang($datum).'</>');

                return $datum;
            }

            $this->error(sprintf('  Datum nicht verstanden: "%s"', $eingabe));
        }
    }

    private function fragZeit(string $vorgabe = ''): string
    {
        while (true) {
            $eingabe = (string) $this->ask('  Uhrzeit (z. B. 18:30)', $vorgabe);

            if ($zeit = Eingabe::zeit($eingabe)) {
                return $zeit;
            }

            $this->error(sprintf('  Uhrzeit nicht verstanden: "%s"', $eingabe));
        }
    }

    private function fragPersonen(string $vorgabe = ''): int
    {
        while (true) {
            $zahl = Eingabe::zahl((string) $this->ask('  Personen', $vorgabe));

            if ($zahl !== null && $zahl > 0) {
                return $zahl;
            }

            $this->error('  Bitte eine Zahl größer 0.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function fragName(array $daten = []): array
    {
        $vorgabe = trim(($daten['first_name'] ?? '').' '.($daten['last_name'] ?? ''));

        while (true) {
            [$vor, $nach] = Eingabe::name((string) $this->ask('  Name ("Hans Müller" oder "Müller, Hans")', $vorgabe));

            if ($vor !== '' || $nach !== '') {
                return [$vor, $nach];
            }

            $this->error('  Bitte einen Namen angeben.');
        }
    }

    private function fragStatus(): int
    {
        $auswahl = array_keys(Eingabe::statusListe());

        return Eingabe::status($this->choice('  Status', $auswahl, 'bestaetigt'));
    }

    private function zusammenfassung(array $daten): void
    {
        $namen = array_flip(Eingabe::statusListe());

        $this->newLine();
        $this->table(['Feld', 'Wert'], [
            ['Datum', Eingabe::datumLang($daten['reserve_date'])],
            ['Uhrzeit', $daten['reserve_time'].' Uhr'],
            ['Personen', $daten['guest_num']],
            ['Name', trim($daten['first_name'].' '.$daten['last_name'])],
            ['Telefon', $daten['telephone'] !== '' ? $daten['telephone'] : '–'],
            ['E-Mail', $daten['email'] !== '' ? $daten['email'] : '–'],
            ['Tische', Tischwahl::namen($daten['table_ids'], $this->tische)],
            ['Dauer', $daten['duration'] ? $daten['duration'].' Min.' : 'Standard des Standorts'],
            ['Status', $namen[$daten['status_id']] ?? $daten['status_id']],
            ['Kommentar', ($daten['comment'] ?? '') !== '' ? $daten['comment'] : '–'],
        ]);
    }
}
