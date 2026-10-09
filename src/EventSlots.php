<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingDay;
use Igniter\Local\Classes\WorkingPeriod;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Exceptions\WorkingHourException;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Hands the event windows of opted-in closure notes to a schedule.
 *
 * This is the one place where the extension creates bookable time instead of
 * removing it, and it runs inside the public booking page. Hence the rules:
 * whatever is not certain is left out, and nothing here may throw. A missing
 * event slot is a disappointment; a dead booking form is not.
 */
class EventSlots
{
    public static function apply(WorkingSchedule $schedule): void
    {
        try {
            self::applyNotes($schedule, ClosureNotes::upcoming());
        } catch (Throwable) {
            // Fall back to the schedule as it was.
        }
    }

    public static function applyNotes(WorkingSchedule $schedule, Collection $notes): void
    {
        $byDate = [];
        foreach ($notes as $note) {
            $byDate[Carbon::parse($note->reserve_date)->toDateString()][] = $note;
        }

        foreach ($byDate as $date => $dayNotes) {
            try {
                $exception = self::exceptionFor($schedule, Carbon::parse($date), $dayNotes);

                if ($exception !== null) {
                    $schedule->setExceptions([$date => $exception]);
                }
            } catch (Throwable) {
                // This day stays as the schedule had it; other days go on.
            }
        }
    }

    /**
     * The periods for one day, or null to leave the day alone.
     *
     * @param  array<int, Reservation>  $notes
     * @return array<int, array{0: string, 1: string}>|null
     */
    private static function exceptionFor(WorkingSchedule $schedule, Carbon $date, array $notes): ?array
    {
        // A day that already has an exception - a blocked day above all - is
        // never reopened by an event note.
        $exceptions = $schedule->exceptions();
        if (isset($exceptions[$date->format('Y-m-d')]) || isset($exceptions[$date->format('m-d')])) {
            return null;
        }

        $normal = self::rangesOf($schedule->forDay(WorkingDay::onDateTime($date)));

        $windows = [];
        $replace = false;
        foreach ($notes as $note) {
            $plan = ClosureNotes::eventPlan($note, $normal);
            if (! $plan->isActive()) {
                continue;
            }

            $replace = $replace || $plan->mode === EventPlan::REPLACE;
            array_push($windows, ...$plan->windows);
        }

        if ($windows === []) {
            return null;
        }

        // Several notes on one day: sorted, and a window that overlaps what is
        // already kept is dropped, never merged into something wider.
        usort($windows, static fn (array $a, array $b): int => $a <=> $b);
        $periods = $replace ? [] : $normal;
        $spans = ClosureNotes::busySpans($periods);

        foreach ($windows as $window) {
            $span = [self::minutes($window[0]), self::minutes($window[1])];
            if (ClosureNotes::overlapsAny($span, $spans)) {
                continue;
            }

            $periods[] = $window;
            $spans[] = $span;
        }

        usort($periods, static fn (array $a, array $b): int => $a <=> $b);

        // Backstop: the platform's own check. If it would throw, the day is
        // left exactly as it was.
        try {
            WorkingPeriod::create($periods);
        } catch (WorkingHourException) {
            return null;
        }

        return $periods;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function rangesOf(WorkingPeriod $period): array
    {
        $ranges = [];
        foreach ($period as $range) {
            $ranges[] = [$range->start()->format('H:i'), $range->end()->format('H:i')];
        }

        return $ranges;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
