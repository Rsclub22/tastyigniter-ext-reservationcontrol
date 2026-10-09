<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Reservation\Classes\BookingManager;
use Illuminate\Support\Collection;
use Wagnersnetz\ReservationControl\Models\Settings;

/**
 * Reservations generally run inside the opening hours (schedule "opening").
 * From a certain party size on that no longer holds, because larger parties are
 * served outside them too, by arrangement.
 */
class LargePartyBookingManager extends BookingManager
{
    /** Used whenever the settings hold no usable value. */
    public const int DEFAULT_THRESHOLD = 20;

    public const string DEFAULT_OPEN = '10:00';

    public const string DEFAULT_CLOSE = '22:00';

    public const int DEFAULT_INTERNAL_HORIZON_DAYS = 365;

    private ?int $forcedGuestCount = null;

    /**
     * Override the lead time and the horizon of public booking - set by phone
     * intake.
     */
    private bool $internal = false;

    private const array WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    public function getSchedule($days = null)
    {
        // Lead time: publicly the location setting applies (currently 2 days up
        // to 60 days), at phone intake it does not - there a booking is taken
        // for today just as well as for the storytelling evening in two months.
        // The bar against times already past is untouched by this, it sits in
        // makeTimeSlots().
        $days ??= $this->internal
            ? [0, self::internalHorizonDays()]
            : [
                $this->location->getMinReservationAdvanceTime(),
                $this->location->getMaxReservationAdvanceTime(),
            ];

        if (! $this->isLargeParty()) {
            return parent::getSchedule($days);
        }

        $window = [self::windowOpen(), self::windowClose()];

        // With the switch off, the window only applies on weekdays that have
        // ordinary opening hours.
        $opening = self::allWeekdays() ? null : parent::getSchedule($days);

        $periods = [];
        foreach (self::WEEKDAYS as $weekday) {
            $periods[$weekday] = $opening?->isClosedOn($weekday) ? [] : [$window];
        }

        $schedule = WorkingSchedule::create($days, $periods);
        // newWorkingSchedule() sets the type as well; without it getType() throws.
        $schedule->setType('opening');

        // This branch bypasses newWorkingSchedule() and with it the event the
        // blocked days otherwise hang off - so pull them in by hand here.
        // Otherwise a blocked day would still be bookable for large parties.
        if ($exceptions = BlockedDates::asScheduleExceptions()) {
            $schedule->setExceptions($exceptions);
        }

        return $schedule;
    }

    /**
     * For large parties there is no fitting single table. The occupancy check
     * then reports *all* time slots as fully booked
     * (Reservation::listFullyBookedTimeslots returns the complete list when
     * there are 0 fitting tables). Such reservations run by arrangement anyway,
     * so the check is dropped.
     */
    public function isTimeslotsFullyBookedOn(Collection $timeslots, Carbon $date, ?int $noOfGuest = null): array
    {
        $booked = $this->blockedDateTimes($timeslots, $date, $noOfGuest);

        // Every blocked date-time is returned in BOTH notations, here at the one
        // place where all branches (cut-off, cap, closure-note window, all-day
        // note, table logic) leave the method. Do not "tidy" this away:
        // TastyIgniter's BookingManager returns 'Y-m-d H:i:s' (toDateTimeString),
        // but the Orange theme looks the slots up with
        // in_array($dateTime->format('Y-m-d H:i'), ...) - Livewire/Booking.php,
        // reducedTimeslots(). Without the second form nothing ever matched and no
        // time slot was ever disabled on the public booking form.
        $both = [];
        foreach ($booked as $dateTime) {
            $both[] = $dateTime;
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $dateTime, $m) === 1) {
                $both[] = $m[0];
            }
        }

        return array_values(array_unique($both));
    }

    /** @return array<int, string> blocked date-times (Y-m-d H:i:s) */
    private function blockedDateTimes(Collection $timeslots, Carbon $date, ?int $noOfGuest): array
    {
        $booked = $this->fullyBookedByTablesAndNotes($timeslots, $date, $noOfGuest);

        // The two online-only limits. Neither applies to large parties (those
        // are arranged by phone) and neither applies at the counter, where
        // staff may deliberately go beyond them.
        if ($this->internal || $this->isLargeParty()) {
            return $booked;
        }

        $extra = $this->onlineLimited($timeslots, $date, max(1, (int) $noOfGuest));

        return $extra === [] ? $booked : array_values(array_unique(array_merge($booked, $extra)));
    }

    /**
     * The moment after which online booking is closed on $date, or null without a
     * cut-off (setting 0, or no opening hours that day). The ONE place the cut-off
     * is decided: makeTimeSlots() uses it to stop the list, onlineLimited() to
     * report the same slots as full.
     */
    private static function cutoffFrom(Carbon $date): ?Carbon
    {
        // 1440 = one day; a larger value is a typo and counts as unset.
        $cutoffMinutes = SettingValue::int('cutoff_minutes_before_closing', 0, 0, 1440);
        if ($cutoffMinutes <= 0 || ($hours = ClosureNotes::openingHours($date)) === null) {
            return null;
        }

        $closing = $date->copy()->setTimeFromTimeString($hours[1]);
        // Closing after midnight: 18:00 - 01:00 closes on the next day.
        if ($hours[1] <= $hours[0]) {
            $closing->addDay();
        }

        return $closing->subMinutes($cutoffMinutes);
    }

    /** A slot strictly after the cut-off moment; one exactly on it stays bookable. */
    private static function isPastCutoff(Carbon $at, ?Carbon $cutoffFrom): bool
    {
        return $cutoffFrom !== null && $at->gt($cutoffFrom);
    }

    /**
     * Online, blocked times are not offered at all: the guest sees exactly what
     * can be booked. Removed are the slots of the cut-off, of the online guest
     * cap, of a closure note's time window and of an all-day note - the same
     * decisions isTimeslotsFullyBookedOn() reports (noteBlocked() and
     * onlineLimited() are the one implementation of each), and independent of
     * TastyIgniter's "automatic table assignment" setting, which decides
     * whether the theme asks for the fully-booked list at all.
     *
     * Table availability is not trimmed here (it stays the theme's business).
     * Phone intake (internal) keeps every slot: staff take late bookings.
     *
     * The guest cap depends on the party size; makeTimeSlots() does not receive
     * it, so the component's count is used (1 when unknown).
     */
    public function makeTimeSlots(Carbon $date, $interval = null, $lead = null)
    {
        $slots = parent::makeTimeSlots($date, $interval, $lead);

        if ($this->internal || ! $slots instanceof Collection || $slots->isEmpty()) {
            return $slots;
        }

        $blocked = $this->noteBlocked($slots, $date);
        if (! $this->isLargeParty()) {
            $guests = max(1, $this->forcedGuestCount ?? BookingContext::guestCount() ?? 1);
            $blocked = array_merge($blocked, $this->onlineLimited($slots, $date, $guests));
        }

        return $blocked === []
            ? $slots
            : $slots->reject(fn ($slot): bool => in_array(
                $date->copy()->setTimeFromTimeString($slot->format('H:i'))->toDateTimeString(), $blocked, true,
            ));
    }

    /**
     * Slots that online booking closes although a table would be free: too
     * close to closing time, or beyond the guest cap of a closure note.
     *
     * @return array<int, string> date-times (Y-m-d H:i:s)
     */
    private function onlineLimited(Collection $timeslots, Carbon $date, int $guests): array
    {
        $applyCap = SettingValue::flag('apply_max_guests_online', false);
        $cutoffFrom = self::cutoffFrom($date);
        $cutoffMinutes = SettingValue::int('cutoff_minutes_before_closing', 0, 0, 1440);

        if ($cutoffMinutes <= 0 && ! $applyCap) {
            return [];
        }

        $notes = ClosureNotes::onDate($date);

        $maxPax = null;
        $maxPerTime = [];
        $occupancy = [];
        if ($applyCap) {
            $maxPax = ClosureNotes::maxPax($notes);
            $maxPerTime = ClosureNotes::maxPaxPerTime($notes);
            $occupancy = ClosureNotes::occupancyPerTime($date);
        }

        $eventTimes = $cutoffMinutes > 0 ? ClosureNotes::eventTimes($notes, true) : [];

        return $timeslots
            ->map(fn ($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
            ->filter(function (Carbon $at) use ($cutoffFrom, $eventTimes, $applyCap, $maxPax, $maxPerTime, $occupancy, $guests): bool {
                // An event time is offered exactly as written: the day's
                // closing time means nothing there (a 17:00 event after a lunch
                // that closes at 15:00 would otherwise be cut off entirely),
                // and counting from the event itself would cut off every one.
                if (! in_array($at->format('H:i'), $eventTimes, true) && self::isPastCutoff($at, $cutoffFrom)) {
                    return true;
                }

                if (! $applyCap) {
                    return false;
                }

                $time = $at->format('H:i');
                $cap = $maxPerTime[$time] ?? $maxPax;

                return $cap !== null && ($occupancy[$time] ?? 0) + $guests > $cap;
            })
            ->map(fn (Carbon $at) => $at->toDateTimeString())
            ->values()
            ->all();
    }

    /** Does a note claim the whole day for online booking? */
    private function allDayClosed(Carbon $date): bool
    {
        // A note beside the opening hours does not fall under this: its times
        // are already locked by the occupied tables, and the lunch service of
        // the same day stays bookable.
        return ClosureNotes::allDay(ClosureNotes::onDate($date), $date)
            ->reject(fn ($note): bool => ClosureNotes::isOnlineOpen($note))
            ->isNotEmpty();
    }

    /**
     * Slots that closure notes take: all of them for an all-day note - also for
     * large parties, otherwise a party could sit itself in online at Christmas
     * although the day has long been planned out - else those inside a note's
     * time window (also for large parties: the storytelling evening is only
     * assigned over the phone).
     *
     * @return array<int, string> date-times (Y-m-d H:i:s)
     */
    private function noteBlocked(Collection $timeslots, Carbon $date): array
    {
        $at = fn ($slot): Carbon => $date->copy()->setTimeFromTimeString($slot->format('H:i'));

        if ($this->allDayClosed($date)) {
            return $timeslots->map(fn ($slot): string => $at($slot)->toDateTimeString())->values()->all();
        }

        $notes = ClosureNotes::onDate($date);

        return $notes->isEmpty() ? [] : $timeslots
            ->map($at)
            ->filter(fn (Carbon $moment): bool => ClosureNotes::isTakenAt($notes, $moment))
            ->map(fn (Carbon $moment): string => $moment->toDateTimeString())
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function fullyBookedByTablesAndNotes(Collection $timeslots, Carbon $date, ?int $noOfGuest): array
    {
        $taken = $this->noteBlocked($timeslots, $date);

        // An all-day note has locked every slot already; no table lookup needed.
        if ($taken !== [] && $this->allDayClosed($date)) {
            return $taken;
        }

        if ($this->isLargeParty() && self::skipTableCheck()) {
            return $taken;
        }

        $locationId = (int) $this->location->location_id;
        $guests = max(1, (int) $noOfGuest);
        $candidates = TableAllocator::candidates($locationId);

        // When the party fits into no table at all, every slot would otherwise
        // be locked - even without a single booking. We place such parties by
        // hand.
        if ($candidates->isEmpty() || TableAllocator::pick($candidates, $guests) === null) {
            return $taken;
        }

        $reservations = TableAllocator::reservationsOn($locationId, $date);
        $duration = (int) $this->location->getReservationStayTime();
        $notes = ClosureNotes::onDate($date);

        return $timeslots
            ->map(fn ($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
            // At an event time people are counted, not tables: the note holds
            // every table, and the lookup would call the event itself fully
            // booked. The note's guest cap governs capacity there. Always on,
            // deliberately NOT tied to large_party_skip_table_check: that
            // setting is about large parties, and switching it off must not
            // silently kill the event slots. Everything else in the envelope
            // is closed by noteBlocked(). Do not turn this into a table lookup.
            ->reject(fn (Carbon $at): bool => ClosureNotes::isOnlineEventTime($notes, $at))
            ->filter(fn (Carbon $at): bool => TableAllocator::pick(
                TableAllocator::freeAt($candidates, $at, $duration, $reservations), $guests,
            ) === null)
            ->map(fn (Carbon $at) => $at->toDateTimeString())
            ->merge($taken)
            ->unique()
            ->values()
            ->all();
    }

    /** Override lead time and horizon - only for internal phone intake. */
    public function allowSameDay(bool $allow = true): static
    {
        $this->internal = $allow;

        return $this;
    }

    /**
     * Phone intake runs without Livewire, there is no component there from
     * which BookingContext could read the guest count. It is therefore set
     * directly and then takes precedence.
     */
    public function forceGuestCount(?int $guests): static
    {
        $this->forcedGuestCount = $guests;

        return $this;
    }

    public function isLargeParty(): bool
    {
        $guests = $this->forcedGuestCount ?? BookingContext::guestCount();

        return ! is_null($guests) && $guests >= self::threshold();
    }

    /** From this guest count on, the opening hours no longer apply. */
    public static function threshold(): int
    {
        return SettingValue::int('large_party_threshold', self::DEFAULT_THRESHOLD);
    }

    /** Start of the time window offered to large parties. */
    public static function windowOpen(): string
    {
        return self::window()[0];
    }

    /** End of the time window offered to large parties. */
    public static function windowClose(): string
    {
        return self::window()[1];
    }

    /** Whether the large-party window applies on all seven weekdays. */
    public static function allWeekdays(): bool
    {
        return self::flag('large_party_all_weekdays');
    }

    /** Whether the table/occupancy check is skipped for large parties. */
    public static function skipTableCheck(): bool
    {
        return self::flag('large_party_skip_table_check');
    }

    /**
     * The configured window, or the defaults when either time is malformed or
     * the window does not close after it opens (e.g. 22:00 - 10:00).
     *
     * @return array{0: string, 1: string}
     */
    private static function window(): array
    {
        $open = self::validTime(SettingValue::stored('large_party_open'), self::DEFAULT_OPEN);
        $close = self::validTime(SettingValue::stored('large_party_close'), self::DEFAULT_CLOSE);

        return $close > $open ? [$open, $close] : [self::DEFAULT_OPEN, self::DEFAULT_CLOSE];
    }

    /** A switch that is on unless explicitly turned off, so an unset value keeps today's behaviour. */
    private static function flag(string $key): bool
    {
        return SettingValue::flag($key, true);
    }

    /**
     * How far ahead phone intake may book. The public horizon (currently 60
     * days) does not apply there: Christmas and New Year's Eve are taken in
     * autumn, and a day without offered times is not something that can be
     * explained on the phone.
     */
    public static function internalHorizonDays(): int
    {
        return SettingValue::int('internal_booking_horizon_days', self::DEFAULT_INTERNAL_HORIZON_DAYS);
    }

    private static function validTime(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : $default;
    }
}
