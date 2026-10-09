# Reservation Control for TastyIgniter

An extension for [TastyIgniter](https://tastyigniter.com) 4 that adds, for restaurants that
take many reservations by phone:

- **Large-party rules** – from a configurable party size on, the usual opening hours and the
  table check no longer apply; a separate time window is offered instead.
- **Internal phone-intake pages** (`/intern`) and a **JSON API** (`/api/intern/...`, through
  TastyIgniter's API extension) for entering reservations and reading the day, month and open
  requests; plus a printable daily sheet.
- **Closure notes and blocked days** that take effect in the public form, phone intake and the
  occupancy display alike.
- **Table assignment** that respects rooms, a turnover buffer and table combinations.
- **Console commands** `reservation:enter` (dialog entry) and `reservation:import`.
- Optional hardening of the public booking form's telephone field (Orange theme only).

> Status: the extension works, but its documentation is still in progress. This README is
> the only user-facing documentation for now; the settings form carries help texts in
> English and German.

## Read this before installing

**It takes over booking and table assignment.** The extension replaces TastyIgniter's
`BookingManager` with its own and assigns tables itself. TastyIgniter's own
**"assign tables automatically"** setting must therefore be **off**, or the two will fight
over the tables.

**`/intern` has no login.** Phone intake is deliberately outside `/admin` and unauthenticated.
The *only* protection is an IP allow-list (setting *Networks for the internal pages*).
Anyone who passes it can create reservations and see guest names and phone numbers.

- The default is loopback only (`127.0.0.1`, `::1`). Add your private range (for example
  `192.168.0.0/16`) deliberately. `0.0.0.0/0` and `::/0` are refused.
- Behind a reverse proxy, the application sees the proxy's address unless you list the proxy
  under *Trusted proxies* (default: none). Never trust a range that untrusted hosts can reach:
  they could spoof `X-Forwarded-For` and walk through the allow-list. Also keep the intake
  off the public address at the proxy.

**Large-party detection needs the Orange theme or your own resolver.** The extension learns
the guest count from the Orange theme's booking component. With any other theme, bind your own
implementation of `Wagnersnetz\ReservationControl\Contracts\GuestCountResolver` in the
container; without one, no booking is treated as a large party. The telephone-field options
likewise only work with the Orange theme.

**Settings are global,** not per location. One threshold, one split time, one list of
networks for the whole installation.

**The German JSON keys are deliberate.** The API answers with keys such as `datum`,
`gaeste`, `belegung` or `vermerke`, and its paths are German (`/api/intern/tagesblatt`,
`/api/intern/sperrtage`). A counter
application reads them; they are a compatibility contract and will not be translated.

## Requirements

PHP 8.3, TastyIgniter 4 with the `reservation`, `local` and `api` extensions, `ext-intl`.

## Installation

```bash
composer require wagnersnetz/ti-ext-reservationcontrol
php artisan igniter:up
```

Then open *Manage → Settings → Reservation Control*, set the networks and proxies for your
setup, and turn TastyIgniter's automatic table assignment off.

## Settings overview

| Group | Settings |
| --- | --- |
| Large parties | threshold, first and last time, every weekday, skip table check |
| Phone intake | booking horizon (days), networks allowed to open the pages |
| Daily sheet | second sheet from (split time), most days per batch print |
| Tables | turnover buffer, dining area for rooms, most tables per reservation¹ |
| Field limits | longest name / e-mail / telephone number |
| Public form (Orange) | telephone required, telephone pattern, recognising fields |
| Mail | Reply-To address and name |
| Security | admin login limit, trusted proxies, internal networks |
| Reserved¹ | online cut-off hours, apply guest limit online, online booking on blocked days |

¹ Stored but without effect yet.

## Development

See [docs/development.md](docs/development.md).

## License

Copyright (C) 2026 Philipp Wagner

This program is free software: you can redistribute it and/or modify it under the terms of
the GNU General Public License as published by the Free Software Foundation, either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See
the GNU General Public License for more details. You should have received a copy of the GNU
General Public License along with this program (see `LICENSE`). If not, see
<https://www.gnu.org/licenses/>.
