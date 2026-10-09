---
name: project-conventions
description: Domain and i18n conventions of the reservationcontrol extension (locale handling, GÄSTEHINWEIS keyword, en/de parity, extension-vs-theme boundary). Load when editing src/, resources/lang or closure-note logic.
user-invocable: false
---

- Closure notes (`src/ClosureNotes.php`): `GÄSTEHINWEIS: <text>` is guest text that is
  advertised and never bookable. Rules read the note's comment *without* that text, so
  phrases like "online buchbar" inside it must not open a window.
- Locale: API routes and console runs carry no `igniter` middleware, so `src/RequestLocale.php`
  sets the default language. Any new entry point (route group, command, job) that renders
  translated text or mail needs the same. Never overwrite an already-set locale; never throw.
- Language files: `resources/lang/en/default.php` and `de/default.php` keep identical keys.
  Guest-facing copy is German-first; write both when adding a key.
- Theme code stays optional: depend on `Contracts/GuestCountResolver`, never on the Orange
  theme directly (`src/Theme/NullGuestCount.php` is the fallback).
- Event times are single times, not windows (see CHANGELOG). Check
  `docs/superpowers/specs/2026-10-09-event-slots-design.md` before changing slot logic.
- Tests are Pest on MariaDB; new tests go in `tests/*Test.php` and inherit `TestCase` from `tests/Pest.php`.
