<?php

declare(strict_types=1);

use Igniter\Flame\Translation\Middleware\Localization;
use Igniter\System\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Wagnersnetz\ReservationControl\RequestLocale;

beforeEach(function (): void {
    Language::clearInternalCache();
    // Resolve first: resolving fills the config from the database and would overwrite ours.
    app('translator.localization');
    // The platform fills these from the default language and the supported-languages parameter.
    config(['localization.locale' => 'de', 'localization.supportedLocales' => ['en', 'de']]);
    app()->setLocale('en');
});

afterEach(fn () => app()->setLocale('en'));

function localeDefaultLanguage(?string $code): void
{
    Language::query()->update(['is_default' => 0]);
    if ($code !== null) {
        Language::query()->updateOrCreate(['code' => $code], ['name' => $code, 'status' => 1, 'is_default' => 1]);
        Language::query()->where('code', $code)->update(['is_default' => 1]);
    }
    Language::clearInternalCache();
    Language::clearDefaultModels();

    if ($code === null) {
        // getDefault() promotes the first language when none is marked, and a
        // language cannot be deleted (pages refer to them): its cache says "none".
        (new ReflectionProperty(Language::class, 'defaultModels'))->setValue(null, [Language::class => null]);
    }
}

function localeRoute(array $middleware): Route
{
    return (new Route('GET', '/api/reservations', fn () => 'ok'))->middleware($middleware);
}

function localeMatched(Route $route): void
{
    Event::dispatch(new RouteMatched($route, Request::create('/api/reservations')));
}

it('gives a route without the igniter group the default language', function (): void {
    localeDefaultLanguage('de');

    localeMatched(localeRoute(['api']));

    expect(app()->getLocale())->toBe('de')
        ->and(Carbon\Carbon::getLocale())->toBe('de');
});

it('renders translated text in german under an API-shaped request', function (): void {
    localeDefaultLanguage('de');
    expect(__('reservationcontrol::default.invitation_call'))->toStartWith('No suitable');

    localeMatched(localeRoute(['api']));

    expect(__('reservationcontrol::default.invitation_call'))->toStartWith('Keine passende');
});

it('leaves a route that carries the igniter group alone', function (): void {
    localeDefaultLanguage('de');

    localeMatched(localeRoute(['web', 'igniter']));

    expect(app()->getLocale())->toBe('en');
});

it('does not overwrite a locale already chosen on an igniter route', function (): void {
    localeDefaultLanguage('de');
    config(['localization.supportedLocales' => ['en', 'de', 'fr']]);
    app()->setLocale('fr');

    localeMatched(localeRoute(['igniter']));

    expect(app()->getLocale())->toBe('fr');
});

it('recognises the group through its Localization middleware too', function (): void {
    expect(RequestLocale::localized(localeRoute(['api'])))->toBeFalse()
        ->and(RequestLocale::localized(localeRoute(['igniter'])))->toBeTrue()
        ->and(RequestLocale::localized(localeRoute([Localization::class])))->toBeTrue();
});

it('leaves the locale alone and throws nothing without a default language', function (): void {
    localeDefaultLanguage(null);

    localeMatched(localeRoute(['api']));

    expect(app()->getLocale())->toBe('en');
});

it('logs and leaves the locale alone for an unsupported default language', function (): void {
    localeDefaultLanguage('de');
    config(['localization.supportedLocales' => ['en']]);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'unsupported'));

    localeMatched(localeRoute(['api']));

    expect(app()->getLocale())->toBe('en');
});

it('survives an exception while looking the language up', function (): void {
    localeDefaultLanguage('de');
    DB::listen(static function (): void {
        throw new RuntimeException('database down');
    });
    Log::shouldReceive('warning')->once();

    localeMatched(localeRoute(['api']));

    expect(app()->getLocale())->toBe('en');
});
