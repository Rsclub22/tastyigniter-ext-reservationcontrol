<?php

declare(strict_types=1);

it('loads every ported class', function(string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    \Wagnersnetz\ReservationControl\Sperrvermerke::class,
    \Wagnersnetz\ReservationControl\BlockedDates::class,
    \Wagnersnetz\ReservationControl\Tagesblatt::class,
    \Wagnersnetz\ReservationControl\Tagesdaten::class,
    \Wagnersnetz\ReservationControl\Rooms::class,
    \Wagnersnetz\ReservationControl\TableAllocator::class,
    \Wagnersnetz\ReservationControl\LargePartyBookingManager::class,
    \Wagnersnetz\ReservationControl\Http\Controllers\InternApi::class,
    \Wagnersnetz\ReservationControl\Http\Controllers\InternalBooking::class,
]);

it('keeps the data-bearing markers untouched for now', function(): void {
    expect(\Wagnersnetz\ReservationControl\Erfassung\Anlegen::MARKER)->toBe('reservetweaks-cli')
        ->and((new ReflectionClassConstant(\Wagnersnetz\ReservationControl\BlockedDates::class, 'SETTING'))->getValue())->toBe('reservetweaks_blocked_dates');
});

it('fails loudly when the legacy extension still owns the routes', function(): void {
    app('router')->get('intern', fn() => null)->name('reservetweaks.intern');

    $doppelt = collect(app('router')->getRoutes())
        ->filter(fn($r): bool => $r->uri() === 'intern')
        ->count();

    expect($doppelt)->toBeGreaterThan(1);
})->note('Beide Erweiterungen gleichzeitig installiert: gleiche URL, zwei Routen. '
    .'Die Installationsanleitung muss das Abschalten der alten Fassung verlangen.');
