<?php

declare(strict_types=1);
use Wagnersnetz\ReservationControl\Annahme;
use Wagnersnetz\ReservationControl\Api\StandardIncludes;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\BookingContext;
use Wagnersnetz\ReservationControl\Console\ReservierungErfassen;
use Wagnersnetz\ReservationControl\Console\ReservierungImport;
use Wagnersnetz\ReservationControl\Erfassung\Anlegen;
use Wagnersnetz\ReservationControl\Erfassung\Eingabe;
use Wagnersnetz\ReservationControl\Erfassung\Tischwahl;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBooking;
use Wagnersnetz\ReservationControl\Http\Controllers\InternApi;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\Sperrvermerke;
use Wagnersnetz\ReservationControl\TableAllocator;
use Wagnersnetz\ReservationControl\Tagesblatt;
use Wagnersnetz\ReservationControl\Tagesdaten;

it('loads every ported class', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    Extension::class,
    Annahme::class,
    BlockedDates::class,
    BookingContext::class,
    LargePartyBookingManager::class,
    Rooms::class,
    Sperrvermerke::class,
    TableAllocator::class,
    Tagesblatt::class,
    Tagesdaten::class,
    StandardIncludes::class,
    ReservierungErfassen::class,
    ReservierungImport::class,
    Anlegen::class,
    Eingabe::class,
    Tischwahl::class,
    InternApi::class,
    InternalBooking::class,
    InternalNetworkOnly::class,
]);

it('keeps the data-bearing markers untouched for now', function (): void {
    expect(Anlegen::MARKER)->toBe('reservetweaks-cli')
        ->and((new ReflectionClassConstant(BlockedDates::class, 'SETTING'))->getValue())->toBe('reservetweaks_blocked_dates');
});

it('registers the internal booking page at /intern', function (): void {
    expect(route('reservationcontrol.intern', absolute: false))->toBe('/intern');
});
