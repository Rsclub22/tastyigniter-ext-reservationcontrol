<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Models\Location;
use Illuminate\Support\Facades\DB;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;

// 2030-06-10 is a Monday. Lunch 11:00-15:00; the note's envelope is 16:00-20:00.
const EVENT_TABLE_DAY = '2030-06-10';

beforeEach(function (): void {
    foreach (['openingHours' => [1 => ['11:00', '15:00']], 'houseCapacity' => null] as $property => $value) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, $value);
    }
});

afterEach(function (): void {
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
    Settings::clearInternalCache();
});

function eventSetting(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();
    Settings::clearInternalCache();
}

/** A closure note 16:00-20:00 that holds every table of the house, as the live ones do. */
function eventNoteHoldingAllTables(string $comment): void
{
    $id = DB::table('reservations')->insertGetId([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => EVENT_TABLE_DAY, 'reserve_time' => '16:00:00',
        'reserve_datetime' => EVENT_TABLE_DAY.' 16:00:00', 'duration' => 240, 'status_id' => 1,
        'comment' => $comment, 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (DB::table('dining_tables')->pluck('id') as $tableId) {
        DB::table('reservation_tables')->insert(['reservation_id' => $id, 'dining_table_id' => $tableId, 'table_id' => $tableId]);
    }
}

/** @return array<int, string> the times (HH:MM) reported as blocked for $guests */
function eventBlocked(array $times, int $guests = 2): array
{
    $location = Mockery::mock(Location::class)->makePartial();
    $location->shouldReceive('newWorkingSchedule')->andReturnUsing(fn () => tap(WorkingSchedule::create([0, 5], []), fn ($s) => $s->setType('opening')));
    $location->location_id = 1;
    $location->shouldReceive('getReservationStayTime')->andReturn(120);

    $manager = (new LargePartyBookingManager)->forceGuestCount($guests);
    $manager->useLocation($location);

    $date = Carbon::parse(EVENT_TABLE_DAY);
    $slots = collect($times)->map(fn (string $t) => $date->copy()->setTimeFromTimeString($t));

    return collect($manager->isTimeslotsFullyBookedOn($slots, $date, $guests))
        ->map(fn (string $dt): string => Carbon::parse($dt)->format('H:i'))
        ->unique()->sort()->values()->all();
}

it('books the event time although the note holds every table, and blocks the rest of the envelope', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr, online buchbar');

    // 12:00 lunch, 16:30 envelope before the event, 17:00 the event, 17:15 and 18:30 after it.
    expect(eventBlocked(['12:00', '16:30', '17:00', '17:15', '18:30']))->toBe(['16:30', '17:15', '18:30']);
});

it('does not tie the event slot to the large-party table switch', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr, online buchbar');
    eventSetting('large_party_skip_table_check', false);

    expect(eventBlocked(['12:00', '16:30', '17:00', '18:30']))->toBe(['16:30', '18:30']);
});

it('changes nothing for a note without the opt-in: the whole envelope stays blocked', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr');

    expect(eventBlocked(['12:00', '16:30', '17:00', '18:30']))->toBe(['16:30', '17:00', '18:30']);
});

it('still applies the guest cap of the note inside the event window', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr, online buchbar, max 10 PAX');
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 8, 'first_name' => 'A', 'last_name' => 'Meier',
        'email' => 'a@example.com', 'reserve_date' => EVENT_TABLE_DAY, 'reserve_time' => '17:00:00',
        'reserve_datetime' => EVENT_TABLE_DAY.' 17:00:00', 'duration' => 120, 'status_id' => 1,
        'comment' => '', 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
    eventSetting('apply_max_guests_online', true);

    // 8 already at 17:00, cap 10: two fit, three do not.
    expect(eventBlocked(['17:00'], 2))->toBe([])
        ->and(eventBlocked(['17:00'], 3))->toBe(['17:00']);
});

it('leaves the event time out of the cut-off, which still trims the lunch', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr, online buchbar');
    eventSetting('cutoff_minutes_before_closing', 120);

    // Lunch closes 15:00, cut-off 13:00: 14:00 is blocked. 17:00 is the event time and is not cut off;
    // 18:00 is no event time and the envelope blocks it.
    expect(eventBlocked(['12:00', '14:00', '17:00', '18:00']))->toBe(['14:00', '18:00']);
});

it('keeps the table check on after the event time', function (): void {
    eventNoteHoldingAllTables('Märchenabend 17 Uhr, online buchbar');
    $id = DB::table('reservations')->insertGetId([
        'location_id' => 1, 'guest_num' => 20, 'first_name' => 'A', 'last_name' => 'Voll',
        'email' => 'a@example.com', 'reserve_date' => EVENT_TABLE_DAY, 'reserve_time' => '20:00:00',
        'reserve_datetime' => EVENT_TABLE_DAY.' 20:00:00', 'duration' => 120, 'status_id' => 1,
        'comment' => '', 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (DB::table('dining_tables')->pluck('id') as $tableId) {
        DB::table('reservation_tables')->insert(['reservation_id' => $id, 'dining_table_id' => $tableId, 'table_id' => $tableId]);
    }

    // 20:30 lies after the envelope (ends 20:00) and every table is taken then.
    expect(eventBlocked(['17:00', '20:30']))->toBe(['20:30']);
});
