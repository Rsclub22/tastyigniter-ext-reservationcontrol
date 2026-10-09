<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Theme;

use Wagnersnetz\ReservationControl\Contracts\GuestCountResolver;

/** Used when no theme integration is available: the guest count is unknown. */
final class NullGuestCount implements GuestCountResolver
{
    public function guestCount(): ?int
    {
        return null;
    }
}
