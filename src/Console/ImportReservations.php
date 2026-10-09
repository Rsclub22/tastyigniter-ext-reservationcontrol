<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Console;

use Igniter\Reservation\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;
use Wagnersnetz\ReservationControl\Entry\CreateReservation;
use Wagnersnetz\ReservationControl\Entry\Prompt;
use Wagnersnetz\ReservationControl\Entry\TableChoice;

/**
 * Take reservations over from a CSV - for entering whole lists after the fact,
 * lists in which fields are allowed to be missing.
 */
class ImportReservations extends Command
{
    protected $signature = 'reservation:import
        {file? : CSV file}
        {--dry-run : Only check and show, write nothing}
        {--duplicates : Create even when the reservation already exists}
        {--location=1 : Location id}
        {--status=bestaetigt : Status for rows without a status column}
        {--undo= : Delete the reservations recorded in this log file again}
        {--template : Print an example CSV}';

    protected $description = 'Take reservations over from a CSV file';

    /**
     * Column headings as they occur in other people's lists. German spellings
     * stay and are not extended: what is recognised here is a data contract
     * with the lists that get handed over.
     */
    private const array COLUMNS = [
        'date' => ['datum', 'date', 'tag'],
        'time' => ['zeit', 'uhrzeit', 'time', 'von'],
        'guests' => ['personen', 'pers', 'gaeste', 'anzahl', 'guests', 'pax', 'p'],
        'name' => ['name', 'gast', 'kunde'],
        'firstName' => ['vorname', 'firstname'],
        'lastName' => ['nachname', 'lastname', 'familienname'],
        'telephone' => ['telefon', 'tel', 'phone', 'handy', 'nummer', 'rufnummer'],
        'email' => ['email', 'mail', 'emailadresse'],
        'table' => ['tisch', 'tische', 'table', 'raum', 'bereich'],
        'duration' => ['dauer', 'duration', 'minuten'],
        'status' => ['status', 'zustand'],
        'comment' => ['kommentar', 'notiz', 'bemerkung', 'anmerkung', 'comment', 'hinweis'],
        'occasion' => ['anlass', 'occasion', 'grund'],
    ];

    public function handle(): int
    {
        if ($this->option('template')) {
            $this->line($this->template());

            return self::SUCCESS;
        }

        if ($log = $this->option('undo')) {
            return $this->undo((string) $log);
        }

        $file = (string) $this->argument('file');

        if ($file === '' || ! is_readable($file)) {
            $this->error('File missing or not readable. Example: --template');

            return self::INVALID;
        }

        $tables = TableChoice::all();
        $rows = $this->read($file);

        if ($rows === []) {
            return self::INVALID;
        }

        $prepared = array_map(fn (array $r): array => $this->prepare($r, $tables), $rows);

        return $this->importRows($prepared, $tables);
    }

    // ------------------------------------------------------------ Reading

    /** @return list<array{row: int, values: array<string, string>}> */
    private function read(string $file): array
    {
        $content = (string) file_get_contents($file);

        // Byte order mark and Windows encoding: a list exported from Excel
        // brings both along, and without this here you end up with "MÃ¼ller" in
        // the database.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = (string) strtok($content, "\n");
        $separator = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        if (substr_count($firstLine, "\t") > substr_count($firstLine, $separator)) {
            $separator = "\t";
        }

        $handle = tmpfile();
        fwrite($handle, $content);
        rewind($handle);

        $raw = [];

        while (($record = fgetcsv($handle, 0, $separator, '"', '\\')) !== false) {
            if ($record === [null] || (count($record) === 1 && trim((string) $record[0]) === '')) {
                continue;
            }

            $raw[] = $record;
        }

        fclose($handle);

        if ($raw === []) {
            $this->error('The file contains no rows.');

            return [];
        }

        $header = array_shift($raw);
        $mapping = [];

        foreach ($header as $i => $heading) {
            $normal = Prompt::normalized((string) $heading);

            foreach (self::COLUMNS as $field => $names) {
                if (! isset($mapping[$field]) && in_array($normal, array_map([Prompt::class, 'normalized'], $names), true)) {
                    $mapping[$field] = $i;
                }
            }
        }

        foreach (['date', 'time', 'guests'] as $required) {
            if (! isset($mapping[$required])) {
                $this->error(sprintf('Required column "%s" is missing. Found: %s', $required, implode(', ', $header)));

                return [];
            }
        }

        if (! isset($mapping['name']) && ! isset($mapping['lastName'])) {
            $this->error('A column "Name" or "Nachname" is needed.');

            return [];
        }

        $result = [];

        foreach ($raw as $no => $record) {
            $values = [];

            foreach ($mapping as $field => $i) {
                $values[$field] = trim((string) ($record[$i] ?? ''));
            }

            // +2: one header row, and counting from 1 as in the spreadsheet
            // program.
            $result[] = ['row' => $no + 2, 'values' => $values];
        }

        return $result;
    }

    // ------------------------------------------------------------ Checking

    private function prepare(array $row, Collection $tables): array
    {
        ['row' => $no, 'values' => $v] = $row;

        $errors = [];
        $notes = [];

        if (! $date = Prompt::date($v['date'] ?? '')) {
            $errors[] = sprintf('Date unreadable: "%s"', $v['date'] ?? '');
        }

        if (! $time = Prompt::time($v['time'] ?? '')) {
            $errors[] = sprintf('Time unreadable: "%s"', $v['time'] ?? '');
        }

        $guests = Prompt::number($v['guests'] ?? '');
        if ($guests === null || $guests < 1) {
            $errors[] = sprintf('Number of persons unreadable: "%s"', $v['guests'] ?? '');
        }

        [$firstName, $lastName] = Prompt::name($v['name'] ?? '', $v['firstName'] ?? '', $v['lastName'] ?? '');
        if ($firstName === '' && $lastName === '') {
            $errors[] = 'No name given';
        }

        $email = $v['email'] ?? '';
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notes[] = sprintf('E-mail looks invalid: "%s" – taken over all the same', $email);
        }

        $statusRaw = ($v['status'] ?? '') !== '' ? $v['status'] : (string) $this->option('status');
        $status = Prompt::status($statusRaw);
        if ($status === null) {
            $errors[] = sprintf('Status unknown: "%s" (possible: %s)', $statusRaw, implode(', ', array_keys(Prompt::statusList())));
        }

        $tableIds = [];
        $tableRaw = $v['table'] ?? '';

        // The German words stay in the comparison: they are what the lists that
        // get handed over actually contain for "no table".
        if ($tableRaw !== '' && $tableRaw !== '-'
            && ! in_array(Prompt::normalized($tableRaw), ['ohne', 'kein', 'keine', 'keiner', 'offen'], true)) {
            [$tableIds, $unknown] = TableChoice::fromText($tableRaw, $tables);

            foreach ($unknown as $u) {
                $errors[] = sprintf('Table "%s" not found', $u);
            }
        }

        if ($tableIds !== [] && $guests !== null) {
            $capacity = TableChoice::capacity($tableIds, $tables);

            if ($capacity > 0 && $guests > $capacity) {
                $notes[] = sprintf('More guests (%d) than seats (%d) – created all the same', $guests, $capacity);
            }
        }

        foreach ($tableIds as $id) {
            if (! $tables->get($id)?->is_enabled) {
                $notes[] = sprintf('Table "%s" is disabled – the assignment is set all the same', $tables->get($id)->name ?? $id);
            }
        }

        // An unknown occasion is no reason to leave the row lying - it moves
        // into the comment so that the information is not lost.
        $occasionRaw = $v['occasion'] ?? '';
        $occasion = $occasionRaw !== '' ? Prompt::occasion($occasionRaw) : null;
        $comment = $v['comment'] ?? '';

        if ($occasionRaw !== '' && $occasion === null) {
            $notes[] = sprintf('Occasion "%s" unknown – now stands in the comment', $occasionRaw);
            $comment = trim($comment === '' ? 'Anlass: '.$occasionRaw : $comment.' | Anlass: '.$occasionRaw);
        }

        return [
            'row' => $no,
            'location_id' => (int) $this->option('location'),
            'reserve_date' => $date,
            'reserve_time' => $time,
            'guest_num' => $guests,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'telephone' => $v['telephone'] ?? '',
            'comment' => $comment,
            'duration' => Prompt::number($v['duration'] ?? ''),
            'status_id' => $status,
            'occasion_id' => $occasion,
            'table_ids' => $tableIds,
            'errors' => $errors,
            'notes' => $notes,
        ];
    }

    // ------------------------------------------------------------ Writing

    private function importRows(array $prepared, Collection $tables): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line($dryRun
            ? sprintf('  <options=bold>Dry run</> – %d row(s), nothing is written', count($prepared))
            : sprintf('  <options=bold>Import</> – %d row(s)', count($prepared)));
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $log = [];

        foreach ($prepared as $r) {
            $label = sprintf(
                'Row %-3d %s %s  %-22s %2s p.  %s',
                $r['row'],
                $r['reserve_date'] ?? '????-??-??',
                $r['reserve_time'] ?? '??:??',
                mb_strimwidth(trim($r['first_name'].' '.$r['last_name']), 0, 22, ''),
                $r['guest_num'] ?? '?',
                TableChoice::names($r['table_ids'], $tables),
            );

            if ($r['errors'] !== []) {
                $this->error('  '.$label);

                foreach ($r['errors'] as $e) {
                    $this->line('      <fg=red>→ '.$e.'</>');
                }

                $failed++;

                continue;
            }

            if (! $this->option('duplicates') && $this->alreadyExists($r)) {
                $this->warn('  '.$label);
                $this->line('      <fg=yellow>→ already exists (date, time, surname) – skipped. Use --duplicates to create it anyway.</>');
                $skipped++;

                continue;
            }

            foreach ($r['notes'] as $n) {
                $this->line('      <fg=yellow>! '.$n.'</>');
            }

            if ($dryRun) {
                $this->line('  <fg=green>OK</>       '.$label);
                $created++;

                continue;
            }

            try {
                $reservation = CreateReservation::create($r, 'Aus Liste nachträglich importiert');
                $log[] = (string) $reservation->reservation_id;
                $created++;
                $this->line(sprintf('  <fg=green>#%-4d</fg=green>   %s', $reservation->reservation_id, $label));
            } catch (Throwable $e) {
                $this->error('  '.$label);
                $this->line('      <fg=red>→ '.$e->getMessage().'</>');
                $failed++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  %s: %d   skipped: %d   failed: %d',
            $dryRun ? 'Would create' : 'Created',
            $created,
            $skipped,
            $failed,
        ));

        if (! $dryRun && $log !== []) {
            $directory = storage_path('import');
            @mkdir($directory, 0775, true);
            $file = $directory.'/importiert-'.date('Ymd-His').'.log';
            file_put_contents($file, implode("\n", $log)."\n");

            $this->newLine();
            $this->line('  Log: '.$file);
            $this->line('  Undo: php artisan reservation:import --undo='.$file);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function alreadyExists(array $r): bool
    {
        return Reservation::query()
            ->where('location_id', $r['location_id'])
            ->where('reserve_date', $r['reserve_date'])
            ->where('reserve_time', $r['reserve_time'].':00')
            ->where('last_name', $r['last_name'])
            ->exists();
    }

    private function undo(string $log): int
    {
        if (! is_readable($log)) {
            $this->error('Log file not readable: '.$log);

            return self::INVALID;
        }

        $ids = array_filter(array_map('trim', (array) file($log)), 'ctype_digit');

        if ($ids === []) {
            $this->warn('No numbers in '.$log);

            return self::SUCCESS;
        }

        $this->line(sprintf('  %d reservation(s) from the log: %s', count($ids), implode(', ', $ids)));

        if ($this->option('dry-run')) {
            $this->warn('  Dry run – nothing is deleted.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            if (CreateReservation::undo((int) $id)) {
                $this->line(sprintf('  <fg=green>#%d deleted</>', $id));
            } else {
                $this->warn(sprintf('  #%d does not exist or is not from this import – skipped', $id));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Example CSV. Headings and status names stay German on purpose: they are
     * the ones a German installation recognises (see COLUMNS and
     * Prompt::statusList()), so the example is one that actually imports.
     */
    private function template(): string
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
