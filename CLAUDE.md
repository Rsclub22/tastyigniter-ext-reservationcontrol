# ti-ext-reservationcontrol

TastyIgniter 4 extension (PHP ^8.3, Laravel-based): large-party rules, closure notes, event
slots, internal booking pages and a JSON API on top of `ti-ext-reservation`. It is a
**library**: no `composer.lock` is committed, on purpose (CI resolves fresh to catch upstream
breaks early).

## Commands

No local PHP. Everything runs in Docker (details: @docs/development.md):

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)
D="docker compose -f docker-compose.dev.yml run --rm php"
$D vendor/bin/pest                 # tests (need MariaDB, not SQLite)
$D vendor/bin/pint --test          # style check (drop --test to fix)
$D vendor/bin/phpstan analyse      # static analysis, level 5
```

CI runs exactly these three (`.github/workflows/ci.yml`). PHP 8.4 is reported but
non-blocking; pint and phpstan run on 8.3 only. Use the `check` skill to run all three.

## Rules that the code does not enforce

- **phpstan-baseline.neon** is frozen. Never regenerate it to silence a new error; fix the
  error. Regenerate only when the cause is understood.
- **Tests / DB**: `DB_*` come from the environment (docker-compose, CI). `phpunit.xml.dist`
  must not override them. `tests/Pest.php` resets Flame's internal `Model::$eventsBooted`
  per test; if every test fails in `beforeEach` after a core upgrade, look there first.
- **CI images**: use the ECR mirror for MariaDB, never Docker Hub (rate limits).
- **Locale**: every entry point must have the installation's default language. API routes
  get it via a `RouteMatched` listener (`src/RequestLocale.php`), console runs after boot.
  Never overwrite a locale that is already set.
- **Guest text keyword** is `GÄSTEHINWEIS:` in closure notes (advertised, never bookable).
- **i18n**: `resources/lang/en/default.php` and `de/default.php` must keep identical keys.
- **Reservation link** belongs in the extension, not the theme. The Orange theme support is
  optional (`src/Theme/`, behind `Contracts/GuestCountResolver`).
- Update `CHANGELOG.md` (`## Unreleased`) for user-visible changes.

## Where decisions live

`docs/superpowers/specs/` and `docs/superpowers/*.md` hold the design specs and decisions.
Read the relevant spec before changing behavior covered there.
