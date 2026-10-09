# Changelog

## Unreleased

### Added

- Event slots in closure notes: an opted-in note (`online buchbar`) that states a time
  ("MÄRCHENABEND 17 UHR") makes that time bookable inside its stored window, replacing or
  extending the day's opening hours depending on whether the window overlaps them. First
  change that creates bookable time instead of removing it. Never throws on the booking
  page: unplaceable or overlapping times are dropped. `ClosureNotes::eventPlan()` exposes
  the interpretation. Known limit: tables occupied by the note itself still report the slot
  as fully booked.
- First public version, extracted from one restaurant's live installation and generalised
  into a standalone package: large-party rules, internal phone-intake pages and JSON API,
  closure notes and blocked days, table allocation, daily sheet, console commands, and a
  settings form for everything that used to be hard-coded.
- English and German language files.
- Large-party detection through the `GuestCountResolver` contract; the Orange theme's
  resolver is bound automatically.
- `cutoff_minutes_before_closing` now works: online, time slots less than N minutes before the
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

- Blocked times are no longer offered on the public booking form at all: the online time
  list leaves out slots closed by the cut-off (`cutoff_minutes_before_closing`), by the online
  guest cap, inside a closure note's time window, and the whole day for an all-day note. The
  guest sees exactly what can be booked. This does **not** depend on TastyIgniter's "automatic
  table assignment" setting (the extension's advice is to keep that off). Phone intake keeps
  every slot. Table availability is not trimmed. The guest cap uses the party size the booking
  form currently has (1 when unknown).
- The online cut-off is now in minutes: `cutoff_hours_before_closing` is renamed to
  `cutoff_minutes_before_closing` (0 to 1440; larger values count as unset). The old key is
  deliberately **not** read and nothing is converted - a stored `1` would silently turn one hour
  into one minute. An installation that set the old value must set the new one. Meant for a
  kitchen that closes before the venue does.
- Blocked days are stored as `{"grund": ..., "online": ..., "hinweis": ...}` per date. Existing plain-string
  entries are still read and keep blocking online booking; nothing needs migrating.
- The unused global setting `allow_online_on_blocked_default` is removed (it never had an
  effect; the choice is now made per day).

- `internal_allowed_networks` now defaults to loopback only (`127.0.0.1`, `::1`) and
  `trusted_proxies` to empty. The extracted installation trusted all private ranges; any
  host on a shared private network could reach the unauthenticated intake pages and spoof
  `X-Forwarded-For`. Private ranges and proxies must now be configured explicitly.

### Fixed

- Blocked time slots were never disabled on the public booking form. TastyIgniter's
  `BookingManager` returns blocked slots as `Y-m-d H:i:s`, the Orange theme looks them up as
  `Y-m-d H:i`, so nothing ever matched. As a result the online cut-off, the online guest cap,
  a closure note's blocked time window and an all-day note (the way a day is closed) had **no
  effect on the public form**; they only took effect from this fix. `isTimeslotsFullyBookedOn()`
  now returns every blocked slot in both notations. Check your closure notes before updating:
  days that were closed on paper but bookable online become closed online.
- A submitted online reservation whose date and time fall in a blocked slot is now refused
  with a clear message, instead of relying on the disabled button alone. Uses the same
  method as the form, so the two cannot disagree. Enforced regardless of the automatic table assignment setting. If the check cannot be made (no location,
  unexpected value, exception) the booking goes through and a warning is logged.
- Allow-list entries with mask `/0` (`0.0.0.0/0`, `::/0`) are rejected; they matched every
  address and would have opened the intake pages to the internet.
- `EnterReservation::changeField()` (console command `reservation:enter`, "change a field")
  never worked: it compared the answer against the choice *labels*, while Symfony's
  `choice()` returns the *key*. Moving the labels into language files forced the comparison
  onto the keys, which is a genuine bug fix and not only a translation.

### Removed

- The planned `internal_route_prefix` setting was dropped before release; the `/intern`
  prefix is fixed.
