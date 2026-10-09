<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Controllers;

use Carbon\Carbon;
use Igniter\Api\Classes\ApiController;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\DailySheet;
use Wagnersnetz\ReservationControl\DayData;
use Wagnersnetz\ReservationControl\Intake;
use Wagnersnetz\ReservationControl\Rooms;

/**
 * Phone intake as an API - the same data the internal web interface shows under
 * /intern, so that the desktop edition of the app can replace it.
 *
 * To draw the line: /intern is reachable without a login and is limited to the
 * local network for that reason (middleware InternalNetworkOnly, its own port
 * 8002, not in the reverse proxy). These endpoints hang off the public API and
 * are secured by a token instead - the token takes the place of the network
 * boundary. With its own ability "intern:*", a token for phone intake is not
 * automatically allowed everything else in the API.
 *
 * The calculations deliberately do NOT sit here but in DayData, ClosureNotes,
 * Rooms, BlockedDates and TableAllocator - the same classes the web interface
 * uses. The controller only reshapes.
 *
 * The request parameters and the JSON keys stay German: they are the contract
 * with the app at the counter, which is already in use.
 */
class InternalApiController extends ApiController
{
    protected string|array $requiredAbilities = ['intern:*'];

    /**
     * ApiController::checkAction() only lets through what stands here - an
     * empty list means 404 for every action, and as empty JSON at that, which
     * at first looks like a serialisation error when searching. For the REST
     * resources the RestController fills this from restConfig; actions of our
     * own have to enter themselves.
     *
     * The keys are the method names of this controller and have to be renamed
     * along with them.
     */
    public array $allowedActions = [
        'day' => [],
        'accept' => [],
        'dailySheet' => [],
        'month' => [],
        'pending' => [],
        'blockedDates' => [],
        'block' => [],
        'unblock' => [],
    ];

    /** Everything intake needs for one day. */
    public function day(Request $request): JsonResponse
    {
        $date = $this->date($request->query('datum'));
        $guests = max(1, (int) ($request->query('gaeste') ?? 2));
        $room = Rooms::find($request->query('raum'));

        $data = DayData::forDate($date, $guests, $room);

        return response()->json([
            'datum' => $date->toDateString(),
            'gaeste' => $guests,
            'raum' => $room ? $this->room($room) : null,

            // Blocked day: to a guest that reads as "closed".
            'gesperrt' => (bool) $data['gesperrt'],
            'online' => (bool) $data['online'],
            'grund' => (string) $data['grund'],
            'sperren' => $data['sperren'],

            'trennzeit' => $data['trennzeit'],
            'tische_gesamt' => (int) $data['tischeGesamt'],
            'plaetze_gesamt' => (int) $data['plaetzeGesamt'],

            // Lists already finished by DayData, field for field as there:
            // zeit, frei, gesamt, freie_plaetze, passt, groesster, raum,
            // ohne_tisch, pax_max, pax_belegt.
            'belegung' => array_values($data['belegung']),

            'raeume' => $data['raeume']->map(fn (DiningTable $r): array => $this->room($r))->values(),

            // Closure notes are ordinary reservations with more guests than the
            // house has seats. What counts as a time or a maximum inside them is
            // decided by the free-text evaluation in ClosureNotes - which is why
            // the results come along and are not rebuilt in the app.
            // ganztags: does the note take up the day, or does it only lock its
            // own time? A storytelling evening at 17:00 leaves the lunch service
            // open, a Christmas note over lunchtime does not.
            'vermerke' => $data['vermerke']->map(fn (Reservation $v): array => [
                'id' => (int) $v->reservation_id,
                'zeit' => substr((string) $v->reserve_time, 0, 5),
                'gaeste' => (int) $v->guest_num,
                'kommentar' => (string) ($v->comment ?? ''),
                'ganztags' => ClosureNotes::isAllDay($v, $date),
            ])->values(),
            'ganztags' => $data['ganztags']->isNotEmpty(),
            'vermerk_zeiten' => array_values($data['vermerkZeiten']),
            'max_pax' => $data['maxPax'],
            'pax_je_zeit' => $data['paxJeZeit'],
            'hausgroesse' => ClosureNotes::houseCapacity(),

            'reservierungen' => $data['reservierungen']
                ->map(fn (Reservation $r): array => $this->toList($r))->values(),
        ]);
    }

    /** The blocked days on which online booking is not possible. */
    public function blockedDates(): JsonResponse
    {
        return response()->json([
            'alle' => BlockedDates::all(),
            'kommende' => BlockedDates::upcoming(),
        ]);
    }

    /**
     * Take a reservation - the same relaxed required fields as on the phone:
     * surname and telephone number suffice, first name and e-mail are dropped.
     *
     * The maximum-number check runs here on the server and not in the app:
     * otherwise a second device would bypass it, one that does not know the
     * limit or shows a stale occupancy.
     */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'zeit' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'gaeste' => ['required', 'integer', 'min:1', 'max:200'],
            'nachname' => ['required', 'string', 'max:48'],
            'telefon' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email:filter', 'max:96'],
            'notiz' => ['nullable', 'string', 'max:500'],
            'raum' => ['nullable', 'integer'],
            // Explicitly without a table and without a room - the emergency
            // exit when the automation does not fit. Wins against a room
            // selection.
            'ohne_tisch' => ['nullable', 'boolean'],
        ]);

        $date = Carbon::parse($data['datum']);

        if ($message = Intake::maxPaxViolation($date, $data['zeit'], (int) $data['gaeste'])) {
            // 422 as for a validation error, so that the app can attach the
            // message to the field instead of reporting a server error.
            return response()->json([
                'message' => $message,
                'errors' => ['gaeste' => [$message]],
            ], 422);
        }

        $reservation = Intake::create($data);

        return response()->json([
            'reservierung' => $this->toList($reservation->refresh()),
        ], 201);
    }

    /**
     * The daily sheet - the same compilation /intern/druck puts on paper, only
     * as data. The typesetting happens in the app.
     *
     * Either a single day (datum) or a range (von/bis). For a range, empty days
     * stay out: Monday and Tuesday are closed, a month would otherwise need a
     * dozen sheets with nothing on them. A day with a closure note comes along
     * all the same - the note is precisely the message.
     */
    public function dailySheet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datum' => ['nullable', 'date'],
            'von' => ['nullable', 'date'],
            'bis' => ['nullable', 'date'],
            'trennzeit' => ['nullable', 'string', 'max:8'],
        ]);

        $splitTime = DayData::resolveSplitTime($data['trennzeit'] ?? null);
        $location = DayData::location();

        $from = $this->date($data['von'] ?? $data['datum'] ?? null);
        $to = $this->date($data['bis'] ?? $data['datum'] ?? null);

        // Do not reject a reversed entry, understand it.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= DailySheet::maxRangeDays()) {
            $to = $from->copy()->addDays(DailySheet::maxRangeDays() - 1);
        }

        $isRange = $from->ne($to);
        $days = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $sheet = DailySheet::forDay($location, $day->copy(), $splitTime);

            if ($isRange && $sheet['blaetter'] === [] && $sheet['sperrvermerke']->isEmpty()) {
                continue;
            }

            $days[] = [
                'datum' => $day->toDateString(),
                'gesperrt' => (bool) $sheet['gesperrt'],
                'online' => (bool) $sheet['online'],
                'grund' => (string) $sheet['grund'],
                'max_pax' => $sheet['maxPax'],
                'pax_je_zeit' => $sheet['paxJeZeit'],
                'sperrvermerke' => $sheet['sperrvermerke']->map(fn (Reservation $v): array => [
                    'id' => (int) $v->reservation_id,
                    'zeit' => substr((string) $v->reserve_time, 0, 5),
                    'kommentar' => (string) ($v->comment ?? ''),
                ])->values(),
                'blaetter' => array_map(fn (array $b): array => [
                    'titel' => (string) $b['titel'],
                    'gaeste' => (int) $b['reservierungen']->sum('guest_num'),
                    'reservierungen' => $b['reservierungen']
                        ->map(fn (Reservation $r): array => $this->toList($r))->values(),
                ], $sheet['blaetter']),
            ];
        }

        return response()->json([
            'von' => $from->toDateString(),
            'bis' => $to->toDateString(),
            'zeitraum' => $isRange,
            'trennzeit' => $splitTime,
            'tage' => $days,
        ]);
    }

    /**
     * Monthly overview: how full it is, per day.
     *
     * An endpoint of its own and not thirty calls of day - the calendar only
     * needs sums, not the time slots. All days of the month are delivered, the
     * empty ones included, so that the grid can be drawn without gaps.
     *
     * Closure notes do not count as guests - they are a bar, not a party - but
     * they are reported per day, because for intake they are the most important
     * information.
     */
    public function month(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jahr' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'monat' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $today = Carbon::today();
        $from = Carbon::create(
            (int) ($data['jahr'] ?? $today->year),
            (int) ($data['monat'] ?? $today->month),
            1,
        )->startOfDay();
        $to = $from->copy()->endOfMonth();

        $location = DayData::location();
        $houseCapacity = ClosureNotes::houseCapacity();

        $all = Reservation::query()
            ->where('location_id', $location->getKey())
            ->whereBetween('reserve_date', [$from->toDateString(), $to->toDateString()])
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->get()
            ->groupBy(fn (Reservation $r): string => Carbon::parse($r->reserve_date)->toDateString());

        $blocked = BlockedDates::entries();
        $days = [];
        $peak = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $ofTheDay = $all->get($key) ?? collect();

            $notes = $ofTheDay->filter(fn (Reservation $r): bool => ClosureNotes::isNote($r, $houseCapacity));
            $guests = $ofTheDay->reject(fn (Reservation $r): bool => ClosureNotes::isNote($r, $houseCapacity));

            $sum = (int) $guests->sum('guest_num');
            $peak = max($peak, $sum);

            $days[] = [
                'datum' => $key,
                'reservierungen' => $guests->count(),
                'gaeste' => $sum,
                'gesperrt' => array_key_exists($key, $blocked),
                'grund' => (string) ($blocked[$key]['grund'] ?? ''),
                'online' => ($blocked[$key]['online'] ?? false) === true,
                'vermerk' => $notes->isNotEmpty(),
                'vermerk_text' => (string) ($notes->first()?->comment ?? ''),
                'max_pax' => ClosureNotes::maxPax($notes),
            ];
        }

        return response()->json([
            'jahr' => $from->year,
            'monat' => $from->month,
            'von' => $from->toDateString(),
            'bis' => $to->toDateString(),
            // Largest guest count of the month. The app shades by it instead of
            // inventing a capacity: the rooms hold a multiple of the tables, a
            // fixed upper limit would be misleading on most days.
            'hoechstwert' => $peak,
            'tage' => $days,
        ]);
    }

    /**
     * Unconfirmed reservations - the ones that came in over the public form and
     * that somebody still has to confirm.
     *
     * Meant for checking back regularly, hence deliberately slim. Two numbers
     * with different purposes: `anzahl` are the ones added since `seit` - that
     * is what gets reported -, `offen_gesamt` are all unconfirmed ones - that is
     * what the marker in the list stands for.
     *
     * The distinction runs over the status and not over the user agent: an
     * intake over /intern carries the browser of the person at the counter and
     * would thus look like an online booking. What the house enters itself is
     * confirmed right away.
     *
     * `seit` is a reservation number, not a time. The model sets
     * $dateFormat = 'Y-m-d', which truncates created_at to the date - by time,
     * "new since last time" could not be answered at all.
     */
    public function pending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seit' => ['nullable', 'integer', 'min:0'],
        ]);

        $since = (int) ($data['seit'] ?? 0);
        $pendingStatus = (int) setting('default_reservation_status');
        $location = DayData::location();

        $pending = Reservation::query()
            ->with('tables')
            ->where('location_id', $location->getKey())
            ->where('status_id', $pendingStatus)
            ->orderBy('reservation_id')
            ->get();

        $new = $pending->filter(fn (Reservation $r): bool => (int) $r->reservation_id > $since);

        return response()->json([
            'seit' => $since,
            // The highest number issued, not the one of the newest unconfirmed
            // reservation: otherwise the marker would never move forward when
            // only confirmed reservations come in between, and the same report
            // would keep coming back.
            'hoechste_id' => (int) Reservation::query()
                ->where('location_id', $location->getKey())
                ->max('reservation_id'),
            'anzahl' => $new->count(),
            'offen_gesamt' => $pending->count(),
            // So that the app recognises closure notes in lists that come over
            // the ordinary /api/reservations as well: the marker is missing
            // there, and the rule is solely "more guests than seats in the
            // house".
            'hausgroesse' => ClosureNotes::houseCapacity(),
            'reservierungen' => $new->values()
                ->map(fn (Reservation $r): array => $this->toList($r))->values(),
        ]);
    }

    /** Mark a day; unless "online" is true it is blocked against online booking. */
    public function block(Request $request): JsonResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'grund' => ['nullable', 'string', 'max:190'],
            'online' => ['nullable', 'boolean'],
            'hinweis' => ['nullable', 'string', 'max:300'],
        ]);

        $date = Carbon::parse($data['datum'])->toDateString();
        BlockedDates::block($date, (string) ($data['grund'] ?? ''), $request->boolean('online'), trim((string) ($data['hinweis'] ?? '')));

        return response()->json(['gesperrt' => $date, 'alle' => BlockedDates::all()]);
    }

    /** Lift a block again. */
    public function unblock(Request $request): JsonResponse
    {
        $data = $request->validate(['datum' => ['required', 'date']]);

        $date = Carbon::parse($data['datum'])->toDateString();
        BlockedDates::unblock($date);

        return response()->json(['freigegeben' => $date, 'alle' => BlockedDates::all()]);
    }

    private function date(mixed $raw): Carbon
    {
        try {
            return $raw ? Carbon::parse((string) $raw)->startOfDay() : Carbon::today();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    private function room(DiningTable $room): array
    {
        return [
            'id' => (int) $room->getKey(),
            'name' => (string) $room->name,
            'min_plaetze' => (int) $room->min_capacity,
            'max_plaetze' => (int) $room->max_capacity + (int) $room->extra_capacity,
        ];
    }

    private function toList(Reservation $r): array
    {
        return [
            'id' => (int) $r->reservation_id,
            // Superfluous in the context of a day, but needed for reports:
            // there the reservation stands without its day.
            'datum' => Carbon::parse($r->reserve_date)->toDateString(),
            'zeit' => substr((string) $r->reserve_time, 0, 5),
            'dauer' => (int) $r->duration,
            'gaeste' => (int) $r->guest_num,
            'name' => trim($r->first_name.' '.$r->last_name),
            'telefon' => (string) ($r->telephone ?? ''),
            'kommentar' => (string) ($r->comment ?? ''),
            'status_id' => (int) $r->status_id,
            'status' => (string) ($r->status_name ?? ''),
            'tische' => $r->tables->map(fn (DiningTable $t): array => [
                'id' => (int) $t->getKey(),
                'name' => (string) $t->name,
            ])->values(),
            // Marks the pseudo reservations that lock a day.
            'ist_vermerk' => ClosureNotes::isNote($r),
        ];
    }
}
