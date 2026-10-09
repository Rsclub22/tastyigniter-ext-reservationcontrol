<?php

declare(strict_types=1);

use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Models\Location;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;

// Settings keeps instances in a static cache and Flame registers the model's
// fetch/save hooks once per process; see resetSettingsState() in Pest.php.
beforeEach(function (): void {
    resetSettingsState();
});
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
        ->and((string) $schedule->forDay('monday'))->toContain('10:00');
});

it('offers the large-party window only on open weekdays when the switch is off', function (): void {
    storeSetting('large_party_all_weekdays', false);
    $schedule = managerFor(25)->getSchedule([0, 5]);

    expect($schedule->isOpenOn('friday'))->toBeTrue()
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
