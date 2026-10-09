<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Orange\Livewire\Booking;

/**
 * Hält die gerade laufende Booking-Komponente, damit der BookingManager die
 * eingegebene Gästezahl kennt. Der Manager bekommt sie sonst nirgends: er wird
 * als Singleton aufgelöst und makeTimeSlots() erhält nur ein Datum.
 */
class BookingContext
{
    private static ?Booking $component = null;

    public static function remember(Booking $component): void
    {
        self::$component = $component;
    }

    public static function forget(Booking $component): void
    {
        if (self::$component === $component) {
            self::$component = null;
        }
    }

    /**
     * Wird bewusst erst beim Aufruf gelesen: Beim Merken der Komponente (boot)
     * ist $guest noch nicht hydriert, beim Rendern der Zeitfenster schon.
     */
    public static function guestCount(): ?int
    {
        return is_null(self::$component?->guest) ? null : (int) self::$component->guest;
    }
}
