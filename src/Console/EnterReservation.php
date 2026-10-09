<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Console;

use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Wagnersnetz\ReservationControl\Entry\CreateReservation;
use Wagnersnetz\ReservationControl\Entry\Prompt;
use Wagnersnetz\ReservationControl\Entry\TableChoice;

/**
 * Enter reservations at the machine when /intern is not an option - entering
 * them after the fact out of the book, several tables per reservation, fields
 * allowed to be incomplete. Needs a terminal.
 */
class EnterReservation extends Command
{
    protected $signature = 'reservation:enter {--location=1 : Location id}';

    protected $description = 'Enter reservations interactively and assign tables';

    private int $locationId;

    private Collection $tables;

    public function handle(): int
    {
        $this->locationId = (int) $this->option('location');

        if (! Location::find($this->locationId)) {
            $this->error(sprintf('There is no location %d.', $this->locationId));

            return self::FAILURE;
        }

        $this->tables = TableChoice::all();

        $this->newLine();
        $this->line('  <options=bold>Enter reservations</>');

        while (true) {
            $this->newLine();

            $choice = $this->choice('  What would you like to do?', [
                'new' => 'Enter a new reservation',
                'tables' => 'Change the tables of an existing reservation',
                'list' => 'Show upcoming reservations',
                'quit' => 'Quit',
            ], 'new');

            match ($choice) {
                'new' => $this->createNew(),
                'tables' => $this->changeTables(),
                'list' => $this->listUpcoming(),
                'quit' => null,
            };

            if ($choice === 'quit') {
                return self::SUCCESS;
            }
        }
    }

    // ------------------------------------------------------------ New entry

    private function createNew(): void
    {
        $data = [
            'location_id' => $this->locationId,
            'reserve_date' => $this->askDate(date('d.m.Y')),
            'reserve_time' => $this->askTime(),
            'guest_num' => $this->askGuests(),
        ];

        [$data['first_name'], $data['last_name']] = $this->askName();
        $data['telephone'] = (string) $this->ask('  Telephone', '');
        $data['email'] = (string) $this->ask('  E-mail', '');
        $data['duration'] = Prompt::number((string) $this->ask('  Duration in minutes (empty = default)', ''));
        $data['table_ids'] = $this->askTables($data);
        $data['status_id'] = $this->askStatus();
        $data['comment'] = (string) $this->ask('  Comment', '');
        $data['occasion_id'] = null;

        while (true) {
            $this->summary($data);

            $choice = $this->choice('  ', [
                'save' => 'Save',
                'change' => 'Change one field',
                'discard' => 'Discard',
            ], 'save');

            if ($choice === 'discard') {
                $this->warn('  Discarded, nothing saved.');

                return;
            }

            if ($choice === 'change') {
                $data = $this->changeField($data);

                continue;
            }

            $r = CreateReservation::create($data, 'Im Dialog nachträglich erfasst');

            $this->info(sprintf(
                '  Saved as #%d – %s %s, %s, %d persons, %s',
                $r->reservation_id,
                Prompt::longDate($data['reserve_date']),
                $data['reserve_time'],
                trim($data['first_name'].' '.$data['last_name']),
                $data['guest_num'],
                TableChoice::names($data['table_ids'], $this->tables),
            ));

            return;
        }
    }

    private function changeField(array $data): array
    {
        $fields = [
            'date' => 'Date', 'time' => 'Time', 'guests' => 'Persons',
            'name' => 'Name', 'telephone' => 'Telephone', 'email' => 'E-mail',
            'tables' => 'Tables', 'duration' => 'Duration', 'status' => 'Status',
            'comment' => 'Comment',
        ];

        switch ($this->choice('  Which field?', $fields, 'tables')) {
            case 'Date':
                $data['reserve_date'] = $this->askDate(date('d.m.Y', strtotime($data['reserve_date'])));
                break;
            case 'Time':
                $data['reserve_time'] = $this->askTime($data['reserve_time']);
                break;
            case 'Persons':
                $data['guest_num'] = $this->askGuests((string) $data['guest_num']);
                break;
            case 'Name':
                [$data['first_name'], $data['last_name']] = $this->askName($data);
                break;
            case 'Telephone':
                $data['telephone'] = (string) $this->ask('  Telephone', $data['telephone']);
                break;
            case 'E-mail':
                $data['email'] = (string) $this->ask('  E-mail', $data['email']);
                break;
            case 'Tables':
                $data['table_ids'] = $this->askTables($data, $data['table_ids']);
                break;
            case 'Duration':
                $data['duration'] = Prompt::number((string) $this->ask('  Duration in minutes (empty = default)', (string) ($data['duration'] ?? '')));
                break;
            case 'Status':
                $data['status_id'] = $this->askStatus();
                break;
            case 'Comment':
                $data['comment'] = (string) $this->ask('  Comment', (string) ($data['comment'] ?? ''));
                break;
        }

        return $data;
    }

    // ------------------------------------------------------------ Tables

    /** @return int[] */
    private function askTables(array $data, array $preselected = []): array
    {
        $duration = $data['duration'] ?: 60;
        $taken = TableChoice::occupancy(
            $this->locationId,
            $data['reserve_date'],
            $data['reserve_time'],
            $duration,
            $data['reservation_id'] ?? null,
        );

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>Tables on %s, %s – %s</>',
            Prompt::longDate($data['reserve_date']),
            $data['reserve_time'],
            date('H:i', strtotime($data['reserve_date'].' '.$data['reserve_time'].' +'.$duration.' minutes')),
        ));

        $rows = [];
        $numbers = [];
        $no = 0;

        foreach ($this->tables as $id => $t) {
            $numbers[++$no] = (int) $id;

            $rows[] = [
                (in_array((int) $id, $preselected, true) ? '»' : ' ').$no,
                $t->name,
                $t->min_capacity.'–'.($t->max_capacity + $t->extra_capacity),
                match (true) {
                    isset($taken[(int) $id]) => sprintf(
                        'taken: %s %s (%d p.)',
                        $taken[(int) $id]['name'] ?: '?',
                        $taken[(int) $id]['time'],
                        $taken[(int) $id]['guests'],
                    ),
                    ! $t->is_enabled => 'disabled',
                    default => 'free',
                },
            ];
        }

        $this->table(['No', 'Table', 'Seats', 'Status'], $rows);

        $default = $preselected === []
            ? ''
            : implode(',', array_keys(array_intersect($numbers, $preselected)));

        while (true) {
            $input = (string) $this->ask('  Tables (numbers or names, several separated by comma; "-" = no table)', $default);

            // The German words stay in the comparison: they are what a person
            // at a German restaurant types to mean "no table".
            if ($input === '' || $input === '-'
                || in_array(Prompt::normalized($input), ['ohne', 'kein', 'keine', 'keiner', 'nein'], true)) {
                $this->warn('  No table assignment – the reservation will be saved without a table.');

                return [];
            }

            // Numbers from the displayed list take precedence; everything else
            // runs over the name resolution.
            $ids = [];
            $rest = [];

            foreach (preg_split('/\s*[,;+]\s*|\s+/', trim($input)) ?: [] as $part) {
                if (ctype_digit($part) && isset($numbers[(int) $part])) {
                    $ids[] = $numbers[(int) $part];
                } elseif ($part !== '') {
                    $rest[] = $part;
                }
            }

            if ($rest !== []) {
                [$more, $unknown] = TableChoice::fromText(implode(',', $rest), $this->tables);

                if ($unknown !== []) {
                    $this->error('  Unknown: '.implode(', ', $unknown));

                    continue;
                }

                $ids = array_merge($ids, $more);
            }

            if (($ids = array_values(array_unique($ids))) === []) {
                $this->error('  No valid selection.');

                continue;
            }

            $capacity = TableChoice::capacity($ids, $this->tables);

            $this->newLine();
            $this->info(sprintf(
                '  %s  =  %d seats for %d guests',
                TableChoice::names($ids, $this->tables),
                $capacity,
                $data['guest_num'],
            ));

            // Everything that follows does not prevent anything, it only says
            // so. That is exactly what this route is for: the backend does not
            // allow such cases.
            if ($capacity < $data['guest_num']) {
                $this->warn(sprintf('  Capacity does not add up (%d < %d).', $capacity, $data['guest_num']));
            }

            foreach ($ids as $id) {
                if (isset($taken[$id])) {
                    $this->warn(sprintf(
                        '  %s is already assigned to %s at that time (#%d).',
                        $this->tables->get($id)->name,
                        $taken[$id]['name'] ?: '?',
                        $taken[$id]['reservation'],
                    ));
                }

                if (! $this->tables->get($id)->is_enabled) {
                    $this->warn(sprintf('  %s is disabled in the table plan.', $this->tables->get($id)->name));
                }
            }

            if ($this->confirm('  Accept?', true)) {
                return $ids;
            }

            $default = '';
        }
    }

    private function changeTables(): void
    {
        if (! $mapping = $this->listUpcoming()) {
            return;
        }

        $input = trim((string) $this->ask('  Which reservation? (No or #id)', ''));
        if ($input === '') {
            return;
        }

        $id = str_starts_with($input, '#')
            ? (int) substr($input, 1)
            : ($mapping[(int) $input] ?? (int) $input);

        if (! $r = Reservation::with('tables')->find($id)) {
            $this->error(sprintf('  Reservation #%d not found.', $id));

            return;
        }

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>#%d  %s %s  %s  %d persons</>',
            $r->reservation_id,
            Prompt::longDate($r->reserve_date->format('Y-m-d')),
            substr((string) $r->reserve_time, 0, 5),
            trim($r->first_name.' '.$r->last_name),
            $r->guest_num,
        ));
        $this->line('  currently: '.TableChoice::names(
            $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all(),
            $this->tables,
        ));

        $new = $this->askTables([
            'reserve_date' => $r->reserve_date->format('Y-m-d'),
            'reserve_time' => substr((string) $r->reserve_time, 0, 5),
            'duration' => (int) $r->duration,
            'guest_num' => (int) $r->guest_num,
            'reservation_id' => (int) $r->reservation_id,
        ], $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all());

        $r->addReservationTables($new);

        $this->info(sprintf('  #%d: tables are now %s', $r->reservation_id, TableChoice::names($new, $this->tables)));
    }

    // ------------------------------------------------------------ List

    /** @return array<int, int> list number => reservation id */
    private function listUpcoming(): array
    {
        $reservations = Reservation::query()
            ->with('tables')
            ->where('location_id', $this->locationId)
            ->where('reserve_date', '>=', date('Y-m-d', strtotime('-1 day')))
            ->orderBy('reserve_date')->orderBy('reserve_time')
            ->limit(50)->get();

        if ($reservations->isEmpty()) {
            $this->warn('  No upcoming reservations.');

            return [];
        }

        $statusNames = array_flip(Prompt::statusList());
        $mapping = [];
        $rows = [];

        foreach ($reservations as $i => $r) {
            $mapping[$i + 1] = (int) $r->reservation_id;

            $rows[] = [
                $i + 1,
                Prompt::longDate($r->reserve_date->format('Y-m-d')),
                substr((string) $r->reserve_time, 0, 5),
                $r->guest_num,
                trim($r->first_name.' '.$r->last_name),
                // A party can have nine tables - unshortened, one single line
                // pulls the whole table out to the sides.
                mb_strimwidth(
                    TableChoice::names($r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all(), $this->tables),
                    0, 38, '…',
                ),
                $statusNames[(int) $r->status_id] ?? '',
            ];
        }

        $this->newLine();
        $this->table(['No', 'Date', 'Time', 'Pers', 'Name', 'Tables', 'Status'], $rows);

        return $mapping;
    }

    // ------------------------------------------------------------ Questions

    private function askDate(string $default = ''): string
    {
        while (true) {
            $input = (string) $this->ask('  Date (DD.MM.YYYY, "today", "tomorrow")', $default);

            if ($date = Prompt::date($input)) {
                $this->line('       <fg=gray>'.Prompt::longDate($date).'</>');

                return $date;
            }

            $this->error(sprintf('  Date not understood: "%s"', $input));
        }
    }

    private function askTime(string $default = ''): string
    {
        while (true) {
            $input = (string) $this->ask('  Time (e.g. 18:30)', $default);

            if ($time = Prompt::time($input)) {
                return $time;
            }

            $this->error(sprintf('  Time not understood: "%s"', $input));
        }
    }

    private function askGuests(string $default = ''): int
    {
        while (true) {
            $number = Prompt::number((string) $this->ask('  Persons', $default));

            if ($number !== null && $number > 0) {
                return $number;
            }

            $this->error('  Please give a number greater than 0.');
        }
    }

    /** @return array{0: string, 1: string} */
    private function askName(array $data = []): array
    {
        $default = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        while (true) {
            [$first, $last] = Prompt::name((string) $this->ask('  Name ("Hans Müller" or "Müller, Hans")', $default));

            if ($first !== '' || $last !== '') {
                return [$first, $last];
            }

            $this->error('  Please give a name.');
        }
    }

    private function askStatus(): int
    {
        $choices = array_keys(Prompt::statusList());

        // The default is a normalised status name as it stands in the database
        // of a German installation - see Prompt::statusList().
        return Prompt::status($this->choice('  Status', $choices, 'bestaetigt'));
    }

    private function summary(array $data): void
    {
        $statusNames = array_flip(Prompt::statusList());

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Date', Prompt::longDate($data['reserve_date'])],
            ['Time', $data['reserve_time']],
            ['Persons', $data['guest_num']],
            ['Name', trim($data['first_name'].' '.$data['last_name'])],
            ['Telephone', $data['telephone'] !== '' ? $data['telephone'] : '–'],
            ['E-mail', $data['email'] !== '' ? $data['email'] : '–'],
            ['Tables', TableChoice::names($data['table_ids'], $this->tables)],
            ['Duration', $data['duration'] ? $data['duration'].' min.' : 'default of the location'],
            ['Status', $statusNames[$data['status_id']] ?? $data['status_id']],
            ['Comment', ($data['comment'] ?? '') !== '' ? $data['comment'] : '–'],
        ]);
    }
}
