<?php

declare(strict_types=1);

use Igniter\System\Models\Settings as SystemSettings;
use Illuminate\Http\Request;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;

/** Write the raw stored value, bypassing BlockedDates - the way a live installation holds it. */
function storeRawBlockedDates(string $json): void
{
    SystemSettings::set('reservetweaks_blocked_dates', $json, 'prefs');
}

it('keeps an old-format blocked day blocking', function (): void {
    // Exactly what the live installation has stored: date => plain reason.
    storeRawBlockedDates('{"2030-12-24":"Betriebsferien"}');

    expect(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []])
        ->and(BlockedDates::isOnlineBookable('2030-12-24'))->toBeFalse()
        ->and(BlockedDates::isBlocked('2030-12-24'))->toBeTrue()
        ->and(BlockedDates::all())->toBe(['2030-12-24' => 'Betriebsferien'])
        ->and(BlockedDates::upcoming())->toBe(['2030-12-24' => 'Betriebsferien']);
});

it('keeps old-format days blocking after another day is blocked', function (): void {
    storeRawBlockedDates('{"2030-12-24":"Betriebsferien"}');

    BlockedDates::block('2030-12-31', 'Silvester', online: true);

    expect(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []])
        ->and(BlockedDates::all())->toBe(['2030-12-24' => 'Betriebsferien', '2030-12-31' => 'Silvester']);
});

it('blocks a new-format day with online = false', function (): void {
    storeRawBlockedDates('{"2030-12-24":{"grund":"Betriebsferien","online":false}}');

    expect(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []]);
});

it('does not block a new-format day with online = true, but still lists it as special', function (): void {
    storeRawBlockedDates('{"2030-12-31":{"grund":"Silvesterabend","online":true}}');

    expect(BlockedDates::asScheduleExceptions())->toBe([])
        ->and(BlockedDates::isOnlineBookable('2030-12-31'))->toBeTrue()
        ->and(BlockedDates::isBlocked('2030-12-31'))->toBeTrue()
        ->and(BlockedDates::all())->toBe(['2030-12-31' => 'Silvesterabend'])
        ->and(BlockedDates::upcoming())->toBe(['2030-12-31' => 'Silvesterabend']);
});

it('stays closed for an entry it cannot read', function (): void {
    storeRawBlockedDates('{"2030-01-02":{"grund":"x","online":"yes"},"2030-01-03":null,"2030-01-04":{}}');

    expect(array_keys(BlockedDates::asScheduleExceptions()))->toBe(['2030-01-02', '2030-01-03', '2030-01-04']);
});

it('blocks by default and stores the new shape', function (): void {
    BlockedDates::block('2030-12-24', 'Betriebsferien');
    BlockedDates::block('2030-12-31', 'Silvester', online: true);

    $stored = json_decode((string) SystemSettings::get('reservetweaks_blocked_dates', '', 'prefs'), true);

    expect($stored)->toBe([
        '2030-12-24' => ['grund' => 'Betriebsferien', 'online' => false],
        '2030-12-31' => ['grund' => 'Silvester', 'online' => true],
    ]);
});

it('takes the optional online flag through the API and defaults it to off', function (): void {
    $controller = app(InternalApiController::class);

    $controller->block(Request::create('/', 'POST', ['datum' => '2030-12-24', 'grund' => 'Ferien']));
    $controller->block(Request::create('/', 'POST', ['datum' => '2030-12-31', 'grund' => 'Silvester', 'online' => true]));

    expect(array_keys(BlockedDates::asScheduleExceptions()))->toBe(['2030-12-24'])
        ->and(BlockedDates::isOnlineBookable('2030-12-31'))->toBeTrue();
});
