<?php

declare(strict_types=1);

use Wagnersnetz\ReservationControl\Models\Settings;

it('stores and reads a setting', function (): void {
    Settings::set('large_party_threshold', 25);
    expect(Settings::get('large_party_threshold'))->toBe(25);
});

it('returns the given default when nothing is stored', function (): void {
    expect(Settings::get('does_not_exist', 'fallback'))->toBe('fallback');
});
