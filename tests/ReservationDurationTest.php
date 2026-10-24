<?php

declare(strict_types=1);

use Igniter\Api\ApiResources\Transformers\ReservationTransformer;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;

// The core lists 'duration' in $appends. Laravel serialises an appended attribute by calling its
// getter with null, and the core's getter turns null into the location's stay time - so every
// reservation left the API with the default (90 at the house) whatever it was saved with, and the
// app wrote that 90 back on its next save. Found at the counter on 2026-10-24.

beforeEach(function (): void {
    Location::query()->first()->settings()->updateOrCreate(['item' => 'booking'], ['data' => [
        'time_interval' => 15, 'stay_time' => 90, 'include_start_time' => 1,
    ]]);
});

function durationReservation(?int $duration): Reservation
{
    $reservation = new Reservation([
        'location_id' => 1, 'guest_num' => 10, 'first_name' => 'Lang', 'last_name' => 'Gast', 'email' => 'g@example.com',
        'telephone' => '123', 'reserve_date' => '2030-11-07', 'reserve_time' => '12:00:00', 'status_id' => 1,
    ]);
    $reservation->duration = $duration;
    $reservation->save();

    return Reservation::query()->findOrFail($reservation->getKey());
}

it('serialises the stored duration instead of the location default', function (): void {
    $reservation = durationReservation(240);

    expect($reservation->toArray()['duration'])->toBe(240)
        ->and((new ReservationTransformer)->transform($reservation)['duration'])->toBe(240);
});

it('still serialises the location default when none was given', function (): void {
    expect(durationReservation(null)->toArray()['duration'])->toBe(90);
});

it('keeps the other appended attributes', function (): void {
    expect(durationReservation(60)->toArray())->toHaveKeys(['customer_name', 'table_name', 'reservation_datetime', 'reservation_end_datetime']);
});
