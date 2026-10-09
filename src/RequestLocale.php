<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Igniter\Flame\Translation\Middleware\Localization;
use Igniter\System\Models\Language;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a request that no Localization middleware touches the installation's
 * default language.
 *
 * TastyIgniter's API routes do not carry the 'igniter' middleware group, so
 * nothing sets the locale there and mails and texts triggered from the API
 * come out in config('app.locale') - English. Routes that DO carry the group
 * are left alone: the middleware has decided, and an admin's own language
 * must not be overwritten.
 *
 * Never throws: an English mail is a blemish, a failing API call stops the
 * counter.
 */
final class RequestLocale
{
    public static function apply(Route $route): void
    {
        try {
            if (self::localized($route)) {
                return;
            }

            $code = Language::getDefault()?->code;
            if (! is_string($code) || $code === '') {
                return;
            }

            // The platform's own setter: also sets Carbon and refuses unsupported codes.
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
