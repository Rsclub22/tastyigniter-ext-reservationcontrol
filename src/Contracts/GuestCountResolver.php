<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Contracts;

/**
 * Tells the extension how many guests a visitor has entered in the public
 * booking form. A theme that is not supported out of the box binds its own
 * implementation in the container.
 */
interface GuestCountResolver
{
    /** Guests of the booking in progress, or null when unknown. */
    public function guestCount(): ?int;
}
