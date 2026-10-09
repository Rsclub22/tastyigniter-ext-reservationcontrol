<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Api\ApiResources\Requests\ReservationRequest as ApiReservationRequest;
use Igniter\Orange\Livewire\Booking;
use Igniter\Reservation\Classes\BookingManager;
use Igniter\Reservation\Http\Requests\ReservationRequest;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Igniter\Local\Events\WorkingScheduleCreatedEvent;
use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Events\RouteMatched;
use Wagnersnetz\ReservationControl\Api\StandardIncludes;
use Wagnersnetz\ReservationControl\Console\ReservierungErfassen;
use Wagnersnetz\ReservationControl\Console\ReservierungImport;
use Wagnersnetz\ReservationControl\Http\Controllers\InternApi;
use Wagnersnetz\ReservationControl\Http\Controllers\InternalBooking;
use Wagnersnetz\ReservationControl\Http\Middleware\InternalNetworkOnly;
use Symfony\Component\Mime\Address;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/**
 * Lokale Anpassungen am Reservierungsformular:
 *
 * 1. Telefonnummer ist Pflichtfeld. Igniter\Orange\Livewire\Booking und dessen
 *    BookingForm sind `final`, lassen sich also nicht ableiten. Die Regeln kommen
 *    aus BookingForm::rules() und greifen erst beim Erzeugen des Validators —
 *    deshalb der Resolver, der genau diese eine Regel verschärft, sobald er das
 *    Regelwerk des Buchungsformulars erkennt.
 * 2. Zeitfenster folgen den Öffnungszeiten, ab einer größeren Gesellschaft nicht
 *    mehr — siehe LargePartyBookingManager.
 */
class Extension extends BaseExtension
{
    /** Felder, an denen das Regelwerk des Buchungsformulars erkannt wird. */
    private const BOOKING_FIELDS = ['firstName', 'lastName', 'telephone'];

    /**
     * Konsolenbefehle fuer die Erfassung von Hand. Muessen in register()
     * angemeldet werden - in boot() ist die Befehlsliste von Artisan schon
     * zusammengestellt, die Befehle tauchen dann nicht auf.
     */
    public function register(): void
    {
        $this->registerConsoleCommand('reservationcontrol.erfassen', ReservierungErfassen::class);
        $this->registerConsoleCommand('reservationcontrol.import', ReservierungImport::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reservationcontrol');
        $this->registerInternalRoutes();
        $this->registerInternalApiRoutes();

        // Die Anwendung laeuft hinter Caddy. Ohne vertrauenswuerdige Proxies sieht
        // sie bei jedem Besucher die Bridge-Adresse des Containers - damit teilen
        // sich alle Besucher einen Drosselzaehler und die Admin-Anmeldung sperrt
        // sich nach wenigen Aufrufen selbst aus. Der Container ist nur an
        // 127.0.0.1 gebunden, erreichbar also ausschliesslich ueber den Proxy.
        TrustProxies::at(['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);

        // TastyIgniter drosselt die Admin-Anmeldung mit 6 Anfragen pro Minute und
        // zaehlt dabei auch das reine Aufrufen der Seite mit. Fuer einen einzelnen
        // Benutzer ist das zu eng; 30 pro Minute schuetzt weiter gegen
        // Durchprobieren, sperrt aber niemanden beim Tippfehler aus.
        config(['igniter-auth.rateLimiter' => env('ADMIN_RATE_LIMIT', '30,1')]);

        // Gesperrte Einzeltage in jeden erzeugten Zeitplan hineinreichen.
        // WorkingSchedule::forDate() prueft Ausnahmen vor dem Wochentag, ein
        // leerer Zeitraum bedeutet geschlossen. Greift dadurch ueberall gleich:
        // oeffentliches Formular, Telefonannahme und Belegungsanzeige.
        // Auf die Ereignisklasse hoeren, nicht auf den Namen: EventDispatchable
        // feuert beides, der String uebergibt aber zwei lose Argumente
        // ($model, $schedule) statt des Objekts.
        Event::listen(WorkingScheduleCreatedEvent::class, function(WorkingScheduleCreatedEvent $event): void {
            if ($exceptions = BlockedDates::asScheduleExceptions()) {
                $event->schedule->setExceptions($exceptions);
            }
        });

        // Absender ist eine No-Reply-Adresse. Antworten eines Gastes sollen trotzdem
        // im Haus landen, daher ein Reply-To. Ueber das MessageSending-Ereignis
        // statt Mail::alwaysReplyTo(), damit der Mailer nicht schon beim Booten
        // aufgeloest wird. Setzt nur, wenn die Nachricht selbst keins mitbringt.
        Event::listen(MessageSending::class, function(MessageSending $event): void {
            $address = env('MAIL_REPLY_TO_ADDRESS', 'info@zum-braunen-ross-bauerbach.de');
            if (!$address || $event->message->getReplyTo()) {
                return;
            }

            $event->message->replyTo(new Address($address, (string)env('MAIL_REPLY_TO_NAME', 'Gasthaus Zum braunen Roß')));
        });

        // Im Backend reicht ein Name. Die importierten Reservierungen fuer den
        // Rest des Jahres haben teils weder E-Mail noch Telefonnummer; mit den
        // Kernregeln liesse sich so ein Datensatz nicht mehr speichern, weil
        // first_name, last_name, email UND telephone alle Pflicht sind, sobald
        // kein Kundenkonto dranhaengt.
        //
        // Backend-Formular UND API: beide erben von Igniter\System\Classes\
        // FormRequest, die dieses Ereignis feuert - das API hat aber seine eigene
        // Request-Klasse mit denselben strengen Regeln. Ohne sie hier mitzunehmen
        // liess sich eine Telefonbestellung ueber das API nicht mehr aendern:
        // die Telefonannahme kennt gar kein Vornamensfeld, die Eintraege haben
        // also nie einen Vornamen und meist keine E-Mail - und das API verlangte
        // beides. Aufgefallen ist es in der Reservierungs-App am Tresen.
        //
        // Das oeffentliche Buchungsformular bleibt unberuehrt: es schickt seine
        // Felder in camelCase (firstName), dort sind Telefonnummer und E-Mail
        // weiterhin Pflicht.
        Event::listen('system.formRequest.extendValidator', function($request, $holder): void {
            if (!$request instanceof ReservationRequest && !$request instanceof ApiReservationRequest) {
                return;
            }

            // Mindestens einer der beiden Namen - sonst steht der Datensatz
            // ohne jeden Anhaltspunkt da, wer da eigentlich kommt. Die Bedingung
            // haengt nur am Nachnamen, sonst meldet das Formular denselben
            // Hinweis zweimal.
            $holder->rules['first_name'] = ['nullable', 'string', 'between:1,48'];
            $holder->rules['last_name'] = ['required_without_all:first_name,customer_id', 'nullable', 'string', 'between:1,48'];
            $holder->rules['email'] = ['nullable', 'email:filter', 'max:96'];
            $holder->rules['telephone'] = ['nullable', 'string', 'max:40'];

            $holder->messages['last_name.required_without_all'] = 'Bitte mindestens Vor- oder Nachname angeben.';
        });

        // Die gelockerten Regeln oben lassen Vorname, Nachname und E-Mail leer
        // durch - die Spalten sind aber NOT NULL. Ohne das Folgende bricht das
        // Backend-Formular mit "Column 'email' cannot be null" ab, sobald eine
        // Reservierung von Hand ohne E-Mail eingetragen wird. Leerer String
        // statt einer erfundenen Adresse: an eine solche wuerde spaeter jemand
        // zu senden versuchen.
        Reservation::saving(function(Reservation $reservation): void {
            foreach (['first_name', 'last_name', 'email'] as $feld) {
                if ($reservation->{$feld} === null) {
                    $reservation->{$feld} = '';
                }
            }

            // Ohne mitgeschickten Status schreibt das API eine 0 in die Spalte -
            // den eingestellten Vorgabestatus wendet es nicht an. Zu dieser 0
            // gibt es keinen Status-Datensatz, und der StatusTransformer des API
            // verlangt per Typ einen echten: ein einziger solcher Eintrag liess
            // die gesamte Reservierungsliste mit einem TypeError auflaufen, auch
            // an Tagen ohne Reservierungen. Am Tresen aufgefallen am 01.10.2026,
            // ausgeloest von einer Reservierung aus der App.
            //
            // Hier und nicht im Client, damit es fuer jeden Weg gilt: App,
            // oeffentliches Formular, Import, Konsole.
            if (!$reservation->status_id && ($vorgabe = (int)setting('default_reservation_status'))) {
                $reservation->status_id = $vorgabe;
            }
        });

        // Tischvergabe selbst uebernehmen: Einzeltisch zuerst, Kombination nur,
        // wenn kein einzelner Tisch reicht.
        //
        // Die Standorteinstellung "Tische automatisch zuweisen" MUSS dafuer aus
        // bleiben. Der Beobachter der Reservierungs-Erweiterung haengt am selben
        // saved-Ereignis, laeuft aber nachweislich spaeter - er wuerde jede
        // Reservierung, die hier keinen Tisch bekommen hat, doch noch mit einer
        // Kombination belegen, deren Einzeltische laengst vergeben sind. Ist die
        // Einstellung aus, haelt er sich heraus und diese Vergabe ist die einzige.
        Reservation::saved(function(Reservation $reservation): void {
            // Von Hand gesetzte Tische (Admin, Telefonannahme, Raeume) gewinnen.
            if (array_key_exists('tables', $reservation->getAttributes())) {
                return;
            }

            // Beim Bearbeiten einen bereits vergebenen Tisch stehen lassen -
            // sonst raeumt jede Aenderung im Backend die Handvergabe weg.
            if (!$reservation->wasRecentlyCreated && $reservation->tables()->count()) {
                return;
            }

            $tisch = TableAllocator::allocate($reservation);

            $reservation->addReservationTables($tisch ? [$tisch->getKey()] : []);
        });

        // Der Manager wird erst pro Request aufgelöst, das Umbiegen im boot()
        // kommt also früh genug - und sicher nach register() der Reservierungs-
        // Erweiterung, die das Singleton ursprünglich bindet.
        $this->app->singleton(BookingManager::class, LargePartyBookingManager::class);

        // Nicht über componentHook(): ComponentHookRegistry::boot() verdrahtet die
        // mount/hydrate-Listener einmalig beim Booten von Livewire. Wird der Hook
        // danach registriert - und Extensions booten später - bekommt er sie nie.
        // listen() hängt dagegen direkt in den EventBus, unabhängig von der Reihenfolge.
        Livewire::listen('mount', function($component): void {
            if ($component instanceof Booking) {
                BookingContext::remember($component);
            }
        });

        Livewire::listen('hydrate', function($component): void {
            if ($component instanceof Booking) {
                BookingContext::remember($component);
            }
        });

        // prepareDates() laeuft nur in mount(); die gesperrten Tage blieben sonst
        // stehen, waehrend die Zeitfenster sich schon geoeffnet haben.
        Livewire::listen('update', function($component, $fullPath) {
            if (!$component instanceof Booking || str_before((string)$fullPath, '.') !== 'guest') {
                return null;
            }

            return function() use ($component): void {
                (function(): void {
                    $this->dates = [];
                    $this->disabledDates = [];
                    $this->prepareDates();
                })->call($component);
            };
        });

        // Reservierungen im API immer mit Status und Tischen ausliefern.
        // Siehe StandardIncludes: ohne das zeigt TastyCompanion beim Status
        // "no value", weil es ihn ueber die Beziehung aufloest.
        Event::listen(RouteMatched::class, function(RouteMatched $event): void {
            StandardIncludes::ergaenzen($event->request, $event->route->getName());
        });

        Validator::resolver(function($translator, array $data, array $rules, array $messages, array $attributes) {
            if ($this->isBookingForm($rules)) {
                $rules['telephone'] = ['required', 'regex:/^([0-9\s\-\+\(\)]*)$/i'];
            }

            return new \Illuminate\Validation\Validator($translator, $data, $rules, $messages, $attributes);
        });
    }

    /**
     * Dieselbe Telefonannahme als API, fuer die Desktop-Fassung der App.
     *
     * Absichtlich an der Middleware und dem Prefix des API angemeldet
     * (config('igniter-api.*')) und nicht an einer eigenen Gruppe: damit gelten
     * Sanctum-Authentifizierung, Drosselung und Prefix genau wie fuer die
     * uebrigen Endpunkte. Dasselbe Muster benutzt ti-ext-api selbst fuer
     * PATCH reservations/{id}/status.
     *
     * Aufgerufen aus boot(): dort ist die Konfiguration der API-Erweiterung
     * gesetzt, in register() waere sie es je nach Ladereihenfolge noch nicht.
     */
    private function registerInternalApiRoutes(): void
    {
        Route::middleware(config('igniter-api.middleware'))
            ->prefix(config('igniter-api.prefix'))
            ->group(function(): void {
                Route::get('intern/tag', [InternApi::class, 'tag'])
                    ->name('reservationcontrol.api.tag');
                Route::post('intern/reservierung', [InternApi::class, 'annehmen'])
                    ->name('reservationcontrol.api.annehmen');
                Route::get('intern/tagesblatt', [InternApi::class, 'tagesblatt'])
                    ->name('reservationcontrol.api.tagesblatt');
                Route::get('intern/monat', [InternApi::class, 'monat'])
                    ->name('reservationcontrol.api.monat');
                Route::get('intern/offen', [InternApi::class, 'offen'])
                    ->name('reservationcontrol.api.offen');
                Route::get('intern/sperrtage', [InternApi::class, 'sperrtage'])
                    ->name('reservationcontrol.api.sperrtage');
                Route::post('intern/sperrtage', [InternApi::class, 'sperren'])
                    ->name('reservationcontrol.api.sperren');
                Route::delete('intern/sperrtage', [InternApi::class, 'freigeben'])
                    ->name('reservationcontrol.api.freigeben');
            });
    }

    /**
     * Telefonannahme. Liegt bewusst ausserhalb von /admin: kein Login, dafuer
     * strikt auf das lokale Netz begrenzt (InternalNetworkOnly).
     */
    private function registerInternalRoutes(): void
    {
        Route::middleware(['web', InternalNetworkOnly::class])
            ->prefix('intern')
            ->group(function(): void {
                Route::get('/', [InternalBooking::class, 'index'])->name('reservationcontrol.intern');
                Route::get('/druck', [InternalBooking::class, 'printDay'])->name('reservationcontrol.intern.print');
                Route::post('/', [InternalBooking::class, 'store'])->name('reservationcontrol.intern.store');
                Route::post('/sperren', [InternalBooking::class, 'block'])->name('reservationcontrol.intern.block');
                Route::post('/freigeben', [InternalBooking::class, 'unblock'])->name('reservationcontrol.intern.unblock');
            });
    }

    private function isBookingForm(array $rules): bool
    {
        foreach (self::BOOKING_FIELDS as $field) {
            if (!array_key_exists($field, $rules)) {
                return false;
            }
        }

        return true;
    }
}
