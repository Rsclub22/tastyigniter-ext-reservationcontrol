<?php

declare(strict_types=1);

use Igniter\Reservation\Http\Requests\ReservationRequest;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\Email;
use Wagnersnetz\ReservationControl\DailySheet;
use Wagnersnetz\ReservationControl\DayData;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBookingController;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;
use Wagnersnetz\ReservationControl\Rooms;
use Wagnersnetz\ReservationControl\TableAllocator;

afterEach(fn () => Settings::clearInternalCache());

/** Store through the real API (what the admin form calls) and re-read from the database. */
function store(string $key, mixed $value): void
{
    expect(Settings::set($key, $value))->toBeTrue();

    Settings::clearInternalCache();
}

it('defaults every reader to the previously hardcoded value', function (): void {
    expect(TableAllocator::turnoverBufferMinutes())->toBe(0)
        ->and(TableAllocator::maxTablesPerReservation())->toBe(1)
        ->and(Rooms::areaName())->toBe('Räume')
        ->and(DailySheet::maxRangeDays())->toBe(92)
        ->and(DayData::splitTime(locationId: 1))->toBe('15:00')
        ->and(InternalNetworkOnly::allowedNetworks())->toBe(['127.0.0.1', '::1'])
        ->and(Extension::trustedProxies())->toBe([])
        ->and(Extension::adminRateLimit())->toBe('30,1')
        ->and(Extension::maxNameLength())->toBe(48)
        ->and(Extension::maxEmailLength())->toBe(96)
        ->and(Extension::maxPhoneLength())->toBe(40)
        ->and(Extension::publicPhoneRules())->toBe(['required', 'regex:/^([0-9\s\-\+\(\)]*)$/i']);
});

it('has no reply-to address unless one is configured', function (): void {
    expect(Extension::replyToAddress())->toBeNull();

    store('reply_to_address', 'not an address');
    expect(Extension::replyToAddress())->toBeNull();

    store('reply_to_address', 'hello@example.com');
    expect(Extension::replyToAddress())->toBe('hello@example.com');
});

it('keeps trusted proxies and internal networks as two independent settings', function (): void {
    store('trusted_proxies', "203.0.113.7\n10.1.0.0/16");

    expect(Extension::trustedProxies())->toBe(['203.0.113.7', '10.1.0.0/16'])
        ->and(InternalNetworkOnly::allowedNetworks())->toBe(['127.0.0.1', '::1'])
        ->and(InternalNetworkOnly::allowedNetworks())->not->toContain('203.0.113.7');

    store('internal_allowed_networks', '198.51.100.0/24');
    expect(InternalNetworkOnly::allowedNetworks())->toBe(['198.51.100.0/24'])
        ->and(Extension::trustedProxies())->toBe(['203.0.113.7', '10.1.0.0/16']);
});

it('lets the middleware follow the configured networks', function (): void {
    store('internal_allowed_networks', '198.51.100.0/24');
    $pass = fn (string $ip) => (new InternalNetworkOnly)->handle(
        Request::create('/intern', server: ['REMOTE_ADDR' => $ip]),
        fn () => response('ok'),
    )->getContent();

    expect($pass('198.51.100.9'))->toBe('ok')
        ->and(fn () => $pass('192.168.1.5'))->toThrow(NotFoundHttpException::class);
});

it('refuses an allow-list entry that matches every address', function (string $entry): void {
    store('internal_allowed_networks', $entry);
    store('trusted_proxies', $entry);

    expect(InternalNetworkOnly::allowedNetworks())->toBe(InternalNetworkOnly::DEFAULT_ALLOWED)
        ->and(Extension::trustedProxies())->toBe(Extension::DEFAULT_TRUSTED_PROXIES);

    // One such entry among valid ones rejects the whole list, as any malformed entry does.
    store('internal_allowed_networks', "192.168.0.0/16\n{$entry}");
    expect(InternalNetworkOnly::allowedNetworks())->toBe(InternalNetworkOnly::DEFAULT_ALLOWED);
})->with(['IPv4 /0' => '0.0.0.0/0', 'IPv6 /0' => '::/0']);

it('does not let a private-network host in by default', function (): void {
    $pass = fn (string $ip) => (new InternalNetworkOnly)->handle(
        Request::create('/intern', server: ['REMOTE_ADDR' => $ip]),
        fn () => response('ok'),
    )->getContent();

    expect($pass('127.0.0.1'))->toBe('ok')
        ->and($pass('::1'))->toBe('ok')
        ->and(fn () => $pass('192.168.1.5'))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $pass('10.1.2.3'))->toThrow(NotFoundHttpException::class);
});

it('honours a configured value', function (): void {
    store('turnover_buffer_minutes', 30);
    store('rooms_area_name', 'Rooms');
    store('max_name_length', 80);

    expect(TableAllocator::turnoverBufferMinutes())->toBe(30)
        ->and(Rooms::areaName())->toBe('Rooms')
        ->and(Extension::maxNameLength())->toBe(80);
});

it('falls back when the stored value is nonsense', function (): void {
    store('turnover_buffer_minutes', -10);
    store('max_print_range_days', 0);
    store('max_name_length', '25:00');
    store('max_email_length', 'abc');
    store('max_tables_per_reservation', 0);
    store('rooms_area_name', '   ');
    store('admin_rate_limit', '0,0');
    store('phone_pattern', '/(unclosed');
    store('trusted_proxies', "10.0.0.0/8\nnot-an-ip");
    store('internal_allowed_networks', '10.0.0.0/99');
    store('split_time', '25:61');

    expect(TableAllocator::turnoverBufferMinutes())->toBe(0)
        ->and(DailySheet::maxRangeDays())->toBe(92)
        ->and(Extension::maxNameLength())->toBe(48)
        ->and(Extension::maxEmailLength())->toBe(96)
        ->and(TableAllocator::maxTablesPerReservation())->toBe(1)
        ->and(Rooms::areaName())->toBe('Räume')
        ->and(Extension::adminRateLimit())->toBe('30,1')
        ->and(Extension::publicPhoneRules()[1])->toBe('regex:/^([0-9\s\-\+\(\)]*)$/i')
        ->and(Extension::trustedProxies())->toBe(Extension::DEFAULT_TRUSTED_PROXIES)
        ->and(InternalNetworkOnly::allowedNetworks())->toBe(InternalNetworkOnly::DEFAULT_ALLOWED)
        ->and(DayData::splitTime(locationId: 1))->toBe('15:00');
});

it('switches the split time off with "off" and rejects other junk', function (): void {
    store('split_time', 'off');
    expect(DayData::splitTime(locationId: 1))->toBeNull();
});

it('makes the public phone optional only when told so', function (): void {
    store('phone_required_public', false);
    expect(Extension::publicPhoneRules()[0])->toBe('nullable');
});

it('declares the settings whose behaviour arrives in a later plan', function (): void {
    $config = require __DIR__.'/../resources/models/settings.php';
    $fields = $config['form']['fields'];

    expect(array_keys($fields))
        ->toContain('cutoff_hours_before_closing')
        ->toContain('apply_max_guests_online')
        ->and($fields['cutoff_hours_before_closing']['default'])->toBe(0)
        ->and($fields['apply_max_guests_online']['default'])->toBeFalse()
        ->and($fields)->not->toHaveKey('allow_online_on_blocked_default');
});

it('has a form default that equals the reader default for the fields compared', function (): void {
    $fields = (require __DIR__.'/../resources/models/settings.php')['form']['fields'];

    // split_time and admin_rate_limit must stay empty in the form: a saved form
    // writes every field, and a stored value would shadow the env fallback.
    expect($fields['split_time']['default'])->toBe('')
        ->and($fields['admin_rate_limit']['default'])->toBe('')
        ->and($fields['max_print_range_days']['default'])->toBe(DailySheet::maxRangeDays())
        ->and($fields['turnover_buffer_minutes']['default'])->toBe(TableAllocator::turnoverBufferMinutes())
        ->and($fields['max_tables_per_reservation']['default'])->toBe(TableAllocator::maxTablesPerReservation())
        ->and($fields['rooms_area_name']['default'])->toBe(Rooms::areaName())
        ->and($fields['max_name_length']['default'])->toBe(Extension::maxNameLength())
        ->and($fields['max_email_length']['default'])->toBe(Extension::maxEmailLength())
        ->and($fields['max_phone_length']['default'])->toBe(Extension::maxPhoneLength())
        ->and($fields['trusted_proxies']['default'])->toBe(implode("\n", Extension::trustedProxies()))
        ->and($fields['internal_allowed_networks']['default'])->toBe(implode("\n", InternalNetworkOnly::allowedNetworks()))
        ->and($fields['large_party_threshold']['default'])->toBe(LargePartyBookingManager::threshold())
        ->and($fields['large_party_open']['default'])->toBe(LargePartyBookingManager::windowOpen())
        ->and($fields['large_party_close']['default'])->toBe(LargePartyBookingManager::windowClose())
        ->and($fields['internal_booking_horizon_days']['default'])->toBe(LargePartyBookingManager::internalHorizonDays())
        ->and($fields['reply_to_address']['default'])->toBe('');
});

// Review Focus 5: settings are global, locations are not
it('applies one global setting to every location', function (): void {
    store('split_time', '14:00');

    expect(DayData::splitTime(locationId: 1))->toBe('14:00')
        ->and(DayData::splitTime(locationId: 2))->toBe('14:00');
})->note('Deliberately global. To be recorded in docs/settings.md as a limitation.');

it('blocks a table for the turnover buffer after a reservation ends', function (): void {
    $r = new class
    {
        public $tables;

        public $reservation_datetime;

        public $reservation_end_datetime;

        public function __construct()
        {
            $this->tables = collect([(object) ['id' => 7]]);
            $this->reservation_datetime = Carbon\Carbon::parse('2026-10-09 18:00');
            $this->reservation_end_datetime = Carbon\Carbon::parse('2026-10-09 20:00');
        }
    };
    $busy = fn () => TableAllocator::busyIds(Carbon\Carbon::parse('2026-10-09 20:10'), Carbon\Carbon::parse('2026-10-09 22:00'), collect([$r]))->all();

    expect($busy())->toBe([]);

    store('turnover_buffer_minutes', 30);
    expect($busy())->toBe([7]);
});

it('sets Reply-To only when an address is configured', function (): void {
    $send = function (): ?string {
        $message = new Message(new Email);
        event(new MessageSending($message->getSymfonyMessage(), []));

        $replyTo = $message->getSymfonyMessage()->getReplyTo();

        return $replyTo === [] ? null : $replyTo[0]->getAddress();
    };

    expect($send())->toBeNull();

    store('reply_to_address', 'hello@example.com');
    expect($send())->toBe('hello@example.com');
});

it('applies the phone rule and length limits through the real validator', function (): void {
    $phone = fn (string $value): bool => Validator::make(
        ['firstName' => 'A', 'lastName' => 'B', 'telephone' => $value],
        ['firstName' => 'required', 'lastName' => 'required', 'telephone' => 'string'],
    )->passes();

    expect($phone('+49 (0) 123-45'))->toBeTrue()
        ->and($phone('call me'))->toBeFalse()
        ->and($phone(''))->toBeFalse();

    store('phone_required_public', false);
    expect($phone(''))->toBeTrue();

    store('phone_pattern', '/^\d+$/');
    expect($phone('+49'))->toBeFalse()->and($phone('0123'))->toBeTrue();
});

it('feeds the configured length limits into the back-office and API rules', function (): void {
    $rulesFor = function (): object {
        $holder = (object) ['rules' => [], 'messages' => []];
        Event::dispatch('system.formRequest.extendValidator', [new ReservationRequest, $holder]);

        return $holder;
    };

    expect($rulesFor()->rules['last_name'])->toContain('between:1,48')
        ->and($rulesFor()->rules['email'])->toContain('max:96')
        ->and($rulesFor()->rules['telephone'])->toContain('max:40');

    store('max_name_length', 60);
    store('max_email_length', 120);
    store('max_phone_length', 25);
    expect($rulesFor()->rules['first_name'])->toContain('between:1,60')
        ->and($rulesFor()->rules['email'])->toContain('max:120')
        ->and($rulesFor()->rules['telephone'])->toContain('max:25');
});

it('keeps honouring the environment variables running installations already use', function (): void {
    $_ENV['ADMIN_RATE_LIMIT'] = '12,3';
    $_ENV['MAIL_REPLY_TO_ADDRESS'] = 'env@example.com';
    $_ENV['INTERN_DRUCK_TRENNZEIT'] = '16:30';

    try {
        expect(Extension::adminRateLimit())->toBe('12,3')
            ->and(Extension::replyToAddress())->toBe('env@example.com')
            ->and(DayData::splitTime(1))->toBe('16:30');

        // A stored setting wins over the variable.
        store('admin_rate_limit', '5,2');
        expect(Extension::adminRateLimit())->toBe('5,2');
    } finally {
        unset($_ENV['ADMIN_RATE_LIMIT'], $_ENV['MAIL_REPLY_TO_ADDRESS'], $_ENV['INTERN_DRUCK_TRENNZEIT']);
    }
});

it('accepts a rate limit written with spaces, as before', function (): void {
    $_ENV['ADMIN_RATE_LIMIT'] = '30, 1';

    try {
        expect(Extension::adminRateLimit())->toBe('30,1');
        $_ENV['ADMIN_RATE_LIMIT'] = '12 , 3';
        expect(Extension::adminRateLimit())->toBe('12,3');
        $_ENV['ADMIN_RATE_LIMIT'] = '0,1';
        expect(Extension::adminRateLimit())->toBe('30,1');
    } finally {
        unset($_ENV['ADMIN_RATE_LIMIT']);
    }
});

it('sends no Reply-To header through the mail listener when nothing is configured', function (): void {
    $_ENV['MAIL_REPLY_TO_ADDRESS'] = '';
    $email = new Email;
    event(new MessageSending($email, []));
    unset($_ENV['MAIL_REPLY_TO_ADDRESS']);

    expect($email->getReplyTo())->toBe([]);
});

it('cuts the printed range at the configured number of days in both controllers', function (): void {
    store('max_print_range_days', 5);
    $query = ['modus' => 'zeitraum', 'von' => '2030-03-01', 'bis' => '2030-06-30'];

    $api = (new InternalApiController)
        ->dailySheet(Request::create('/', 'GET', ['von' => '2030-03-01', 'bis' => '2030-06-30']))
        ->getData(true);

    $controller = new InternalBookingController;
    $range = (new ReflectionMethod($controller, 'dateRange'))->invoke($controller, Request::create('/', 'GET', $query));

    expect($api['von'])->toBe('2030-03-01')
        ->and($api['bis'])->toBe('2030-03-05')
        ->and($range[0]->toDateString())->toBe('2030-03-01')
        ->and($range[1]->toDateString())->toBe('2030-03-05');

    // 5 days (03-01..03-05) fit; 6 days are one too many. Both controllers.
    $apiTo = fn (string $to) => (new InternalApiController)
        ->dailySheet(Request::create('/', 'GET', ['von' => '2030-03-01', 'bis' => $to]))->getData(true)['bis'];
    $webTo = fn (string $to) => (new ReflectionMethod($controller, 'dateRange'))
        ->invoke($controller, Request::create('/', 'GET', ['modus' => 'zeitraum', 'von' => '2030-03-01', 'bis' => $to]))[1]->toDateString();

    expect($apiTo('2030-03-05'))->toBe('2030-03-05')
        ->and($apiTo('2030-03-06'))->toBe('2030-03-05')
        ->and($webTo('2030-03-05'))->toBe('2030-03-05')
        ->and($webTo('2030-03-06'))->toBe('2030-03-05');
});
