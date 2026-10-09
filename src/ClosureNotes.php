<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closure notes: pseudo reservations that lock time against online booking by
 * occupying every table.
 *
 * How much time depends on the note. A Christmas note over lunchtime takes up
 * the whole day - nothing is accepted alongside it there. A storytelling
 * evening at 17:00 does not: the house is regularly open at midday, and the
 * lunch service is to stay bookable. The dividing line is therefore the opening
 * hours of that weekday, see isAllDay().
 *
 * This is deliberately not a blocked day (BlockedDates): "blocked" reads like
 * "closed" to a guest, and that is not true - the house is open, the tables are
 * merely fully planned. "Fully booked" is imprecise, but closer to the truth.
 * As long as there is no table plan of our own, it stays this way.
 *
 * They are recognised by carrying more guests than fit into the house at all.
 * That is neither a name comparison nor a flag in the database, but a property
 * a real party cannot have.
 */
class ClosureNotes
{
    /**
     * Times in the text of a note: "11 Uhr", "11:30 Uhr", "11.30 Uhr". The
     * number must not be part of a longer number, otherwise "Buchung Nummer
     * 1611 Uhr" would turn into nonsense.
     *
     * The German word "Uhr" (o'clock) stays: this is matched against free-text
     * comments as the staff of a German restaurant actually writes them.
     */
    private const string TIME_PATTERN = '/(?<![\d:.])(\d{1,2})(?:[:.](\d{2}))?\s*Uhr\b/iu';

    /**
     * Upper limit per time slot in the text: "max 60", "max. 60 Personen",
     * "maximal 60 Gaeste", or "60 PAX" for short.
     *
     * Deliberately narrow: either a "max" precedes it, or the unit is PAX.
     * Otherwise a passing "Tisch 5 Personen" in prose would cap the intake at
     * five guests.
     *
     * The German words stay for the same reason as in TIME_PATTERN: they are
     * what gets written into the comment field.
     */
    private const string PAX_PATTERN = '/(?:max(?:imal)?\.?\s*(\d{1,4})\s*(?:pax|personen|pers\.?|g[\x{00e4}a]ste|pl[\x{00e4}a]tze)?|(\d{1,4})\s*pax)\b/iu';

    /**
     * Wordings with which a note claims the whole day, even when it lies
     * outside the opening hours in time. Deliberately narrow: whatever is not
     * listed here is decided by the clock.
     *
     * German wordings again - this is matched against what the staff writes.
     */
    private const string ALL_DAY_PATTERN = '/ganzt[\x{00e4}a]gig|ganze[rn]\s+tag|online[^.!]{0,40}nicht\s*(?:mehr\s*)?(?:verf[\x{00fc}u]gbar|m[\x{00f6}o]glich|buchbar)|keine\s+online/iu';

    /**
     * Opt-in: the note's time window stays open for online booking ("Märchenabend,
     * online buchbar"). Everything else about the note stays as it is.
     *
     * A bare "online buchbar" also sits inside "nicht online buchbar" and "nicht
     * mehr online buchbar", which mean the opposite. So this pattern alone is
     * never enough: isOnlineOpen() asks ALL_DAY_PATTERN first (a note that
     * closes online booking always wins), and then refuses any occurrence with
     * a negation earlier in the same clause (NEGATION_PATTERN).
     *
     * German only, like every pattern here: this free-text parser is German by
     * design and is to be replaced by real fields. Do not internationalise the
     * regexes.
     */
    private const string ONLINE_OPEN_PATTERN = '/\bonline\s+(?:wieder\s+)?buchbar\b/iu';

    /**
     * The keyword with an optional guest text: "online buchbar: Märchenabend mit
     * Menü ab 18 Uhr". The text starts after the colon (on the same line - only
     * blanks may sit between keyword, colon and text) and runs to the end of
     * the line, so a clause on the next line is never swallowed.
     */
    private const string GUEST_TEXT_PATTERN = '/\bonline\s+(?:wieder\s+)?buchbar\b[ \t]*:[ \t]*([^\r\n]*)/iu';

    /** A negation word; looked for in the clause in front of an ONLINE_OPEN_PATTERN match. */
    private const string NEGATION_PATTERN = '/\b(?:nicht|kein\w*)\b/iu';

    /** Opening hours per weekday, fetched once per request. */
    private static ?array $openingHours = null;

    /** Seats of the whole house. Combinations do not count, those would be the same seats twice. */
    /** Once per request is enough - table sizes do not change in the middle of a request. */
    private static ?int $houseCapacity = null;

    public static function houseCapacity(): int
    {
        return self::$houseCapacity ??= (int) DiningTable::query()
            ->where('is_combo', 0)
            ->sum(DB::raw('max_capacity + extra_capacity'));
    }

    public static function isNote(Reservation $reservation, ?int $houseCapacity = null): bool
    {
        $houseCapacity ??= self::houseCapacity();

        return $houseCapacity > 0 && (int) $reservation->guest_num > $houseCapacity;
    }

    /**
     * Opening hours of the weekday as ['HH:MM', 'HH:MM'] - null when the house
     * is closed that day or no schedule has been set up.
     */
    public static function openingHours(Carbon $date): ?array
    {
        if (self::$openingHours === null) {
            self::$openingHours = [];

            if ($location = Location::query()->whereIsEnabled()->first()) {
                foreach ($location->getWorkingHours() as $hour) {
                    if ($hour->type !== 'opening' || ! $hour->status) {
                        continue;
                    }

                    self::$openingHours[Carbon::parse($hour->day)->dayOfWeek] = [
                        Carbon::parse($hour->opening_time)->format('H:i'),
                        Carbon::parse($hour->closing_time)->format('H:i'),
                    ];
                }
            }
        }

        return self::$openingHours[$date->dayOfWeek] ?? null;
    }

    /**
     * Does this note claim the whole day?
     *
     * Yes, when its time window reaches into the opening hours - then the party
     * sits on the seats that would otherwise go to regular business, and
     * nothing runs alongside it any more. Yes as well when the text says so
     * explicitly.
     *
     * No for a note that lies outside: an evening at 17:00 does not close the
     * lunch service. It then locks only its own time, and the occupied tables
     * take care of that anyway.
     */
    public static function isAllDay(Reservation $note, Carbon $date): bool
    {
        if (preg_match(self::ALL_DAY_PATTERN, (string) $note->comment)) {
            return true;
        }

        if (! $openingHours = self::openingHours($date)) {
            return false;
        }

        $start = Carbon::parse($note->reserve_time)->format('H:i');
        $end = Carbon::parse($note->reserve_time)
            ->addMinutes(max(0, (int) $note->duration))
            ->format('H:i');

        // Past midnight: count up to the end of the day, otherwise the end
        // would be smaller than the start and the check would yield nonsense.
        if ($end <= $start) {
            $end = '23:59';
        }

        return $start < $openingHours[1] && $end > $openingHours[0];
    }

    /**
     * Does this moment fall into the time window of a note?
     *
     * Meant is the window of the pseudo reservation itself, not the one its
     * text names: the storytelling evening runs from 16:00 to 20:00, even when
     * the text names "17 Uhr" as the start of the programme.
     */
    public static function isTakenAt(iterable $notes, Carbon $at): bool
    {
        foreach ($notes as $note) {
            if (self::isOnlineOpen($note)) {
                continue;
            }

            $start = $at->copy()->setTimeFromTimeString(
                Carbon::parse($note->reserve_time)->format('H:i'),
            );
            $end = $start->copy()->addMinutes(max(0, (int) $note->duration));

            if ($at >= $start && $at < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the text explicitly keep this note's window open for online booking?
     *
     * Errs towards closed: a note that closes online booking by its wording
     * wins over the keyword, and so does a single negated occurrence.
     */
    public static function isOnlineOpen(Reservation $note): bool
    {
        $text = (string) $note->comment;

        if (preg_match(self::ALL_DAY_PATTERN, $text)) {
            return false;
        }

        if (! preg_match_all(self::ONLINE_OPEN_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [, $offset]) {
            // The clause in front of the keyword: back to the last separator.
            $before = substr($text, 0, (int) $offset);
            $clause = preg_split('/[.!?;,\n]/u', $before);
            $clause = $clause === false ? $before : (string) end($clause);

            if (preg_match(self::NEGATION_PATTERN, $clause)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The text for guests that follows "online buchbar:" in an opted-in note,
     * trimmed - '' when the note is not opted in, has no colon, or nothing
     * after it. The first occurrence that carries a text wins.
     */
    public static function guestText(Reservation $note): string
    {
        if (! self::isOnlineOpen($note)) {
            return '';
        }

        if (! preg_match_all(self::GUEST_TEXT_PATTERN, (string) $note->comment, $matches)) {
            return '';
        }

        foreach ($matches[1] as $text) {
            if (($text = trim($text)) !== '') {
                return $text;
            }
        }

        return '';
    }

    /** The notes that claim the whole day. */
    public static function allDay(iterable $notes, Carbon $date): Collection
    {
        return collect($notes)
            ->filter(fn (Reservation $n): bool => self::isAllDay($n, $date))
            ->values();
    }

    /**
     * The notes that apply to a particular time: all-day ones always, the rest
     * only at the times their text names.
     */
    public static function forTime(iterable $notes, Carbon $date, string $time): Collection
    {
        return collect($notes)
            ->filter(fn (Reservation $n): bool => self::isAllDay($n, $date)
                || in_array($time, self::times([$n]), true))
            ->values();
    }

    /** Notes of one day. Cancelled ones no longer count. */
    public static function onDate(Carbon $date): Collection
    {
        $houseCapacity = self::houseCapacity();

        if ($houseCapacity <= 0) {
            return collect();
        }

        return Reservation::query()
            ->with('tables')
            ->whereDate('reserve_date', $date->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->where('guest_num', '>', $houseCapacity)
            ->orderBy('reserve_time')
            ->get();
    }

    /**
     * Times named in the notes of a day - for instance the two sittings at
     * Christmas.
     *
     * The text is a free comment field, not a form. Whatever is not recognised
     * here is therefore not an error case: phone intake then falls back to the
     * usual time slots. Better to offer too many times than to make a day
     * accidentally unbookable.
     *
     * @return array<int, string> times as HH:MM, ascending
     */
    public static function times(iterable $notes): array
    {
        $times = [];

        foreach ($notes as $note) {
            if (! $text = trim((string) $note->comment)) {
                continue;
            }

            if (! preg_match_all(self::TIME_PATTERN, $text, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $hour = (int) $m[1];
                $minute = isset($m[2]) ? (int) $m[2] : 0;

                if ($hour > 23 || $minute > 59) {
                    continue;
                }

                $times[] = sprintf('%02d:%02d', $hour, $minute);
            }
        }

        $times = array_values(array_unique($times));
        sort($times);

        return $times;
    }

    /**
     * Maximum number of guests per time slot as it stands in the text of a
     * note - null when none is named.
     *
     * Several figures: the smallest wins. Whoever writes two numbers into the
     * same text means the stricter one in case of doubt, and accepting too few
     * can be sorted out on the phone - accepting too many cannot.
     */
    public static function maxPax(iterable $notes): ?int
    {
        $values = [];

        foreach ($notes as $note) {
            if (! $text = trim((string) $note->comment)) {
                continue;
            }

            if (! preg_match_all(self::PAX_PATTERN, $text, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $number = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0));

                if ($number >= 1) {
                    $values[] = $number;
                }
            }
        }

        return $values === [] ? null : min($values);
    }

    /**
     * Seats already taken per time on a day.
     *
     * The notes themselves do not count - they deliberately carry a nonsensical
     * guest count. Everything else counts, rooms included: the kitchen does not
     * distinguish whether a party sits in the hall or at table 3.
     *
     * @return array<string, int> time (HH:MM) => persons
     */
    public static function occupancyPerTime(Carbon $date): array
    {
        $houseCapacity = self::houseCapacity();

        $reservations = Reservation::query()
            ->whereDate('reserve_date', $date->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->when($houseCapacity > 0, fn ($q) => $q->where('guest_num', '<=', $houseCapacity))
            ->get(['reserve_time', 'guest_num']);

        $taken = [];
        foreach ($reservations as $r) {
            $time = Carbon::parse($r->reserve_time)->format('H:i');
            $taken[$time] = ($taken[$time] ?? 0) + (int) $r->guest_num;
        }

        return $taken;
    }

    /**
     * Maximum numbers per time when the text names them individually:
     * "11 Uhr max 60 PAX, 13 Uhr max 80 PAX".
     *
     * The assignment runs over the order in the text: a number belongs to the
     * time that stands before it. So that this does not guess, it has to add
     * up - exactly one number behind every time. Otherwise nothing is assigned
     * at all and the single number for all sittings stands (maxPax).
     *
     * Because of that, "11 Uhr und 13 Uhr. MAX 60 PAX" still means 60 for both
     * and not 60 for the second sitting only.
     *
     * @return array<string, int> time (HH:MM) => maximum; empty when it does not add up
     */
    public static function maxPaxPerTime(iterable $notes): array
    {
        $plan = [];

        foreach ($notes as $note) {
            if (! $text = trim((string) $note->comment)) {
                continue;
            }

            preg_match_all(self::TIME_PATTERN, $text, $timeMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            preg_match_all(self::PAX_PATTERN, $text, $paxMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            if ($timeMatches === [] || $paxMatches === []) {
                continue;
            }

            $times = [];
            foreach ($timeMatches as $m) {
                $hour = (int) $m[1][0];
                $minute = isset($m[2]) && $m[2][0] !== '' ? (int) $m[2][0] : 0;

                if ($hour <= 23 && $minute <= 59) {
                    $times[] = [sprintf('%02d:%02d', $hour, $minute), (int) $m[0][1]];
                }
            }

            $pairs = [];
            foreach ($paxMatches as $m) {
                $number = (int) ($m[1][0] !== '' ? $m[1][0] : ($m[2][0] ?? 0));
                $pos = (int) $m[0][1];

                if ($number < 1) {
                    continue;
                }

                $before = null;
                foreach ($times as [$time, $timePos]) {
                    if ($timePos < $pos) {
                        $before = $time;
                    }
                }

                // Number before the first time, or two numbers at the same one:
                // not assignable, so it applies to all sittings.
                if ($before === null || isset($pairs[$before])) {
                    return [];
                }

                $pairs[$before] = $number;
            }

            // There has to be a number for every time named, otherwise we guess.
            if (count($pairs) !== count(array_unique(array_column($times, 0)))) {
                return [];
            }

            foreach ($pairs as $time => $number) {
                $plan[$time] = isset($plan[$time]) ? min($plan[$time], $number) : $number;
            }
        }

        ksort($plan);

        return $plan;
    }
}
