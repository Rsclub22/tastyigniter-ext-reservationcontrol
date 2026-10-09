<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Reservation\Classes\BookingManager;
use Illuminate\Support\Collection;

/**
 * Reservations generally run inside the opening hours (schedule "opening").
 * From a certain party size on that no longer holds, because larger parties are
 * served outside them too, by arrangement.
 */
class LargePartyBookingManager extends BookingManager
{
    /** From this guest count on, the opening hours no longer apply. */
    public const int LARGE_PARTY_FROM = 20;

    /** Time window offered to large parties instead. */
    public const string LARGE_PARTY_OPEN = '10:00';

    public const string LARGE_PARTY_CLOSE = '22:00';

    private ?int $forcedGuestCount = null;

    /**
     * Override the lead time and the horizon of public booking - set by phone
     * intake.
     */
    private bool $internal = false;

    /**
     * How far ahead phone intake may book. The public horizon (currently 60
     * days) does not apply there: Christmas and New Year's Eve are taken in
     * autumn, and a day without offered times is not something that can be
     * explained on the phone.
     */
    public const int INTERNAL_ADVANCE_DAYS = 365;

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
            ? [0, self::INTERNAL_ADVANCE_DAYS]
            : [
                $this->location->getMinReservationAdvanceTime(),
                $this->location->getMaxReservationAdvanceTime(),
            ];

        if (! $this->isLargeParty()) {
            return parent::getSchedule($days);
        }

        $periods = [];
        foreach (self::WEEKDAYS as $weekday) {
            $periods[$weekday] = [[self::LARGE_PARTY_OPEN, self::LARGE_PARTY_CLOSE]];
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

        if ($this->isLargeParty()) {
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

        return ! is_null($guests) && $guests >= self::LARGE_PARTY_FROM;
    }
}
