<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Facades\Location as LocationFacade;
use Igniter\Local\Models\Location;
use Throwable;

/**
 * The coming special evenings and the telephone invitation, as decisions
 * (rendering stays in GuestNotice and the views).
 *
 * An entry exists only with a guest text; the raw note or reason is internal
 * shorthand and is never advertised. Sources, in the order they speak on a day:
 *  - a blocked day's hinweis (independent of its online flag),
 *  - a closure note's text after "online buchbar:" (bookable),
 *  - a closure note's text after "HINWEIS:" (never bookable).
 */
final class SpecialEvenings
{
    /** A list, not a calendar: more than this is noise on someone else's page. */
    public const int MAX_ENTRIES = 5;

    /**
     * @return list<array{date: string, text: string, bookable: bool}>
     */
    public static function upcoming(Carbon $today, int $minAdvanceDays, int $maxAdvanceDays): array
    {
        $from = $today->copy()->startOfDay();
        // The booking page runs to the END of the last advance day.
        $to = $today->copy()->addDays($maxAdvanceDays)->toDateString();
        $firstBookable = $today->copy()->addDays(max(0, $minAdvanceDays))->toDateString();

        $blocked = BlockedDates::entries();
        $entries = [];
        $seen = [];

        $add = function (string $date, string $text, bool $bookable) use (&$entries, &$seen, $firstBookable): void {
            $text = trim($text);
            if ($text === '' || isset($seen[$date."\0".$text])) {
                return;
            }

            $seen[$date."\0".$text] = true;
            // A date inside the minimum lead time cannot be booked online yet:
            // it is still advertised, by telephone.
            $entries[] = ['date' => $date, 'text' => $text, 'bookable' => $bookable && $date >= $firstBookable];
        };

        foreach ($blocked as $date => $entry) {
            if ($date >= $from->toDateString() && $date <= $to) {
                $add($date, $entry['hinweis'], $entry['online']);
            }
        }

        foreach (ClosureNotes::upcoming() as $note) {
            $date = Carbon::parse($note->reserve_date)->toDateString();
            if ($date < $from->toDateString() || $date > $to) {
                continue;
            }

            // A blocked day with a hinweis speaks for itself (as in
            // GuestNotice::forDate()); one that is blocked without online
            // booking closes everything on it, a note's opt-in included.
            $day = $blocked[$date] ?? null;
            if ($day !== null && trim($day['hinweis']) !== '') {
                continue;
            }

            $add($date, ClosureNotes::guestText($note), $day === null || $day['online']);
            $add($date, ClosureNotes::hinweisText($note), false);
        }

        usort($entries, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /** The location the booking page belongs to (the first enabled one when no request location is set). */
    public static function location(): ?Location
    {
        try {
            $location = LocationFacade::current() ?? Location::query()->whereIsEnabled()->first();

            return $location instanceof Location ? $location : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The location's telephone number, '' when there is none. */
    public static function telephone(): string
    {
        return trim((string) self::location()?->location_telephone);
    }

    /**
     * Invite the guest to call: only when online booking actually took a time
     * away on this date (OnlineBlock, written by the manager that does the
     * removing) and there is a number to call.
     */
    public static function invitation(string $date, string $telephone): ?string
    {
        if ($telephone === '' || ! OnlineBlock::blockedOn($date)) {
            return null;
        }

        return $telephone;
    }
}
