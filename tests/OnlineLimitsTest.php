<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Facades\Location as LocationFacade;
use Igniter\Local\Models\Location;
use Igniter\Orange\Livewire\Booking;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Wagnersnetz\ReservationControl\BookingContext;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;

// 2030-06-10 is a Monday. The house is open 11:00-22:00 that day.
const LIMITS_DAY = '2030-06-10';

beforeEach(function (): void {
    // ClosureNotes caches per request; the testbench runs many "requests" in one process.
    foreach (['openingHours' => [1 => ['11:00', '22:00']], 'houseCapacity' => null] as $property => $value) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, $value);
    }
});

afterEach(function (): void {
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
    Settings::clearInternalCache();
});

function limitSetting(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();
    Settings::clearInternalCache();
}

/** A manager for $guests; $internal = true is the phone intake. */
function limitManager(int $guests, bool $internal = false): LargePartyBookingManager
{
    $location = Mockery::mock(Location::class)->makePartial();
    $location->shouldReceive('newWorkingSchedule')->andReturnUsing(function () {
        $schedule = WorkingSchedule::create([0, 5], ['monday' => [['11:00', '22:00']]]);
        $schedule->setType('opening');

        return $schedule;
    });
    $location->location_id = 1;
    $location->shouldReceive('getReservationStayTime')->andReturn(120);

    $manager = new LargePartyBookingManager;
    $manager->useLocation($location);

    return $manager->forceGuestCount($guests)->allowSameDay($internal);
}

/** @return array<int, string> the times (HH:MM) reported as fully booked */
function fullyBooked(LargePartyBookingManager $manager, int $guests, array $times): array
{
    $date = Carbon::parse(LIMITS_DAY);
    $slots = collect($times)->map(fn (string $t) => $date->copy()->setTimeFromTimeString($t));

    // Every blocked slot comes back in two notations (see isTimeslotsFullyBookedOn); one time each here.
    return array_values(array_unique(array_map(
        fn (string $dateTime): string => Carbon::parse($dateTime)->format('H:i'),
        $manager->isTimeslotsFullyBookedOn($slots, $date, $guests),
    )));
}

/**
 * A table in another location (the seeded tables already make the house 160
 * seats, so the table check has no candidates and stays out of the way), eight
 * guests at 19:00, and a closure note at 23:00 - after closing, so it does not
 * claim the whole day.
 */
function noteWithCapAndEightGuests(string $noteText = 'Geschlossene Gesellschaft, max 10 PAX', bool $busyAtEight = false, string $noteTime = '23:00:00'): void
{
    $areaId = DB::table('dining_areas')->insertGetId(['location_id' => 2, 'name' => 'Gastraum']);
    $tableId = DB::table('dining_tables')->insertGetId([
        'dining_area_id' => $areaId, 'name' => 'Lange Tafel', 'min_capacity' => 1,
        'max_capacity' => 30, 'is_combo' => 0, 'is_enabled' => 1,
    ]);

    $rows = [[8, '19:00:00', 'Meier'], [999, $noteTime, 'Vermerk']];
    if ($busyAtEight) {
        $rows[] = [20, '20:00:00', 'Voll'];
    }

    foreach ($rows as [$guests, $time, $name]) {
        DB::table('reservations')->insert([
            'location_id' => 1, 'table_id' => $tableId, 'guest_num' => $guests, 'first_name' => 'A', 'last_name' => $name,
            'email' => 'a@example.com', 'reserve_date' => LIMITS_DAY, 'reserve_time' => $time,
            'reserve_datetime' => LIMITS_DAY.' '.$time, 'duration' => 120, 'status_id' => 1,
            'comment' => $guests === 999 ? $noteText : '',
            'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

// ---- Feature 1: cut-off before closing ---------------------------------------------

it('changes nothing at the default cut-off of 0', function (): void {
    expect(fullyBooked(limitManager(2), 2, ['19:30', '21:00', '21:30']))->toBe([]);
});

it('closes the slots less than N minutes before closing online, and only those', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    // Closing is 22:00: 20:00 is exactly 120 minutes before and stays bookable.
    expect(fullyBooked(limitManager(2), 2, ['19:30', '20:00', '20:30', '21:30']))->toBe(['20:30', '21:30']);
});

it('works in minutes: a kitchen closing 30 minutes early keeps the exact boundary bookable', function (): void {
    limitSetting('cutoff_minutes_before_closing', 30);

    // Closing is 22:00: 21:30 is exactly 30 minutes before and stays bookable.
    expect(fullyBooked(limitManager(2), 2, ['21:00', '21:30', '21:45']))->toBe(['21:45']);
});

it('ignores the old hours key: a stored hours value must not be read as minutes', function (): void {
    // 30 rather than the live 1: with 1 minute no half-hourly slot is affected, so a regression would go unseen.
    limitSetting('cutoff_hours_before_closing', 30);

    expect(fullyBooked(limitManager(2), 2, ['20:30', '21:30', '21:45']))->toBe([]);
});

it('accepts a cut-off of one day and treats anything larger as unset', function (): void {
    limitSetting('cutoff_minutes_before_closing', 1440);
    expect(fullyBooked(limitManager(2), 2, ['11:00', '21:30']))->toBe(['11:00', '21:30']);

    limitSetting('cutoff_minutes_before_closing', 1441);
    expect(fullyBooked(limitManager(2), 2, ['11:00', '21:30']))->toBe([]);
});

it('leaves large parties alone with the cut-off on', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(fullyBooked(limitManager(25), 25, ['20:30', '21:30']))->toBe([]);
});

it('does not apply the cut-off on the internal path', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(fullyBooked(limitManager(2, internal: true), 2, ['20:30', '21:30']))->toBe([]);
});

// ---- Feature 2: guest cap online ---------------------------------------------------

it('ignores the guest cap while the setting is off', function (): void {
    noteWithCapAndEightGuests();

    expect(fullyBooked(limitManager(5), 5, ['19:00', '20:00']))->toBe([]);
});

it('applies the guest cap online, with occupancy plus request equal to the cap still fitting', function (): void {
    noteWithCapAndEightGuests();
    limitSetting('apply_max_guests_online', true);

    // 8 already at 19:00, cap 10: two more fit exactly, three do not.
    expect(fullyBooked(limitManager(2), 2, ['19:00', '20:00']))->toBe([])
        ->and(fullyBooked(limitManager(3), 3, ['19:00', '20:00']))->toBe(['19:00']);
});

it('prefers the cap named for one time over the general one', function (): void {
    // The general figure is the smallest one named (9); 20:00 has its own, larger, cap of 30.
    noteWithCapAndEightGuests('Weihnachten 19 Uhr max 9 PAX, 20 Uhr max 30 PAX', busyAtEight: true);
    limitSetting('apply_max_guests_online', true);

    // 19:00: 8 + 2 > 9 is full. 20:00: 20 + 2 <= 30 still fits - the general 9 must not win there.
    expect(fullyBooked(limitManager(2), 2, ['19:00', '20:00']))->toBe(['19:00']);
});

it('keeps the guest cap a hint on the internal path and for large parties', function (): void {
    noteWithCapAndEightGuests();
    limitSetting('apply_max_guests_online', true);

    expect(fullyBooked(limitManager(5, internal: true), 5, ['19:00']))->toBe([])
        ->and(fullyBooked(limitManager(25), 25, ['19:00']))->toBe([]);
});

// ---- Feature 4: a closure note opting back in --------------------------------------

it('opens a note window online only for an unnegated "online buchbar"', function (string $text, bool $open): void {
    $note = new Reservation(['comment' => $text]);

    expect(ClosureNotes::isOnlineOpen($note))->toBe($open);
})->with([
    'plain keyword' => ['Märchenabend, online buchbar', true],
    'keyword and a cap' => ['Märchenabend, online buchbar, max 40 PAX', true],
    'negation of something else, other clause' => ['Nicht ganz voll, online buchbar', true],
    'nicht before' => ['Märchenabend, nicht online buchbar', false],
    'nicht mehr before' => ['Märchenabend nicht mehr online buchbar', false],
    'negation after online' => ['Märchenabend, online nicht mehr buchbar', false],
    'keine online' => ['Märchenabend, keine online Buchung, online buchbar', false],
    'together with ganztägig' => ['Ganztägig Märchenabend, online buchbar', false],
    'one negated occurrence among several' => ['online buchbar. Abends nicht online buchbar', false],
    'no keyword' => ['Märchenabend', false],
]);

it('keeps the window of an opted-in note out of the taken slots, and keeps it taken without the keyword', function (): void {
    noteWithCapAndEightGuests('Märchenabend, online buchbar');
    expect(fullyBooked(limitManager(2), 2, ['23:00']))->toBe([]);

    DB::table('reservations')->where('guest_num', 999)->update(['comment' => 'Märchenabend']);
    expect(fullyBooked(limitManager(2), 2, ['23:00']))->toBe(['23:00']);
});

it('lets an opted-in note inside the opening hours leave the day bookable, but not a plain one', function (): void {
    noteWithCapAndEightGuests('Märchenabend, online buchbar', noteTime: '18:00:00');
    expect(fullyBooked(limitManager(2), 2, ['12:00', '20:30']))->toBe([]);

    DB::table('reservations')->where('guest_num', 999)->update(['comment' => 'Märchenabend']);
    expect(fullyBooked(limitManager(2), 2, ['12:00', '20:30']))->toBe(['12:00', '20:30']);
});

it('still applies the guest cap of an opted-in note online', function (): void {
    noteWithCapAndEightGuests('Märchenabend, online buchbar, max 10 PAX', noteTime: '18:00:00');
    limitSetting('apply_max_guests_online', true);

    expect(fullyBooked(limitManager(3), 3, ['19:00', '20:00']))->toBe(['19:00']);
});

it('keeps a note that says "nicht online buchbar" blocking its window', function (): void {
    noteWithCapAndEightGuests('Märchenabend, nicht online buchbar');

    expect(fullyBooked(limitManager(2), 2, ['23:00']))->toBe(['23:00']);
});

// ---- Blocked slots: both notations, and the validator ------------------------------

/** @return array<int, string> exactly what the manager returns for these slots */
function rawBlocked(LargePartyBookingManager $manager, int $guests, array $times): array
{
    $date = Carbon::parse(LIMITS_DAY);
    $slots = collect($times)->map(fn (string $t) => $date->copy()->setTimeFromTimeString($t));

    return $manager->isTimeslotsFullyBookedOn($slots, $date, $guests);
}

/** How the Orange theme looks a slot up (Livewire/Booking.php, reducedTimeslots()). */
function themeSeesBlocked(array $result, string $time): bool
{
    return in_array(Carbon::parse(LIMITS_DAY.' '.$time)->format('Y-m-d H:i'), $result);
}

it('lets the theme find a blocked slot by its Y-m-d H:i lookup', function (): void {
    // The defect: the manager returned only 'Y-m-d H:i:s', the theme tests without seconds, nothing matched.
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(themeSeesBlocked(rawBlocked(limitManager(2), 2, ['21:30']), '21:30'))->toBeTrue();
});

it('returns a cut-off slot in both notations', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(rawBlocked(limitManager(2), 2, ['21:30']))->toBe([LIMITS_DAY.' 21:30:00', LIMITS_DAY.' 21:30']);
});

it('returns a cap slot in both notations', function (): void {
    noteWithCapAndEightGuests();
    limitSetting('apply_max_guests_online', true);

    expect(rawBlocked(limitManager(3), 3, ['19:00']))->toBe([LIMITS_DAY.' 19:00:00', LIMITS_DAY.' 19:00']);
});

it('returns a closure-note window slot in both notations', function (): void {
    noteWithCapAndEightGuests('Märchenabend');

    expect(rawBlocked(limitManager(2), 2, ['23:00']))->toBe([LIMITS_DAY.' 23:00:00', LIMITS_DAY.' 23:00']);
});

it('returns every slot of an all-day note in both notations', function (): void {
    noteWithCapAndEightGuests('Ganztägig geschlossen');

    expect(rawBlocked(limitManager(2), 2, ['12:00', '19:00']))->toBe([
        LIMITS_DAY.' 12:00:00', LIMITS_DAY.' 12:00', LIMITS_DAY.' 19:00:00', LIMITS_DAY.' 19:00',
    ]);
});

it('returns a free slot in neither notation', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    $result = rawBlocked(limitManager(2), 2, ['19:00', '21:30']);

    expect($result)->not->toContain(LIMITS_DAY.' 19:00:00')
        ->and($result)->not->toContain(LIMITS_DAY.' 19:00');
});

/** Runs the public booking form's validator with a running component asking for $time. */
function bookingErrors(string $time, ?LargePartyBookingManager $manager = null, bool $withLocation = true, int $guests = 2): MessageBag
{
    $manager ??= limitManager($guests);
    $location = (new ReflectionProperty($manager, 'location'))->getValue($manager);
    $location->shouldReceive('getSettings')->andReturn(1);
    app()->instance(BookingManager::class, $manager);
    LocationFacade::shouldReceive('current')->andReturn($withLocation ? $location : null);

    $component = (new ReflectionClass(Booking::class))->newInstanceWithoutConstructor();
    $component->guest = $guests;
    $component->date = LIMITS_DAY;
    $component->time = $time;
    BookingContext::remember($component);

    try {
        $validator = Validator::make(
            ['firstName' => 'Anna', 'lastName' => 'Test', 'telephone' => '+49 123 456789'],
            ['firstName' => 'required', 'lastName' => 'required', 'telephone' => 'nullable'],
        );
        $validator->fails();

        return $validator->errors();
    } finally {
        BookingContext::forget($component);
    }
}

it('rejects a submitted time inside the cut-off', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);
    app()->setLocale('de');

    $errors = bookingErrors('21:30');

    expect($errors->has('time'))->toBeTrue()
        ->and($errors->first('time'))->toContain('Online-Reservierung');
});

it('accepts a normal time', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(bookingErrors('19:00')->isEmpty())->toBeTrue();
});

it('lets the booking through when no location can be found', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);

    expect(bookingErrors('21:30', withLocation: false)->isEmpty())->toBeTrue();
});

it('lets the booking through, and logs, when the check throws', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);
    Log::shouldReceive('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'booking let through'),
    );

    $broken = new class extends LargePartyBookingManager
    {
        public function isTimeslotsFullyBookedOn(Collection $timeslots, Carbon $date, ?int $noOfGuest = null): array
        {
            throw new RuntimeException('schedule exploded');
        }
    };
    $location = (new ReflectionProperty(limitManager(2), 'location'))->getValue(limitManager(2));
    $broken->useLocation($location);

    expect(bookingErrors('21:30', $broken)->isEmpty())->toBeTrue();
});

it('lets the booking through when the form has no running component', function (): void {
    limitSetting('cutoff_minutes_before_closing', 120);
    $manager = limitManager(2);
    app()->instance(BookingManager::class, $manager);

    $validator = Validator::make(
        ['firstName' => 'Anna', 'lastName' => 'Test', 'telephone' => '+49 123 456789'],
        ['firstName' => 'required', 'lastName' => 'required', 'telephone' => 'nullable'],
    );

    expect($validator->fails())->toBeFalse();
});

// ---- The online list ends at the cut-off -------------------------------------------

/** A manager whose location opens Mondays 11:00 to $close, 15-minute slots. */
function trimManager(int $guests, string $close = '15:00', bool $internal = false): LargePartyBookingManager
{
    (new ReflectionProperty(ClosureNotes::class, 'openingHours'))->setValue(null, [1 => ['11:00', $close]]);

    $location = Mockery::mock(Location::class)->makePartial();
    $location->shouldReceive('newWorkingSchedule')->andReturnUsing(function () use ($close) {
        $schedule = WorkingSchedule::create([0, 3000], ['monday' => [['11:00', $close]]]);
        $schedule->setType('opening');

        return $schedule;
    });
    $location->location_id = 1;
    $location->shouldReceive('getReservationStayTime')->andReturn(120);
    $location->shouldReceive('getReservationInterval')->andReturn(15);
    $location->shouldReceive('getReservationLeadTime')->andReturn(0);
    $location->shouldReceive('getSettings')->andReturn(1);
    $location->shouldReceive('getMinReservationAdvanceTime')->andReturn(0);
    $location->shouldReceive('getMaxReservationAdvanceTime')->andReturn(3000);

    $manager = new LargePartyBookingManager;
    $manager->useLocation($location);

    return $manager->forceGuestCount($guests)->allowSameDay($internal);
}

/** @return array<int, string> the offered times (H:i) on the fixture day */
function offeredTimes(LargePartyBookingManager $manager): array
{
    return collect($manager->makeTimeSlots(Carbon::parse(LIMITS_DAY)))
        ->map(fn ($slot): string => $slot->format('H:i'))->values()->all();
}

it('ends the online list at the cut-off and keeps the boundary slot', function (int $minutes, string $last): void {
    limitSetting('cutoff_minutes_before_closing', $minutes);

    $times = offeredTimes(trimManager(2));

    expect(end($times))->toBe($last)->and($times)->toContain($last);
})->with([
    '60 minutes before a 15:00 close' => [60, '14:00'],
    '75 minutes' => [75, '13:45'],
]);

it('leaves the list unchanged with the cut-off at 0', function (): void {
    limitSetting('cutoff_minutes_before_closing', 0);

    expect(offeredTimes(trimManager(2)))->toBe(offeredTimes(trimManager(2, internal: true)))
        ->and(offeredTimes(trimManager(2)))->toContain('14:45');
});

it('keeps every slot on the internal path and for large parties', function (): void {
    limitSetting('cutoff_minutes_before_closing', 60);

    expect(offeredTimes(trimManager(2, internal: true)))->toContain('14:45')
        ->and(offeredTimes(trimManager(25)))->toContain('14:45');
});

it('trims only the cut-off: cap and note slots stay in the list, reported as fully booked', function (): void {
    noteWithCapAndEightGuests();
    limitSetting('apply_max_guests_online', true);
    limitSetting('cutoff_minutes_before_closing', 60);

    $manager = trimManager(3, '22:00');
    $times = offeredTimes($manager);

    expect($times)->toContain('19:00')->and(end($times))->toBe('21:00')
        ->and(fullyBooked($manager, 3, ['19:00']))->toBe(['19:00']);
});

it('keeps the slots of a plain closure note in the list, reported as fully booked', function (): void {
    noteWithCapAndEightGuests('Märchenabend', noteTime: '18:00:00');

    $manager = trimManager(2, '22:00');

    expect(offeredTimes($manager))->toContain('12:00')
        ->and(fullyBooked($manager, 2, ['12:00']))->toBe(['12:00']);
});
