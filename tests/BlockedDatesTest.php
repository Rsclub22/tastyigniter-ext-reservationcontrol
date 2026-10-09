<?php

declare(strict_types=1);

use Igniter\System\Models\Settings as SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBookingController;

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
        '2030-12-24' => ['grund' => 'Betriebsferien', 'online' => false, 'hinweis' => ''],
        '2030-12-31' => ['grund' => 'Silvester', 'online' => true, 'hinweis' => ''],
    ]);
});

it('takes the optional online flag through the API and defaults it to off', function (): void {
    $controller = app(InternalApiController::class);

    $controller->block(Request::create('/', 'POST', ['datum' => '2030-12-24', 'grund' => 'Ferien']));
    $controller->block(Request::create('/', 'POST', ['datum' => '2030-12-31', 'grund' => 'Silvester', 'online' => true]));

    expect(array_keys(BlockedDates::asScheduleExceptions()))->toBe(['2030-12-24'])
        ->and(BlockedDates::isOnlineBookable('2030-12-31'))->toBeTrue();
});

// ---- hinweis: the text a guest sees ------------------------------------------------

it('stores the guest notice as the third key and reads it back', function (): void {
    BlockedDates::block('2030-12-31', 'Silvester', online: true, notice: 'Silvestermenü ab 18 Uhr');

    $stored = json_decode((string) SystemSettings::get('reservetweaks_blocked_dates', '', 'prefs'), true);

    expect($stored['2030-12-31'])->toBe(['grund' => 'Silvester', 'online' => true, 'hinweis' => 'Silvestermenü ab 18 Uhr'])
        ->and(BlockedDates::notice('2030-12-31'))->toBe('Silvestermenü ab 18 Uhr')
        ->and(BlockedDates::entries()['2030-12-31']['hinweis'])->toBe('Silvestermenü ab 18 Uhr')
        ->and(BlockedDates::all())->toBe(['2030-12-31' => 'Silvester']);
});

it('reads a missing, non-string or unreadable hinweis as an empty string without changing the block', function (string $json): void {
    storeRawBlockedDates($json);

    expect(BlockedDates::notice('2030-12-24'))->toBe('')
        ->and(BlockedDates::entries()['2030-12-24']['hinweis'])->toBe('')
        ->and(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []]);
})->with([
    'plain string (old format)' => ['{"2030-12-24":"Betriebsferien"}'],
    'new format without hinweis' => ['{"2030-12-24":{"grund":"Ferien","online":false}}'],
    'hinweis is a number' => ['{"2030-12-24":{"grund":"Ferien","online":false,"hinweis":5}}'],
    'hinweis is an array' => ['{"2030-12-24":{"grund":"Ferien","online":false,"hinweis":["x"]}}'],
    'entry is a number' => ['{"2030-12-24":42}'],
    'entry is null' => ['{"2030-12-24":null}'],
]);

it('keeps an old-format entry blocking after a hinweis day is stored beside it', function (): void {
    storeRawBlockedDates('{"2030-12-24":"Betriebsferien"}');

    BlockedDates::block('2030-12-31', 'Silvester', online: true, notice: 'Menü');

    expect(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []])
        ->and(BlockedDates::notice('2030-12-24'))->toBe('')
        ->and(BlockedDates::notice('2030-12-31'))->toBe('Menü');
});

it('takes the optional hinweis through the API and through the intern form', function (): void {
    app(InternalApiController::class)->block(Request::create('/', 'POST', [
        'datum' => '2030-12-31', 'grund' => 'Silvester', 'online' => true, 'hinweis' => '  Menü ab 18 Uhr ',
    ]));
    app(InternalApiController::class)->block(Request::create('/', 'POST', ['datum' => '2030-12-24', 'grund' => 'Ferien']));

    expect(BlockedDates::notice('2030-12-31'))->toBe('Menü ab 18 Uhr')
        ->and(BlockedDates::notice('2030-12-24'))->toBe('');

    app(InternalBookingController::class)->block(Request::create('/', 'POST', [
        'datum' => '2030-11-30', 'online' => '1', 'hinweis' => 'Märchenabend',
    ]));

    expect(BlockedDates::notice('2030-11-30'))->toBe('Märchenabend');
});

it('rejects a hinweis over 300 characters on the API', function (): void {
    expect(fn () => app(InternalApiController::class)->block(Request::create('/', 'POST', [
        'datum' => '2030-12-31', 'online' => true, 'hinweis' => str_repeat('x', 301),
    ])))->toThrow(ValidationException::class);
});

it('still blocks an entry whose online flag is unreadable, even with a hinweis', function (): void {
    storeRawBlockedDates('{"2030-12-24":{"grund":"Ferien","online":"true","hinweis":"X"}}');

    expect(BlockedDates::asScheduleExceptions())->toBe(['2030-12-24' => []])
        ->and(BlockedDates::isOnlineBookable('2030-12-24'))->toBeFalse();
});
