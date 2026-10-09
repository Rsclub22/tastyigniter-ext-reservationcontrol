<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

/**
 * What the extension understood from a closure note that offers an event slot.
 * Plain data, built by ClosureNotes::eventPlan(), meant to be shown to staff
 * as well as to drive the schedule.
 *
 * Everything is HH:MM strings. Nothing in here opens more than the note said:
 * a time that could not be placed is listed under $dropped and has no window.
 */
final readonly class EventPlan
{
    /** The note did not opt in online: the envelope blocks, stated times open nothing. */
    public const string NOT_OPTED_IN = 'not_opted_in';

    /** Opted in, but no usable time stands in the text. */
    public const string NO_TIME = 'no_time';

    /** Opted in, but the stored window is empty or runs past midnight. */
    public const string NO_ENVELOPE = 'no_envelope';

    /** At least one window opens. */
    public const string ACTIVE = 'active';

    /** The event windows replace the day's normal opening hours. */
    public const string REPLACE = 'replace';

    /** The event windows are added to the day's normal opening hours. */
    public const string ADD = 'add';

    /** A stated time lies outside the stored window. */
    public const string OUTSIDE_ENVELOPE = 'outside_envelope';

    /** A stated time is no clock time (hour above 23, minute above 59). */
    public const string INVALID_TIME = 'invalid_time';

    /**
     * @param  string  $status  one of NOT_OPTED_IN, NO_TIME, NO_ENVELOPE, ACTIVE
     * @param  array{0: string, 1: string}|null  $envelope  the stored window (start, end), null when unusable
     * @param  array<int, string>  $stated  every time found in the text, ascending
     * @param  array<int, array{0: string, 1: string}>  $windows  bookable windows (start, end)
     * @param  string|null  $mode  REPLACE or ADD while active, otherwise null
     * @param  array<int, array{time: string, reason: string}>  $dropped  what was left out and why
     */
    public function __construct(
        public string $status,
        public ?array $envelope,
        public array $stated,
        public array $windows,
        public ?string $mode,
        public array $dropped,
    ) {}

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->windows !== [];
    }
}
