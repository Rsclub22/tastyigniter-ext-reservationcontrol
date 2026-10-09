<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Wagnersnetz\ReservationControl\SettingValue;

/**
 * Lets only calls from the local network through.
 *
 * Phone intake creates reservations without any login at all - it must
 * therefore under no circumstances be reachable from the internet. Three
 * layers secure that:
 *   1. Caddy answers /intern* on the public address with a 404.
 *   2. The container publishes a port of its own for it, LAN only.
 *   3. This middleware additionally checks the actual sender address.
 *
 * Point 3 is the fallback layer in case somebody changes 1 or 2 later on.
 */
class InternalNetworkOnly
{
    /**
     * Loopback only. Private ranges (10/8, 172.16/12, 192.168/16, fc00::/7) must
     * be opted into via the setting: the page is unauthenticated, and any other
     * host on a shared private network would otherwise reach it.
     */
    public const array DEFAULT_ALLOWED = ['127.0.0.1', '::1'];

    /**
     * Who may see the internal pages. A separate question from whom the
     * application believes about a request's origin (trusted proxies), even
     * though both are narrow by default.
     *
     * @return list<string>
     */
    public static function allowedNetworks(): array
    {
        return SettingValue::networks('internal_allowed_networks', self::DEFAULT_ALLOWED);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Trusted proxies are set, so ip() returns the real sender address and
        // not the one of the bridge.
        if (! IpUtils::checkIp((string) $request->ip(), self::allowedNetworks())) {
            abort(404);
        }

        return $next($request);
    }
}
