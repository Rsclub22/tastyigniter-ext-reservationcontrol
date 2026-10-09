<?php

declare(strict_types=1);

use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Models\Location;
use Illuminate\Support\Facades\DB;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;

afterEach(fn () => Settings::clearInternalCache());

/** Store through the real API (what the admin form calls) and re-read from the database. */
function storeSetting(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();

    Settings::clearInternalCache();
}

it('defaults to the previous hardcoded rules', function (): void {
    expect(Settings::query()->where('item', (new Settings)->settingsCode)->count())->toBe(0)
        ->and(LargePartyBookingManager::threshold())->toBe(20)
        ->and(LargePartyBookingManager::windowOpen())->toBe('10:00')
        ->and(LargePartyBookingManager::windowClose())->toBe('22:00')
        ->and(LargePartyBookingManager::allWeekdays())->toBeTrue()
        ->and(LargePartyBookingManager::skipTableCheck())->toBeTrue()
        ->and(LargePartyBookingManager::internalHorizonDays())->toBe(365);
});

it('honours a configured threshold', function (): void {
    storeSetting('large_party_threshold', 8);
    expect(LargePartyBookingManager::threshold())->toBe(8);
});

// Review Focus 1: fresh installation, nothing stored for this key
it('treats an unset threshold as the default, not as zero', function (): void {
    storeSetting('large_party_open', '11:00'); // a row exists, the threshold key does not
    expect(LargePartyBookingManager::threshold())->toBe(20);

    storeSetting('large_party_threshold', null);
    expect(LargePartyBookingManager::threshold())->toBe(20);

    storeSetting('large_party_threshold', '');
    expect(LargePartyBookingManager::threshold())->toBe(20);
});

// Review Focus 2: nonsense from the form
it('ignores a nonsensical threshold', function (): void {
    foreach ([-5, 0, 'abc'] as $bad) {
        storeSetting('large_party_threshold', $bad);
        expect(LargePartyBookingManager::threshold())->toBe(20);
    }
});

it('ignores a nonsensical window and keeps the defaults', function (): void {
    storeSetting('large_party_open', '25:00');
    expect(LargePartyBookingManager::windowOpen())->toBe('10:00')
        ->and(LargePartyBookingManager::windowClose())->toBe('22:00');

    // A bad closing time must be rejected on its own: 25:00 sorts after 10:00,
    // so only the format check, not the order check, can catch it.
    storeSetting('large_party_open', '10:00');
    storeSetting('large_party_close', '25:00');
    expect(LargePartyBookingManager::windowClose())->toBe('22:00');
});

it('falls back to the default window when it does not close after it opens', function (): void {
    storeSetting('large_party_open', '22:00');
    storeSetting('large_party_close', '10:00');

    expect(LargePartyBookingManager::windowOpen())->toBe('10:00')
        ->and(LargePartyBookingManager::windowClose())->toBe('22:00');
});

it('honours a valid window', function (): void {
    storeSetting('large_party_open', '12:00');
    storeSetting('large_party_close', '23:30');

    expect(LargePartyBookingManager::windowOpen())->toBe('12:00')
        ->and(LargePartyBookingManager::windowClose())->toBe('23:30');
});

it('turns the two switches off only when told to, and keeps them on when unset', function (): void {
    storeSetting('large_party_all_weekdays', false);
    storeSetting('large_party_skip_table_check', '0');
    expect(LargePartyBookingManager::allWeekdays())->toBeFalse()
        ->and(LargePartyBookingManager::skipTableCheck())->toBeFalse();

    storeSetting('large_party_all_weekdays', null);
    storeSetting('large_party_skip_table_check', null);
    expect(LargePartyBookingManager::allWeekdays())->toBeTrue()
        ->and(LargePartyBookingManager::skipTableCheck())->toBeTrue();
});

it('reads the phone booking horizon and falls back to 365 days', function (): void {
    expect(LargePartyBookingManager::internalHorizonDays())->toBe(365);

    storeSetting('internal_booking_horizon_days', 90);
    expect(LargePartyBookingManager::internalHorizonDays())->toBe(90);

    storeSetting('internal_booking_horizon_days', 0);
    expect(LargePartyBookingManager::internalHorizonDays())->toBe(365);
});

/** A manager for a location that is open Monday to Friday only, for a party of the given size. */
function managerFor(int $guests): LargePartyBookingManager
{
    $location = Mockery::mock(Location::class)->makePartial();
    $location->shouldReceive('newWorkingSchedule')->andReturnUsing(function () {
        $open = [['12:00', '14:00']];
        $schedule = WorkingSchedule::create([0, 5], [
            'monday' => $open, 'tuesday' => $open, 'wednesday' => $open, 'thursday' => $open,
            'friday' => $open, 'saturday' => [], 'sunday' => [],
        ]);
        $schedule->setType('opening');

        return $schedule;
    });

    $manager = new LargePartyBookingManager;
    $manager->useLocation($location);

    return $manager->forceGuestCount($guests);
}

it('offers the large-party window on all seven weekdays by default', function (): void {
    $schedule = managerFor(25)->getSchedule([0, 5]);

    expect($schedule->isOpenOn('saturday'))->toBeTrue()
        ->and($schedule->isOpenOn('sunday'))->toBeTrue()
        ->and((string) $schedule->forDay('monday'))->toContain('10:00')->toContain('22:00')->not->toContain('12:00')
        ->and((string) $schedule->forDay('sunday'))->toContain('10:00')->toContain('22:00');
});

it('offers the large-party window only on open weekdays when the switch is off', function (): void {
    storeSetting('large_party_all_weekdays', false);
    $schedule = managerFor(25)->getSchedule([0, 5]);

    // Open days offer the large-party window, not the ordinary 12:00-14:00.
    expect((string) $schedule->forDay('friday'))->toContain('10:00')->toContain('22:00')->not->toContain('12:00')
        ->and($schedule->isClosedOn('saturday'))->toBeTrue()
        ->and($schedule->isClosedOn('sunday'))->toBeTrue();
});

it('leaves the ordinary opening hours alone for a small party', function (): void {
    $schedule = managerFor(2)->getSchedule([0, 5]);

    expect($schedule->isClosedOn('saturday'))->toBeTrue()
        ->and((string) $schedule->forDay('monday'))->toContain('12:00');
});

it('uses the configured threshold to decide who is a large party', function (): void {
    storeSetting('large_party_threshold', 8);

    expect(managerFor(8)->isLargeParty())->toBeTrue()
        ->and(managerFor(7)->isLargeParty())->toBeFalse();
});

/**
 * One table that seats 30 and one reservation on it from 18:00 to 20:00, so a party
 * of 25 fits that table in principle (otherwise the check returns early either way).
 */
function bookTheOnlyTable(): void
{
    $areaId = DB::table('dining_areas')->insertGetId(['location_id' => 1, 'name' => 'Gastraum']);
    $tableId = DB::table('dining_tables')->insertGetId([
        'dining_area_id' => $areaId, 'name' => 'Lange Tafel', 'min_capacity' => 1,
        'max_capacity' => 30, 'is_combo' => 0, 'is_enabled' => 1,
    ]);
    $reservationId = DB::table('reservations')->insertGetId([
        'location_id' => 1, 'table_id' => $tableId, 'guest_num' => 4, 'first_name' => 'A', 'last_name' => 'B',
        'email' => 'a@example.com', 'reserve_date' => '2030-06-10', 'reserve_time' => '18:00:00',
        'reserve_datetime' => '2030-06-10 18:00:00', 'duration' => 120, 'status_id' => 1,
        'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('reservation_tables')->insert([
        'reservation_id' => $reservationId, 'dining_table_id' => $tableId, 'table_id' => $tableId,
    ]);
}

/** @return array<int, string> the slots reported as taken for a party of 25 on the fixture day */
function takenSlotsForLargeParty(): array
{
    $date = Carbon\Carbon::parse('2030-06-10');
    $slots = collect(['18:30', '21:00'])->map(fn (string $t) => $date->copy()->setTimeFromTimeString($t));

    $manager = managerFor(25);
    $location = (new ReflectionProperty($manager, 'location'))->getValue($manager);
    $location->location_id = 1;
    $location->shouldReceive('getReservationStayTime')->andReturn(120);

    return $manager->isTimeslotsFullyBookedOn($slots, $date, 25);
}

it('skips the table check for large parties by default', function (): void {
    bookTheOnlyTable();

    expect(takenSlotsForLargeParty())->toBe([]);
});

it('runs the table check for large parties when the switch is off', function (): void {
    bookTheOnlyTable();
    storeSetting('large_party_skip_table_check', false);

    // 18:30 overlaps the booking on the only table that seats 25; 21:00 does not.
    expect(takenSlotsForLargeParty())->toBe(['2030-06-10 18:30:00']);
});
