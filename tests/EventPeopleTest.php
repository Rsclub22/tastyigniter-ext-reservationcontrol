<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\DayData;
use Wagnersnetz\ReservationControl\Intake;
use Wagnersnetz\ReservationControl\Models\Settings;

// The operator's two real days, moved to 2030: the Märchenabend on a Friday and the two
// Christmas days (Wednesday and Thursday). The wording is theirs; see
// docs/superpowers/specs/2026-10-09-event-slots-design.md. The house serves lunch 11:30-15:00.
const PEOPLE_FRIDAY = '2030-11-29';
const PEOPLE_XMAS_1 = '2030-12-25';
const PEOPLE_XMAS_2 = '2030-12-26';

const PEOPLE_MAERCHEN_KEYWORD = 'MÄRCHENABEND 17 UHR MAX 30 PAX. ONLINE BUCHBAR: Märchenabend mit Menü …';
const PEOPLE_MAERCHEN_PLAIN = 'MÄRCHENABEND 17 UHR MAX 30 PAX. NUR PER TELEFON BUCHBAR';
const PEOPLE_XMAS = 'WEIHNACHTEN: 2 Gänge: 11 Uhr und 13 Uhr. NICHTS DAZWISCHEN ANNEHMEN. BUCHUNG NUMMER 161 NICHT STORNIEREN. ONLINE RESERVIERUNGEN AN DEM TAG NICHT VERFÜGBAR. MAX 120 PAX.';
// The same note with its "not available online" sentence swapped for the keyword.
const PEOPLE_XMAS_KEYWORD = 'WEIHNACHTEN: 2 Gänge: 11 Uhr und 13 Uhr. NICHTS DAZWISCHEN ANNEHMEN. BUCHUNG NUMMER 161 NICHT STORNIEREN. ONLINE BUCHBAR. MAX 120 PAX.';

function peopleReset(): void
{
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
    Settings::clearInternalCache();
}

beforeEach(function (): void {
    peopleReset();

    // The default hours (round the clock) are created on first read; then narrow them to lunch.
    Location::query()->first()->getWorkingHours();
    DB::table('working_hours')->where('location_id', 1)->where('type', 'opening')
        ->update(['opening_time' => '11:30:00', 'closing_time' => '15:00:00', 'status' => 1]);

    Location::query()->first()->settings()->updateOrCreate(['item' => 'booking'], ['data' => [
        'time_interval' => 15, 'stay_time' => 120, 'include_start_time' => 1,
        'min_advance_time' => 0, 'max_advance_time' => 3000,
    ]]);

    peopleSetting('internal_booking_horizon_days', 3650);
    peopleSetting('cutoff_minutes_before_closing', 60);
});

afterEach(fn () => peopleReset());

function peopleSetting(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();
    Settings::clearInternalCache();
}

/** A closure note that holds every table of the house, as the live ones do. */
function peopleNote(string $date, string $comment, string $time = '16:00:00', int $duration = 240, bool $holdTables = true): int
{
    $id = DB::table('reservations')->insertGetId([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => $date, 'reserve_time' => $time,
        'reserve_datetime' => $date.' '.$time, 'duration' => $duration, 'status_id' => 1,
        'comment' => $comment, 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($holdTables) {
        foreach (DB::table('dining_tables')->pluck('id') as $tableId) {
            DB::table('reservation_tables')->insert(['reservation_id' => $id, 'dining_table_id' => $tableId, 'table_id' => $tableId]);
        }
    }

    peopleReset();

    return $id;
}

/** An ordinary booking of $guests at $time, without a table. */
function peopleBooking(string $date, string $time, int $guests): void
{
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => $guests, 'first_name' => 'A', 'last_name' => 'Meier',
        'email' => 'a@example.com', 'reserve_date' => $date, 'reserve_time' => $time.':00',
        'reserve_datetime' => $date.' '.$time.':00', 'duration' => 120, 'status_id' => 1,
        'comment' => '', 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The times a guest is offered online: the list of the booking form, minus
 * what the form greys out (the same call the submission guard makes).
 *
 * @return array<int, string> HH:MM
 */
function peopleOnline(string $date, int $guests = 2): array
{
    $manager = resolve(BookingManager::class);
    $manager->useLocation(DayData::location());
    $manager->allowSameDay(false)->forceGuestCount($guests);

    $day = Carbon::parse($date);
    $slots = collect($manager->makeTimeSlots($day));
    $blocked = $manager->isTimeslotsFullyBookedOn($slots->map(fn ($s) => $day->copy()->setTimeFromTimeString($s->format('H:i'))), $day, $guests);

    return $slots->map(fn ($s): string => $s->format('H:i'))
        ->reject(fn (string $t): bool => in_array($day->copy()->setTimeFromTimeString($t)->format('Y-m-d H:i'), $blocked, true))
        ->values()->all();
}

/**
 * The rows of the internal page for $date, by time.
 *
 * @return array<string, array<string, mixed>>
 */
function peopleInternal(string $date, int $guests = 2): array
{
    return collect(DayData::forDate(Carbon::parse($date), $guests)['belegung'])->keyBy('zeit')->all();
}

/** The times the internal page offers: every row, whether or not it still fits. */
function peopleInternalTimes(string $date, int $guests = 2): array
{
    return array_keys(peopleInternal($date, $guests));
}

const PEOPLE_LUNCH_ONLINE = ['11:30', '11:45', '12:00', '12:15', '12:30', '12:45', '13:00', '13:15', '13:30', '13:45', '14:00'];

// ---- The acceptance table of the design document -----------------------------------

it('27.11. with the keyword: lunch with tables, and exactly 17:00 in the evening', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD);

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe([...PEOPLE_LUNCH_ONLINE, '17:00']);

    $internal = peopleInternal(PEOPLE_FRIDAY);
    expect(array_keys($internal))->toBe([...PEOPLE_LUNCH_ONLINE, '14:15', '14:30', '14:45', '17:00'])
        ->and($internal['17:00']['ohne_tisch'])->toBeTrue()
        ->and($internal['17:00']['passt'])->toBeTrue()
        ->and($internal['17:00']['pax_max'])->toBe(30)
        ->and($internal['12:00']['ohne_tisch'])->toBeFalse();
});

it('27.11. without the keyword: online lunch only, phone intake still takes 17:00', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_PLAIN);

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe(PEOPLE_LUNCH_ONLINE);

    $internal = peopleInternal(PEOPLE_FRIDAY);
    expect(array_keys($internal))->toBe([...PEOPLE_LUNCH_ONLINE, '14:15', '14:30', '14:45', '17:00'])
        ->and($internal['17:00']['ohne_tisch'])->toBeTrue()
        ->and($internal['17:00']['passt'])->toBeTrue();
});

it('25./26.12. as written, without the keyword: nothing online, 11:00 and 13:00 by phone, up to 120 guests', function (string $date): void {
    peopleNote($date, PEOPLE_XMAS, '10:00:00', 360);

    expect(peopleOnline($date))->toBe([]);

    $internal = peopleInternal($date);
    expect(array_keys($internal))->toBe(['11:00', '13:00'])
        ->and($internal['11:00']['ohne_tisch'])->toBeTrue()
        ->and($internal['13:00']['pax_max'])->toBe(120)
        ->and($internal['13:00']['passt'])->toBeTrue();
})->with([PEOPLE_XMAS_1, PEOPLE_XMAS_2]);

it('25./26.12. with the keyword: online 11:00 and 13:00 only, the same as by phone', function (string $date): void {
    peopleNote($date, PEOPLE_XMAS_KEYWORD, '10:00:00', 360);

    expect(peopleOnline($date))->toBe(['11:00', '13:00'])
        ->and(array_keys(peopleInternal($date)))->toBe(['11:00', '13:00']);
})->with([PEOPLE_XMAS_1, PEOPLE_XMAS_2]);

// ---- One time, not a window --------------------------------------------------------

it('offers each of two stated times and nothing between them', function (): void {
    peopleNote(PEOPLE_FRIDAY, 'MÄRCHENABEND 17 UHR UND 19 UHR MAX 30 PAX. ONLINE BUCHBAR', '16:00:00', 300);

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe([...PEOPLE_LUNCH_ONLINE, '17:00', '19:00'])
        ->and(array_keys(peopleInternal(PEOPLE_FRIDAY)))->toBe([...PEOPLE_LUNCH_ONLINE, '14:15', '14:30', '14:45', '17:00', '19:00']);
});

it('replaces the lunch with a stated time that lies inside the opening hours', function (): void {
    // 13 Uhr is inside 11:30-15:00; the envelope 12:00-15:00 overlaps lunch, so the stated time replaces it.
    peopleNote(PEOPLE_FRIDAY, 'Sondermenü 13 Uhr MAX 20 PAX, online buchbar', '12:00:00', 180);

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe(['13:00']);
});

it('never offers a time that the note states outside its own window', function (): void {
    // "12 UHR" is a typo in an evening note.
    peopleNote(PEOPLE_FRIDAY, 'MÄRCHENABEND 12 UHR MAX 30 PAX. ONLINE BUCHBAR');

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe(PEOPLE_LUNCH_ONLINE)
        ->and(array_keys(peopleInternal(PEOPLE_FRIDAY)))->not->toContain('16:00', '17:00', '18:00');
});

it('does not read a time inside the guest text as a booking time', function (): void {
    peopleNote(PEOPLE_FRIDAY, 'MÄRCHENABEND 17 UHR MAX 30 PAX. ONLINE BUCHBAR: Menü ab 18 Uhr');

    expect(peopleOnline(PEOPLE_FRIDAY))->toBe([...PEOPLE_LUNCH_ONLINE, '17:00']);
});

// ---- People, not tables ------------------------------------------------------------

it('counts what is already booked at the event time against the cap, online and by phone', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD);
    peopleBooking(PEOPLE_FRIDAY, '17:00', 25);
    peopleSetting('apply_max_guests_online', true);

    // 25 of 30 taken: five fit, six do not.
    expect(peopleOnline(PEOPLE_FRIDAY, 5))->toContain('17:00')
        ->and(peopleOnline(PEOPLE_FRIDAY, 6))->not->toContain('17:00');

    $five = peopleInternal(PEOPLE_FRIDAY, 5)['17:00'];
    $six = peopleInternal(PEOPLE_FRIDAY, 6)['17:00'];
    expect($five['pax_belegt'])->toBe(25)
        ->and($five['passt'])->toBeTrue()
        ->and($six['passt'])->toBeFalse()
        ->and(Intake::maxPaxViolation(Carbon::parse(PEOPLE_FRIDAY), '17:00', 6))->not->toBeNull()
        ->and(Intake::maxPaxViolation(Carbon::parse(PEOPLE_FRIDAY), '17:00', 5))->toBeNull();
});

it('applies the cap at a time written as a bare 17:30 as well', function (): void {
    peopleNote(PEOPLE_FRIDAY, 'MÄRCHENABEND 17:30 MAX 30 PAX. ONLINE BUCHBAR');
    peopleBooking(PEOPLE_FRIDAY, '17:30', 28);

    expect(Intake::maxPaxViolation(Carbon::parse(PEOPLE_FRIDAY), '17:30', 3))->not->toBeNull()
        ->and(Intake::maxPaxViolation(Carbon::parse(PEOPLE_FRIDAY), '17:30', 2))->toBeNull();
});

it('lets a cap of its own for one time beat the general cap', function (): void {
    peopleNote(PEOPLE_FRIDAY, 'MÄRCHENABEND 17 UHR MAX 30 PAX, 19 UHR MAX 10 PAX. ONLINE BUCHBAR', '16:00:00', 300);
    peopleBooking(PEOPLE_FRIDAY, '19:00', 8);
    peopleSetting('apply_max_guests_online', true);

    $internal = peopleInternal(PEOPLE_FRIDAY, 3);
    expect($internal['17:00']['pax_max'])->toBe(30)
        ->and($internal['19:00']['pax_max'])->toBe(10)
        // 8 of 10 taken: three do not fit at 19:00, but do at 17:00.
        ->and($internal['19:00']['passt'])->toBeFalse()
        ->and($internal['17:00']['passt'])->toBeTrue()
        ->and(peopleOnline(PEOPLE_FRIDAY, 3))->toContain('17:00')->not->toContain('19:00');
});

it('lets the internal page take the event time that used to read "belegt"', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_PLAIN);

    $row = peopleInternal(PEOPLE_FRIDAY)['17:00'];

    // The note holds all eight tables: the table logic would say "no table free" (passt = false, ohne_tisch = false).
    expect($row['passt'])->toBeTrue()
        ->and($row['ohne_tisch'])->toBeTrue()
        ->and($row['frei'])->toBe(30);
});

it('assigns no table to a phone booking at an event time, even when tables are free', function (): void {
    // The note holds NO tables here: every table is free, so only a rule can keep the booking without one.
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD, holdTables: false);
    DB::table('reservations')->where('guest_num', 999)->update(['guest_num' => 999]);

    $reservation = Intake::create([
        'datum' => PEOPLE_FRIDAY, 'zeit' => '17:00', 'gaeste' => 4, 'nachname' => 'Telefon', 'telefon' => '123',
    ]);

    expect($reservation->fresh()->tables)->toHaveCount(0);
});

it('assigns no table to an online booking at an event time, even when tables are free', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD, holdTables: false);

    // The way the booking page saves: no 'tables' attribute at all, so every hook that assigns automatically is in play.
    $reservation = new Reservation([
        'location_id' => 1, 'guest_num' => 4, 'first_name' => 'Online', 'last_name' => 'Gast', 'email' => 'g@example.com',
        'telephone' => '123', 'reserve_date' => PEOPLE_FRIDAY, 'reserve_time' => '17:00:00', 'duration' => 120,
        'status_id' => 1,
    ]);
    $reservation->save();

    expect(DB::table('reservation_tables')->where('reservation_id', $reservation->getKey())->count())->toBe(0);
});

it('still assigns a table at lunch on the same day', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD, holdTables: false);

    $reservation = new Reservation([
        'location_id' => 1, 'guest_num' => 4, 'first_name' => 'Online', 'last_name' => 'Gast', 'email' => 'g@example.com',
        'telephone' => '123', 'reserve_date' => PEOPLE_FRIDAY, 'reserve_time' => '12:00:00', 'duration' => 120,
        'status_id' => 1,
    ]);
    $reservation->save();

    expect(DB::table('reservation_tables')->where('reservation_id', $reservation->getKey())->count())->toBe(1);
});

// ---- Notes without the keyword stay closed online ----------------------------------

it('keeps the whole envelope closed online for a note without the keyword, large parties included', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_PLAIN);

    expect(peopleOnline(PEOPLE_FRIDAY, 25))->not->toContain('17:00', '17:15', '18:00', '19:00');
});

it('does not let a large party into the evening beyond the stated time', function (): void {
    peopleNote(PEOPLE_FRIDAY, PEOPLE_MAERCHEN_KEYWORD);

    // Large parties are served by arrangement outside the opening hours, but the envelope 16:00-20:00 stays shut.
    $inEnvelope = array_values(array_filter(peopleOnline(PEOPLE_FRIDAY, 25), fn (string $t): bool => $t >= '16:00' && $t < '20:00'));
    expect($inEnvelope)->toBe(['17:00']);
});

// ---- An ordinary day is untouched --------------------------------------------------

it('shows an ordinary day without a note exactly as the table logic gives it', function (): void {
    $rows = peopleInternal(PEOPLE_FRIDAY);

    expect(array_keys($rows))->toBe([...PEOPLE_LUNCH_ONLINE, '14:15', '14:30', '14:45'])
        ->and(collect($rows)->pluck('ohne_tisch')->unique()->all())->toBe([false])
        ->and(collect($rows)->pluck('pax_max')->unique()->all())->toBe([null]);
});
