<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Api;

use Illuminate\Http\Request;

/**
 * Beziehungen, die das API immer mitliefern soll.
 *
 * Der ReservationTransformer fuehrt "status" nur als moeglichen Include: ohne
 * ?include=status steht im Datensatz zwar status_id und status_name, aber kein
 * Status-Objekt und kein Eintrag unter "included". Eine App, die den Status ueber
 * die Beziehung aufloest - TastyCompanion tut das - zeigt dann "no value" an.
 *
 * Statt in der Middleware-Liste des API zu ruehren (die haengt an
 * config('igniter-api.middleware') und wird beim Anmelden der Routen
 * ausgelesen, also von der Ladereihenfolge der Erweiterungen abhaengig) wird der
 * Parameter beim Routing-Ereignis nachgetragen. laravel-fractal liest ihn mit
 * request()->query('include'), und das passiert erst im Controller - danach.
 *
 * Eine ausdrueckliche Angabe des Aufrufers bleibt unangetastet.
 */
class StandardIncludes
{
    /** Routen-Praefix => Beziehungen, die ohne eigene Angabe mitkommen. */
    private const array VORGABE = [
        'igniter.api.reservations.' => 'status,tables',
    ];

    public static function ergaenzen(Request $request, ?string $routenname): void
    {
        if ($routenname === null || $request->query('include')) {
            return;
        }

        foreach (self::VORGABE as $praefix => $includes) {
            if (str_starts_with($routenname, $praefix)) {
                $request->query->set('include', $includes);

                return;
            }
        }
    }
}
