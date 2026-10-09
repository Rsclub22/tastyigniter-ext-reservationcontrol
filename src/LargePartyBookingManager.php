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
        // A note that claims the whole day closes online booking entirely -
        // also for large parties, which run past the table check just below.
        // Without this, a party could sit itself in online at Christmas even
        // though the day has long been planned out.
        //
        // A note beside the opening hours does not fall under this: its times
        // are already locked by the occupied tables, and the lunch service of
        // the same day stays bookable.
        if (ClosureNotes::allDay(ClosureNotes::onDate($date), $date)->isNotEmpty()) {
            return $timeslots
                ->map(fn ($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i'))->toDateTimeString())
                ->values()
                ->all();
        }

        // Whatever lies inside the time window of a note is taken - also for
        // large parties, which run past the table check just below. Without
        // this, a party could put itself online into the storytelling evening,
        // which is explicitly only assigned over the phone.
        $notes = ClosureNotes::onDate($date);

        $taken = $notes->isEmpty() ? [] : $timeslots
            ->map(fn ($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
            ->filter(fn (Carbon $at): bool => ClosureNotes::isTakenAt($notes, $at))
            ->map(fn (Carbon $at) => $at->toDateTimeString())
            ->values()
            ->all();

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

        return $timeslots
            ->map(fn ($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
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
        $value = self::stored('large_party_threshold');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_THRESHOLD;
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
        $open = self::validTime(self::stored('large_party_open'), self::DEFAULT_OPEN);
        $close = self::validTime(self::stored('large_party_close'), self::DEFAULT_CLOSE);

        return $close > $open ? [$open, $close] : [self::DEFAULT_OPEN, self::DEFAULT_CLOSE];
    }

    /** A switch that is on unless explicitly turned off, so an unset value keeps today's behaviour. */
    private static function flag(string $key): bool
    {
        $value = self::stored($key);

        return $value === null || $value === '' ? true : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * How far ahead phone intake may book. The public horizon (currently 60
     * days) does not apply there: Christmas and New Year's Eve are taken in
     * autumn, and a day without offered times is not something that can be
     * explained on the phone.
     */
    public static function internalHorizonDays(): int
    {
        $value = self::stored('internal_booking_horizon_days');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_INTERNAL_HORIZON_DAYS;
    }

    /**
     * Reads a stored setting. Settings::get() is typed by Eloquent as a Collection,
     * so the result is only ever checked here, never trusted.
     */
    private static function stored(string $key): mixed
    {
        return Settings::get($key);
    }

    private static function validTime(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : $default;
    }
}
