<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Api;

use Illuminate\Http\Request;

/**
 * Relations the API should always deliver along.
 *
 * The ReservationTransformer only lists "status" as a possible include:
 * without ?include=status the record does contain status_id and status_name,
 * but no status object and no entry under "included". An app that resolves the
 * status over the relation - TastyCompanion does - then shows "no value".
 *
 * Instead of meddling with the middleware list of the API (that hangs off
 * config('igniter-api.middleware') and is read when the routes are registered,
 * so it depends on the load order of the extensions), the parameter is added at
 * the routing event. laravel-fractal reads it with
 * request()->query('include'), and that only happens in the controller -
 * afterwards.
 *
 * An explicit entry by the caller is left untouched.
 */
class StandardIncludes
{
    /** Route prefix => relations that come along without an entry of their own. */
    private const array DEFAULTS = [
        'igniter.api.reservations.' => 'status,tables',
    ];

    public static function apply(Request $request, ?string $routeName): void
    {
        if ($routeName === null || $request->query('include')) {
            return;
        }

        foreach (self::DEFAULTS as $prefix => $includes) {
            if (str_starts_with($routeName, $prefix)) {
                $request->query->set('include', $includes);

                return;
            }
        }
    }
}
