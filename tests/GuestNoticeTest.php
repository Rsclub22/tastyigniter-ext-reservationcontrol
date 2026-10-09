<?php

declare(strict_types=1);

use Igniter\Reservation\Models\Reservation;
use Igniter\System\Models\Settings as SystemSettings;
use Illuminate\Support\Facades\DB;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\GuestNotice;

const NOTICE_DAY = '2030-12-31';

beforeEach(function (): void {
    // ClosureNotes caches per request; the testbench runs many "requests" in one process.
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
});

/** A closure note (more guests than the house seats) on $date with this comment. */
function noticeNote(string $comment, string $date = NOTICE_DAY, ?int $status = null): void
{
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => $date, 'reserve_time' => '18:00:00',
        'reserve_datetime' => $date.' 18:00:00', 'duration' => 120, 'status_id' => $status ?? 1,
        'comment' => $comment, 'ip_address' => '', 'user_agent' => '',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function noteWith(string $comment): Reservation
{
    return new Reservation(['comment' => $comment]);
}

// ---- the guest text inside a closure note ------------------------------------------

it('reads the guest text after "online buchbar:" to the end of the line', function (string $comment, string $text): void {
    expect(ClosureNotes::guestText(noteWith($comment)))->toBe($text);
})->with([
    'the documented example' => ['Märchenabend, max 60 PAX, online buchbar: Märchenabend mit Menü ab 18 Uhr', 'Märchenabend mit Menü ab 18 Uhr'],
    'text only' => ['online buchbar: Nur heute', 'Nur heute'],
    'blanks around the colon' => ["Fest, online buchbar \t:   Mit Musik  ", 'Mit Musik'],
    'a colon inside the text' => ['online buchbar: Beginn: 18 Uhr', 'Beginn: 18 Uhr'],
    'keyword in capitals' => ['ONLINE BUCHBAR: Hallo', 'Hallo'],
    'wieder' => ['online wieder buchbar: Wieder offen', 'Wieder offen'],
    'no colon' => ['Märchenabend, online buchbar', ''],
    'no colon, more text after' => ['online buchbar mit Menü', ''],
    'colon, nothing after it' => ['Märchenabend, online buchbar:', ''],
    'colon, only blanks after it' => ["Märchenabend, online buchbar:  \t ", ''],
    'colon, then a newline' => ["online buchbar:\nmax 60 PAX", ''],
    'colon on the next line' => ["online buchbar\n: Hallo", ''],
    'following line is not swallowed (LF)' => ["Fest, online buchbar: Menü ab 18 Uhr\nmax 60 PAX", 'Menü ab 18 Uhr'],
    'following line is not swallowed (CRLF)' => ["Fest, online buchbar: Menü ab 18 Uhr\r\nmax 60 PAX", 'Menü ab 18 Uhr'],
    'clause before the keyword is not part of it' => ["Vorher\nonline buchbar: Text", 'Text'],
    'first occurrence without text, a later one with' => ["online buchbar\nonline buchbar: Später", 'Später'],
    'first occurrence has a colon but no text, a later one has' => ["online buchbar:\nonline buchbar: Später", 'Später'],
    'no keyword' => ['Märchenabend: Menü ab 18 Uhr', ''],
    'empty comment' => ['', ''],
]);

it('gives no guest text when the note is not opted in, however the text reads', function (string $comment): void {
    expect(ClosureNotes::isOnlineOpen(noteWith($comment)))->toBeFalse()
        ->and(ClosureNotes::guestText(noteWith($comment)))->toBe('');
})->with([
    'negated' => ['Märchenabend, nicht online buchbar: Menü ab 18 Uhr'],
    'negated, nicht mehr' => ['nicht mehr online buchbar: Menü'],
    'all-day wording' => ['Ganztägig, online buchbar: Menü'],
    'keine online' => ['keine online Buchung, online buchbar: Menü'],
]);

it('still opens the window of a note without guest text', function (string $comment): void {
    expect(ClosureNotes::isOnlineOpen(noteWith($comment)))->toBeTrue();
})->with([
    'no colon' => ['Märchenabend, online buchbar'],
    'colon, nothing after it' => ['Märchenabend, online buchbar:'],
    'colon and text' => ['Märchenabend, online buchbar: Menü'],
    'colon, then a newline and a clause' => ["online buchbar:\nmax 60 PAX"],
]);

// ---- the resolver ------------------------------------------------------------------

it('gives the hinweis of a blocked day that is open online', function (): void {
    BlockedDates::block(NOTICE_DAY, 'Silvester', online: true, notice: 'Silvestermenü ab 18 Uhr');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe(['Silvestermenü ab 18 Uhr']);
});

it('gives nothing for a blocked day that is still closed, even with a hinweis', function (): void {
    BlockedDates::block(NOTICE_DAY, 'Betriebsferien', online: false, notice: 'Kommen Sie vorbei!');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
});

it('gives nothing for a closed blocked day even when an opted-in note sits on it', function (): void {
    BlockedDates::block(NOTICE_DAY, 'Betriebsferien', online: false);
    noticeNote('Märchenabend, online buchbar: Menü ab 18 Uhr');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
});

it('gives nothing for an old-format or unreadable blocked entry, even with an opted-in note', function (string $json): void {
    SystemSettings::set('reservetweaks_blocked_dates', $json, 'prefs');
    noticeNote('Märchenabend, online buchbar: Menü ab 18 Uhr');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
})->with([
    'plain string' => ['{"2030-12-31":"Betriebsferien"}'],
    'a number' => ['{"2030-12-31":42}'],
    'online flag not a boolean' => ['{"2030-12-31":{"grund":"x","online":"true","hinweis":"Text"}}'],
]);

it('gives the guest texts of the opted-in notes of the day', function (): void {
    noticeNote('Märchenabend, max 60 PAX, online buchbar: Märchenabend mit Menü ab 18 Uhr');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe(['Märchenabend mit Menü ab 18 Uhr']);
});

it('gives every distinct text when several notes carry one, and each only once', function (): void {
    noticeNote('A, online buchbar: Erstes');
    noticeNote('B, online buchbar: Zweites');
    noticeNote('C, online buchbar: Erstes');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe(['Erstes', 'Zweites']);
});

it('ignores a note that has no usable guest text, one note at a time', function (string $comment, string $date, bool $cancelled): void {
    noticeNote($comment, $date, status: $cancelled ? (int) setting('canceled_reservation_status') : null);

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
})->with([
    'keyword without colon' => ['online buchbar', NOTICE_DAY, false],
    'colon without text' => ['online buchbar:', NOTICE_DAY, false],
    'negated' => ['nicht online buchbar: Nein', NOTICE_DAY, false],
    'not opted in' => ['Geschlossene Gesellschaft: nur Text', NOTICE_DAY, false],
    'another day' => ['online buchbar: Anderer Tag', '2030-12-30', false],
    'cancelled' => ['online buchbar: Storniert', NOTICE_DAY, true],
]);

it('ignores a real reservation whose comment happens to say it', function (): void {
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 4, 'first_name' => 'A', 'last_name' => 'Gast',
        'email' => 'a@example.com', 'reserve_date' => NOTICE_DAY, 'reserve_time' => '18:00:00',
        'reserve_datetime' => NOTICE_DAY.' 18:00:00', 'duration' => 120, 'status_id' => 1,
        'comment' => 'online buchbar: Werbung', 'ip_address' => '', 'user_agent' => '',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
});

it('falls back to the notes of an open blocked day that has no hinweis', function (): void {
    BlockedDates::block(NOTICE_DAY, 'Silvester', online: true);
    noticeNote('Menü, online buchbar: Silvestermenü');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe(['Silvestermenü']);
});

it('prefers the hinweis of an open blocked day over a note', function (): void {
    BlockedDates::block(NOTICE_DAY, 'Silvester', online: true, notice: 'Vom Sperrtag');
    noticeNote('Menü, online buchbar: Von der Notiz');

    expect(GuestNotice::forDate(NOTICE_DAY))->toBe(['Vom Sperrtag']);
});

it('gives nothing on a day with neither', function (): void {
    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);

    BlockedDates::block('2030-12-30', 'Anderer Tag', online: true, notice: 'Nicht dieser');
    expect(GuestNotice::forDate(NOTICE_DAY))->toBe([]);
});
