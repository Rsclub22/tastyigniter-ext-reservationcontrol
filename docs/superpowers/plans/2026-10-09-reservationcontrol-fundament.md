# reservationcontrol — Plan 1: Fundament

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aus dem hausinternen `wagnersnetz/ti-ext-reservetweaks` wird ein eigenständiges, englischsprachiges, vollständig konfigurierbares und themeunabhängiges Paket `rsclub22/ti-ext-reservationcontrol` — **ohne dass sich das Verhalten der laufenden Installation ändert**.

**Architecture:** Das vorhandene Paket wird unverändert portiert und dann in vier beweisbar verhaltensneutralen Schritten umgebaut: Namensraum, englische Klassennamen, Sprachdateien, Einstellungen statt Konstanten, Theme-Entkopplung über eine Schnittstelle. Jeder Schritt endet mit grünen Tests, die das *alte* Verhalten festhalten.

**Tech Stack:** PHP 8.3, TastyIgniter 4, Laravel, Pest 3 + `sampoyigi/testbench`, Pint, Larastan, Rector.

**Spec:** `2026-10-09-reservationcontrol-design.md` (gleiches Verzeichnis)

## Global Constraints

- PHP-Untergrenze `^8.3` — der Code nutzt typisierte Konstanten (`private const array SPALTEN`).
- Lizenz `GPL-3.0-or-later` in `composer.json` **und** als `LICENSE`-Datei.
- Erweiterungscode `rsclub22.reservationcontrol`, Namensraum `Rsclub22\ReservationControl\`, Paket `rsclub22/ti-ext-reservationcontrol`.
- `"type": "tastyigniter-package"` mit `extra."tastyigniter-extension"` — kanonische Form laut Generator-Stub.
- Code vollständig englisch, **einschließlich Kommentare**. Sprachdateien `en` und `de`, beide vollständig.
- Jede neue Einstellung hat als Vorgabe exakt den heute fest verdrahteten Wert.
- URL-Pfade und Routennamen-Suffixe bleiben erhalten; nur das Präfix wird einstellbar.
- Keine `*.bak-*`-Dateien im Repo.

## Review Focus

1. **Frische Installation ohne gespeicherte Einstellungen** — `Settings::get()` liefert `null`. Ein `null`-Schwellwert darf nicht dazu führen, dass jede Buchung als Gesellschaft gilt (oder keine). Test in Task 6.
2. **Unsinnige Werte aus dem Einstellungsformular** — `large_party_threshold = -5`, `large_party_open = '25:00'`, `cutoff_hours_before_closing = 999`. Muss abgefangen werden, nicht in einen Ausnahmefehler laufen. Test in Task 6.
3. **Andere Locale als `en`/`de`** — eine Installation auf Französisch muss auf Englisch zurückfallen, nicht leere Beschriftungen zeigen. Test in Task 4.
4. **Alte und neue Erweiterung gleichzeitig installiert** — gleiche URL-Pfade (`/intern`) führen zu kollidierenden Routennamen. Muss erkennbar scheitern statt still eine Route zu überschreiben. Test in Task 2.
5. **Mehrere Standorte** — Einstellungen sind global, Standorte nicht. Muss dokumentiert und im Verhalten eindeutig sein (globale Einstellung gilt für alle Standorte). Test in Task 7.

---

### Task 0: Reproduzierbare Entwicklungsumgebung

Auf dem Entwicklungsrechner gibt es weder PHP noch Composer noch eine Datenbank; `php:8.3-cli` bringt nur `pdo_sqlite` mit und keine der von TastyIgniter verlangten Erweiterungen. Ohne diese Task kann **keine** andere Task ihre Tests ausführen.

**Files:**
- Create: `Dockerfile.dev`, `docker-compose.dev.yml`, `docs/development.md`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: nichts
- Produces: ein Befehl, mit dem jede spätere Task ihre Tests fährt. Er wird in `docs/development.md` dokumentiert und lautet (oder ist gleichwertig):
  `docker compose -f docker-compose.dev.yml run --rm php vendor/bin/pest`

- [ ] **Step 1: Anforderungen feststellen**

Nachsehen, was `tastyigniter/core` an PHP-Erweiterungen verlangt und was `sampoyigi/testbench` als Datenbank erwartet:

```bash
docker run --rm php:8.3-cli sh -c '
  php -r "echo file_get_contents(\"https://repo.packagist.org/p2/tastyigniter/core.json\");"' \
  | head -c 3000
```

Alternativ `composer show tastyigniter/core` nach dem ersten erfolgreichen `composer install`. Festhalten, ob die Tests mit SQLite laufen oder MariaDB brauchen — davon hängt ab, ob `docker-compose.dev.yml` einen zweiten Dienst bekommt.

- [ ] **Step 2: `Dockerfile.dev` schreiben**

Basis `php:8.3-cli`. Erforderlich sind mindestens die Erweiterungen, die Step 1 ermittelt hat; erfahrungsgemäß `intl`, `zip`, `gd`, `bcmath`, `pdo_mysql` (nur falls MariaDB nötig). Composer kommt aus dem offiziellen Image:

```dockerfile
FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd bcmath pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
```

- [ ] **Step 3: `docker-compose.dev.yml` schreiben**

Dienst `php` mit Build aus `Dockerfile.dev`, Bind-Mount des Repos nach `/app`, und — nur falls Step 1 es verlangt — ein Dienst `db` mit `mariadb:10.11`, Datenbank `testbench`, Benutzer `forge`. Die Umgebungsvariablen aus `phpunit.xml.dist` müssen dazu passen.

- [ ] **Step 4: `.gitignore` ergänzen**

`vendor/`, `.phpunit.cache/`, `composer.lock` stehen schon drin. Ergänzen: nichts Weiteres nötig — die Compose-Dateien gehören ins Repo, sie sind Teil der Entwicklungsanleitung.

- [ ] **Step 5: Beweisen, dass es läuft**

```bash
docker compose -f docker-compose.dev.yml build php
docker compose -f docker-compose.dev.yml run --rm php php --version
docker compose -f docker-compose.dev.yml run --rm php composer --version
docker compose -f docker-compose.dev.yml run --rm php php -r 'print_r(PDO::getAvailableDrivers());'
```

Expected: PHP 8.3.x, Composer 2.x, und die in Step 1 festgestellten Treiber.

- [ ] **Step 6: `docs/development.md` schreiben**

Enthält: Voraussetzung (Docker), der Bauschritt, der Testbefehl, der Pint-Befehl, der PHPStan-Befehl. Ausdrücklich vermerken, dass diese Dateien **nur der Entwicklung dienen** und für den Betrieb der Erweiterung nicht gebraucht werden.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add a reproducible Docker development toolchain"
```

**Hinweis für alle folgenden Tasks:** Jeder Aufruf von `composer`, `vendor/bin/pest`, `vendor/bin/pint` und `vendor/bin/phpstan` im Plan läuft ab jetzt durch diesen Container, also als `docker compose -f docker-compose.dev.yml run --rm php <befehl>`.

---

### Task 1: Paketgerüst, das bootet und sich testet

**Files:**
- Create: `composer.json`, `LICENSE`, `.gitignore`, `README.md`, `phpunit.xml.dist`, `pint.json`, `tests/Pest.php`, `tests/ExtensionTest.php`, `src/Extension.php`

**Interfaces:**
- Consumes: nichts
- Produces: Klasse `Rsclub22\ReservationControl\Extension extends BaseExtension`

- [ ] **Step 1: Verzeichnis und Repo anlegen**

```bash
mkdir -p /home/philipp/Projects/micha/ti-ext-reservationcontrol
cd /home/philipp/Projects/micha/ti-ext-reservationcontrol
git init
mkdir -p src tests resources/lang/en resources/lang/de resources/models docs database
```

- [ ] **Step 2: `composer.json` schreiben**

```json
{
    "name": "rsclub22/ti-ext-reservationcontrol",
    "type": "tastyigniter-package",
    "description": "Large-party rules, closure notes, internal booking pages and a JSON API for TastyIgniter reservations.",
    "license": "GPL-3.0-or-later",
    "authors": [{ "name": "Philipp Wagner" }],
    "require": {
        "php": "^8.3",
        "tastyigniter/core": "^v4.0",
        "tastyigniter/ti-ext-reservation": "^v4.0",
        "tastyigniter/ti-ext-local": "^v4.0",
        "tastyigniter/ti-ext-api": "^v4.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.2",
        "pestphp/pest-plugin-laravel": "^3.0",
        "rector/rector": "^2.0",
        "sampoyigi/testbench": "^1.0"
    },
    "suggest": {
        "tastyigniter/ti-theme-orange": "Detects the guest count a visitor typed into the public booking form."
    },
    "autoload": { "psr-4": { "Rsclub22\\ReservationControl\\": "src/" } },
    "autoload-dev": { "psr-4": { "Rsclub22\\ReservationControl\\Tests\\": "tests/" } },
    "extra": {
        "tastyigniter-extension": {
            "code": "rsclub22.reservationcontrol",
            "name": "Reservation Control",
            "icon": { "class": "fa fa-calendar-check", "backgroundColor": "#8C2B2B", "color": "#FFFFFF" }
        }
    }
}
```

- [ ] **Step 3: `LICENSE` anlegen**

Den vollständigen GPLv3-Text von <https://www.gnu.org/licenses/gpl-3.0.txt> speichern. Kein gekürzter Text — eine unvollständige Lizenzdatei ist rechtlich wertlos.

- [ ] **Step 4: Testgerüst anlegen**

`tests/Pest.php`:

```php
<?php

declare(strict_types=1);

use SamPoyigi\Testbench\TestCase;

uses(TestCase::class)->in(__DIR__);
```

`phpunit.xml.dist` — wie `ti-ext-reservation`, Testsuite-Name `Reservation Control Test Suite`, `<directory suffix=".php">./src</directory>` als Quelle.

- [ ] **Step 5: Den fehlschlagenden Test schreiben**

`tests/ExtensionTest.php`:

```php
<?php

declare(strict_types=1);

use Igniter\System\Classes\BaseExtension;
use Rsclub22\ReservationControl\Extension;

it('registers as a TastyIgniter extension', function(): void {
    expect(new Extension(app()))->toBeInstanceOf(BaseExtension::class);
});
```

- [ ] **Step 6: Test laufen lassen, Fehlschlag bestätigen**

Run: `composer install && vendor/bin/pest --filter=ExtensionTest`
Expected: FAIL — `Class "Rsclub22\ReservationControl\Extension" not found`

- [ ] **Step 7: Minimale `src/Extension.php`**

```php
<?php

declare(strict_types=1);

namespace Rsclub22\ReservationControl;

use Igniter\System\Classes\BaseExtension;

class Extension extends BaseExtension
{
    public function boot(): void {}
}
```

- [ ] **Step 8: Test laufen lassen, Erfolg bestätigen**

Run: `vendor/bin/pest --filter=ExtensionTest`
Expected: PASS

- [ ] **Step 9: `composer validate`**

Run: `composer validate --strict`
Expected: `./composer.json is valid`

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "Package skeleton that boots and tests itself"
```

---

### Task 2: Quellcode unverändert portieren

Der gesamte vorhandene Code wandert herüber, **nur** mit getauschtem Namensraum. Keine Umbenennung von Klassen, keine inhaltliche Änderung. Das trennt den mechanischen Teil vom inhaltlichen und macht den nächsten Schritt überprüfbar.

**Files:**
- Create: `src/**` (aus dem Bestand), `resources/views/intern.blade.php`, `resources/views/intern-druck.blade.php`
- Create: `tests/SmokeTest.php`
- Modify: `src/Extension.php` (vollständige Fassung aus dem Bestand)

**Interfaces:**
- Consumes: `Rsclub22\ReservationControl\Extension` aus Task 1
- Produces: alle Bestandsklassen unter `Rsclub22\ReservationControl\*` — `Sperrvermerke`, `BlockedDates`, `Tagesblatt`, `Tagesdaten`, `Annahme`, `Rooms`, `TableAllocator`, `BookingContext`, `LargePartyBookingManager`, `Api\StandardIncludes`, `Erfassung\{Anlegen,Eingabe,Tischwahl}`, `Http\Controllers\{InternApi,InternalBooking}`, `Http\Middleware\InternalNetworkOnly`, `Console\{ReservierungErfassen,ReservierungImport}`

- [ ] **Step 1: Quelle kopieren, Sicherungsdateien auslassen**

```bash
SRC=/tmp/claude-1000/-home-philipp-Projects-micha-tastyigniter-reservations-app/7569ef7b-a2a1-407f-a97b-dac8a1c9bce6/scratchpad/tweaks
cd /home/philipp/Projects/micha/ti-ext-reservationcontrol
rsync -a --exclude '*.bak-*' "$SRC/src/" src/
rsync -a "$SRC/resources/" resources/
test -z "$(find src -name '*.bak-*')" && echo "keine Sicherungsdateien"
```

- [ ] **Step 2: Namensraum mechanisch tauschen**

```bash
grep -rl 'Wagnersnetz\\ReserveTweaks' src/ resources/ \
  | xargs sed -i 's/Wagnersnetz\\\\ReserveTweaks/Rsclub22\\\\ReservationControl/g; s/Wagnersnetz\\ReserveTweaks/Rsclub22\\ReservationControl/g'
grep -rn 'Wagnersnetz' src/ resources/ || echo "kein Rest"
```

- [ ] **Step 3: View- und Routennamensraum tauschen**

Ansichten heißen künftig `reservationcontrol::intern` statt `reservetweaks::intern`, Routennamen `reservationcontrol.intern` statt `reservetweaks.intern`. Die **URL-Pfade bleiben** (`/intern`, `/intern/druck`).

```bash
grep -rl 'reservetweaks' src/ resources/ | xargs sed -i 's/reservetweaks::/reservationcontrol::/g; s/reservetweaks\./reservationcontrol./g'
```

Danach von Hand prüfen: `src/Erfassung/Anlegen.php` (`MARKER`) und `src/BlockedDates.php` (`SETTING`) dürfen **noch nicht** umbenannt sein — die tragen Datenbankinhalte und werden erst in Plan 2 migriert. Der Wert bleibt vorerst wörtlich `reservetweaks-cli` bzw. `reservetweaks_blocked_dates`.

- [ ] **Step 4: Den fehlschlagenden Test schreiben**

`tests/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

it('loads every ported class', function(string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    \Rsclub22\ReservationControl\Sperrvermerke::class,
    \Rsclub22\ReservationControl\BlockedDates::class,
    \Rsclub22\ReservationControl\Tagesblatt::class,
    \Rsclub22\ReservationControl\Tagesdaten::class,
    \Rsclub22\ReservationControl\Rooms::class,
    \Rsclub22\ReservationControl\TableAllocator::class,
    \Rsclub22\ReservationControl\LargePartyBookingManager::class,
    \Rsclub22\ReservationControl\Http\Controllers\InternApi::class,
    \Rsclub22\ReservationControl\Http\Controllers\InternalBooking::class,
]);

it('keeps the data-bearing markers untouched for now', function(): void {
    expect(\Rsclub22\ReservationControl\Erfassung\Anlegen::MARKER)->toBe('reservetweaks-cli')
        ->and(\Rsclub22\ReservationControl\BlockedDates::SETTING)->toBe('reservetweaks_blocked_dates');
});
```

- [ ] **Step 5: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=SmokeTest`
Expected: FAIL vor dem Kopieren; nach Step 1–3 PASS.

- [ ] **Step 6: Review-Focus-Test — Routenkollision**

```php
it('fails loudly when the legacy extension still owns the routes', function(): void {
    app('router')->get('intern', fn() => null)->name('reservetweaks.intern');

    $doppelt = collect(app('router')->getRoutes())
        ->filter(fn($r): bool => $r->uri() === 'intern')
        ->count();

    expect($doppelt)->toBeGreaterThan(1);
})->note('Beide Erweiterungen gleichzeitig installiert: gleiche URL, zwei Routen. '
    .'Die Installationsanleitung muss das Abschalten der alten Fassung verlangen.');
```

- [ ] **Step 7: Alle Tests laufen lassen**

Run: `vendor/bin/pest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Port the extension verbatim under the new namespace"
```

---

### Task 3: Klassennamen auf Englisch

Rein mechanisch, Verhalten unverändert. Umbenennungstabelle aus der Spec.

**Files:**
- Modify: alle unter `src/`
- Modify: `tests/SmokeTest.php`

**Interfaces:**
- Consumes: alle Klassen aus Task 2
- Produces: `ClosureNotes`, `DailySheet`, `DayData`, `Intake`, `Entry\CreateReservation`, `Entry\Prompt`, `Entry\TableChoice`, `Http\Controllers\InternalApiController`, `Http\Controllers\InternalBookingController`, `Console\EnterReservation`, `Console\ImportReservations`. Unverändert bleiben: `BlockedDates`, `Rooms`, `TableAllocator`, `BookingContext`, `LargePartyBookingManager`, `Api\StandardIncludes`, `Http\Middleware\InternalNetworkOnly`, `Extension`.

- [ ] **Step 1: Test auf die neuen Namen umschreiben**

In `tests/SmokeTest.php` die Klassenliste auf die englischen Namen setzen (`ClosureNotes::class` statt `Sperrvermerke::class` usw.).

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=SmokeTest`
Expected: FAIL — `Class "…\ClosureNotes" not found`

- [ ] **Step 3: Umbenennen**

Datei für Datei: Datei umbenennen, Klassennamen anpassen, alle Verwendungen nachziehen.

```bash
git mv src/Sperrvermerke.php src/ClosureNotes.php
git mv src/Tagesblatt.php src/DailySheet.php
git mv src/Tagesdaten.php src/DayData.php
git mv src/Annahme.php src/Intake.php
git mv src/Erfassung src/Entry
git mv src/Entry/Anlegen.php src/Entry/CreateReservation.php
git mv src/Entry/Eingabe.php src/Entry/Prompt.php
git mv src/Entry/Tischwahl.php src/Entry/TableChoice.php
git mv src/Http/Controllers/InternApi.php src/Http/Controllers/InternalApiController.php
git mv src/Http/Controllers/InternalBooking.php src/Http/Controllers/InternalBookingController.php
git mv src/Console/ReservierungErfassen.php src/Console/EnterReservation.php
git mv src/Console/ReservierungImport.php src/Console/ImportReservations.php
```

Danach die Bezeichner ersetzen:

```bash
grep -rl 'Sperrvermerke' src/ | xargs sed -i 's/\bSperrvermerke\b/ClosureNotes/g'
grep -rl 'Tagesblatt' src/ | xargs sed -i 's/\bTagesblatt\b/DailySheet/g'
grep -rl 'Tagesdaten' src/ | xargs sed -i 's/\bTagesdaten\b/DayData/g'
grep -rl '\bAnnahme\b' src/ | xargs sed -i 's/\bAnnahme\b/Intake/g'
grep -rl 'Erfassung' src/ | xargs sed -i 's/\bErfassung\b/Entry/g'
grep -rl '\bAnlegen\b' src/ | xargs sed -i 's/\bAnlegen\b/CreateReservation/g'
grep -rl '\bEingabe\b' src/ | xargs sed -i 's/\bEingabe\b/Prompt/g'
grep -rl 'Tischwahl' src/ | xargs sed -i 's/\bTischwahl\b/TableChoice/g'
grep -rl 'InternApi' src/ | xargs sed -i 's/\bInternApi\b/InternalApiController/g'
grep -rl '\bInternalBooking\b' src/ | xargs sed -i 's/\bInternalBooking\b/InternalBookingController/g'
grep -rl 'ReservierungErfassen' src/ | xargs sed -i 's/\bReservierungErfassen\b/EnterReservation/g'
grep -rl 'ReservierungImport' src/ | xargs sed -i 's/\bReservierungImport\b/ImportReservations/g'
```

**Vorsicht:** Die Ersetzungen treffen auch deutsche Methodennamen und Variablen (`$sperrvermerke`, `annehmen()`). Nach dem Lauf jede Datei durchgehen und Methoden- sowie Variablennamen von Hand auf Englisch bringen — `sperrvermerke()` → `closureNotes()`, `annehmen()` → `accept()`, `fuerTag()` → `forDay()`, `alsListe()` → `toList()`. Die Routennamen-Suffixe bleiben wie sie sind.

- [ ] **Step 4: Kommentare übersetzen**

Jede deutsche Kommentarzeile ins Englische. Die Kommentare erklären durchgehend das *Warum* — diese Begründungen müssen erhalten bleiben, nicht zu Einzeilern zusammenschrumpfen.

- [ ] **Step 5: Tests laufen lassen**

Run: `vendor/bin/pest`
Expected: PASS

- [ ] **Step 6: Statische Prüfung**

Run: `vendor/bin/pint && vendor/bin/phpstan analyse`
Expected: keine Fehler

- [ ] **Step 7: Kein deutscher Bezeichner mehr übrig**

```bash
grep -rniE '\b(sperrvermerk|tagesblatt|tagesdaten|annahme|erfassung|anlegen|eingabe|tischwahl|reservierung|gaeste|standort|datum|zeit|belegung|vermerk)\b' src/ \
  | grep -v 'resources/' || echo "keine deutschen Bezeichner mehr"
```

Treffer in Zeichenketten, die der Benutzer sieht, sind in Ordnung — die kommen in Task 4 in die Sprachdateien.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Rename classes, methods and comments to English"
```

---

### Task 4: Sprachdateien en und de

**Files:**
- Create: `resources/lang/en/default.php`, `resources/lang/de/default.php`
- Create: `tests/LanguageTest.php`
- Modify: `src/Extension.php` (`loadTranslationsFrom`), `resources/views/*.blade.php`, `src/Http/Controllers/InternalBookingController.php` (Attributnamen), `src/Console/*`, `src/DailySheet.php`, `src/DayData.php`

**Interfaces:**
- Consumes: Klassen aus Task 3
- Produces: Übersetzungsnamensraum `reservationcontrol::default.*`

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

`tests/LanguageTest.php`:

```php
<?php

declare(strict_types=1);

it('resolves a label in english', function(): void {
    app()->setLocale('en');
    expect(trans('reservationcontrol::default.label_full_day'))->toBe('All day');
});

it('resolves the same label in german', function(): void {
    app()->setLocale('de');
    expect(trans('reservationcontrol::default.label_full_day'))->toBe('Ganzer Tag');
});

it('falls back to english for an unsupported locale', function(): void {
    app()->setLocale('fr');
    expect(trans('reservationcontrol::default.label_full_day'))
        ->not->toBe('reservationcontrol::default.label_full_day')
        ->and(trans('reservationcontrol::default.label_full_day'))->toBe('All day');
});
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=LanguageTest`
Expected: FAIL — der Schlüssel kommt unübersetzt zurück

- [ ] **Step 3: Übersetzungen laden**

In `src/Extension.php::boot()`:

```php
$this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'reservationcontrol');
```

Sicherstellen, dass `config('app.fallback_locale')` auf `en` steht — darauf beruht der dritte Test.

- [ ] **Step 4: Sprachdateien füllen**

`resources/lang/en/default.php` und `de/default.php` mit demselben Schlüsselsatz. Mindestbestand aus dem Bestandscode:

| Schlüssel | en | de |
| --- | --- | --- |
| `label_full_day` | All day | Ganzer Tag |
| `label_until_time` | Until :time | Bis :time Uhr |
| `label_from_time` | From :time | Ab :time Uhr |
| `error_no_active_location` | No active location available. | Kein aktiver Standort vorhanden. |
| `attribute_date` | Date | Datum |
| `attribute_time` | Time | Uhrzeit |
| `attribute_guests` | Number of guests | Personenzahl |
| `attribute_last_name` | Last name | Nachname |
| `attribute_telephone` | Telephone | Telefon |
| `attribute_email` | E-mail | E-Mail |
| `attribute_note` | Note | Notiz |

- [ ] **Step 5: Fundstellen ersetzen**

`DailySheet.php:73,77,80`, `DayData.php:225`, die Validierungs-Attributnamen in `InternalBookingController` (heute deutsch), die Blade-Ansichten und die Konsolenausgaben auf `lang:`-Schlüssel bzw. `trans()` umstellen.

- [ ] **Step 6: Tests laufen lassen**

Run: `vendor/bin/pest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add English and German language resources"
```

---

### Task 5: Einstellungsmodell

**Files:**
- Create: `src/Models/Settings.php`, `resources/models/settings.php`, `tests/SettingsTest.php`
- Modify: `src/Extension.php` (`registerSettings`, `registerPermissions`)

**Interfaces:**
- Consumes: Übersetzungen aus Task 4
- Produces: `Rsclub22\ReservationControl\Models\Settings` mit `Settings::get(string $key, mixed $default = null)`

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

`tests/SettingsTest.php`:

```php
<?php

declare(strict_types=1);

use Rsclub22\ReservationControl\Models\Settings;

it('stores and reads a setting', function(): void {
    Settings::set('large_party_threshold', 25);
    expect(Settings::get('large_party_threshold'))->toBe(25);
});

it('returns the given default when nothing is stored', function(): void {
    expect(Settings::get('does_not_exist', 'fallback'))->toBe('fallback');
});
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=SettingsTest`
Expected: FAIL — Klasse nicht gefunden

- [ ] **Step 3: Modell anlegen**

```php
<?php

declare(strict_types=1);

namespace Rsclub22\ReservationControl\Models;

use Igniter\Flame\Database\Model;
use Igniter\System\Actions\SettingsModel;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string|array $key, mixed $value = null)
 * @mixin SettingsModel
 */
class Settings extends Model
{
    public array $implement = [SettingsModel::class];

    public string $settingsCode = 'rsclub22_reservationcontrol_settings';

    public string $settingsFieldsConfig = 'settings';
}
```

- [ ] **Step 4: Feldkonfiguration anlegen**

`resources/models/settings.php` nach dem Muster von `ti-ext-broadcast`: `form.toolbar.buttons.save` / `saveClose`, dann `form.fields` — in dieser Task nur das Grundgerüst mit einer Registerkarte je Gruppe, Felder kommen in Task 6 und 7.

- [ ] **Step 5: In der Erweiterung anmelden**

```php
public function registerSettings(): array
{
    return [
        'settings' => [
            'label' => 'lang:reservationcontrol::default.settings_label',
            'description' => 'lang:reservationcontrol::default.settings_description',
            'icon' => 'fa fa-calendar-check',
            'model' => \Rsclub22\ReservationControl\Models\Settings::class,
            'permissions' => ['Rsclub22.ReservationControl.ManageSettings'],
        ],
    ];
}

public function registerPermissions(): array
{
    return [
        'Rsclub22.ReservationControl.ManageSettings' => [
            'label' => 'lang:reservationcontrol::default.permission_manage_settings',
            'group' => 'module',
        ],
    ];
}
```

- [ ] **Step 6: Tests laufen lassen**

Run: `vendor/bin/pest --filter=SettingsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Add settings model and registration"
```

---

### Task 6: Gesellschaften aus den Einstellungen

Die erste Gruppe echter Einstellungen, und die mit dem größten Schadenspotenzial — hier hängen die beiden Review-Focus-Punkte 1 und 2.

**Files:**
- Modify: `src/LargePartyBookingManager.php:20,23,25,41`, `resources/models/settings.php`, `resources/lang/{en,de}/default.php`
- Create: `tests/LargePartyTest.php`

**Interfaces:**
- Consumes: `Settings::get()` aus Task 5
- Produces: `LargePartyBookingManager::threshold(): int`, `::windowOpen(): string`, `::windowClose(): string` — alle lesen aus den Einstellungen und fallen auf die bisherigen Werte zurück

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

`tests/LargePartyTest.php`:

```php
<?php

declare(strict_types=1);

use Rsclub22\ReservationControl\LargePartyBookingManager;
use Rsclub22\ReservationControl\Models\Settings;

it('defaults to the previous hardcoded threshold', function(): void {
    expect(LargePartyBookingManager::threshold())->toBe(20)
        ->and(LargePartyBookingManager::windowOpen())->toBe('10:00')
        ->and(LargePartyBookingManager::windowClose())->toBe('22:00');
});

it('honours a configured threshold', function(): void {
    Settings::set('large_party_threshold', 8);
    expect(LargePartyBookingManager::threshold())->toBe(8);
});

// Review Focus 1: frische Installation, nichts gespeichert
it('treats an unset threshold as the default, not as zero', function(): void {
    Settings::set('large_party_threshold', null);
    expect(LargePartyBookingManager::threshold())->toBe(20);
});

// Review Focus 2: Unsinn aus dem Formular
it('ignores a nonsensical threshold', function(): void {
    Settings::set('large_party_threshold', -5);
    expect(LargePartyBookingManager::threshold())->toBe(20);
});

it('ignores a nonsensical window and keeps the defaults', function(): void {
    Settings::set('large_party_open', '25:00');
    expect(LargePartyBookingManager::windowOpen())->toBe('10:00');
});
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=LargePartyTest`
Expected: FAIL — Methoden nicht vorhanden

- [ ] **Step 3: Umsetzen**

```php
public const int DEFAULT_THRESHOLD = 20;
public const string DEFAULT_OPEN = '10:00';
public const string DEFAULT_CLOSE = '22:00';

public static function threshold(): int
{
    $value = Settings::get('large_party_threshold');

    return is_numeric($value) && (int)$value > 0 ? (int)$value : self::DEFAULT_THRESHOLD;
}

public static function windowOpen(): string
{
    return self::validTime(Settings::get('large_party_open'), self::DEFAULT_OPEN);
}

public static function windowClose(): string
{
    return self::validTime(Settings::get('large_party_close'), self::DEFAULT_CLOSE);
}

private static function validTime(mixed $value, string $default): string
{
    return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1
        ? $value
        : $default;
}
```

Danach `isLargeParty()` und die Zeitplanerzeugung auf diese Methoden umstellen statt auf die alten Konstanten.

- [ ] **Step 4: Tests laufen lassen**

Run: `vendor/bin/pest --filter=LargePartyTest`
Expected: PASS

- [ ] **Step 5: Felder ins Einstellungsformular**

In `resources/models/settings.php` die vier Felder ergänzen (`large_party_threshold` als `number`, `large_party_open`/`large_party_close` als `text` mit Platzhalter `HH:MM`, `large_party_all_weekdays` und `large_party_skip_table_check` als `switch`), Beschriftungen als `lang:`-Schlüssel in beiden Sprachdateien.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Read large-party rules from settings, keeping today's values as defaults"
```

---

### Task 7: Die übrigen Einstellungen

**Files:**
- Modify: `src/TableAllocator.php:37,53,104`, `src/Rooms.php:23`, `src/DayData.php:26,204`, `src/DailySheet.php:26`, `src/Http/Middleware/InternalNetworkOnly.php:27`, `src/Extension.php:43,67,73,93,98,127-130,235`, `resources/models/settings.php`, beide Sprachdateien
- Create: `tests/SettingsCoverageTest.php`

**Interfaces:**
- Consumes: `Settings::get()`
- Produces: ersetzte Konstanten werden zu statischen Lesern nach dem Muster aus Task 6. Öffentlich neu: `DayData::splitTime(int $locationId): string`, `TableAllocator::turnoverBufferMinutes(): int`, `Rooms::areaName(): string`, `DailySheet::maxRangeDays(): int`, `InternalNetworkOnly::allowedNetworks(): array`. Der Standortparameter an `splitTime()` dient der Lesbarkeit am Aufrufer und der Prüfbarkeit von Review Focus 5 — die Einstellung ist und bleibt global.

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

use Rsclub22\ReservationControl\Models\Settings;

it('defaults every setting to the previously hardcoded value', function(string $key, mixed $expected): void {
    expect(Settings::get($key, $expected))->toBe($expected);
})->with([
    ['turnover_buffer_minutes', 0],
    ['count_extra_capacity', true],
    ['max_tables_per_reservation', 1],
    ['only_enabled_tables', true],
    ['internal_route_prefix', 'intern'],
    ['internal_booking_horizon_days', 365],
    ['internal_allow_same_day', true],
    ['rooms_area_name', 'Räume'],
    ['split_time', '15:00'],
    ['max_print_range_days', 92],
    ['max_length_first_name', 48],
    ['max_length_last_name', 48],
    ['max_length_email', 96],
    ['max_length_telephone', 40],
    ['max_length_comment', 520],
    ['require_phone_on_public_form', true],
    ['apply_max_guests_online', false],
    ['allow_online_on_blocked_default', false],
    ['cutoff_hours_before_closing', 0],
]);

// Review Focus 5: Einstellungen sind global, Standorte nicht
it('applies one global setting to every location', function(): void {
    Settings::set('split_time', '14:00');

    expect(\Rsclub22\ReservationControl\DayData::splitTime(locationId: 1))->toBe('14:00')
        ->and(\Rsclub22\ReservationControl\DayData::splitTime(locationId: 2))->toBe('14:00');
})->note('Bewusst global. In docs/settings.md als Einschränkung festhalten.');
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=SettingsCoverageTest`
Expected: FAIL beim Standort-Test — `splitTime()` nimmt noch keinen Standort

- [ ] **Step 3: Konstanten ersetzen**

Je Fundstelle nach dem Muster aus Task 6: Konstante wird `DEFAULT_*`, dazu ein statischer Leser mit Prüfung. Dabei unbedingt:

- `trusted_proxies` und `internal_allowed_networks` werden **zwei getrennte Einstellungen**, auch wenn beide heute dieselbe Liste tragen (`Extension:67`, `InternalNetworkOnly:27`).
- `env('MAIL_REPLY_TO_ADDRESS', 'info@zum-braunen-ross-bauerbach.de')` verliert die Hausadresse als Vorgabe: neue Vorgabe ist `null`, und ohne Wert wird **kein** Reply-To gesetzt.
- `rooms_area_name` behält `'Räume'` als Vorgabe — das ist der Bestandswert, auch wenn er deutsch ist.
- `cutoff_hours_before_closing`, `apply_max_guests_online` und `allow_online_on_blocked_default` werden hier **nur angelegt**, mit verhaltensneutraler Vorgabe. Ihre Wirkung baut Plan 3 bzw. Plan 2. Eine Einstellung ohne Wirkung ist vertretbar, eine Wirkung ohne Einstellung nicht.

- [ ] **Step 4: Tests laufen lassen**

Run: `vendor/bin/pest`
Expected: PASS

- [ ] **Step 5: Felder und Beschriftungen ergänzen**

Alle Felder in `resources/models/settings.php`, alle Beschriftungen in beiden Sprachdateien.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Move the remaining hardcoded values into settings"
```

---

### Task 8: Entkopplung vom Theme

**Files:**
- Create: `src/Contracts/GuestCountResolver.php`, `src/Theme/OrangeGuestCount.php`, `src/Theme/NullGuestCount.php`, `tests/GuestCountTest.php`
- Modify: `src/BookingContext.php:34-37`, `src/Extension.php:7,43,213`

**Interfaces:**
- Consumes: nichts aus früheren Tasks
- Produces: `Rsclub22\ReservationControl\Contracts\GuestCountResolver` mit `guestCount(): ?int`; im Container gebunden

- [ ] **Step 1: Den fehlschlagenden Test schreiben**

```php
<?php

declare(strict_types=1);

use Rsclub22\ReservationControl\Contracts\GuestCountResolver;
use Rsclub22\ReservationControl\LargePartyBookingManager;
use Rsclub22\ReservationControl\Theme\NullGuestCount;

it('reports no guest count without a theme integration', function(): void {
    app()->instance(GuestCountResolver::class, new NullGuestCount());
    expect(app(GuestCountResolver::class)->guestCount())->toBeNull();
});

it('is not a large party when the guest count is unknown', function(): void {
    app()->instance(GuestCountResolver::class, new NullGuestCount());
    expect(app(LargePartyBookingManager::class)->isLargeParty())->toBeFalse();
});

it('accepts a resolver supplied by another theme', function(): void {
    app()->instance(GuestCountResolver::class, new class implements GuestCountResolver {
        public function guestCount(): ?int { return 40; }
    });
    expect(app(LargePartyBookingManager::class)->isLargeParty())->toBeTrue();
});
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag bestätigen**

Run: `vendor/bin/pest --filter=GuestCountTest`
Expected: FAIL — Schnittstelle nicht vorhanden

- [ ] **Step 3: Schnittstelle und beide Umsetzungen**

```php
interface GuestCountResolver
{
    /** Guests of the booking in progress, or null when unknown. */
    public function guestCount(): ?int;
}
```

`NullGuestCount::guestCount()` gibt `null` zurück. `OrangeGuestCount` enthält den heutigen Inhalt von `BookingContext.php:34-37`.

- [ ] **Step 4: Bedingt binden**

In `Extension::boot()`:

```php
$this->app->singleton(GuestCountResolver::class, fn(): GuestCountResolver =>
    class_exists(\Igniter\Orange\Livewire\Booking::class)
        ? new OrangeGuestCount()
        : new NullGuestCount());
```

Der `Livewire::listen('mount', …)`-Haken und der Validator-Haken (`Extension:43`) hängen sich ebenfalls nur ein, wenn die Klasse existiert. `BookingContext::guestCount()` ruft künftig den Resolver.

- [ ] **Step 5: Tests laufen lassen**

Run: `vendor/bin/pest`
Expected: PASS

- [ ] **Step 6: Statische Prüfung**

Run: `vendor/bin/pint && vendor/bin/phpstan analyse`
Expected: keine Fehler. Der direkte Verweis auf `Igniter\Orange\…` steht nur noch hinter `class_exists`; Larastan braucht dafür ggf. einen Eintrag in `phpstan-baseline.neon`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Decouple guest-count detection from the Orange theme"
```

---

## Abnahme dieses Plans

Nach Task 8 muss gelten:

1. `vendor/bin/pest` grün, `vendor/bin/pint` und `vendor/bin/phpstan` ohne Befund.
2. `composer validate --strict` sauber.
3. Kein `Wagnersnetz`, kein `reservetweaks::`, kein `reservetweaks.` mehr im Code — **außer** den beiden datentragenden Werten `reservetweaks-cli` und `reservetweaks_blocked_dates`, die erst Plan 2 migriert.
4. Keine deutschen Bezeichner oder Kommentare in `src/`.
5. Die Erweiterung verhält sich in einer Installation **identisch zu heute**, solange keine Einstellung verändert wurde.

Punkt 5 wird von Hand gegen eine Kopie der Produktionsdatenbank geprüft, nicht nur von den Tests behauptet.
