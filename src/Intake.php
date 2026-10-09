<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Reservation\Models\Reservation;

/**
 * Taking a reservation over the phone.
 *
 * Used to sit in InternalBookingController::store(). Pulled out because the
 * intake is now needed twice - by the internal web interface and by the API
 * that serves the desktop edition of the app. The maximum-number check above
 * all has to live in one place: it is the reason why two devices do not
 * overbook a full Christmas day at the same time.
 *
 * No mail is sent, to nobody: BookingManager::saveReservation() is NOT used
 * here, that would fire "igniter.reservation.confirmed" and send three mails.
 * The status history is created with notify=false so that the status mail stays
 * out as well.
 */
class Intake
{
    /**
     * Does a maximum number from a closure note stand in the way of taking
     * this reservation?
     *
     * @return string|null the message in plain words, or null when nothing stands in the way
     */
    public static function maxPaxViolation(Carbon $date, string $time, int $guests): ?string
    {
        // Only the notes that apply to this time of day at all: an evening note
        // does not cap the lunch service.
        $notes = ClosureNotes::forTime(ClosureNotes::onDate($date), $date, $time);

        // When a number of its own stands in the text for this time slot, it
        // applies; otherwise the single number for all sittings.
        $maxPax = ClosureNotes::maxPaxPerTime($notes)[$time]
            ?? ClosureNotes::maxPax($notes);

        if ($maxPax === null) {
            return null;
        }

        // Everything already standing at this time is counted - rooms
        // included: the kitchen does not distinguish where people sit.
        $already = (int) (ClosureNotes::occupancyPerTime($date)[$time] ?? 0);

        if ($already + $guests <= $maxPax) {
            return null;
        }

        return __('reservationcontrol::default.error_max_pax', [
            'time' => $time,
            'already' => $already,
            'max' => $maxPax,
            'guests' => $guests,
            'free' => max(0, $maxPax - $already),
        ]);
    }

    /**
     * Creates the reservation.
     *
     * The keys of $data are German because they are the field names of the form
     * in the Blade view and of the JSON request the app at the counter sends.
     *
     * @param array{datum: string, zeit: string, gaeste: int, nachname: string,
     *     telefon: string, email?: ?string, notiz?: ?string, raum?: mixed,
     *     ohne_tisch?: bool} $data
     */
    public static function create(array $data): Reservation
    {
        $location = DayData::location();
        $date = Carbon::parse($data['datum']);
        $notes = ClosureNotes::forTime(ClosureNotes::onDate($date), $date, $data['zeit']);

        $reservation = new Reservation;
        $reservation->location_id = $location->getKey();
        $reservation->guest_num = (int) $data['gaeste'];
        // On the phone only the surname is asked for. The column is NOT NULL,
        // hence an empty string instead of repeating the surname.
        $reservation->first_name = '';
        $reservation->last_name = $data['nachname'];
        $reservation->telephone = $data['telefon'];
        // Column is NOT NULL; without an entry it stays empty instead of
        // inventing a bogus address that someone later tries to send to.
        $reservation->email = $data['email'] ?? '';
        $reservation->comment = $data['notiz'] ?? null;
        $reservation->reserve_date = $data['datum'];
        $reservation->reserve_time = $data['zeit'].':00';
        $reservation->duration = $location->getReservationStayTime();
        $reservation->status_id = (int) setting('confirmed_reservation_status');

        $room = Rooms::find($data['raum'] ?? null);

        // Explicitly without an assignment: no table, no room. This is the
        // emergency exit for the cases in which the automation does not fit -
        // for instance when the guest is yet to call back about where to sit,
        // or when the distribution for that day is done by hand anyway. Wins
        // against a room selection so that the entry stays unambiguous.
        if (! empty($data['ohne_tisch'])) {
            $reservation->tables = [];
        } elseif (! $room && $notes->isNotEmpty()) {
            // At a time for which a closure note applies, the table plan on
            // paper does the distribution, not the system. The empty value is
            // not an oversight here but the message to the observer in
            // Extension.php: hands off, the assignment is done by hand. (It
            // would not find anything anyway, the note occupies all tables -
            // but relying on a side effect is not an intention.)
            $reservation->tables = [];
        } elseif ($room) {
            // The observer only assigns when no table is attached yet. So pass
            // the room right away, then the automation stays out of it.
            $reservation->tables = [$room->getKey()];
        }

        $reservation->save();

        // notify=false: no status mail to the guest.
        // Stays German on purpose: the status history already holds years of German rows.
        $reservation->addStatusHistory(
            (int) setting('confirmed_reservation_status'),
            ['notify' => false, 'comment' => 'Telefonisch angenommen'],
        );

        return $reservation;
    }
}
