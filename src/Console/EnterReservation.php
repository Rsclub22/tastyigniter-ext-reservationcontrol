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
            $this->error($this->t('console_no_location', ['id' => $this->locationId]));

            return self::FAILURE;
        }

        $this->tables = TableChoice::all();

        $this->newLine();
        $this->line('  <options=bold>'.$this->t('console_enter_heading').'</>');

        while (true) {
            $this->newLine();

            $choice = $this->choice('  '.$this->t('console_menu_prompt'), [
                'new' => $this->t('console_menu_new'),
                'tables' => $this->t('console_menu_tables'),
                'list' => $this->t('console_menu_list'),
                'quit' => $this->t('console_menu_quit'),
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
        $data['telephone'] = (string) $this->ask('  '.$this->t('console_field_telephone'), '');
        $data['email'] = (string) $this->ask('  '.$this->t('console_field_email'), '');
        $data['duration'] = Prompt::number((string) $this->ask('  '.$this->t('console_ask_duration'), ''));
        $data['table_ids'] = $this->askTables($data);
        $data['status_id'] = $this->askStatus();
        $data['comment'] = (string) $this->ask('  '.$this->t('console_field_comment'), '');
        $data['occasion_id'] = null;

        while (true) {
            $this->summary($data);

            $choice = $this->choice('  ', [
                'save' => $this->t('console_action_save'),
                'change' => $this->t('console_action_change'),
                'discard' => $this->t('console_action_discard'),
            ], 'save');

            if ($choice === 'discard') {
                $this->warn('  '.$this->t('console_discarded'));

                return;
            }

            if ($choice === 'change') {
                $data = $this->changeField($data);

                continue;
            }

            // Stays German on purpose: the status history already holds years of German rows.
            $r = CreateReservation::create($data, 'Im Dialog nachträglich erfasst');

            $this->info('  '.$this->t('console_saved', [
                'id' => $r->reservation_id,
                'date' => Prompt::longDate($data['reserve_date']),
                'time' => $data['reserve_time'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'persons' => trans_choice('reservationcontrol::default.count_persons', (int) $data['guest_num']),
                'tables' => TableChoice::names($data['table_ids'], $this->tables),
            ]));

            return;
        }
    }

    private function changeField(array $data): array
    {
        $fields = [];
        foreach (['date', 'time', 'persons', 'name', 'telephone', 'email', 'tables', 'duration', 'status', 'comment'] as $field) {
            $fields[$field] = $this->t('console_field_'.$field);
        }

        // choice() returns the key, so the switch does not depend on the language.
        switch ($this->choice('  '.$this->t('console_which_field'), $fields, 'tables')) {
            case 'date':
                $data['reserve_date'] = $this->askDate(date('d.m.Y', strtotime($data['reserve_date'])));
                break;
            case 'time':
                $data['reserve_time'] = $this->askTime($data['reserve_time']);
                break;
            case 'persons':
                $data['guest_num'] = $this->askGuests((string) $data['guest_num']);
                break;
            case 'name':
                [$data['first_name'], $data['last_name']] = $this->askName($data);
                break;
            case 'telephone':
                $data['telephone'] = (string) $this->ask('  '.$this->t('console_field_telephone'), $data['telephone']);
                break;
            case 'email':
                $data['email'] = (string) $this->ask('  '.$this->t('console_field_email'), $data['email']);
                break;
            case 'tables':
                $data['table_ids'] = $this->askTables($data, $data['table_ids']);
                break;
            case 'duration':
                $data['duration'] = Prompt::number((string) $this->ask('  '.$this->t('console_ask_duration'), (string) ($data['duration'] ?? '')));
                break;
            case 'status':
                $data['status_id'] = $this->askStatus();
                break;
            case 'comment':
                $data['comment'] = (string) $this->ask('  '.$this->t('console_field_comment'), (string) ($data['comment'] ?? ''));
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
        $this->line('  <options=bold>'.$this->t('console_tables_on', [
            'date' => Prompt::longDate($data['reserve_date']),
            'from' => $data['reserve_time'],
            'to' => date('H:i', strtotime($data['reserve_date'].' '.$data['reserve_time'].' +'.$duration.' minutes')),
        ]).'</>');

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
                    isset($taken[(int) $id]) => $this->t('console_taken', [
                        'name' => $taken[(int) $id]['name'] ?: '?',
                        'time' => $taken[(int) $id]['time'],
                        'guests' => $taken[(int) $id]['guests'],
                    ]),
                    ! $t->is_enabled => $this->t('console_disabled'),
                    default => $this->t('console_free'),
                },
            ];
        }

        $this->table([
            $this->t('console_col_no'),
            $this->t('console_col_table'),
            $this->t('console_col_seats'),
            $this->t('console_col_status'),
        ], $rows);

        $default = $preselected === []
            ? ''
            : implode(',', array_keys(array_intersect($numbers, $preselected)));

        while (true) {
            $input = (string) $this->ask('  '.$this->t('console_ask_tables'), $default);

            // The German words stay in the comparison: they are what a person
            // at a German restaurant types to mean "no table".
            if ($input === '' || $input === '-'
                || in_array(Prompt::normalized($input), ['ohne', 'kein', 'keine', 'keiner', 'nein'], true)) {
                $this->warn('  '.$this->t('console_no_assignment'));

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
                    $this->error('  '.$this->t('console_unknown', ['list' => implode(', ', $unknown)]));

                    continue;
                }

                $ids = array_merge($ids, $more);
            }

            if (($ids = array_values(array_unique($ids))) === []) {
                $this->error('  '.$this->t('console_no_valid_selection'));

                continue;
            }

            $capacity = TableChoice::capacity($ids, $this->tables);

            $this->newLine();
            $this->info('  '.$this->t('console_seats_for_guests', [
                'tables' => TableChoice::names($ids, $this->tables),
                'seats' => $capacity,
                'guests' => $data['guest_num'],
            ]));

            // Everything that follows does not prevent anything, it only says
            // so. That is exactly what this route is for: the backend does not
            // allow such cases.
            if ($capacity < $data['guest_num']) {
                $this->warn('  '.$this->t('console_capacity_mismatch', [
                    'capacity' => $capacity,
                    'guests' => $data['guest_num'],
                ]));
            }

            foreach ($ids as $id) {
                if (isset($taken[$id])) {
                    $this->warn('  '.$this->t('console_table_taken', [
                        'table' => $this->tables->get($id)->name,
                        'name' => $taken[$id]['name'] ?: '?',
                        'id' => $taken[$id]['reservation'],
                    ]));
                }

                if (! $this->tables->get($id)->is_enabled) {
                    $this->warn('  '.$this->t('console_table_disabled', ['table' => $this->tables->get($id)->name]));
                }
            }

            if ($this->confirm('  '.$this->t('console_accept'), true)) {
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

        $input = trim((string) $this->ask('  '.$this->t('console_which_reservation'), ''));
        if ($input === '') {
            return;
        }

        $id = str_starts_with($input, '#')
            ? (int) substr($input, 1)
            : ($mapping[(int) $input] ?? (int) $input);

        if (! $r = Reservation::with('tables')->find($id)) {
            $this->error('  '.$this->t('console_reservation_not_found', ['id' => $id]));

            return;
        }

        $this->newLine();
        $this->line('  <options=bold>'.$this->t('console_reservation_line', [
            'id' => $r->reservation_id,
            'date' => Prompt::longDate($r->reserve_date->format('Y-m-d')),
            'time' => substr((string) $r->reserve_time, 0, 5),
            'name' => trim($r->first_name.' '.$r->last_name),
            'persons' => trans_choice('reservationcontrol::default.count_persons', (int) $r->guest_num),
        ]).'</>');
        $this->line('  '.$this->t('console_currently', [
            'tables' => TableChoice::names(
                $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all(),
                $this->tables,
            ),
        ]));

        $new = $this->askTables([
            'reserve_date' => $r->reserve_date->format('Y-m-d'),
            'reserve_time' => substr((string) $r->reserve_time, 0, 5),
            'duration' => (int) $r->duration,
            'guest_num' => (int) $r->guest_num,
            'reservation_id' => (int) $r->reservation_id,
        ], $r->tables->pluck('id')->map(fn ($v): int => (int) $v)->all());

        $r->addReservationTables($new);

        $this->info('  '.$this->t('console_tables_now', [
            'id' => $r->reservation_id,
            'tables' => TableChoice::names($new, $this->tables),
        ]));
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
            $this->warn('  '.$this->t('console_no_upcoming'));

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
        $this->table([
            $this->t('console_col_no'),
            $this->t('console_col_date'),
            $this->t('console_col_time'),
            $this->t('console_col_persons'),
            $this->t('console_col_name'),
            $this->t('console_col_tables'),
            $this->t('console_col_status'),
        ], $rows);

        return $mapping;
    }

    // ------------------------------------------------------------ Questions

    private function askDate(string $default = ''): string
    {
        while (true) {
            $input = (string) $this->ask('  '.$this->t('console_ask_date'), $default);

            if ($date = Prompt::date($input)) {
                $this->line('       <fg=gray>'.Prompt::longDate($date).'</>');

                return $date;
            }

            $this->error('  '.$this->t('console_date_not_understood', ['input' => $input]));
        }
    }

    private function askTime(string $default = ''): string
    {
        while (true) {
            $input = (string) $this->ask('  '.$this->t('console_ask_time'), $default);

            if ($time = Prompt::time($input)) {
                return $time;
            }

            $this->error('  '.$this->t('console_time_not_understood', ['input' => $input]));
        }
    }

    private function askGuests(string $default = ''): int
    {
        while (true) {
            $number = Prompt::number((string) $this->ask('  '.$this->t('console_ask_persons'), $default));

            if ($number !== null && $number > 0) {
                return $number;
            }

            $this->error('  '.$this->t('console_need_number'));
        }
    }

    /** @return array{0: string, 1: string} */
    private function askName(array $data = []): array
    {
        $default = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

        while (true) {
            [$first, $last] = Prompt::name((string) $this->ask('  '.$this->t('console_ask_name'), $default));

            if ($first !== '' || $last !== '') {
                return [$first, $last];
            }

            $this->error('  '.$this->t('console_need_name'));
        }
    }

    private function askStatus(): int
    {
        $choices = array_keys(Prompt::statusList());

        // The default is a normalised status name as it stands in the database
        // of a German installation - see Prompt::statusList().
        return Prompt::status($this->choice('  '.$this->t('console_ask_status'), $choices, 'bestaetigt'));
    }

    private function summary(array $data): void
    {
        $statusNames = array_flip(Prompt::statusList());

        $this->newLine();
        $this->table([$this->t('console_col_field'), $this->t('console_col_value')], [
            [$this->t('console_field_date'), Prompt::longDate($data['reserve_date'])],
            [$this->t('console_field_time'), $data['reserve_time']],
            [$this->t('console_field_persons'), $data['guest_num']],
            [$this->t('console_field_name'), trim($data['first_name'].' '.$data['last_name'])],
            [$this->t('console_field_telephone'), $data['telephone'] !== '' ? $data['telephone'] : '–'],
            [$this->t('console_field_email'), $data['email'] !== '' ? $data['email'] : '–'],
            [$this->t('console_field_tables'), TableChoice::names($data['table_ids'], $this->tables)],
            [$this->t('console_field_duration'), $data['duration']
                ? $this->t('console_minutes', ['count' => $data['duration']])
                : $this->t('console_default_duration')],
            [$this->t('console_field_status'), $statusNames[$data['status_id']] ?? $data['status_id']],
            [$this->t('console_field_comment'), ($data['comment'] ?? '') !== '' ? $data['comment'] : '–'],
        ]);
    }

    /** Translate a key of this extension's language file. */
    private function t(string $key, array $replace = []): string
    {
        return __('reservationcontrol::default.'.$key, $replace);
    }
}
