<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Flame\Translation\Middleware\Localization;
use Igniter\System\Models\Language;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives an execution that nothing has set a locale for the installation's
 * default language: one decision (ensure()), two entry points.
 *
 *  - forRoute(): a request. TastyIgniter's API routes do not carry the 'igniter'
 *    middleware group, so nothing sets the locale there.
 *  - forConsole(): the command line (scheduler, queue worker, artisan), where no
 *    request exists at all - the automation's reminders are sent from there.
 *
 * If nothing established a locale, the installation's default language is used.
 *
 * Without it mails and texts come out in config('app.locale') - English. Routes
 * that DO carry the group are left alone: the middleware has decided, and an admin's own language
 * must not be overwritten.
 *
 * Never throws: an English mail is a blemish, a failing API call stops the
 * counter.
 */
final class RequestLocale
{
    /**
     * The locale the application started with. Application::setLocale() also
     * rewrites config('app.locale'), so the config cannot tell afterwards
     * whether somebody set a locale; this snapshot, taken at boot, can.
     */
    private static ?string $initial = null;

    /** Take the snapshot (once, at boot, before anything can set a locale). */
    public static function remember(): void
    {
        self::$initial ??= (string) app()->getLocale();
    }

    /** A request: the route's middleware may already have decided. */
    public static function forRoute(Route $route): void
    {
        try {
            self::ensure(self::localized($route));
        } catch (Throwable $e) {
            self::report($e->getMessage());
        }
    }

    /** The command line: runs at boot, before any command can set a locale of its own. */
    public static function forConsole(): void
    {
        try {
            if (app()->runningInConsole()) {
                self::ensure(false);
            }
        } catch (Throwable $e) {
            self::report($e->getMessage());
        }
    }

    /**
     * The one decision. Nothing is done when a middleware has decided
     * ($decided) or when the locale already differs from the one the application
     * started with (a command, an admin's preference: deliberately set).
     * Otherwise the default language, through the platform's own setter (it
     * also sets Carbon and refuses unsupported codes). Never throws: an English
     * mail is a blemish, a failing request or artisan command stops the day.
     */
    public static function ensure(bool $decided): void
    {
        try {
            self::remember();

            if ($decided || app()->getLocale() !== self::$initial) {
                return;
            }

            $code = Language::getDefault()?->code;
            if (! is_string($code) || $code === '') {
                return;
            }

            if (app('translator.localization')->setLocale($code) === false) {
                self::report('unsupported default language "'.$code.'"');
            }
        } catch (Throwable $e) {
            self::report($e->getMessage());
        }
    }

    /** Does something on this route set the locale (the 'igniter' group, i.e. the Localization middleware)? */
    public static function localized(Route $route): bool
    {
        $names = $route->gatherMiddleware();
        if (in_array('igniter', $names, true)) {
            return true;
        }

        $resolved = app('router')->resolveMiddleware($names, $route->excludedMiddleware());

        return in_array(Localization::class, $resolved, true);
    }

    private static function report(string $message): void
    {
        try {
            Log::warning('reservationcontrol: request locale left alone: '.$message);
        } catch (Throwable) {
            // Logging must not take the request down either.
        }
    }
}
