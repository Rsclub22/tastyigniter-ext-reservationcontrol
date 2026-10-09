<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Models\Location;
use Illuminate\Support\Facades\DB;
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

    return array_map(
        fn (string $dateTime): string => Carbon::parse($dateTime)->format('H:i'),
        $manager->isTimeslotsFullyBookedOn($slots, $date, $guests),
    );
}

/**
 * A table in another location (the seeded tables already make the house 160
 * seats, so the table check has no candidates and stays out of the way), eight
 * guests at 19:00, and a closure note at 23:00 - after closing, so it does not
 * claim the whole day.
 */
function noteWithCapAndEightGuests(string $noteText = 'Geschlossene Gesellschaft, max 10 PAX', bool $busyAtEight = false): void
{
    $areaId = DB::table('dining_areas')->insertGetId(['location_id' => 2, 'name' => 'Gastraum']);
    $tableId = DB::table('dining_tables')->insertGetId([
        'dining_area_id' => $areaId, 'name' => 'Lange Tafel', 'min_capacity' => 1,
        'max_capacity' => 30, 'is_combo' => 0, 'is_enabled' => 1,
    ]);

    $rows = [[8, '19:00:00', 'Meier'], [999, '23:00:00', 'Vermerk']];
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

it('closes the slots less than N hours before closing online, and only those', function (): void {
    limitSetting('cutoff_hours_before_closing', 2);

    // Closing is 22:00: 20:00 is exactly two hours before and stays bookable.
    expect(fullyBooked(limitManager(2), 2, ['19:30', '20:00', '20:30', '21:30']))->toBe(['20:30', '21:30']);
});

it('leaves large parties alone with the cut-off on', function (): void {
    limitSetting('cutoff_hours_before_closing', 2);

    expect(fullyBooked(limitManager(25), 25, ['20:30', '21:30']))->toBe([]);
});

it('does not apply the cut-off on the internal path', function (): void {
    limitSetting('cutoff_hours_before_closing', 2);

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
