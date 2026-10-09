# Changelog

## Unreleased

### Added

- First public version, extracted from one restaurant's live installation and generalised
  into a standalone package: large-party rules, internal phone-intake pages and JSON API,
  closure notes and blocked days, table allocation, daily sheet, console commands, and a
  settings form for everything that used to be hard-coded.
- English and German language files.
- Large-party detection through the `GuestCountResolver` contract; the Orange theme's
  resolver is bound automatically.
- `cutoff_hours_before_closing` now works: online, time slots less than N hours before the
  day's closing time count as fully booked. Not applied to large parties or to phone
  intake. 0 (default) means no cut-off.
- `apply_max_guests_online` now works: the guest cap of a closure note (`max N PAX`, also
  per time) is applied to online booking. Phone intake keeps it as a hint. Off by default.
- Blocked days can be left open for online booking per day (checkbox under
  `/intern/sperren`, optional boolean `online` on `POST /api/intern/sperrtage`; both default
  to off). Such days still show as special days on the internal pages and the printout.

- A closure note containing `online buchbar` keeps its time window open for online booking
  (it still appears on the daily sheet and in phone intake, and its guest cap still applies).
  "nicht online buchbar", "online nicht mehr buchbar", "keine online" and "ganztägig" always
  keep it closed.

- Guest notice on online-open special days, shown above the booking form: the new `hinweis`
  per blocked day (text field under `/intern/sperren`, optional string `hinweis` on
  `POST /api/intern/sperrtage`), or the text after `online buchbar:` in a closure note (to the
  end of the line). Escaped, never shown on a day that is still blocked, and failures are
  logged without affecting the form.

### Changed

- Blocked days are stored as `{"grund": ..., "online": ..., "hinweis": ...}` per date. Existing plain-string
  entries are still read and keep blocking online booking; nothing needs migrating.
- The unused global setting `allow_online_on_blocked_default` is removed (it never had an
  effect; the choice is now made per day).

- `internal_allowed_networks` now defaults to loopback only (`127.0.0.1`, `::1`) and
  `trusted_proxies` to empty. The extracted installation trusted all private ranges; any
  host on a shared private network could reach the unauthenticated intake pages and spoof
  `X-Forwarded-For`. Private ranges and proxies must now be configured explicitly.

### Fixed

- Allow-list entries with mask `/0` (`0.0.0.0/0`, `::/0`) are rejected; they matched every
  address and would have opened the intake pages to the internet.
- `EnterReservation::changeField()` (console command `reservation:enter`, "change a field")
  never worked: it compared the answer against the choice *labels*, while Symfony's
  `choice()` returns the *key*. Moving the labels into language files forced the comparison
  onto the keys, which is a genuine bug fix and not only a translation.

### Removed

- The planned `internal_route_prefix` setting was dropped before release; the `/intern`
  prefix is fixed.
