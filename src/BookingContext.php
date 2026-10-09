<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Orange\Livewire\Booking;
use Wagnersnetz\ReservationControl\Contracts\GuestCountResolver;

/**
 * Holds the currently running Orange Booking component (only ever used when
 * that theme is installed) and answers the guest-count question through the
 * GuestCountResolver. The manager gets the count nowhere else: it is resolved
 * as a singleton and makeTimeSlots() only receives a date.
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

    public static function component(): ?Booking
    {
        return self::$component;
    }

    /**
     * Delegates to the bound theme integration. Without any binding the count
     * is unknown, which callers treat as "not a large party".
     */
    public static function guestCount(): ?int
    {
        if (! app()->bound(GuestCountResolver::class)) {
            return null;
        }

        return app(GuestCountResolver::class)->guestCount();
    }
}
