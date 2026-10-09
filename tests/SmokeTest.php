<?php

declare(strict_types=1);
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\Erfassung\Anlegen;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBooking;
use Wagnersnetz\ReservationControl\Http\Controllers\InternApi;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\Sperrvermerke;
use Wagnersnetz\ReservationControl\TableAllocator;
use Wagnersnetz\ReservationControl\Tagesblatt;
use Wagnersnetz\ReservationControl\Tagesdaten;

it('loads every ported class', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    Sperrvermerke::class,
    BlockedDates::class,
    Tagesblatt::class,
    Tagesdaten::class,
    Rooms::class,
    TableAllocator::class,
    LargePartyBookingManager::class,
    InternApi::class,
    InternalBooking::class,
]);

it('keeps the data-bearing markers untouched for now', function (): void {
    expect(Anlegen::MARKER)->toBe('reservetweaks-cli')
        ->and((new ReflectionClassConstant(BlockedDates::class, 'SETTING'))->getValue())->toBe('reservetweaks_blocked_dates');
});

it('fails loudly when the legacy extension still owns the routes', function (): void {
    app('router')->get('intern', fn () => null)->name('reservetweaks.intern');

    $doppelt = collect(app('router')->getRoutes())
        ->filter(fn ($r): bool => $r->uri() === 'intern')
        ->count();

    expect($doppelt)->toBeGreaterThan(1);
})->note('Beide Erweiterungen gleichzeitig installiert: gleiche URL, zwei Routen. '
    .'Die Installationsanleitung muss das Abschalten der alten Fassung verlangen.');
