<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

/**
 * Remembers, per date, whether online booking took times away from a guest.
 *
 * The one writer is LargePartyBookingManager::makeTimeSlots(), at the one place
 * where it rejects slots (cut-off, guest cap, a closure note's window, an
 * all-day note). Whoever asks - the telephone invitation - reads this instead
 * of working the same decision out a second time. A day on which the house is
 * simply closed has no slots to remove and is therefore never recorded.
 *
 * Request-scoped state, like BookingContext: GuestNotice::onRender() resets it
 * before the page renders, the rendering then fills it.
 */
final class OnlineBlock
{
    /** @var array<string, int> date (Y-m-d) => slots removed */
    private static array $removed = [];

    public static function record(string $date, int $removed): void
    {
        self::$removed[$date] = max(self::$removed[$date] ?? 0, $removed);
    }

    /** Did online booking remove at least one time on this date? */
    public static function blockedOn(string $date): bool
    {
        return (self::$removed[$date] ?? 0) > 0;
    }

    public static function reset(): void
    {
        self::$removed = [];
    }
}
