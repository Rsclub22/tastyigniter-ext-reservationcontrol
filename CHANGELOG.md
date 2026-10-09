# Changelog

## Unreleased

### Fixed

- Mails (and any translated text) triggered from the API rendered in English on a German
  installation: API routes carry no `igniter` middleware group, so nothing set the locale and
  the request kept `app.locale`. A `RouteMatched` listener now gives such routes the
  installation's default language (through the platform's own localization setter); routes
  that carry the group are untouched. Never throws; failures are logged and leave the locale
  alone.

### Changed

- Event times are no longer windows: the first version offered everything from the stated
  time to the end of the note's window (17:00, 17:15 ... 19:45 for "17 UHR"), let the table
  check call every one of them taken on the internal page, and applied the guest cap per
  quarter hour. An opted-in note that names a time now closes the rest of its window.
  A time written inside the `online buchbar:` guest text no longer counts. Times that a note
  names outside its own window are no longer offered by phone either.

### Added

- Telephone invitation on the booking page: when the online cut-off, the guest cap, a closure
  note's window or an all-day note removed a time on the selected date, "Keine passende Zeit
  dabei? Rufen Sie uns an" with the location's telephone number. Not on a closed day, outside
  the booking horizon, when nothing was removed, or without a number. The manager records what
  it removed (`OnlineBlock`); nothing is re-derived.
- List of coming special evenings on the booking page (`SpecialEvenings`): blocked days'
  `hinweis`, closure notes' `online buchbar:` text and the new `HINWEIS:` text, up to the
  public booking horizon, five at most, each with a link to its date or "Reservierung
  telefonisch". Never the raw note or reason.
- Closure note keyword `HINWEIS:`: guest text that is advertised, never bookable and never
  opens anything; it is cut out before any other keyword, time or cap is read. The guest text
  after `online buchbar:` now stops in front of a `HINWEIS:` on the same line.

- Event times in closure notes: a time written in a note ("MÄRCHENABEND 17 UHR") is exactly
  one bookable time inside the note's stored window - `17 UHR` is 17:00, `11 Uhr und 13 Uhr` is
  those two, the rest of the window stays blocked. Online they open only with `online
  buchbar`, replacing or extending the day's opening hours depending on whether the window
  overlaps them; phone intake takes them always. First change that creates bookable time
  instead of removing it. Never throws on the booking page: unplaceable or overlapping times
  are dropped. `ClosureNotes::eventPlan()` and `ClosureNotes::eventTimes()` expose the
  interpretation.
- At an event time people are counted, not tables: no table check, no table assigned (what
  TastyIgniter's automatic assignment adds on its own is taken back), the note's guest cap
  governs and counts what is already booked at that time. The internal page offers such
  times without a table instead of reading "belegt".
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
