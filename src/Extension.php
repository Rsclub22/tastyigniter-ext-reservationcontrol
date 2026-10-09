<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Api\ApiResources\Requests\ReservationRequest as ApiReservationRequest;
use Igniter\Local\Events\WorkingScheduleCreatedEvent;
use Igniter\Orange\Livewire\Booking;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Http\Requests\ReservationRequest;
use Igniter\Reservation\Models\Reservation;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Symfony\Component\Mime\Address;
use Throwable;
use Wagnersnetz\ReservationControl\Api\StandardIncludes;
use Wagnersnetz\ReservationControl\Console\EnterReservation;
use Wagnersnetz\ReservationControl\Console\ImportReservations;
use Wagnersnetz\ReservationControl\Contracts\GuestCountResolver;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBookingController;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\Models\Settings;
use Wagnersnetz\ReservationControl\Theme\NullGuestCount;
use Wagnersnetz\ReservationControl\Theme\OrangeGuestCount;

/**
 * Reservation control for TastyIgniter:
 *
 * 1. Time slots follow the opening hours, from a larger party on they no longer
 *    do - see LargePartyBookingManager. Which guest count counts is answered by
 *    the bound GuestCountResolver; this works with any theme that binds one.
 *    The Orange theme's resolver is bound when that theme is installed.
 * 2. Internal phone-intake pages and a JSON API under /intern, gated by an IP
 *    allow-list (InternalNetworkOnly), plus closure notes and blocked days.
 * 3. Orange theme only: the telephone number of the public booking form can be
 *    made required and pattern-checked. Igniter\Orange\Livewire\Booking and
 *    its BookingForm are `final`, so they cannot be subclassed. The rules come
 *    from BookingForm::rules() and only take effect when the validator is
 *    created - hence the resolver, which tightens exactly this one rule as soon
 *    as it recognises the rule set of the booking form. Other themes are left
 *    untouched.
 */
class Extension extends BaseExtension
{
    /** Fields by which the rule set of the public booking form is recognised. */
    public const array DEFAULT_PUBLIC_FORM_FIELDS = ['firstName', 'lastName', 'telephone'];

    /**
     * Used whenever the settings hold no usable value: no proxy is trusted. A
     * wide default would let any host on the same private network spoof
     * X-Forwarded-For and so defeat the IP gate of the internal pages.
     */
    public const array DEFAULT_TRUSTED_PROXIES = [];

    public const string DEFAULT_ADMIN_RATE_LIMIT = '30,1';

    public const int DEFAULT_MAX_NAME_LENGTH = 48;

    public const int DEFAULT_MAX_EMAIL_LENGTH = 96;

    public const int DEFAULT_MAX_PHONE_LENGTH = 40;

    public const string DEFAULT_PHONE_PATTERN = '/^([0-9\s\-\+\(\)]*)$/i';

    /**
     * Console commands for entry by hand. They have to be registered in
     * register() - in boot() the command list of Artisan is already assembled
     * and the commands would not show up.
     */
    public function register(): void
    {
        $this->registerConsoleCommand('reservationcontrol.enter', EnterReservation::class);
        $this->registerConsoleCommand('reservationcontrol.import', ImportReservations::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reservationcontrol');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'reservationcontrol');
        $this->registerInternalRoutes();
        $this->registerInternalApiRoutes();

        // Behind a reverse proxy the application sees the proxy's address for
        // every visitor: all visitors then share one throttling counter and the
        // admin login locks itself out after a few calls. Operators behind a
        // proxy therefore list it in the trusted_proxies setting. The default is
        // empty - trusting a whole private range would let any host in it spoof
        // X-Forwarded-For.
        TrustProxies::at(self::trustedProxies());

        // TastyIgniter throttles the admin login with 6 requests per minute and
        // counts merely opening the page towards that. For a single user that is
        // too tight; 30 per minute still protects against brute forcing but
        // does not lock anybody out over a typo.
        config(['igniter-auth.rateLimiter' => self::adminRateLimit()]);

        // Reach the individually blocked days into every schedule that is
        // created. WorkingSchedule::forDate() checks exceptions before the
        // weekday, an empty period means closed. This way it takes effect
        // everywhere alike: public form, phone intake and occupancy display.
        // Listen on the event class, not on the name: EventDispatchable fires
        // both, but the string passes two loose arguments ($model, $schedule)
        // instead of the object.
        Event::listen(WorkingScheduleCreatedEvent::class, function (WorkingScheduleCreatedEvent $event): void {
            if ($exceptions = BlockedDates::asScheduleExceptions()) {
                $event->schedule->setExceptions($exceptions);
            }

            // The one place that creates bookable time: the event windows of
            // opted-in closure notes, on the opening schedule only. After the
            // blocked days, so that a blocked day is never reopened. Never
            // throws, see EventSlots.
            try {
                $isOpening = $event->schedule->getType() === 'opening';
            } catch (Throwable) {
                $isOpening = false;
            }

            if ($isOpening) {
                EventSlots::apply($event->schedule);
            }
        });

        // The sender is a no-reply address. A guest's replies should still end
        // up in the house, hence a Reply-To. Over the MessageSending event
        // instead of Mail::alwaysReplyTo(), so that the mailer is not resolved
        // during boot already. Only sets it when the message does not bring one
        // of its own.
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            // No address configured: no Reply-To at all. An invented one would
            // send guest replies to somebody who never asked for them.
            $address = self::replyToAddress();
            if ($address === null || $event->message->getReplyTo()) {
                return;
            }

            $event->message->replyTo(new Address($address, self::replyToName()));
        });

        // In the backend a name is enough. The reservations imported for the
        // rest of the year partly have neither e-mail nor telephone number;
        // with the core rules such a record could no longer be saved, because
        // first_name, last_name, email AND telephone are all required as soon
        // as no customer account is attached.
        //
        // Backend form AND API: both inherit from Igniter\System\Classes\
        // FormRequest, which fires this event - but the API has a request class
        // of its own with the same strict rules. Without taking that one along
        // here, a phone order could no longer be changed over the API: phone
        // intake has no first-name field at all, so those entries never have a
        // first name and mostly no e-mail - and the API demanded both. It came
        // up in the reservation app at the counter.
        //
        // The public booking form stays untouched: it sends its fields in
        // camelCase (firstName), and there telephone number and e-mail remain
        // required.
        Event::listen('system.formRequest.extendValidator', function ($request, $holder): void {
            if (! $request instanceof ReservationRequest && ! $request instanceof ApiReservationRequest) {
                return;
            }

            // At least one of the two names - otherwise the record stands there
            // without any clue who is actually coming. The condition hangs off
            // the last name only, otherwise the form reports the same hint
            // twice.
            $name = self::maxNameLength();
            $holder->rules['first_name'] = ['nullable', 'string', 'between:1,'.$name];
            $holder->rules['last_name'] = ['required_without_all:first_name,customer_id', 'nullable', 'string', 'between:1,'.$name];
            $holder->rules['email'] = ['nullable', 'email:filter', 'max:'.self::maxEmailLength()];
            $holder->rules['telephone'] = ['nullable', 'string', 'max:'.self::maxPhoneLength()];

            $holder->messages['last_name.required_without_all'] = __('reservationcontrol::default.error_name_required');
        });

        // The relaxed rules above let first name, last name and e-mail through
        // empty - but the columns are NOT NULL. Without the following, the
        // backend form breaks off with "Column 'email' cannot be null" as soon
        // as a reservation is entered by hand without an e-mail. Empty string
        // instead of an invented address: someone would later try to send to
        // such a one.
        Reservation::saving(function (Reservation $reservation): void {
            foreach (['first_name', 'last_name', 'email'] as $field) {
                if ($reservation->{$field} === null) {
                    $reservation->{$field} = '';
                }
            }

            // Without a status sent along, the API writes a 0 into the column -
            // it does not apply the configured default status. There is no
            // status record for that 0, and the StatusTransformer of the API
            // demands a real one by type: one single such entry made the entire
            // reservation list run into a TypeError, even on days without
            // reservations. Noticed at the counter on 2026-10-01, triggered by
            // a reservation from the app.
            //
            // Here and not in the client, so that it holds for every route:
            // app, public form, import, console.
            if (! $reservation->status_id && ($default = (int) setting('default_reservation_status'))) {
                $reservation->status_id = $default;
            }
        });

        // Take over the table assignment ourselves: single table first, a
        // combination only when no single table suffices.
        //
        // The location setting "assign tables automatically" MUST stay off for
        // this. The observer of the reservation extension hangs off the same
        // saved event but demonstrably runs later - it would still give every
        // reservation that got no table here a combination whose individual
        // tables have long been taken. With the setting off it holds back and
        // this assignment is the only one.
        Reservation::saved(function (Reservation $reservation): void {
            // Tables set by hand (admin, phone intake, rooms) win.
            if (array_key_exists('tables', $reservation->getAttributes())) {
                return;
            }

            // When editing, leave an already assigned table alone - otherwise
            // every change in the backend clears the manual assignment away.
            if (! $reservation->wasRecentlyCreated && $reservation->tables()->count()) {
                return;
            }

            $table = TableAllocator::allocate($reservation);

            $reservation->addReservationTables($table ? [$table->getKey()] : []);
        });

        // The manager is only resolved per request, so redirecting it in boot()
        // happens early enough - and certainly after register() of the
        // reservation extension, which originally binds the singleton.
        $this->app->singleton(BookingManager::class, LargePartyBookingManager::class);

        $orange = class_exists(Booking::class);

        $this->app->singleton(GuestCountResolver::class, fn (): GuestCountResolver => $orange
            ? new OrangeGuestCount
            : new NullGuestCount);

        // The hooks below only make sense for the Orange theme. Without it the
        // guest count stays unknown and the ordinary booking path applies.
        if ($orange) {
            $this->registerOrangeHooks();
        }

        // Always deliver reservations in the API with status and tables. See
        // StandardIncludes: without it TastyCompanion shows "no value" for the
        // status, because it resolves it over the relation.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            StandardIncludes::apply($event->request, $event->route->getName());
        });

        if ($orange) {
            $this->registerOrangeValidator();
        }

        // Notice above the online booking form on special days. Any theme: the
        // listener only looks at the component's shape (see
        // GuestNotice::selectedDate()). listen(), not componentHook(), for the
        // same timing reason as in registerOrangeHooks(). A throwing listener
        // would break the page, so GuestNotice::onRender() never throws.
        Livewire::listen('render', static fn ($component) => GuestNotice::onRender($component));
    }

    /** Tightens the telephone rule once the rule set of the public booking form is recognised. */
    private function registerOrangeValidator(): void
    {
        Validator::resolver(function ($translator, array $data, array $rules, array $messages, array $attributes) {
            $isBookingForm = $this->isBookingForm($rules);
            if ($isBookingForm) {
                $rules['telephone'] = self::publicPhoneRules();
            }

            $validator = new \Illuminate\Validation\Validator($translator, $data, $rules, $messages, $attributes);

            // A greyed-out button is not a rule: refuse a blocked slot here too.
            // The component carries date, time and guest; the form's own data
            // does not. Never throws, and lets the booking through when unsure.
            if ($isBookingForm) {
                $validator->after(function ($validator): void {
                    if (($message = BlockedSlotGuard::blockedMessage(BookingContext::component())) !== null) {
                        $validator->errors()->add('time', $message);
                    }
                });
            }

            return $validator;
        });
    }

    /**
     * Whom the application believes about the origin of a request. Not the same
     * question as who may see the internal pages - see
     * InternalNetworkOnly::allowedNetworks().
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        return SettingValue::networks('trusted_proxies', self::DEFAULT_TRUSTED_PROXIES);
    }

    /**
     * "attempts,minutes". Setting first, then the ADMIN_RATE_LIMIT variable
     * that running installations already use, then the default.
     */
    public static function adminRateLimit(): string
    {
        // Whitespace around the parts is tolerated ("30, 1"), as it always was.
        $normalise = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\s*([1-9]\d{0,5})\s*,\s*([1-9]\d{0,4})\s*$/', $v, $m) === 1
            ? $m[1].','.$m[2]
            : null;

        return $normalise(SettingValue::stored('admin_rate_limit'))
            ?? $normalise(env('ADMIN_RATE_LIMIT'))
            ?? self::DEFAULT_ADMIN_RATE_LIMIT;
    }

    /** No default: without a configured address no Reply-To is set. */
    public static function replyToAddress(): ?string
    {
        $address = SettingValue::nullableString('reply_to_address') ?? (is_string($env = env('MAIL_REPLY_TO_ADDRESS')) ? trim($env) : null);

        return $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    public static function replyToName(): string
    {
        $env = env('MAIL_REPLY_TO_NAME');

        return SettingValue::nullableString('reply_to_name') ?? (is_string($env) ? $env : '');
    }

    public static function maxNameLength(): int
    {
        return SettingValue::int('max_name_length', self::DEFAULT_MAX_NAME_LENGTH);
    }

    public static function maxEmailLength(): int
    {
        return SettingValue::int('max_email_length', self::DEFAULT_MAX_EMAIL_LENGTH);
    }

    public static function maxPhoneLength(): int
    {
        return SettingValue::int('max_phone_length', self::DEFAULT_MAX_PHONE_LENGTH);
    }

    /** @return list<string> */
    public static function publicFormFields(): array
    {
        return SettingValue::identifiers('public_form_fields', self::DEFAULT_PUBLIC_FORM_FIELDS);
    }

    /** Rules for the telephone field of the public booking form. */
    public static function publicPhoneRules(): array
    {
        $rules = ['regex:'.SettingValue::pattern('phone_pattern', self::DEFAULT_PHONE_PATTERN)];

        return SettingValue::flag('phone_required_public', true) ? ['required', ...$rules] : ['nullable', ...$rules];
    }

    public function registerSettings(): array
    {
        return [
            'settings' => [
                'label' => 'lang:reservationcontrol::default.settings_label',
                'description' => 'lang:reservationcontrol::default.settings_description',
                'icon' => 'fa fa-calendar-check',
                'model' => Settings::class,
                'permissions' => ['Wagnersnetz.ReservationControl.ManageSettings'],
            ],
        ];
    }

    public function registerPermissions(): array
    {
        return [
            'Wagnersnetz.ReservationControl.ManageSettings' => [
                'label' => 'lang:reservationcontrol::default.permission_manage_settings',
                'group' => 'module',
            ],
        ];
    }

    /**
     * The same phone intake as an API, for the desktop edition of the app.
     *
     * Deliberately registered on the middleware and the prefix of the API
     * (config('igniter-api.*')) and not on a group of our own: that way Sanctum
     * authentication, throttling and prefix apply exactly as for the remaining
     * endpoints. ti-ext-api itself uses the same pattern for
     * PATCH reservations/{id}/status.
     *
     * Called from boot(): there the configuration of the API extension is set,
     * in register() it would not be yet, depending on the load order.
     *
     * The URL paths and route names stay German - live installations and the
     * app at the counter call them.
     */
    private function registerInternalApiRoutes(): void
    {
        Route::middleware(config('igniter-api.middleware'))
            ->prefix(config('igniter-api.prefix'))
            ->group(function (): void {
                Route::get('intern/tag', [InternalApiController::class, 'day'])
                    ->name('reservationcontrol.api.tag');
                Route::post('intern/reservierung', [InternalApiController::class, 'accept'])
                    ->name('reservationcontrol.api.annehmen');
                Route::get('intern/tagesblatt', [InternalApiController::class, 'dailySheet'])
                    ->name('reservationcontrol.api.tagesblatt');
                Route::get('intern/monat', [InternalApiController::class, 'month'])
                    ->name('reservationcontrol.api.monat');
                Route::get('intern/offen', [InternalApiController::class, 'pending'])
                    ->name('reservationcontrol.api.offen');
                Route::get('intern/sperrtage', [InternalApiController::class, 'blockedDates'])
                    ->name('reservationcontrol.api.sperrtage');
                Route::post('intern/sperrtage', [InternalApiController::class, 'block'])
                    ->name('reservationcontrol.api.sperren');
                Route::delete('intern/sperrtage', [InternalApiController::class, 'unblock'])
                    ->name('reservationcontrol.api.freigeben');
            });
    }

    /**
     * Phone intake. Deliberately lies outside /admin: no login, but strictly
     * limited to the local network (InternalNetworkOnly).
     */
    private function registerInternalRoutes(): void
    {
        // The 'igniter' group carries TastyIgniter's Localization middleware,
        // which sets the request locale from the installation's default
        // language. Without it these pages render in config('app.locale')
        // regardless: an installation whose default language is German served
        // English internal pages, because nothing on this route ever set the
        // locale. Found while deploying to the first real installation.
        Route::middleware(['web', 'igniter', InternalNetworkOnly::class])
            ->prefix('intern')
            ->group(function (): void {
                Route::get('/', [InternalBookingController::class, 'index'])->name('reservationcontrol.intern');
                Route::get('/druck', [InternalBookingController::class, 'printDay'])->name('reservationcontrol.intern.print');
                Route::post('/', [InternalBookingController::class, 'store'])->name('reservationcontrol.intern.store');
                Route::post('/sperren', [InternalBookingController::class, 'block'])->name('reservationcontrol.intern.block');
                Route::post('/freigeben', [InternalBookingController::class, 'unblock'])->name('reservationcontrol.intern.unblock');
            });
    }

    /** Livewire hooks for the Orange theme's booking component. */
    private function registerOrangeHooks(): void
    {
        // Not over componentHook(): ComponentHookRegistry::boot() wires up the
        // mount/hydrate listeners once when Livewire boots. If the hook is
        // registered after that - and extensions boot later - it never gets
        // them. listen(), by contrast, hangs straight into the event bus,
        // independent of the order.
        Livewire::listen('mount', function ($component): void {
            if ($component instanceof Booking) {
                BookingContext::remember($component);
            }
        });

        Livewire::listen('hydrate', function ($component): void {
            if ($component instanceof Booking) {
                BookingContext::remember($component);
            }
        });

        // prepareDates() only runs in mount(); the blocked days would otherwise
        // stay as they were while the time slots have already opened up.
        Livewire::listen('update', function ($component, $fullPath) {
            if (! $component instanceof Booking || str_before((string) $fullPath, '.') !== 'guest') {
                return null;
            }

            return function () use ($component): void {
                (function (): void {
                    $this->dates = [];
                    $this->disabledDates = [];
                    $this->prepareDates();
                })->call($component);
            };
        });
    }

    private function isBookingForm(array $rules): bool
    {
        foreach (self::publicFormFields() as $field) {
            if (! array_key_exists($field, $rules)) {
                return false;
            }
        }

        return true;
    }
}
