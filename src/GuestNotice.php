<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;

/**
 * The notice a guest sees above the online booking form on a special day.
 *
 * Two sources keep a day or a window open for online booking: a blocked date
 * with its "online" flag (its hinweis is the text) and a closure note whose
 * comment carries "online buchbar" (the text after the colon). forDate() is the
 * one place that answers which text applies on a date.
 *
 * Staff type these texts and the booking form is public: the view escapes them.
 * Never print them with {!! !!}.
 */
final class GuestNotice
{
    /**
     * The texts that apply on a date (Y-m-d), possibly none.
     *
     * A day that is still blocked has no notice, whatever else is on it: it
     * cannot be booked, so a text would advertise something nobody can book.
     * On an online-open blocked day the blocked day's hinweis wins; without
     * one, the day's opted-in closure notes speak.
     *
     * @return list<string>
     */
    public static function forDate(string $date): array
    {
        $entry = BlockedDates::entries()[$date] ?? null;

        if ($entry !== null) {
            if (! $entry['online']) {
                return [];
            }

            if (($text = trim($entry['hinweis'])) !== '') {
                return [$text];
            }
        }

        $texts = [];
        foreach (ClosureNotes::onDate(Carbon::parse($date)) as $note) {
            if (($text = ClosureNotes::guestText($note)) !== '') {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }
}
