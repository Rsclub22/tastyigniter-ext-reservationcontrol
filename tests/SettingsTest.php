<?php

declare(strict_types=1);

use Wagnersnetz\ReservationControl\Models\Settings;

// SettingsModel keeps its instances in a static cache that survives the
// database rollback of the test transaction. Start every test without it.
beforeEach(function (): void {
    resetSettingsState();
});
afterEach(fn () => Settings::clearInternalCache());

it('stores and reads a setting', function (): void {
    Settings::set('large_party_threshold', 25);

    // Drop the in-memory copy, so the read has to come from the database.
    Settings::clearInternalCache();

    expect(Settings::get('large_party_threshold'))->toBe(25);
});

it('returns the given default when nothing is stored', function (): void {
    expect(Settings::get('does_not_exist', 'fallback'))->toBe('fallback');
});
