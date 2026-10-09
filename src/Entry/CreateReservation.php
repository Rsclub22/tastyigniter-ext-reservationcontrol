<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Entry;

use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * Create a reservation from the console and take it back again.
 *
 * Deliberately over save() and not saveQuietly(): the table assignment of this
 * extension hangs off saved(), the filling of the NOT NULL columns off
 * saving(). A quiet save would bypass both and produce records that behave
 * differently from everything coming in over /intern or the backend.
 */
class CreateReservation
{
    /**
     * Stands in user_agent and is the only handle for --undo: only what carries
     * this marker may be deleted again.
     *
     * The value keeps the old "reservetweaks" name on purpose: live
     * installations have it written into their records, and renaming it would
     * make every reservation imported so far undeletable.
     */
    public const string MARKER = 'reservetweaks-cli';

    /**
     * On the console there is no request. The observer of the reservation
     * extension fills ip_address and user_agent from request(), though - both
     * columns are NOT NULL, and without the following the insert breaks off. At
     * the same time this sets the marker exactly where the observer writes it
     * anyway, instead of overwriting it afterwards.
     */
    public static function prepareConsole(): void
    {
        request()->server->set('REMOTE_ADDR', '127.0.0.1');
        request()->headers->set('User-Agent', self::MARKER);
    }

    /**
     * @param array{
     *     location_id: int, reserve_date: string, reserve_time: string,
     *     guest_num: int, first_name?: string, last_name?: string,
     *     email?: string, telephone?: string, comment?: ?string,
     *     duration?: ?int, status_id: int, occasion_id?: ?int, table_ids: int[]
     * } $data
     */
    public static function create(array $data, string $historyComment): Reservation
    {
        self::prepareConsole();

        return DB::transaction(function () use ($data, $historyComment): Reservation {
            $r = new Reservation;
            $r->location_id = $data['location_id'];
            $r->guest_num = $data['guest_num'];
            $r->occasion_id = $data['occasion_id'] ?? null;
            $r->first_name = $data['first_name'] ?? '';
            $r->last_name = $data['last_name'] ?? '';
            // Columns are NOT NULL. Empty string instead of an invented
            // address - someone would later try to send to such a one.
            $r->email = $data['email'] ?? '';
            $r->telephone = $data['telephone'] ?? '';
            $r->comment = ($data['comment'] ?? '') !== '' ? $data['comment'] : null;
            $r->reserve_date = $data['reserve_date'];
            $r->reserve_time = $data['reserve_time'].':00';
            // Leaving it empty is allowed: setDurationAttribute() then puts in
            // the stay time of the location.
            $r->duration = $data['duration'] ?? null;
            $r->notify = false;
            $r->status_id = $data['status_id'];

            // Always set, even empty. The extension reads exactly this to tell
            // whether the tables were chosen by hand, and then holds its own
            // assignment back - so an empty field explicitly means "without a
            // table" and not "go pick one".
            $r->tables = $data['table_ids'];

            $r->save();

            // notify=false: the guest gets no mail. For a reservation entered
            // after the fact it would be confusing, they already had their
            // table confirmed on the phone.
            $r->addStatusHistory($data['status_id'], [
                'notify' => false,
                'comment' => $historyComment,
            ]);

            return $r->refresh();
        });
    }

    /**
     * Take it back. Only touches records of our own - whoever writes the number
     * of a reservation created by hand in the backend into a log file should
     * not be able to delete it with this.
     */
    public static function undo(int $id): bool
    {
        $r = Reservation::find($id);

        if (! $r || $r->user_agent !== self::MARKER) {
            return false;
        }

        DB::transaction(function () use ($r, $id): void {
            DB::table('reservation_tables')->where('reservation_id', $id)->delete();
            DB::table('status_history')
                ->where('object_type', $r->getMorphClass())
                ->where('object_id', $id)
                ->delete();
            $r->deleteQuietly();
        });

        return true;
    }
}
