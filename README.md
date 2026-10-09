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

## Closure note keywords

A closure note is a reservation with more guests than the house seats; its comment text is
read for a few German keywords (this parser is German-only on purpose):

- `max 60 PAX`, optionally per time (`11 Uhr max 60 PAX, 13 Uhr max 80 PAX`): guest cap.
- `ganztägig` (also `ganzer Tag`, `keine online`, `online nicht mehr buchbar`): the note
  closes the whole day for online booking.
- `online buchbar`: the note's time window stays open for online booking, e.g.
  `Märchenabend, online buchbar`. Any negation in front of it (`nicht online buchbar`) or any
  all-day wording in the same note keeps the window closed.

### Guest notice on special days

A guest who books an online-open special day sees a notice above the booking form. Two
sources, both optional:

- A blocked day with *Guests can book online* ticked: the text field next to it on
  `/intern/sperren` (`hinweis`, up to 300 characters; optional string `hinweis` on
  `POST /api/intern/sperrtage`). Stored as
  `{"2026-12-31": {"grund": "...", "online": true, "hinweis": "..."}}`.
- A closure note: `online buchbar` followed by a colon and the text, up to the end of the
  line, e.g. `Märchenabend, max 60 PAX, online buchbar: Märchenabend mit Menü ab 18 Uhr`. No
  colon or nothing after it means no text; the window still opens. A clause on the next line
  is not part of the text.

A blocked day's `hinweis` wins over notes; without one, the day's opted-in notes speak. A day
that is still blocked never shows a notice. The text is HTML-escaped. The notice is added by
a Livewire render listener to any component that has public `date` and `guest` properties
(the Orange booking form does); any error is logged and the form renders without it.

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
| Online booking | cut-off hours before closing², apply guest limit online³ |

¹ Stored but without effect yet.

² Time slots *less than* N hours before closing are closed online; a slot exactly N hours
before closing stays bookable. 0 turns it off. Not applied to large parties or phone intake.

³ The cap from a closure note (`max N PAX`); a booking that exactly reaches the cap still fits.
Not applied to large parties or phone intake.

Blocked days can be left open for online booking per day (checkbox in the block form, optional
`online` flag on the API).

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
