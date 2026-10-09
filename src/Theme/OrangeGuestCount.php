<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Theme;

use Igniter\Orange\Livewire\Booking;
use Wagnersnetz\ReservationControl\BookingContext;
use Wagnersnetz\ReservationControl\Contracts\GuestCountResolver;

/**
 * Reads the guest count from the running Igniter\Orange Booking component.
 *
 * Deliberately read only on call: when the component is remembered (boot),
 * $guest is not hydrated yet; when the time slots are rendered, it is.
 */
final class OrangeGuestCount implements GuestCountResolver
{
    public function guestCount(): ?int
    {
        $component = BookingContext::component();

        if (! $component instanceof Booking) {
            return null;
        }

        return is_null($component->guest) ? null : (int) $component->guest;
    }
}
