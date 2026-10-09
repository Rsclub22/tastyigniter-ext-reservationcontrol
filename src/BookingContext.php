<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Orange\Livewire\Booking;

/**
 * Holds the currently running Booking component so that the BookingManager
 * knows the guest count that was typed in. The manager gets it nowhere else: it
 * is resolved as a singleton and makeTimeSlots() only receives a date.
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
     * Deliberately read only on call: when the component is remembered (boot),
     * $guest is not hydrated yet; when the time slots are rendered, it is.
     */
    public static function guestCount(): ?int
    {
        return is_null(self::$component?->guest) ? null : (int) self::$component->guest;
    }
}
