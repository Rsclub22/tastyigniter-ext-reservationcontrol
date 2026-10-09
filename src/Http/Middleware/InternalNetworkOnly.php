<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laesst nur Aufrufe aus dem lokalen Netz durch.
 *
 * Die Telefonannahme legt Reservierungen ohne jede Anmeldung an - sie darf
 * deshalb unter keinen Umstaenden aus dem Internet erreichbar sein. Drei Ebenen
 * sichern das ab:
 *   1. Caddy beantwortet /intern* auf der oeffentlichen Adresse mit 404.
 *   2. Der Container veroeffentlicht dafuer einen eigenen Port nur im LAN.
 *   3. Diese Middleware prueft zusaetzlich die tatsaechliche Absenderadresse.
 *
 * Punkt 3 ist die Rueckfallebene, falls jemand 1 oder 2 spaeter aendert.
 */
class InternalNetworkOnly
{
    /** Private Netze nach RFC 1918 plus Loopback und das VPN-Netz des Pi. */
    private const array ALLOWED = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Vertraute Proxies sind gesetzt, ip() liefert daher die echte
        // Absenderadresse und nicht die der Bridge.
        if (!IpUtils::checkIp((string)$request->ip(), self::ALLOWED)) {
            abort(404);
        }

        return $next($request);
    }
}
