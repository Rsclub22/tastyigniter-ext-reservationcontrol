<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;

/**
 * Everything phone intake needs to know about a day: time slots with their
 * occupancy, blocked days, closure notes including their maximum numbers,
 * rooms, table counts.
 *
 * Used to sit as pageData() in the controller of the internal page. Pulled out
 * because the same calculation is now needed twice - by the web interface and
 * by the API that serves the desktop edition of the app. Two versions of it
 * would drift apart, and the mistake only shows up once two parties get the
 * same time on a Christmas day.
 *
 * The array keys returned by forDate() are German because they are the contract
 * with the Blade views and with the JSON of InternalApiController, which the app
 * at the counter reads. They are left untouched on purpose.
 */
class DayData
{
    /** Default for when the daily sheet switches to a second sheet. */
    public const string DEFAULT_SPLIT_TIME = '15:00';

    public static function forDate(Carbon $date, int $guests, ?DiningTable $room = null, ?Reservation $new = null): array
    {
        $location = self::location();

        /** @var LargePartyBookingManager $manager */
        $manager = resolve(BookingManager::class);
        $manager->useLocation($location);
        if ($manager instanceof LargePartyBookingManager) {
            $manager->forceGuestCount($guests)->allowSameDay();
        }

        $slots = collect($manager->makeTimeSlots($date))->map(fn ($t): Carbon => Carbon::parse($t));

        $notes = ClosureNotes::onDate($date);
        $noteTimes = ClosureNotes::times($notes);
        $maxPax = ClosureNotes::maxPax($notes);
        $paxPerTime = ClosureNotes::maxPaxPerTime($notes);

        // All assignable tables, combinations and their individual tables.
        $tables = TableAllocator::candidates($location->getKey());

        // For counting, only the real tables: a combination is the same seats
        // once more, it would report the capacity twice.
        $realTables = $tables->where('is_combo', false);

        $reservations = TableAllocator::reservationsOn($location->getKey(), $date);

        $roomReservations = Rooms::reservationsOn($date);

        $duration = (int) $location->getReservationStayTime();

        $allDay = ClosureNotes::allDay($notes, $date);

        // When a closure note lies over the day, all tables are occupied. The
        // usual check then reports every time as full and phone intake would be
        // closed - but that is not what the note is there for. On the phone we
        // keep accepting, just without a table: on such days the distribution
        // is done by the table plan on paper, not by the system.
        //
        // When the note names times ("2 Gaenge: 11 Uhr und 13 Uhr"), only those
        // are offered - even when they lie outside the opening hours, because
        // on such days they apply and not the weekly schedule. When no time
        // stands in the text, the usual time slots remain: better to offer too
        // much than to accidentally shut the day down.
        //
        // A selected room takes precedence: barn, cellar and hall do not hang
        // off the tables and are freely assignable even on such days.
        if (! $room && $allDay->isNotEmpty()) {
            $times = $noteTimes ?: $slots->map(fn (Carbon $s): string => $s->format('H:i'))->all();

            // When the note names a maximum, it applies per time slot.
            // Everything already standing at that time is counted - rooms
            // included: the kitchen does not distinguish where people sit.
            $taken = $maxPax === null ? [] : ClosureNotes::occupancyPerTime($date);

            $occupancy = array_map(static function (string $time) use ($maxPax, $paxPerTime, $taken, $guests): array {
                $already = (int) ($taken[$time] ?? 0);

                // When a number of its own stands in the text for this sitting,
                // it applies; otherwise the single number for all sittings.
                $maxPax = $paxPerTime[$time] ?? $maxPax;

                return [
                    'zeit' => $time,
                    'frei' => $maxPax === null ? 0 : max(0, $maxPax - $already),
                    'gesamt' => $maxPax ?? 0,
                    'freie_plaetze' => $maxPax === null ? 0 : max(0, $maxPax - $already),
                    'passt' => $maxPax === null || $already + $guests <= $maxPax,
                    'groesster' => 0,
                    'raum' => null,
                    'ohne_tisch' => true,
                    'pax_max' => $maxPax,
                    'pax_belegt' => $already,
                ];
            }, $times);
        } else {
            $occupancy = $slots->map(function (Carbon $slot) use ($date, $tables, $realTables, $duration, $reservations, $guests, $room, $roomReservations): array {
                $at = $date->copy()->setTimeFromTimeString($slot->format('H:i'));

                // When a room is chosen, only its occupancy counts - the tables
                // are then irrelevant, and the number of persons no longer
                // limits anything.
                if ($room) {
                    $free = Rooms::isFreeAt($room, $at, $roomReservations);

                    return [
                        'zeit' => $slot->format('H:i'),
                        'frei' => $free ? 1 : 0,
                        'gesamt' => 1,
                        'freie_plaetze' => 0,
                        'passt' => $free,
                        'groesster' => 0,
                        'raum' => $room->name,
                        'ohne_tisch' => false,
                        'pax_max' => null,
                        'pax_belegt' => 0,
                    ];
                }

                $free = TableAllocator::freeAt($tables, $at, $duration, $reservations);
                $freeReal = $free->where('is_combo', false);

                return [
                    'zeit' => $slot->format('H:i'),
                    'frei' => $freeReal->count(),
                    'gesamt' => $realTables->count(),
                    'freie_plaetze' => (int) $freeReal->sum('max_capacity'),
                    'passt' => TableAllocator::pick($free, $guests) !== null,
                    'groesster' => (int) $free->max(fn ($t): int => $t->max_capacity + $t->extra_capacity) ?: 0,
                    'raum' => null,
                    'ohne_tisch' => false,
                    'pax_max' => null,
                    'pax_belegt' => 0,
                ];
            })->all();

            // A note beside the opening hours does not take up the day, but it
            // has times of its own: the storytelling evening at 17:00 stands up
            // for intake on the phone without closing the lunch service. Without
            // a table, because at that time the tables are occupied by the note.
            if (! $room && $notes->isNotEmpty()) {
                $known = array_column($occupancy, 'zeit');
                $taken = ClosureNotes::occupancyPerTime($date);

                foreach ($noteTimes as $time) {
                    if (in_array($time, $known, true)) {
                        continue;
                    }

                    $limit = $paxPerTime[$time] ?? $maxPax;
                    $already = (int) ($taken[$time] ?? 0);

                    $occupancy[] = [
                        'zeit' => $time,
                        'frei' => $limit === null ? 0 : max(0, $limit - $already),
                        'gesamt' => $limit ?? 0,
                        'freie_plaetze' => $limit === null ? 0 : max(0, $limit - $already),
                        'passt' => $limit === null || $already + $guests <= $limit,
                        'groesster' => 0,
                        'raum' => null,
                        'ohne_tisch' => true,
                        'pax_max' => $limit,
                        'pax_belegt' => $already,
                    ];
                }

                usort($occupancy, static fn (array $a, array $b): int => strcmp($a['zeit'], $b['zeit']));
            }
        }

        return [
            'datum' => $date,
            'gaeste' => $guests,
            'gesperrt' => BlockedDates::isBlocked($date->toDateString()),
            'grund' => BlockedDates::all()[$date->toDateString()] ?? '',
            'online' => BlockedDates::isOnlineBookable($date->toDateString()),
            'sperren' => BlockedDates::upcoming(),
            'raeume' => Rooms::all(),
            'raum' => $room,
            'raumBelegung' => $roomReservations,
            'belegung' => $occupancy,
            'neu' => $new,
            'reservierungen' => $reservations,
            'tischeGesamt' => $realTables->count(),
            'plaetzeGesamt' => (int) $realTables->sum('max_capacity'),
            'standort' => $location,
            'vermerke' => $notes,
            'ganztags' => $allDay,
            'vermerkZeiten' => $noteTimes,
            'maxPax' => $maxPax,
            'paxJeZeit' => $paxPerTime,
            'trennzeit' => self::resolveSplitTime(null),
        ];
    }

    /**
     * The configured split time of a location, or null for a single sheet.
     *
     * The setting is global: $locationId exists so that callers say which
     * location they mean and a per-location setting can arrive later without
     * changing every call site. Today every location gets the same answer.
     */
    public static function splitTime(int $locationId): ?string
    {
        return self::resolveSplitTime(null);
    }

    /**
     * Split time as HH:MM, or null for a single sheet, from user input; without
     * input the configured value.
     *
     * The env variable and the literal "aus" stay as they are because they are
     * configuration of a running installation; "off" is accepted as well, so
     * that the English hint on the print form tells the truth.
     */
    public static function resolveSplitTime(?string $raw): ?string
    {
        $raw = trim((string) ($raw ?? SettingValue::nullableString('split_time') ?? env('INTERN_DRUCK_TRENNZEIT', self::DEFAULT_SPLIT_TIME)));

        if ($raw === '' || in_array(strtolower($raw), ['aus', 'off'], true)) {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $matches)) {
            return self::DEFAULT_SPLIT_TIME;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        return $hour <= 23 && $minute <= 59
            ? sprintf('%02d:%02d', $hour, $minute)
            : self::DEFAULT_SPLIT_TIME;
    }

    public static function location(): Location
    {
        $location = Location::query()->whereIsEnabled()->first();
        abort_if(! $location, 500, __('reservationcontrol::default.error_no_active_location'));

        return $location;
    }
}
