<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Console;

use Igniter\Reservation\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;
use Wagnersnetz\ReservationControl\Erfassung\Anlegen;
use Wagnersnetz\ReservationControl\Erfassung\Eingabe;
use Wagnersnetz\ReservationControl\Erfassung\Tischwahl;

/**
 * Reservierungen aus einer CSV uebernehmen - fuer die Nacherfassung ganzer
 * Listen, bei denen Felder fehlen duerfen.
 */
class ReservierungImport extends Command
{
    protected $signature = 'reservierung:import
        {datei? : CSV-Datei}
        {--probe : Nur pruefen und anzeigen, nichts schreiben}
        {--doppelte : Auch anlegen, wenn es die Reservierung schon gibt}
        {--location=1 : Standort-ID}
        {--status=bestaetigt : Status für Zeilen ohne Status-Spalte}
        {--zurueck= : Die in dieser Protokolldatei vermerkten Reservierungen wieder löschen}
        {--vorlage : Eine Beispiel-CSV ausgeben}';

    protected $description = 'Reservierungen aus einer CSV-Datei übernehmen';

    /** Spaltenueberschriften, wie sie in fremden Listen vorkommen. */
    private const array SPALTEN = [
        'datum' => ['datum', 'date', 'tag'],
        'zeit' => ['zeit', 'uhrzeit', 'time', 'von'],
        'personen' => ['personen', 'pers', 'gaeste', 'anzahl', 'guests', 'pax', 'p'],
        'name' => ['name', 'gast', 'kunde'],
        'vorname' => ['vorname', 'firstname'],
        'nachname' => ['nachname', 'lastname', 'familienname'],
        'telefon' => ['telefon', 'tel', 'phone', 'handy', 'nummer', 'rufnummer'],
        'email' => ['email', 'mail', 'emailadresse'],
        'tisch' => ['tisch', 'tische', 'table', 'raum', 'bereich'],
        'dauer' => ['dauer', 'duration', 'minuten'],
        'status' => ['status', 'zustand'],
        'kommentar' => ['kommentar', 'notiz', 'bemerkung', 'anmerkung', 'comment', 'hinweis'],
        'anlass' => ['anlass', 'occasion', 'grund'],
    ];

    public function handle(): int
    {
        if ($this->option('vorlage')) {
            $this->line($this->vorlage());

            return self::SUCCESS;
        }

        if ($protokoll = $this->option('zurueck')) {
            return $this->zuruecknehmen((string) $protokoll);
        }

        $datei = (string) $this->argument('datei');

        if ($datei === '' || ! is_readable($datei)) {
            $this->error('Datei fehlt oder ist nicht lesbar. Vorlage: --vorlage');

            return self::INVALID;
        }

        $tische = Tischwahl::alle();
        $zeilen = $this->lesen($datei);

        if ($zeilen === []) {
            return self::INVALID;
        }

        $vorbereitet = array_map(fn (array $z): array => $this->aufbereiten($z, $tische), $zeilen);

        return $this->ausfuehren($vorbereitet, $tische);
    }

    // ------------------------------------------------------------ Lesen

    /** @return list<array{zeile: int, werte: array<string, string>}> */
    private function lesen(string $datei): array
    {
        $inhalt = (string) file_get_contents($datei);

        // Byte-Order-Mark und Windows-Kodierung: eine aus Excel exportierte
        // Liste bringt beides mit, und ohne das hier stehen dann "MÃ¼ller"
        // in der Datenbank.
        $inhalt = preg_replace('/^\xEF\xBB\xBF/', '', $inhalt) ?? $inhalt;

        if (! mb_check_encoding($inhalt, 'UTF-8')) {
            $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
        }

        $erste = (string) strtok($inhalt, "\n");
        $trenner = substr_count($erste, ';') >= substr_count($erste, ',') ? ';' : ',';

        if (substr_count($erste, "\t") > substr_count($erste, $trenner)) {
            $trenner = "\t";
        }

        $zeiger = tmpfile();
        fwrite($zeiger, $inhalt);
        rewind($zeiger);

        $rohe = [];

        while (($satz = fgetcsv($zeiger, 0, $trenner, '"', '\\')) !== false) {
            if ($satz === [null] || (count($satz) === 1 && trim((string) $satz[0]) === '')) {
                continue;
            }

            $rohe[] = $satz;
        }

        fclose($zeiger);

        if ($rohe === []) {
            $this->error('Die Datei enthält keine Zeilen.');

            return [];
        }

        $kopf = array_shift($rohe);
        $zuordnung = [];

        foreach ($kopf as $i => $ueberschrift) {
            $normal = Eingabe::normalisiert((string) $ueberschrift);

            foreach (self::SPALTEN as $feld => $namen) {
                if (! isset($zuordnung[$feld]) && in_array($normal, array_map([Eingabe::class, 'normalisiert'], $namen), true)) {
                    $zuordnung[$feld] = $i;
                }
            }
        }

        foreach (['datum', 'zeit', 'personen'] as $pflicht) {
            if (! isset($zuordnung[$pflicht])) {
                $this->error(sprintf('Pflichtspalte "%s" fehlt. Gefunden: %s', $pflicht, implode(', ', $kopf)));

                return [];
            }
        }

        if (! isset($zuordnung['name']) && ! isset($zuordnung['nachname'])) {
            $this->error('Es braucht eine Spalte "Name" oder "Nachname".');

            return [];
        }

        $ergebnis = [];

        foreach ($rohe as $nr => $satz) {
            $werte = [];

            foreach ($zuordnung as $feld => $i) {
                $werte[$feld] = trim((string) ($satz[$i] ?? ''));
            }

            // +2: eine Zeile Kopf, und Zaehlung ab 1 wie im Tabellenprogramm.
            $ergebnis[] = ['zeile' => $nr + 2, 'werte' => $werte];
        }

        return $ergebnis;
    }

    // ------------------------------------------------------------ Pruefen

    private function aufbereiten(array $zeile, Collection $tische): array
    {
        ['zeile' => $nr, 'werte' => $w] = $zeile;

        $fehler = [];
        $hinweise = [];

        if (! $datum = Eingabe::datum($w['datum'] ?? '')) {
            $fehler[] = sprintf('Datum unlesbar: "%s"', $w['datum'] ?? '');
        }

        if (! $zeit = Eingabe::zeit($w['zeit'] ?? '')) {
            $fehler[] = sprintf('Uhrzeit unlesbar: "%s"', $w['zeit'] ?? '');
        }

        $personen = Eingabe::zahl($w['personen'] ?? '');
        if ($personen === null || $personen < 1) {
            $fehler[] = sprintf('Personenzahl unlesbar: "%s"', $w['personen'] ?? '');
        }

        [$vorname, $nachname] = Eingabe::name($w['name'] ?? '', $w['vorname'] ?? '', $w['nachname'] ?? '');
        if ($vorname === '' && $nachname === '') {
            $fehler[] = 'Kein Name angegeben';
        }

        $email = $w['email'] ?? '';
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hinweise[] = sprintf('E-Mail sieht ungültig aus: "%s" – wird trotzdem übernommen', $email);
        }

        $statusRoh = ($w['status'] ?? '') !== '' ? $w['status'] : (string) $this->option('status');
        $status = Eingabe::status($statusRoh);
        if ($status === null) {
            $fehler[] = sprintf('Status unbekannt: "%s" (möglich: %s)', $statusRoh, implode(', ', array_keys(Eingabe::statusListe())));
        }

        $tischIds = [];
        $tischRoh = $w['tisch'] ?? '';

        if ($tischRoh !== '' && $tischRoh !== '-'
            && ! in_array(Eingabe::normalisiert($tischRoh), ['ohne', 'kein', 'keine', 'keiner', 'offen'], true)) {
            [$tischIds, $unbekannt] = Tischwahl::ausText($tischRoh, $tische);

            foreach ($unbekannt as $u) {
                $fehler[] = sprintf('Tisch "%s" nicht gefunden', $u);
            }
        }

        if ($tischIds !== [] && $personen !== null) {
            $kapazitaet = Tischwahl::kapazitaet($tischIds, $tische);

            if ($kapazitaet > 0 && $personen > $kapazitaet) {
                $hinweise[] = sprintf('Mehr Gäste (%d) als Plätze (%d) – wird trotzdem angelegt', $personen, $kapazitaet);
            }
        }

        foreach ($tischIds as $id) {
            if (! $tische->get($id)?->is_enabled) {
                $hinweise[] = sprintf('Tisch "%s" ist deaktiviert – Zuordnung wird trotzdem gesetzt', $tische->get($id)->name ?? $id);
            }
        }

        // Ein unbekannter Anlass ist kein Grund, die Zeile liegen zu lassen -
        // er wandert in den Kommentar, damit die Angabe nicht verloren geht.
        $anlassRoh = $w['anlass'] ?? '';
        $anlass = $anlassRoh !== '' ? Eingabe::anlass($anlassRoh) : null;
        $kommentar = $w['kommentar'] ?? '';

        if ($anlassRoh !== '' && $anlass === null) {
            $hinweise[] = sprintf('Anlass "%s" unbekannt – steht jetzt im Kommentar', $anlassRoh);
            $kommentar = trim($kommentar === '' ? 'Anlass: '.$anlassRoh : $kommentar.' | Anlass: '.$anlassRoh);
        }

        return [
            'zeile' => $nr,
            'location_id' => (int) $this->option('location'),
            'reserve_date' => $datum,
            'reserve_time' => $zeit,
            'guest_num' => $personen,
            'first_name' => $vorname,
            'last_name' => $nachname,
            'email' => $email,
            'telephone' => $w['telefon'] ?? '',
            'comment' => $kommentar,
            'duration' => Eingabe::zahl($w['dauer'] ?? ''),
            'status_id' => $status,
            'occasion_id' => $anlass,
            'table_ids' => $tischIds,
            'fehler' => $fehler,
            'hinweise' => $hinweise,
        ];
    }

    // ------------------------------------------------------------ Schreiben

    private function ausfuehren(array $vorbereitet, Collection $tische): int
    {
        $probe = (bool) $this->option('probe');

        $this->newLine();
        $this->line($probe
            ? sprintf('  <options=bold>Probelauf</> – %d Zeile(n), es wird nichts geschrieben', count($vorbereitet))
            : sprintf('  <options=bold>Import</> – %d Zeile(n)', count($vorbereitet)));
        $this->newLine();

        $angelegt = 0;
        $uebersprungen = 0;
        $fehlerhaft = 0;
        $protokoll = [];

        foreach ($vorbereitet as $z) {
            $bezeichnung = sprintf(
                'Zeile %-3d %s %s  %-22s %2s P.  %s',
                $z['zeile'],
                $z['reserve_date'] ?? '????-??-??',
                $z['reserve_time'] ?? '??:??',
                mb_strimwidth(trim($z['first_name'].' '.$z['last_name']), 0, 22, ''),
                $z['guest_num'] ?? '?',
                Tischwahl::namen($z['table_ids'], $tische),
            );

            if ($z['fehler'] !== []) {
                $this->error('  '.$bezeichnung);

                foreach ($z['fehler'] as $f) {
                    $this->line('      <fg=red>→ '.$f.'</>');
                }

                $fehlerhaft++;

                continue;
            }

            if (! $this->option('doppelte') && $this->gibtEsSchon($z)) {
                $this->warn('  '.$bezeichnung);
                $this->line('      <fg=yellow>→ gibt es schon (Datum, Uhrzeit, Nachname) – übersprungen. Mit --doppelte trotzdem anlegen.</>');
                $uebersprungen++;

                continue;
            }

            foreach ($z['hinweise'] as $h) {
                $this->line('      <fg=yellow>! '.$h.'</>');
            }

            if ($probe) {
                $this->line('  <fg=green>OK</>       '.$bezeichnung);
                $angelegt++;

                continue;
            }

            try {
                $r = Anlegen::reservierung($z, 'Aus Liste nachträglich importiert');
                $protokoll[] = (string) $r->reservation_id;
                $angelegt++;
                $this->line(sprintf('  <fg=green>#%-4d</fg=green>   %s', $r->reservation_id, $bezeichnung));
            } catch (Throwable $e) {
                $this->error('  '.$bezeichnung);
                $this->line('      <fg=red>→ '.$e->getMessage().'</>');
                $fehlerhaft++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  %s: %d   übersprungen: %d   fehlerhaft: %d',
            $probe ? 'Würde anlegen' : 'Angelegt',
            $angelegt,
            $uebersprungen,
            $fehlerhaft,
        ));

        if (! $probe && $protokoll !== []) {
            $verzeichnis = storage_path('import');
            @mkdir($verzeichnis, 0775, true);
            $datei = $verzeichnis.'/importiert-'.date('Ymd-His').'.log';
            file_put_contents($datei, implode("\n", $protokoll)."\n");

            $this->newLine();
            $this->line('  Protokoll: '.$datei);
            $this->line('  Rückgängig: php artisan reservierung:import --zurueck='.$datei);
        }

        return $fehlerhaft > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function gibtEsSchon(array $z): bool
    {
        return Reservation::query()
            ->where('location_id', $z['location_id'])
            ->where('reserve_date', $z['reserve_date'])
            ->where('reserve_time', $z['reserve_time'].':00')
            ->where('last_name', $z['last_name'])
            ->exists();
    }

    private function zuruecknehmen(string $protokoll): int
    {
        if (! is_readable($protokoll)) {
            $this->error('Protokolldatei nicht lesbar: '.$protokoll);

            return self::INVALID;
        }

        $ids = array_filter(array_map('trim', (array) file($protokoll)), 'ctype_digit');

        if ($ids === []) {
            $this->warn('Keine Nummern in '.$protokoll);

            return self::SUCCESS;
        }

        $this->line(sprintf('  %d Reservierung(en) aus dem Protokoll: %s', count($ids), implode(', ', $ids)));

        if ($this->option('probe')) {
            $this->warn('  Probelauf – es wird nichts gelöscht.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            if (Anlegen::zuruecknehmen((int) $id)) {
                $this->line(sprintf('  <fg=green>#%d gelöscht</>', $id));
            } else {
                $this->warn(sprintf('  #%d nicht vorhanden oder nicht aus diesem Import – übersprungen', $id));
            }
        }

        return self::SUCCESS;
    }

    private function vorlage(): string
    {
        return <<<'CSV'
            Datum;Zeit;Personen;Name;Telefon;EMail;Tisch;Status;Kommentar
            19.09.2026;12:00;5;Karin Häntzschel;01626583590;;Tisch 2;bestaetigt;Telefonisch angenommen
            20.09.2026;18:30;12;Müller, Hans;03685 12345;hans@example.de;Tisch 1/Tisch 2;bestaetigt;Geburtstag
            21.09.2026;19:00;2;Schmidt;;;;bestaetigt;Tisch noch offen
            22.09.2026;11:30;40;Feuerwehr Bauerbach;;;Saal;ausstehend;Warten auf Rückmeldung
            CSV;
    }
}
