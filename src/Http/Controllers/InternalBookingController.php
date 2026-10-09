<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Controllers;

use Carbon\Carbon;
use Igniter\Admin\Models\Status;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\DailySheet;
use Wagnersnetz\ReservationControl\DayData;
use Wagnersnetz\ReservationControl\Intake;
use Wagnersnetz\ReservationControl\Rooms;

/**
 * Phone intake for reservations, reachable from the local network only.
 *
 * Deliberately different from the public page:
 *   - no mandatory e-mail (on the phone nobody wants to ask for it)
 *   - surname and telephone number only, no first name
 *   - status immediately "confirmed" instead of "pending"
 *   - no mail sent, to nobody
 *   - the occupancy of the day at a glance
 *
 * No mail is sent because BookingManager::saveReservation() is NOT used here:
 * that would fire "igniter.reservation.confirmed", upon which
 * SendReservationConfirmation sends three mails. The status history is created
 * with notify=false so that the status mail stays out as well.
 *
 * The request parameters and the keys of the view data stay German: they are
 * the field names of the forms and the contract with the app at the counter.
 */
class InternalBookingController extends Controller
{
    public function index(Request $request): View
    {
        $date = $this->resolveDate($request->query('datum'));
        $guests = max(1, (int) $request->query('gaeste', 2));
        $room = Rooms::find($request->query('raum'));

        return view('reservationcontrol::intern', $this->pageData($date, $guests, $room, $this->justCreated($request, $date)));
    }

    /**
     * Daily sheet for the folder.
     *
     * The day is split in two sheets at a time of day - midday and evening
     * would otherwise lie mixed up in the folder. The split time is set per
     * INTERN_DRUCK_TRENNZEIT in the .env and can be overridden when printing:
     * at Christmas there are only two sittings, and their boundary is not at
     * 15:00. "aus" ("off") prints the day in one piece.
     *
     * A section without reservations is not printed.
     */
    public function printDay(Request $request): View
    {
        $splitTime = $this->splitTime($request->query('trennzeit'));
        $location = $this->location();

        [$from, $to] = $this->dateRange($request);
        $batchPrint = $from->ne($to);

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $sheet = $this->dailySheets($location, $day->copy(), $splitTime);

            // Skip empty days in a batch print: Monday and Tuesday are closed,
            // a month would otherwise need a dozen sheets with nothing on them.
            // A day with a closure note but without guests is printed all the
            // same - the note is precisely the message for that day.
            if ($batchPrint && $sheet['blaetter'] === [] && $sheet['sperrvermerke']->isEmpty()) {
                continue;
            }

            $days[] = $sheet;
        }

        return view('reservationcontrol::intern-druck', [
            'tage' => $days,
            'von' => $from,
            'bis' => $to,
            'sammeldruck' => $batchPrint,
            'standort' => $location,
            'trennzeit' => $splitTime,
            'bestaetigt' => (int) setting('confirmed_reservation_status'),
            'status' => Status::query()->where('status_for', 'reservation')
                ->pluck('status_name', 'status_id')->all(),
            'gedruckt' => Carbon::now(),
        ]);
    }

    /** Date range to print. Without from/to it stays at the single day. */
    private function dateRange(Request $request): array
    {
        if ($request->query('modus') !== 'zeitraum') {
            $day = $this->resolveDate($request->query('datum'));

            return [$day, $day->copy()];
        }

        $from = $this->resolveDate($request->query('von') ?: $request->query('datum'));
        $to = $this->resolveDate($request->query('bis') ?: $request->query('datum'));

        // Do not reject a reversed entry, understand it.
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= DailySheet::MAX_DAYS) {
            $to = $from->copy()->addDays(DailySheet::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    /** Everything a single day puts on paper. */
    private function dailySheets(Location $location, Carbon $date, ?string $splitTime): array
    {
        return DailySheet::forDay($location, $date, $splitTime);
    }

    /** Split time as HH:MM, or null for a single sheet. */
    private function splitTime(?string $raw): ?string
    {
        return DayData::splitTime($raw);
    }

    public function store(Request $request): RedirectResponse
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
        ], [], [
            'datum' => __('reservationcontrol::default.attribute_date'),
            'zeit' => __('reservationcontrol::default.attribute_time'),
            'gaeste' => __('reservationcontrol::default.attribute_guests'),
            'nachname' => __('reservationcontrol::default.attribute_last_name'),
            'telefon' => __('reservationcontrol::default.attribute_telephone'),
            'email' => __('reservationcontrol::default.attribute_email'),
            'notiz' => __('reservationcontrol::default.attribute_note'),
        ]);

        $date = Carbon::parse($data['datum']);

        // The check sits in Intake so that it is the same one for the web
        // interface and the API. Two open browser windows on a full Christmas
        // day are exactly the case in which a display limit alone comes too
        // late.
        if ($message = Intake::maxPaxViolation($date, $data['zeit'], (int) $data['gaeste'])) {
            return $this->backTo($request, $date->toDateString())
                ->withErrors(['gaeste' => $message]);
        }

        $reservation = Intake::create($data);

        // Neither route() nor a relative path: TastyIgniter enforces APP_URL
        // (urlPolicy=force), Laravel would build the public address out of it -
        // and /intern is barred there. Hence explicitly the host of the current
        // request, so that intake stays on the LAN port.
        // The confirmation hangs off the number in the address, not off a flash
        // message: that would be gone after the next click or a reload. On the
        // phone you still want to see it, though, while repeating the time back
        // to the guest.
        $target = $request->getSchemeAndHttpHost().'/intern?'.http_build_query(array_filter([
            'datum' => $data['datum'],
            'gaeste' => $data['gaeste'],
            'raum' => $data['raum'] ?? null,
            'neu' => $reservation->getKey(),
        ]));

        return redirect()->to($target);
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'datum' => ['required', 'date'],
            'grund' => ['nullable', 'string', 'max:120'],
        ], [], [
            'datum' => __('reservationcontrol::default.attribute_date'),
            'grund' => __('reservationcontrol::default.attribute_reason'),
        ]);

        $date = Carbon::parse($data['datum'])->toDateString();
        $open = Reservation::query()
            ->whereDate('reserve_date', $date)
            ->whereNotIn('status_id', [0, (int) setting('canceled_reservation_status')])
            ->count();

        // Blocking a day on which guests are already expected is almost always
        // a mistake - hence reject it instead of blocking silently.
        if ($open > 0) {
            return $this->backTo($request, $date)->withErrors([
                'datum' => trans_choice('reservationcontrol::default.error_day_has_reservations', $open, [
                    'date' => $this->formatDate($date, 'format_day_month'),
                ]),
            ]);
        }

        BlockedDates::block($date, trim((string) ($data['grund'] ?? '')));

        return $this->backTo($request, $date)->with('hinweis', __('reservationcontrol::default.notice_day_blocked', [
            'date' => $this->formatDate($date, 'format_weekday_date'),
        ]));
    }

    public function unblock(Request $request): RedirectResponse
    {
        $data = $request->validate(['datum' => ['required', 'date']], [], ['datum' => 'Datum']);
        $date = Carbon::parse($data['datum'])->toDateString();

        BlockedDates::unblock($date);

        return $this->backTo($request, $date)->with('hinweis', __('reservationcontrol::default.notice_day_unblocked', [
            'date' => $this->formatDate($date, 'format_weekday_date'),
        ]));
    }

    /** A date in the format and language of the current locale. */
    private function formatDate(string $date, string $formatKey): string
    {
        return Carbon::parse($date)
            ->locale(app()->getLocale())
            ->isoFormat(__('reservationcontrol::default.'.$formatKey));
    }

    private function backTo(Request $request, string $date): RedirectResponse
    {
        return redirect()->to(
            $request->getSchemeAndHttpHost().'/intern?'.http_build_query(['datum' => $date]),
        );
    }

    /** Reservation just taken, from ?neu=... - only from the day on display. */
    private function justCreated(Request $request, Carbon $date): ?Reservation
    {
        if (! $id = (int) $request->query('neu')) {
            return null;
        }

        return Reservation::query()
            ->with('tables')
            ->whereDate('reserve_date', $date->toDateString())
            ->find($id);
    }

    private function pageData(Carbon $date, int $guests, ?DiningTable $room = null, ?Reservation $new = null): array
    {
        return DayData::forDate($date, $guests, $room, $new);
    }

    private function resolveDate(?string $raw): Carbon
    {
        try {
            $date = $raw ? Carbon::parse($raw) : Carbon::today();
        } catch (\Throwable) {
            $date = Carbon::today();
        }

        return $date->startOfDay();
    }

    private function location(): Location
    {
        return DayData::location();
    }
}
