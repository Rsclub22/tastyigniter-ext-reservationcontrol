<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Facades\Location;
use Igniter\Reservation\Classes\BookingManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Submission-time twin of the greyed-out time slot: a reservation whose date and
 * time lie in a slot the booking form shows as blocked is refused.
 *
 * It asks the very same method the form asks
 * (LargePartyBookingManager::isTimeslotsFullyBookedOn), with the same date,
 * guest count and location, and tests the result the way the Orange theme does
 * ('Y-m-d H:i'). It does not depend on booking.auto_allocate_table. There is no second rule here, so display and submission cannot
 * disagree.
 *
 * Fail open: whatever is uncertain - no component, no location, odd values, any
 * exception - lets the booking through (and logs). A wrongly turned-away guest
 * is silent; a wrongly accepted booking is caught on the phone.
 */
class BlockedSlotGuard
{
    /** The reason to refuse, or null when the booking may proceed (or nothing can be said). */
    public static function blockedMessage(?object $component): ?string
    {
        try {
            return self::blocked($component)
                ? (string) lang('reservationcontrol::default.error_slot_blocked')
                : null;
        } catch (Throwable $e) {
            self::report($e);

            return null;
        }
    }

    private static function blocked(?object $component): bool
    {
        if ($component === null) {
            return false;
        }

        $date = self::publicProperty($component, 'date');
        $time = self::publicProperty($component, 'time');
        $guest = self::publicProperty($component, 'guest');

        if (! is_string($date) || ! is_string($time) || ! is_int($guest) || $guest < 1) {
            return false;
        }

        $day = Carbon::createFromFormat('!Y-m-d', $date);
        $at = Carbon::createFromFormat('!Y-m-d H:i', $date.' '.$time);
        // Strict, like the form: "2030-02-31" would otherwise overflow into March.
        if (! $day instanceof Carbon || ! $at instanceof Carbon
            || $day->toDateString() !== $date || $at->format('H:i') !== $time) {
            return false;
        }

        $location = Location::current();
        $manager = resolve(BookingManager::class);
        if ($location === null || ! $manager instanceof LargePartyBookingManager) {
            return false;
        }

        // Always enforced - not tied to TastyIgniter's automatic table assignment,
        // which only decides whether the theme asks the manager at all.
        $manager->useLocation($location);

        $booked = $manager->isTimeslotsFullyBookedOn(collect([$at]), $day, $guest);

        return in_array($at->format('Y-m-d H:i'), $booked, true);
    }

    private static function publicProperty(object $component, string $name): mixed
    {
        if (! property_exists($component, $name)) {
            return null;
        }

        $property = new \ReflectionProperty($component, $name);

        return $property->isPublic() && $property->isInitialized($component) ? $property->getValue($component) : null;
    }

    private static function report(Throwable $e): void
    {
        try {
            Log::warning('reservationcontrol: slot check skipped, booking let through: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable) {
            // Logging must not turn a guest away either.
        }
    }
}
