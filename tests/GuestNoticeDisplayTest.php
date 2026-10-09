<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\EventBus;
use Wagnersnetz\ReservationControl\BlockedDates;
use Wagnersnetz\ReservationControl\ClosureNotes;
use Wagnersnetz\ReservationControl\GuestNotice;

const DISPLAY_DAY = '2030-12-31';

beforeEach(function (): void {
    foreach (['openingHours', 'houseCapacity'] as $property) {
        (new ReflectionProperty(ClosureNotes::class, $property))->setValue(null, null);
    }
});

/** The shape of the Orange booking component, as far as we read it. */
function bookingLike(?string $date = DISPLAY_DAY): Component
{
    return new class($date) extends Component
    {
        public ?int $guest = 2;

        public function __construct(public ?string $date = null) {}
    };
}

function openDayWithNotice(string $text): void
{
    BlockedDates::block(DISPLAY_DAY, 'Fest', online: true, notice: $text);
}

/**
 * Every query from here on throws. Done with a listener rather than by dropping
 * a table or swapping the connection: the shared test database (and the
 * transaction the test runs in) must survive.
 */
function breakDatabase(): void
{
    DB::listen(static function (): void {
        throw new RuntimeException('database down');
    });
}

const ROOT = '<div wire:id="abc"><form>FORM</form></div>';

// ---- escaping ----------------------------------------------------------------------

it('escapes a hinweis that contains markup', function (): void {
    openDayWithNotice('<script>alert(1)</script> "quoted" & <b>bold</b>');

    $html = GuestNotice::noticeHtml(bookingLike());

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&amp;')
        ->and($html)->toContain('&lt;b&gt;bold&lt;/b&gt;')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<b>');
});

it('escapes the guest text of a closure note that contains markup', function (): void {
    DB::table('reservations')->insert([
        'location_id' => 1, 'guest_num' => 999, 'first_name' => 'A', 'last_name' => 'Vermerk',
        'email' => 'a@example.com', 'reserve_date' => DISPLAY_DAY, 'reserve_time' => '18:00:00',
        'reserve_datetime' => DISPLAY_DAY.' 18:00:00', 'duration' => 120, 'status_id' => 1,
        'comment' => 'online buchbar: <img src=x onerror=alert(1)>', 'ip_address' => '', 'user_agent' => '',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $html = GuestNotice::noticeHtml(bookingLike());

    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($html)->not->toContain('<img');
});

it('escapes the notice all the way through the render listener', function (): void {
    openDayWithNotice('<script>alert(1)</script>');

    $page = GuestNotice::onRender(bookingLike())(ROOT);

    expect($page)->toContain('&lt;script&gt;')->and($page)->not->toContain('<script');
});

// ---- placement ---------------------------------------------------------------------

it('puts the notice inside the root element, above the form', function (): void {
    openDayWithNotice('Märchenabend mit Menü');

    $page = GuestNotice::onRender(bookingLike())(ROOT);

    expect($page)->toStartWith('<div wire:id="abc"><div class="alert')
        ->and($page)->toEndWith('<form>FORM</form></div>')
        ->and(strpos($page, 'Märchenabend mit Menü'))->toBeLessThan(strpos($page, '<form>'));
});

it('finds the root tag across attributes with ">" in them and leading blanks or comments', function (string $page): void {
    $out = GuestNotice::inject($page, '[N]');

    expect($out)->toContain('>[N]<p>');
})->with([
    'greater-than inside a quoted attribute' => ['<div x-data="{ a: 1 > 0 }" wire:id=\'z\'><p>x</p></div>'],
    'leading whitespace and a comment' => ["\n  <!-- c -->\n<section class=\"a\"><p>x</p></section>"],
    'unquoted attribute' => ['<div id=main><p>x</p></div>'],
]);

it('leaves a page untouched when it has no plain opening root tag', function (string $page): void {
    expect(GuestNotice::inject($page, '[N]'))->toBe($page);
})->with([
    'empty' => [''],
    'plain text' => ['hello'],
    'self-closing root' => ['<div wire:id="a" />'],
    'text before the root' => ['x<div>y</div>'],
]);

// ---- only on days that are actually open -------------------------------------------

it('renders nothing for a day that is still blocked', function (): void {
    BlockedDates::block(DISPLAY_DAY, 'Betriebsferien', online: false, notice: 'Kommen Sie!');

    expect(GuestNotice::onRender(bookingLike()))->toBeNull()
        ->and(GuestNotice::noticeHtml(bookingLike()))->toBe('');
});

it('renders nothing when no date is selected or the day has no notice', function (): void {
    openDayWithNotice('Text');

    expect(GuestNotice::onRender(bookingLike(null)))->toBeNull()
        ->and(GuestNotice::onRender(bookingLike('2030-12-30')))->toBeNull();
});

// ---- never take the booking page down ----------------------------------------------

it('renders nothing and throws nothing for an unexpected component shape', function (mixed $component): void {
    openDayWithNotice('Text');

    expect(GuestNotice::onRender($component))->toBeNull()
        ->and(GuestNotice::selectedDate($component))->toBeNull();
})->with([
    'null' => [null],
    'a string' => ['booking'],
    'a plain object' => [new stdClass],
    'a plain object with date and guest' => [(object) ['date' => DISPLAY_DAY, 'guest' => 2]],
    'a component without date' => [new class extends Component
    {
        public ?int $guest = 2;
    }],
    'a component without guest' => [new class extends Component
    {
        public ?string $date = DISPLAY_DAY;
    }],
    'a protected date' => [new class extends Component
    {
        protected ?string $date = DISPLAY_DAY;

        public ?int $guest = 2;
    }],
    'an uninitialised typed date' => [new class extends Component
    {
        public string $date;

        public ?int $guest = 2;
    }],
    'a date of the wrong type' => [new class extends Component
    {
        public array $date = [DISPLAY_DAY];

        public ?int $guest = 2;
    }],
]);

it('reads the date of a well-formed booking component', function (): void {
    expect(GuestNotice::selectedDate(bookingLike('2030-02-28')))->toBe('2030-02-28');
});

it('renders nothing for a date that is not a real day', function (string $date): void {
    openDayWithNotice('Text');

    expect(GuestNotice::onRender(bookingLike($date)))->toBeNull()
        ->and(GuestNotice::selectedDate(bookingLike($date)))->toBeNull();
})->with(['garbage', '2030-02-31', '2030-12-31 10:00', '31.12.2030', '2030-1-1', '']);

it('logs and renders nothing when resolving the notice throws', function (): void {
    openDayWithNotice('Text');
    breakDatabase();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_starts_with($message, 'reservationcontrol: guest notice skipped'));

    expect(GuestNotice::onRender(bookingLike('2030-12-30')))->toBeNull();
});

it('leaves a page alone that is not a string', function (): void {
    openDayWithNotice('Text');

    $finish = GuestNotice::onRender(bookingLike());

    expect($finish(null))->toBeNull()
        ->and($finish(['not', 'a', 'string']))->toBeNull();
});

it('renders nothing even when the logger itself throws', function (): void {
    openDayWithNotice('Text');
    breakDatabase();
    Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('log down'));

    expect(GuestNotice::onRender(bookingLike('2030-12-30')))->toBeNull();
});

// ---- wired into Livewire -----------------------------------------------------------

it('is wired into the Livewire render event by the extension', function (): void {
    openDayWithNotice('Silvestermenü');

    $finish = app(EventBus::class)->trigger('render', bookingLike(), null, []);
    $page = ROOT;
    $page = $finish($page);

    expect($page)->toContain('Silvestermenü')->and($page)->toStartWith('<div wire:id="abc"><div');
});

it('lets a render of any other component through unchanged', function (): void {
    openDayWithNotice('Silvestermenü');

    $other = new class extends Component
    {
        public string $title = 'x';
    };
    $finish = app(EventBus::class)->trigger('render', $other, null, []);
    $page = ROOT;

    expect($finish($page))->toBe(ROOT);
});
