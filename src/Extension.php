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
use Wagnersnetz\ReservationControl\Api\StandardIncludes;
use Wagnersnetz\ReservationControl\Console\EnterReservation;
use Wagnersnetz\ReservationControl\Console\ImportReservations;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalApiController;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBookingController;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Wagnersnetz\ReservationControl\Models\Settings;

/**
 * Local adjustments to the reservation form:
 *
 * 1. The telephone number is a required field. Igniter\Orange\Livewire\Booking
 *    and its BookingForm are `final`, so they cannot be subclassed. The rules
 *    come from BookingForm::rules() and only take effect when the validator is
 *    created - hence the resolver, which tightens exactly this one rule as soon
 *    as it recognises the rule set of the booking form.
 * 2. Time slots follow the opening hours, from a larger party on they no longer
 *    do - see LargePartyBookingManager.
 */
class Extension extends BaseExtension
{
    /** Fields by which the rule set of the booking form is recognised. */
    private const BOOKING_FIELDS = ['firstName', 'lastName', 'telephone'];

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

        // The application runs behind Caddy. Without trusted proxies it sees
        // the bridge address of the container for every visitor - all visitors
        // then share one throttling counter and the admin login locks itself
        // out after a few calls. The container is bound to 127.0.0.1 only, so
        // it is reachable exclusively through the proxy.
        TrustProxies::at(['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        // TastyIgniter throttles the admin login with 6 requests per minute and
        // counts merely opening the page towards that. For a single user that is
        // too tight; 30 per minute still protects against brute forcing but
        // does not lock anybody out over a typo.
        config(['igniter-auth.rateLimiter' => env('ADMIN_RATE_LIMIT', '30,1')]);

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
        });

        // The sender is a no-reply address. A guest's replies should still end
        // up in the house, hence a Reply-To. Over the MessageSending event
        // instead of Mail::alwaysReplyTo(), so that the mailer is not resolved
        // during boot already. Only sets it when the message does not bring one
        // of its own.
        Event::listen(MessageSending::class, function (MessageSending $event): void {
            $address = env('MAIL_REPLY_TO_ADDRESS', 'info@zum-braunen-ross-bauerbach.de');
            if (! $address || $event->message->getReplyTo()) {
                return;
            }

            $event->message->replyTo(new Address($address, (string) env('MAIL_REPLY_TO_NAME', 'Gasthaus Zum braunen Roß')));
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
            $holder->rules['first_name'] = ['nullable', 'string', 'between:1,48'];
            $holder->rules['last_name'] = ['required_without_all:first_name,customer_id', 'nullable', 'string', 'between:1,48'];
            $holder->rules['email'] = ['nullable', 'email:filter', 'max:96'];
            $holder->rules['telephone'] = ['nullable', 'string', 'max:40'];

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

        // Always deliver reservations in the API with status and tables. See
        // StandardIncludes: without it TastyCompanion shows "no value" for the
        // status, because it resolves it over the relation.
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            StandardIncludes::apply($event->request, $event->route->getName());
        });

        Validator::resolver(function ($translator, array $data, array $rules, array $messages, array $attributes) {
            if ($this->isBookingForm($rules)) {
                $rules['telephone'] = ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/i'];
            }

            return new \Illuminate\Validation\Validator($translator, $data, $rules, $messages, $attributes);
        });
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
        Route::middleware(['web', InternalNetworkOnly::class])
            ->prefix('intern')
            ->group(function (): void {
                Route::get('/', [InternalBookingController::class, 'index'])->name('reservationcontrol.intern');
                Route::get('/druck', [InternalBookingController::class, 'printDay'])->name('reservationcontrol.intern.print');
                Route::post('/', [InternalBookingController::class, 'store'])->name('reservationcontrol.intern.store');
                Route::post('/sperren', [InternalBookingController::class, 'block'])->name('reservationcontrol.intern.block');
                Route::post('/freigeben', [InternalBookingController::class, 'unblock'])->name('reservationcontrol.intern.unblock');
            });
    }

    private function isBookingForm(array $rules): bool
    {
        foreach (self::BOOKING_FIELDS as $field) {
            if (! array_key_exists($field, $rules)) {
                return false;
            }
        }

        return true;
    }
}
