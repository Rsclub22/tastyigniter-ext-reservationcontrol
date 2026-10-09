<?php

declare(strict_types=1);

use Wagnersnetz\ReservationControl\Api\StandardIncludes;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\BookingContext;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\Console\EnterReservation;
use Wagnersnetz\ReservationControl\Console\ImportReservations;
use Wagnersnetz\ReservationControl\DailySheet;
use Wagnersnetz\ReservationControl\DayData;
use Wagnersnetz\ReservationControl\Entry\CreateReservation;
use Wagnersnetz\ReservationControl\Entry\Prompt;
use Wagnersnetz\ReservationControl\Entry\TableChoice;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBookingController;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\Intake;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\TableAllocator;

it('loads every ported class', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    Extension::class,
    Intake::class,
    BlockedDates::class,
    BookingContext::class,
    LargePartyBookingManager::class,
    Rooms::class,
    ClosureNotes::class,
    TableAllocator::class,
    DailySheet::class,
    DayData::class,
    StandardIncludes::class,
    EnterReservation::class,
    ImportReservations::class,
    CreateReservation::class,
    Prompt::class,
    TableChoice::class,
    InternalApiController::class,
    InternalBookingController::class,
    InternalNetworkOnly::class,
]);

it('keeps the data-bearing markers untouched for now', function (): void {
    expect(CreateReservation::MARKER)->toBe('reservetweaks-cli')
        ->and((new ReflectionClassConstant(BlockedDates::class, 'SETTING'))->getValue())->toBe('reservetweaks_blocked_dates');
});

it('registers the internal booking page at /intern', function (): void {
    expect(route('reservationcontrol.intern', absolute: false))->toBe('/intern');
});
