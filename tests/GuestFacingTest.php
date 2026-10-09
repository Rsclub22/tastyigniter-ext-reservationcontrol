<?php

declare(strict_types=1);

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\GuestNotice;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;
use Wagnersnetz\ReservationControl\OnlineBlock;
use Wagnersnetz\ReservationControl\SpecialEvenings;

// 2030-06-10 is a Monday; the fixture house opens 11:00-22:00 (lunch-only variants close at 15:00).
const FACING_DAY = '2030-06-10';
const FACING_TODAY = '2030-06-01';
const FACING_PHONE = '+49 36945 519400';

beforeEach(function (): void {
    foreach (['openingHours' => [1 => ['11:00', '22:00']], 'houseCapacity' => null] as $property => $value) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, $value);
    }
    OnlineBlock::reset();
    app()->setLocale('de');

    DB::table('locations')->where('location_id', 1)->update(['location_telephone' => FACING_PHONE]);
    facingBookingSettings(0, 3000);
});

afterEach(function (): void {
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
    OnlineBlock::reset();
    Settings::clearInternalCache();
});

function facingBookingSettings(int $min, int $max): void
{
    Location::query()->first()->settings()->updateOrCreate(['item' => 'booking'], ['data' => [
        'time_interval' => 15, 'stay_time' => 120, 'include_start_time' => 1,
        'min_advance_time' => $min, 'max_advance_time' => $max,
    ]]);
}

function facingSetting(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();
    Settings::clearInternalCache();
}

/** A public (not internal) manager; the house opens Mondays 11:00 to $close, schedule horizon $horizon days. */
function facingManager(int $guests, string $close = '22:00', int $horizon = 3000): LargePartyBookingManager
{
    (new ReflectionProperty(ClosureNotes::class, 'openingHours'))->setValue(null, [1 => ['11:00', $close]]);

    $location = Mockery::mock(Location::class)->makePartial();
    $location->shouldReceive('newWorkingSchedule')->andReturnUsing(function () use ($close, $horizon) {
        $schedule = WorkingSchedule::create([0, $horizon], ['monday' => [['11:00', $close]]]);
        $schedule->setType('opening');

        return $schedule;
    });
    $location->location_id = 1;
    $location->shouldReceive('getReservationStayTime')->andReturn(120);
    $location->shouldReceive('getReservationInterval')->andReturn(15);
    $location->shouldReceive('getReservationLeadTime')->andReturn(0);
    $location->shouldReceive('getSettings')->andReturn(1);
    $location->shouldReceive('getMinReservationAdvanceTime')->andReturn(0);
    $location->shouldReceive('getMaxReservationAdvanceTime')->andReturn($horizon);

    $manager = new LargePartyBookingManager;
    $manager->useLocation($location);

    return $manager->forceGuestCount($guests);
}

/** @return array<int, string> the times (H:i) the guest is offered on $date */
function facingOffered(LargePartyBookingManager $manager, string $date = FACING_DAY): array
{
    return collect($manager->makeTimeSlots(Carbon::parse($date)))
        ->map(fn ($slot): string => $slot->format('H:i'))->values()->all();
}

/** A closure note (more guests than the house seats) on $date. */
function facingNote(string $comment, string $date = FACING_DAY, string $time = '18:00:00', int $duration = 120): void
{
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => $date, 'reserve_time' => $time,
        'reserve_datetime' => $date.' '.$time, 'duration' => $duration, 'status_id' => 1,
        'comment' => $comment, 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['openingHours' => [1 => ['11:00', '22:00']], 'houseCapacity' => null] as $property => $value) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, $value);
    }
}

function facingBooking(string $time, int $guests, string $date = FACING_DAY): void
{
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => $guests, 'first_name' => 'A', 'last_name' => 'Meier',
        'email' => 'a@example.com', 'reserve_date' => $date, 'reserve_time' => $time.':00',
        'reserve_datetime' => $date.' '.$time.':00', 'duration' => 120, 'status_id' => 1,
        'comment' => '', 'ip_address' => '', 'user_agent' => '', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function facingNoteOf(string $comment): Reservation
{
    return new Reservation(['comment' => $comment]);
}

function facingComponent(string $date = FACING_DAY): Component
{
    return new class($date) extends Component
    {
        public ?int $guest = 2;

        public function __construct(public ?string $date = null) {}
    };
}

// ---- Feature 1: the invitation follows what the manager removed -----------------------

it('records a removal and invites when the cut-off took a time away', function (): void {
    facingSetting('cutoff_minutes_before_closing', 60);

    $times = facingOffered(facingManager(2, '15:00'));

    expect($times)->not->toContain('14:15')->toContain('13:45')
        ->and(OnlineBlock::blockedOn(FACING_DAY))->toBeTrue()
        ->and(SpecialEvenings::invitation(FACING_DAY, FACING_PHONE))->toBe(FACING_PHONE);
});

it('invites when the guest cap took a time away', function (): void {
    facingSetting('apply_max_guests_online', true);
    facingNote('Geschlossene Gesellschaft, max 10 PAX', time: '23:00:00');
    facingBooking('19:00', 8);

    $times = facingOffered(facingManager(3));

    expect($times)->not->toContain('19:00')->toContain('18:45')
        ->and(SpecialEvenings::invitation(FACING_DAY, FACING_PHONE))->toBe(FACING_PHONE);
});

it('invites when a closure note window took a time away', function (): void {
    // 09:00-11:00 lies beside the opening hours (no all-day note); the large-party window starts at 10:00.
    facingNote('Märchenabend', time: '09:00:00');

    expect(facingOffered(facingManager(25)))->not->toContain('10:00')
        ->and(SpecialEvenings::invitation(FACING_DAY, FACING_PHONE))->toBe(FACING_PHONE);
});

it('invites when an all-day note took the whole day away', function (): void {
    facingNote('Ganztägig geschlossen');

    expect(facingOffered(facingManager(2)))->toBe([])
        ->and(SpecialEvenings::invitation(FACING_DAY, FACING_PHONE))->toBe(FACING_PHONE);
});

it('does not invite on a weekday the house is closed, even with the cut-off on', function (): void {
    facingSetting('cutoff_minutes_before_closing', 60);

    // Tuesday: no weekday hours, so nothing was on offer to take away.
    expect(facingOffered(facingManager(2, '15:00'), '2030-06-11'))->toBe([])
        ->and(OnlineBlock::blockedOn('2030-06-11'))->toBeFalse()
        ->and(SpecialEvenings::invitation('2030-06-11', FACING_PHONE))->toBeNull();
});

it('does not invite beyond the booking horizon', function (): void {
    facingSetting('cutoff_minutes_before_closing', 60);
    $monday = Carbon::today()->addDays(3100)->next(Carbon::MONDAY)->toDateString();

    expect(facingOffered(facingManager(2, '15:00', horizon: 3000), $monday))->toBe([])
        ->and(SpecialEvenings::invitation($monday, FACING_PHONE))->toBeNull();
});

it('does not invite when nothing was blocked', function (): void {
    facingSetting('cutoff_minutes_before_closing', 0);

    expect(facingOffered(facingManager(2)))->toContain('12:00', '21:45')
        ->and(OnlineBlock::blockedOn(FACING_DAY))->toBeFalse()
        ->and(SpecialEvenings::invitation(FACING_DAY, FACING_PHONE))->toBeNull();
});

it('does not invite on another date than the one that was blocked', function (): void {
    facingSetting('cutoff_minutes_before_closing', 60);
    facingOffered(facingManager(2, '15:00'));

    expect(SpecialEvenings::invitation('2030-06-17', FACING_PHONE))->toBeNull();
});

it('does not invite without a number', function (): void {
    OnlineBlock::record(FACING_DAY, 3);

    expect(SpecialEvenings::invitation(FACING_DAY, ''))->toBeNull();

    DB::table('locations')->where('location_id', 1)->update(['location_telephone' => '  ']);
    expect(SpecialEvenings::telephone())->toBe('');
});

it('reads the number from the location', function (): void {
    expect(SpecialEvenings::telephone())->toBe(FACING_PHONE);
});

it('invites on the rendered page, escaped, only for what this render removed', function (): void {
    DB::table('locations')->where('location_id', 1)->update(['location_telephone' => '<b>+49 1</b>']);
    $root = '<div wire:id="a"><form>FORM</form></div>';

    // The view renders between onRender() and the finisher: that is when the manager records.
    $finish = GuestNotice::onRender(facingComponent());
    OnlineBlock::record(FACING_DAY, 2);
    $page = $finish($root);

    expect($page)->toContain('Keine passende Zeit dabei? Rufen Sie uns an:')
        ->and($page)->toContain('&lt;b&gt;+49 1&lt;/b&gt;')
        ->and($page)->not->toContain('<b>')
        ->and($page)->toContain('href="tel:+491"')
        ->and($page)->toEndWith('<form>FORM</form></div>');

    // Nothing recorded during a render: no invitation. A record left over from an earlier render does not count.
    OnlineBlock::record(FACING_DAY, 2);
    $finish = GuestNotice::onRender(facingComponent());

    expect($finish($root))->toBeNull();
});

it('has an english invitation too', function (): void {
    app()->setLocale('en');
    OnlineBlock::record(FACING_DAY, 1);

    expect(GuestNotice::invitationHtml(FACING_DAY, FACING_PHONE))->toContain('No suitable time? Give us a call:');
});

// ---- Feature 2: the guest text sources --------------------------------------------------

function facingEvenings(string $today = FACING_TODAY, int $min = 2, int $max = 60): array
{
    return SpecialEvenings::upcoming(Carbon::parse($today), $min, $max);
}

it('reads HINWEIS text to the end of the line, like the online keyword', function (string $comment, string $text): void {
    expect(ClosureNotes::hinweisText(facingNoteOf($comment)))->toBe($text);
})->with([
    'plain' => ['GÄSTEHINWEIS: Märchenabend mit Menü', 'Märchenabend mit Menü'],
    'without umlaut' => ['GAESTEHINWEIS: Märchenabend mit Menü', 'Märchenabend mit Menü'],
    'the everyday word is no keyword' => ['Hinweis: Tisch 4 wackelt', ''],
    'the everyday word in capitals is no keyword' => ['MAX 30 PAX. HINWEIS: Tisch 4 wackelt', ''],
    'gast + hinweis apart is no keyword' => ['Gast Hinweis: Tisch 4 wackelt', ''],
    'after other words' => ['MÄRCHENABEND 17 UHR MAX 30 PAX. GÄSTEHINWEIS: Märchenabend, Reservierung telefonisch', 'Märchenabend, Reservierung telefonisch'],
    'lower case' => ['gästehinweis: Nur heute', 'Nur heute'],
    'blanks around the colon' => ["Gästehinweis \t:   Mit Musik  ", 'Mit Musik'],
    'no colon' => ['GÄSTEHINWEIS Märchenabend', ''],
    'nothing after the colon' => ['GÄSTEHINWEIS:', ''],
    'colon, then a newline' => ["GÄSTEHINWEIS:\nMax 60 PAX", ''],
    'next line is not swallowed' => ["GÄSTEHINWEIS: Menü ab 18 Uhr\nmax 60 PAX", 'Menü ab 18 Uhr'],
    'an empty first occurrence, a later one with text' => ["GÄSTEHINWEIS:\nGÄSTEHINWEIS: Später", 'Später'],
    'no keyword' => ['Märchenabend: Menü', ''],
]);

it('keeps HINWEIS and ONLINE BUCHBAR apart, whichever comes first and on one line or two', function (string $comment, string $online, string $hinweis): void {
    $note = facingNoteOf($comment);

    expect(ClosureNotes::guestText($note))->toBe($online)
        ->and(ClosureNotes::hinweisText($note))->toBe($hinweis);
})->with([
    'online first, same line' => ['ONLINE BUCHBAR: Märchenabend GÄSTEHINWEIS: nur Menü', 'Märchenabend', 'nur Menü'],
    'hinweis first, same line' => ['GÄSTEHINWEIS: nur Menü ONLINE BUCHBAR: Märchenabend', 'Märchenabend', 'nur Menü'],
    'two lines' => ["ONLINE BUCHBAR: Märchenabend\nGÄSTEHINWEIS: nur Menü", 'Märchenabend', 'nur Menü'],
    'two lines, reversed' => ["GÄSTEHINWEIS: nur Menü\nONLINE BUCHBAR: Märchenabend", 'Märchenabend', 'nur Menü'],
    'online keyword without colon inside the hinweis stays hinweis text' => ['GÄSTEHINWEIS: telefonisch, online buchbar ab Oktober', '', 'telefonisch, online buchbar ab Oktober'],
]);

it('lets HINWEIS open nothing, even when its text says "online buchbar"', function (string $comment): void {
    $note = facingNoteOf($comment);

    expect(ClosureNotes::isOnlineOpen($note))->toBeFalse()
        ->and(ClosureNotes::eventTimes([$note], true))->toBe([]);

    facingNote($comment);

    expect(facingOffered(facingManager(2)))->toBe([]);
})->with([
    'plain hinweis' => ['MÄRCHENABEND MAX 30 PAX. GÄSTEHINWEIS: Märchenabend mit Menü'],
    'hinweis that says online buchbar' => ['MÄRCHENABEND MAX 30 PAX. GÄSTEHINWEIS: Reservierung auch online buchbar'],
    'hinweis that says online buchbar with a colon in its text' => ['MÄRCHENABEND MAX 30 PAX. GÄSTEHINWEIS: online buchbar'],
    'hinweis with a time in it' => ['MÄRCHENABEND MAX 30 PAX. GÄSTEHINWEIS: Beginn 18 Uhr'],
]);

it('still opens the window with ONLINE BUCHBAR, hinweis beside it or not', function (string $comment): void {
    facingNote($comment);

    expect(facingOffered(facingManager(2)))->toContain('12:00', '18:00');
})->with([
    'alone' => ['MÄRCHENABEND MAX 30 PAX. ONLINE BUCHBAR: Märchenabend mit Menü'],
    'with a hinweis' => ['MÄRCHENABEND MAX 30 PAX. ONLINE BUCHBAR: Märchenabend mit Menü GÄSTEHINWEIS: nur Menü'],
    'hinweis first' => ['MÄRCHENABEND MAX 30 PAX. GÄSTEHINWEIS: nur Menü ONLINE BUCHBAR: Märchenabend mit Menü'],
]);

it('advertises a blocked day by its hinweis while the day stays closed', function (): void {
    BlockedDates::block(FACING_DAY, 'Betriebsferien intern', online: false, notice: 'Silvestermenü, Reservierung telefonisch');

    expect(facingEvenings())->toBe([['date' => FACING_DAY, 'text' => 'Silvestermenü, Reservierung telefonisch', 'bookable' => false]])
        ->and(BlockedDates::asScheduleExceptions())->toHaveKey(FACING_DAY)
        ->and(GuestNotice::forDate(FACING_DAY))->toBe([])
        ->and(json_encode(facingEvenings()))->not->toContain('Betriebsferien');
});

it('marks an online-open blocked day as bookable', function (): void {
    BlockedDates::block(FACING_DAY, 'Fest', online: true, notice: 'Silvester');

    expect(facingEvenings())->toBe([['date' => FACING_DAY, 'text' => 'Silvester', 'bookable' => true]]);
});

it('lists ONLINE BUCHBAR text as bookable and HINWEIS text as not bookable', function (): void {
    facingNote('MÄRCHENABEND 17 UHR MAX 30 PAX. ONLINE BUCHBAR: Märchenabend mit Menü');
    facingNote('WEIHNACHTEN 2 Gänge. GÄSTEHINWEIS: Weihnachtsmenü, Reservierung telefonisch', '2030-06-14');

    expect(facingEvenings())->toBe([
        ['date' => FACING_DAY, 'text' => 'Märchenabend mit Menü', 'bookable' => true],
        ['date' => '2030-06-14', 'text' => 'Weihnachtsmenü, Reservierung telefonisch', 'bookable' => false],
    ]);
});

it('lists both texts of one note, the hinweis never bookable', function (): void {
    facingNote('ONLINE BUCHBAR: Märchenabend GÄSTEHINWEIS: nur Menü');

    expect(facingEvenings())->toBe([
        ['date' => FACING_DAY, 'text' => 'Märchenabend', 'bookable' => true],
        ['date' => FACING_DAY, 'text' => 'nur Menü', 'bookable' => false],
    ]);
});

it('uses the restaurant\'s real note wording: raw text is never advertised', function (): void {
    // Real wording from the design document.
    facingNote('MÄRCHENABEND 17 UHR MAX 30 PAX. NUR PER TELEFON BUCHBAR');
    facingNote('WEIHNACHTEN: 2 Gänge: 11 Uhr und 13 Uhr. NICHTS DAZWISCHEN ANNEHMEN. BUCHUNG NUMMER 161 NICHT STORNIEREN. ONLINE RESERVIERUNGEN AN DEM TAG NICHT VERFÜGBAR. MAX 120 PAX.', '2030-06-14');

    expect(facingEvenings())->toBe([]);

    // With a guest text the Christmas day is advertised - and stays closed online.
    facingNote('WEIHNACHTEN: 2 Gänge: 11 Uhr und 13 Uhr. NICHTS DAZWISCHEN ANNEHMEN. BUCHUNG NUMMER 161 NICHT STORNIEREN. ONLINE RESERVIERUNGEN AN DEM TAG NICHT VERFÜGBAR. MAX 120 PAX. GÄSTEHINWEIS: Weihnachtsmenü mit zwei Gängen, Reservierung telefonisch', '2030-06-17');

    $listed = facingEvenings();

    expect($listed)->toBe([['date' => '2030-06-17', 'text' => 'Weihnachtsmenü mit zwei Gängen, Reservierung telefonisch', 'bookable' => false]])
        ->and(json_encode($listed))->not->toContain('NICHTS DAZWISCHEN')->not->toContain('161');
});

it('publishes nothing of an internal "Hinweis:" remark', function (string $comment): void {
    facingNote($comment);

    expect(facingEvenings())->toBe([])
        ->and(GuestNotice::eveningsHtml(Carbon::parse(FACING_TODAY)))->toBe('')
        ->and(GuestNotice::forDate(FACING_DAY))->toBe([]);
})->with([
    'plain' => ['Hinweis: Tisch 4 wackelt'],
    'after a cap' => ['Geschlossene Gesellschaft MAX 30 PAX. HINWEIS: Tisch 4 wackelt'],
    'beside a real keyword' => ['online buchbar. Hinweis: Tisch 4 wackelt'],
]);

it('lists nothing without a guest text', function (): void {
    facingNote('Geschlossene Gesellschaft, online buchbar');
    facingNote('GÄSTEHINWEIS:', '2030-06-14');
    BlockedDates::block('2030-06-17', 'Ruhetag', online: true, notice: '');
    BlockedDates::block('2030-06-18', 'Ruhetag', online: false, notice: '   ');

    expect(facingEvenings())->toBe([]);
});

it('does not advertise a note that closes online booking as bookable', function (): void {
    facingNote('Ganztägig, nicht online buchbar: Märchenabend');
    facingNote('Märchenabend, nicht online buchbar: Menü', '2030-06-14');

    expect(facingEvenings())->toBe([]);
});

it('does not let an online-opt-in override a blocked day that stays closed', function (): void {
    BlockedDates::block(FACING_DAY, 'Ruhetag', online: false);
    facingNote('ONLINE BUCHBAR: Märchenabend');

    expect(facingEvenings())->toBe([['date' => FACING_DAY, 'text' => 'Märchenabend', 'bookable' => false]]);
});

it('lets the blocked day\'s hinweis speak for its date, as the notice does', function (): void {
    BlockedDates::block(FACING_DAY, 'Fest', online: true, notice: 'Das Fest');
    facingNote('ONLINE BUCHBAR: Märchenabend');

    expect(facingEvenings())->toBe([['date' => FACING_DAY, 'text' => 'Das Fest', 'bookable' => true]]);
});

it('keeps the horizon: the last bookable day is in, the day after is out, the past is out', function (): void {
    // Today 2030-06-01 + 60 days = 2030-07-31.
    BlockedDates::block('2030-05-31', 'x', notice: 'Gestern');
    BlockedDates::block('2030-06-01', 'x', notice: 'Heute');
    BlockedDates::block('2030-07-31', 'x', notice: 'Letzter Tag');
    BlockedDates::block('2030-08-01', 'x', notice: 'Zu weit');

    expect(array_column(facingEvenings(), 'text'))->toBe(['Heute', 'Letzter Tag']);
});

it('keeps the same horizon for closure notes', function (): void {
    facingNote('ONLINE BUCHBAR: Note gestern', '2030-05-31');
    facingNote('ONLINE BUCHBAR: Note heute', '2030-06-01');
    facingNote('ONLINE BUCHBAR: Note am letzten Tag', '2030-07-31');
    facingNote('ONLINE BUCHBAR: Note zu weit', '2030-08-01');

    expect(array_column(facingEvenings(), 'text'))->toBe(['Note heute', 'Note am letzten Tag']);
});

it('offers a link only from the first day online booking accepts', function (): void {
    BlockedDates::block('2030-06-02', 'x', online: true, notice: 'Zu kurzfristig');
    BlockedDates::block('2030-06-03', 'x', online: true, notice: 'Gerade noch');

    // Lead time 2 days from 2030-06-01: the 3rd is the first bookable day.
    expect(array_column(facingEvenings(), 'bookable', 'text'))->toBe(['Zu kurzfristig' => false, 'Gerade noch' => true]);
});

it('sorts by date and keeps the list short', function (): void {
    foreach (range(20, 10) as $day) {
        BlockedDates::block('2030-06-'.$day, 'x', notice: 'Abend '.$day);
    }

    // A note on an earlier date than every blocked day: sorting spans both sources.
    facingNote('ONLINE BUCHBAR: Früher', '2030-06-05');

    $dates = array_column(facingEvenings(), 'date');

    expect($dates)->toHaveCount(SpecialEvenings::MAX_ENTRIES)
        ->and($dates)->toBe(['2030-06-05', '2030-06-10', '2030-06-11', '2030-06-12', '2030-06-13']);
});

it('lists a text once', function (): void {
    facingNote('ONLINE BUCHBAR: Märchenabend');
    facingNote('ONLINE BUCHBAR: Märchenabend', time: '19:00:00');

    expect(facingEvenings())->toHaveCount(1);
});

// ---- Feature 2: the markup --------------------------------------------------------------

it('renders a bookable evening with a link to its date and the others with the telephone', function (): void {
    BlockedDates::block(FACING_DAY, 'x', online: true, notice: 'Märchenabend');
    BlockedDates::block('2030-06-14', 'x', online: false, notice: 'Weihnachtsmenü');

    $html = GuestNotice::eveningsHtml(Carbon::parse(FACING_TODAY));

    expect($html)->toContain('Besondere Abende')
        ->and($html)->toContain('10.06.')->toContain('Märchenabend')
        ->and($html)->toContain('href="?date=2030-06-10"')->toContain('Zu diesem Tag')
        ->and($html)->toContain('14.06.')->toContain('Weihnachtsmenü')->toContain('Reservierung telefonisch')
        ->and($html)->toContain('+49 36945 519400')
        ->and(substr_count($html, '?date='))->toBe(1);
});

it('lists a non-bookable evening with the invitation, but without a number it just says by phone', function (): void {
    BlockedDates::block('2030-06-14', 'x', online: false, notice: 'Weihnachtsmenü');
    DB::table('locations')->where('location_id', 1)->update(['location_telephone' => '']);

    $html = GuestNotice::eveningsHtml(Carbon::parse(FACING_TODAY));

    expect($html)->toContain('Weihnachtsmenü')->toContain('Reservierung telefonisch')->not->toContain('tel:');
});

it('renders no list when there is no evening', function (): void {
    expect(GuestNotice::eveningsHtml(Carbon::parse(FACING_TODAY)))->toBe('');
});

it('escapes markup in every guest text, in the list and on the page', function (): void {
    $evil = '<script>alert(1)</script>';
    BlockedDates::block(FACING_DAY, 'x', online: true, notice: $evil.' hinweis');
    facingNote('ONLINE BUCHBAR: '.$evil.' online', '2030-06-14');
    facingNote('GÄSTEHINWEIS: '.$evil.' note', '2030-06-17');

    $html = GuestNotice::eveningsHtml(Carbon::parse(FACING_TODAY));
    $page = GuestNotice::onRender(facingComponent('2030-06-11'))('<div wire:id="a"><form>F</form></div>');

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; hinweis')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; online')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; note')
        ->not->toContain('<script')
        ->and($page)->not->toContain('<script');
});

it('puts the list on the page whatever date is selected, below the notice', function (): void {
    facingBookingSettings(0, 3000);
    BlockedDates::block(FACING_DAY, 'x', online: true, notice: 'Märchenabend');

    $page = GuestNotice::onRender(facingComponent('2030-06-11'))('<div wire:id="a"><form>F</form></div>');

    expect($page)->toContain('Märchenabend')->toContain('Besondere Abende')
        ->and(strpos($page, 'Besondere Abende'))->toBeLessThan(strpos($page, '<form>'));
});
