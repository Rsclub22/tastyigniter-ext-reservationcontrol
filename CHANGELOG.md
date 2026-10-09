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

### Changed

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
