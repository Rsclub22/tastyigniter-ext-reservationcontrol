<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;

/**
 * The daily sheet: what belongs on paper for one day.
 *
 * Used to sit as tagesblaetter()/blaetter() in the controller of the internal
 * page. Pulled out because the same compilation is now needed twice - by the
 * print view and by the API through which the desktop edition of the app
 * replaces the internal page.
 *
 * The array keys below are German because they are the contract with the Blade
 * views and with the JSON of InternalApiController, which the app at the
 * counter reads. They are left untouched on purpose.
 */
class DailySheet
{
    /**
     * Upper limit for the batch print. Without it, a mistyped date range would
     * be an order spanning years.
     */
    public const int DEFAULT_MAX_DAYS = 92;

    public static function maxRangeDays(): int
    {
        return SettingValue::int('max_print_range_days', self::DEFAULT_MAX_DAYS);
    }

    /** Everything a single day puts on paper. */
    public static function forDay(Location $location, Carbon $date, ?string $splitTime): array
    {
        // Deliberately NOT TableAllocator::reservationsOn(): that excludes
        // status_id = 0, because such a record does not count towards
        // occupancy. On paper it still has to appear - a reservation nobody
        // sees is exactly the case this sheet is meant to prevent. Tables are
        // only loaded, not required: some of the reservations deliberately
        // have none.
        $all = Reservation::query()
            ->with('tables')
            ->where('location_id', $location->getKey())
            ->whereDate('reserve_date', $date->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->orderBy('reserve_time')
            ->orderBy('reservation_id')
            ->get();

        // Sort out the closure notes: entries carrying more guests than fit
        // into the house at all are not a party but a bar against online
        // booking. As a guest line they would be useless (one line with all
        // fourteen tables) and they would render the guest count in the header
        // useless too. Their text is often the most important information of
        // the day, though - it therefore comes as a banner above the table.
        $houseCapacity = ClosureNotes::houseCapacity();

        $isNote = static fn (Reservation $r): bool => ClosureNotes::isNote($r, $houseCapacity);

        return [
            'datum' => $date,
            'blaetter' => self::sheets($all->reject($isNote)->values(), $splitTime),
            'sperrvermerke' => $all->filter($isNote)->values(),
            'maxPax' => ClosureNotes::maxPax($all->filter($isNote)),
            'paxJeZeit' => ClosureNotes::maxPaxPerTime($all->filter($isNote)),
            'gesperrt' => BlockedDates::isBlocked($date->toDateString()),
            'grund' => BlockedDates::all()[$date->toDateString()] ?? '',
            'online' => BlockedDates::isOnlineBookable($date->toDateString()),
        ];
    }

    /** Spread the reservations of a day across the sheets. */
    private static function sheets(Collection $all, ?string $splitTime): array
    {
        $timeOf = static fn (Reservation $r): string => Carbon::parse($r->reserve_time)->format('H:i');

        if ($splitTime === null) {
            return $all->isEmpty() ? [] : [['titel' => __('reservationcontrol::default.label_full_day'), 'reservierungen' => $all]];
        }

        $sections = [
            ['titel' => __('reservationcontrol::default.label_until_time', ['time' => $splitTime]), 'reservierungen' => $all->filter(
                static fn (Reservation $r): bool => $timeOf($r) < $splitTime,
            )->values()],
            ['titel' => __('reservationcontrol::default.label_from_time', ['time' => $splitTime]), 'reservierungen' => $all->filter(
                static fn (Reservation $r): bool => $timeOf($r) >= $splitTime,
            )->values()],
        ];

        return array_values(array_filter(
            $sections,
            static fn (array $s): bool => $s['reservierungen']->isNotEmpty(),
        ));
    }
}
