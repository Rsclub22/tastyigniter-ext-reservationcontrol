<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Events\WorkingScheduleCreatedEvent;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\EventPlan;
use Wagnersnetz\ReservationControl\EventSlots;

// 2030-06-14 is a Friday. Lunch is 11:30-15:00 on every weekday here.
const EVENT_DAY = '2030-06-14';

const REAL_MAERCHEN = 'MÄRCHENABEND 17 UHR MAX 30 PAX. NUR PER TELEFON BUCHBAR';
const REAL_CHRISTMAS = 'WEIHNACHTEN: 2 Gänge: 11 Uhr und 13 Uhr. NICHTS DAZWISCHEN ANNEHMEN. BUCHUNG NUMMER 161 NICHT STORNIEREN. ONLINE RESERVIERUNGEN AN DEM TAG NICHT VERFÜGBAR. MAX 120 PAX.';

function eventSchedule(array $lunch = [['11:30', '15:00']]): WorkingSchedule
{
    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $schedule = WorkingSchedule::create([0, 3000], array_fill_keys($days, $lunch));
    $schedule->setType('opening');

    return $schedule;
}

function eventNote(string $comment, string $time = '16:00:00', int $duration = 240, string $date = EVENT_DAY): Reservation
{
    return new Reservation([
        'comment' => $comment, 'reserve_time' => $time, 'duration' => $duration,
        'reserve_date' => $date, 'guest_num' => 999,
    ]);
}

/** The day's periods as the schedule serves them, e.g. "11:30-15:00,17:00-20:00". */
function dayOf(WorkingSchedule $schedule, string $date = EVENT_DAY): string
{
    return (string) $schedule->forDate(Carbon::parse($date));
}

it('adds the single event time beside lunch when the envelope lies outside the opening hours', function (): void {
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([eventNote('MÄRCHENABEND 17 UHR MAX 30 PAX. online buchbar')]));

    expect(dayOf($schedule))->toBe('11:30-15:00,17:00-17:05')
        // The other Fridays are untouched.
        ->and(dayOf($schedule, '2030-06-21'))->toBe('11:30-15:00');
});

it('lets the event times replace the day when the envelope covers lunch', function (): void {
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([eventNote('Weihnachtsmenü ab 11 Uhr, online buchbar', '10:00:00', 360)]));

    expect(dayOf($schedule))->toBe('11:00-11:05');
});

it('changes nothing without the online opt-in', function (): void {
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([eventNote(REAL_MAERCHEN), eventNote('Märchenabend 17 Uhr, nicht online buchbar')]));

    expect(dayOf($schedule))->toBe('11:30-15:00')
        ->and(ClosureNotes::eventPlan(eventNote(REAL_MAERCHEN))->status)->toBe(EventPlan::NOT_OPTED_IN)
        ->and(ClosureNotes::eventPlan(eventNote(REAL_MAERCHEN))->windows)->toBe([]);
});

it('reads the Märchenabend note: 17 Uhr, and not the 30 of "MAX 30 PAX"', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote(REAL_MAERCHEN.' online buchbar'), [['11:30', '15:00']]);

    expect($plan->stated)->toBe(['17:00'])
        ->and($plan->windows)->toBe([['17:00', '17:05']])
        ->and($plan->mode)->toBe(EventPlan::ADD)
        ->and($plan->envelope)->toBe(['16:00', '20:00'])
        ->and($plan->dropped)->toBe([]);
});

it('keeps the real Christmas note fully closed online: no opt-in, no slot', function (): void {
    $note = eventNote(REAL_CHRISTMAS, '10:00:00', 360, '2030-12-25');
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([$note]));

    expect(ClosureNotes::isOnlineOpen($note))->toBeFalse()
        ->and(ClosureNotes::eventPlan($note)->status)->toBe(EventPlan::NOT_OPTED_IN)
        // "2 Gänge" yields no time; the two sittings are the only ones found.
        ->and(ClosureNotes::eventPlan($note)->stated)->toBe(['11:00', '13:00'])
        // Unchanged: lunch exactly as the weekday has it, not 11:00-16:00.
        ->and(dayOf($schedule, '2030-12-25'))->toBe('11:30-15:00');
});

it('does not let an opt-in appended to the Christmas wording open anything', function (): void {
    $note = eventNote(REAL_CHRISTMAS.' online buchbar', '10:00:00', 360, '2030-12-25');
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([$note]));

    expect(dayOf($schedule, '2030-12-25'))->toBe('11:30-15:00');
});

it('drops a time outside the envelope without throwing and leaves the day as it was', function (): void {
    // A typo in an evening note: "12 UHR" lies in lunch, outside 16:00-20:00.
    $note = eventNote('MÄRCHENABEND 12 UHR, online buchbar');
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([$note]));

    $plan = ClosureNotes::eventPlan($note, [['11:30', '15:00']]);

    expect(dayOf($schedule))->toBe('11:30-15:00')
        ->and($plan->windows)->toBe([])
        ->and($plan->status)->toBe(EventPlan::NO_TIME)
        ->and($plan->dropped)->toBe([['time' => '12:00', 'reason' => EventPlan::OUTSIDE_ENVELOPE]]);
});

it('keeps the valid time and drops the typo when both stand in one note', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote('12 Uhr und 18 Uhr, online buchbar'), [['11:30', '15:00']]);

    expect($plan->windows)->toBe([['18:00', '18:05']])
        ->and($plan->dropped)->toBe([['time' => '12:00', 'reason' => EventPlan::OUTSIDE_ENVELOPE]]);
});

it('never hands overlapping periods over when two notes name times too close together', function (): void {
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([
        eventNote('Erstes 17 Uhr, online buchbar'),
        eventNote('Zweites 17:03 Uhr, online buchbar'),
    ]));

    // 17:00-17:05 and 17:03-17:08 overlap: the second is dropped, not merged or thrown.
    expect(dayOf($schedule))->toBe('11:30-15:00,17:00-17:05');
});

it('keeps two notes with times far enough apart as two separate single times', function (): void {
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([
        eventNote('Erstes 17 Uhr, online buchbar'),
        eventNote('Zweites 18 Uhr, online buchbar'),
    ]));

    expect(dayOf($schedule))->toBe('11:30-15:00,17:00-17:05,18:00-18:05');
});

it('opens nothing for a time it cannot read', function (string $comment): void {
    $note = eventNote($comment);
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([$note]));

    expect(dayOf($schedule))->toBe('11:30-15:00')
        ->and(ClosureNotes::eventPlan($note, [['11:30', '15:00']])->windows)->toBe([]);
})->with([
    'in words' => ['Märchenabend ab fünf Uhr, online buchbar'],
    'no time at all' => ['Märchenabend, online buchbar'],
    'hour 25' => ['Märchenabend 25 Uhr, online buchbar'],
    'minute 75' => ['Märchenabend 17:75 Uhr, online buchbar'],
    'a count, not a time' => ['2 Gänge, max 30 PAX, online buchbar'],
]);

it('reads 25 Uhr as an invalid time and says so', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote('Märchenabend 25 Uhr, online buchbar'));

    expect($plan->dropped)->toBe([['time' => '25:00', 'reason' => EventPlan::INVALID_TIME]]);
});

it('opens exactly the stated times: each one alone, the rest of the envelope stays closed', function (): void {
    $note = eventNote('Märchenabend 17 Uhr und 19:30 Uhr, online buchbar', '16:00:00', 300);
    $plan = ClosureNotes::eventPlan($note, [['11:30', '15:00']]);
    $schedule = eventSchedule();
    EventSlots::applyNotes($schedule, collect([$note]));

    expect($plan->windows)->toBe([['17:00', '17:05'], ['19:30', '19:35']])
        ->and($plan->stated)->toBe(['17:00', '19:30'])
        ->and(dayOf($schedule))->toBe('11:30-15:00,17:00-17:05,19:30-19:35');
});

it('cuts the range of a time short when the next stated time is closer than the slot width', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote('17 Uhr und 17:03 Uhr, online buchbar'), [['11:30', '15:00']]);

    expect($plan->windows)->toBe([['17:00', '17:03'], ['17:03', '17:08']]);
});

it('never lets a time at the very end of the envelope reach past it', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote('19:59 Uhr, online buchbar'), [['11:30', '15:00']]);

    expect($plan->windows)->toBe([['19:59', '20:00']]);
});

it('reads a bare 17:30 and ignores dates and numbers', function (): void {
    $plan = ClosureNotes::eventPlan(eventNote('Am 10.12. um 17:30 Buchung Nummer 1611, 24.12 Tisch 5, online buchbar'));

    expect($plan->stated)->toBe(['17:30']);
});

it('never reopens a day that is already an exception', function (): void {
    $schedule = eventSchedule();
    $schedule->setExceptions([EVENT_DAY => []]);
    EventSlots::applyNotes($schedule, collect([eventNote('Märchenabend 17 Uhr, online buchbar')]));

    expect(dayOf($schedule))->toBe('');
});

it('opens an event on a day the house is normally closed', function (): void {
    $schedule = eventSchedule([]);
    EventSlots::applyNotes($schedule, collect([eventNote('Märchenabend 17 Uhr, online buchbar')]));

    expect(dayOf($schedule))->toBe('17:00-17:05');
});

it('treats an overnight opening range as occupying the early hours', function (): void {
    // 18:00-01:00 is open at 00:30 of the same date; an envelope there is a replace, not an add.
    $plan = ClosureNotes::eventPlan(eventNote('Nachtmenü 00:30 Uhr, online buchbar', '00:00:00', 120), [['18:00', '01:00']]);

    expect($plan->mode)->toBe(EventPlan::REPLACE);
});

it('ignores a note whose envelope is empty or runs past midnight', function (): void {
    $empty = ClosureNotes::eventPlan(eventNote('Märchenabend 17 Uhr, online buchbar', '16:00:00', 0));
    $overnight = ClosureNotes::eventPlan(eventNote('Märchenabend 23 Uhr, online buchbar', '22:00:00', 180));

    expect($empty->status)->toBe(EventPlan::NO_ENVELOPE)
        ->and($empty->windows)->toBe([])
        ->and($overnight->status)->toBe(EventPlan::NO_ENVELOPE)
        ->and($overnight->windows)->toBe([]);
});

it('reaches the schedule through the created event, from the database', function (): void {
    foreach (['openingHours' => null, 'houseCapacity' => null] as $property => $value) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, $value);
    }

    $future = Carbon::today()->addDays(10);
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => $future->toDateString(), 'reserve_time' => '16:00:00',
        'reserve_datetime' => $future->toDateString().' 16:00:00', 'duration' => 240, 'status_id' => 1,
        'comment' => 'Märchenabend 17 Uhr, online buchbar',
        'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $opening = eventSchedule();
    WorkingScheduleCreatedEvent::dispatch(new Location, $opening);
    $delivery = eventSchedule();
    $delivery->setType('delivery');
    WorkingScheduleCreatedEvent::dispatch(new Location, $delivery);

    expect(dayOf($opening, $future->toDateString()))->toBe('11:30-15:00,17:00-17:05')
        // Only the opening schedule: nothing opens for delivery or collection.
        ->and(dayOf($delivery, $future->toDateString()))->toBe('11:30-15:00');
});
