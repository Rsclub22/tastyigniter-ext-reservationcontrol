# wagnersnetz.reservationcontrol — Entwurf

Stand: 2026-10-09

Die Erweiterung `wagnersnetz/ti-ext-reservetweaks` wird aus der Installation des
Gasthauses herausgelöst, verallgemeinert und als eigenständiges, konfigurierbares
Paket unter GPLv3 veröffentlicht — Ziel ist der TastyIgniter-Marktplatz.

## Was entschieden ist

| Frage | Entscheidung |
| --- | --- |
| Migration | Der Tresen-Server zieht auf die verallgemeinerte Fassung um. **Eine** Codebasis. |
| Sprache | Sprachdateien deutsch **und** englisch, Code vollständig englisch (inkl. Kommentare). |
| Zuschnitt | Einstellungen herausziehen **und** die sprachgebundenen Mechanismen ersetzen. |
| Erweiterungscode | `wagnersnetz.reservationcontrol` |
| Einstellungsbreite | Maximal — auch das, was heute stillschweigend feststeht. |
| Sperrvermerke/Tage | **Zwei** getrennte Modelle und Masken, nicht eines für beides. |
| Lizenz | `GPL-3.0-or-later` |

## Warum überhaupt

Die Erweiterung ist für genau ein Haus geschrieben. Drei Dinge binden sie daran,
und zwar nicht als Schönheitsfehler, sondern funktional:

1. **Sperrvermerke werden aus deutschem Freitext gesteuert.** Ein Sperrvermerk ist
   eine Reservierung mit absurder Gästezahl; was sie bewirkt, liest ein Regex aus
   dem Kommentarfeld. `ZEIT_MUSTER` verlangt wörtlich „Uhr", `PAX_MUSTER` kennt
   `max`/`personen`/`gäste`/`plätze`, `GANZTAGS_MUSTER` sucht „ganztägig" und
   „keine online" (`Sperrvermerke.php:40,50,57`).
2. **Räume werden über einen deutschen Namen gefunden** —
   `Rooms.php:23: const string AREA = 'Räume'`.
3. **Gesperrte Tage liegen als JSON-Blob in einer Settings-Zeile**
   (`BlockedDates.php:22,24`) und werden beim Schreiben um alles Vergangene
   gekürzt (`:83-85`) — es gibt keine Historie.

Dazu kommt: kein Settings-Modell, keine Sprachdateien, keine Berechtigungen, keine
Migrationen, und in der `composer.json` kein einziges `require` — obwohl der Code
hart auf `ti-ext-api` und das Orange-Theme zugreift und wegen typisierter
Konstanten faktisch PHP ≥ 8.3 verlangt.

## Paket

```
wagnersnetz/ti-ext-reservationcontrol
  Code       wagnersnetz.reservationcontrol
  Namensraum Wagnersnetz\ReservationControl\
  Verzeichnis extensions/wagnersnetz/reservationcontrol/
  type       tastyigniter-extension
```

`type` wird `tastyigniter-package` — die kanonische Form. `Flame/Composer/
Manager.php:118` akzeptiert `tastyigniter-package`, `tastyigniter-extension` und
`tastyigniter-theme` gleichermaßen, aber der Generator-Stub
(`Flame/Scaffold/Console/stubs/composer.stub:3`) und die offiziellen
Erweiterungen verwenden `tastyigniter-package` mit `extra."tastyigniter-
extension"`. Das heutige `tastyigniter-extension` funktioniert, weicht aber von
der Konvention ab.

Paketgerüst nach dem Vorbild der offiziellen Erweiterungen: `phpunit.xml.dist`,
`pint.json`, `phpstan.neon.dist`, `rector.php`, `database/`, `docs/`, `tests/`
mit `tests/Pest.php` auf `SamPoyigi\Testbench\TestCase`. `require-dev`:
`pestphp/pest-plugin-laravel`, `sampoyigi/testbench`, `laravel/pint`,
`larastan/larastan`, `rector/rector`.

Neu in `composer.json`: `require` mit `php: ^8.3`, `tastyigniter/core`,
`tastyigniter/ti-ext-reservation`, `tastyigniter/ti-ext-api`; `extra…icon`;
englische Beschreibung ≤ 130 Zeichen; `license: GPL-3.0-or-later`.
Dazu `LICENSE`, `README.md`, `CHANGELOG.md`, `.gitignore`.
Die drei `src/Extension.php.bak-*` werden nicht übernommen.

## Einstellungen

Ein Settings-Modell nach dem Muster der Kern-Erweiterungen:
`src/Models/Settings.php` (`$implement = [SettingsModel::class]`,
`$settingsCode = 'wagnersnetz_reservationcontrol_settings'`,
`$settingsFieldsConfig = 'settings'`), Felder in `resources/models/settings.php`,
Beschriftungen als `lang:`-Schlüssel.

**Jede Vorgabe entspricht dem heutigen Verhalten.** Wer nichts anfasst, merkt vom
Umzug nichts.

### Gesellschaften

| Schlüssel | Vorgabe | ersetzt |
| --- | --- | --- |
| `large_party_threshold` | 20 | `LargePartyBookingManager::LARGE_PARTY_FROM` |
| `large_party_open` | 10:00 | `LARGE_PARTY_OPEN` |
| `large_party_close` | 22:00 | `LARGE_PARTY_CLOSE` |
| `large_party_all_weekdays` | an | implizit (`:43-45`) |
| `large_party_skip_table_check` | an | implizit (`:122-124`) |

### Annahmeschluss (neu)

| Schlüssel | Vorgabe | Wirkung |
| --- | --- | --- |
| `cutoff_hours_before_closing` | **0** | Unterhalb der Schwelle werden Zeitfenster ausgeblendet, die weniger als N Stunden vor Schließung liegen. 0 = wie bisher. |

Gilt bewusst nur unterhalb von `large_party_threshold`: große Gesellschaften
werden ohnehin von Hand besprochen.

### Online-Buchung an Sperrtagen

| Schlüssel | Vorgabe | Wirkung |
| --- | --- | --- |
| `allow_online_on_blocked_default` | aus | Vorbelegung des Feldes `allow_online` bei neuen `BlockedDate`- und `ClosureNote`-Einträgen |

Die Einstellung legt nur die Vorbelegung fest. Entschieden wird **je Eintrag** —
sonst ließe sich „Märchenabend: online zu, Weihnachtsmarkt: online offen" nicht
abbilden.

| Schlüssel | Vorgabe | Wirkung |
| --- | --- | --- |
| `apply_max_guests_online` | **aus** | Wirkt `max_guests` eines `ClosureNote` auch auf die Online-Buchung? Aus = wie bisher, die Obergrenze bindet nur das Personal. |

### Tische

| Schlüssel | Vorgabe | ersetzt |
| --- | --- | --- |
| `turnover_buffer_minutes` | **0** | `TableAllocator:53` — heute kein Puffer |
| `count_extra_capacity` | an | `:104` |
| `max_tables_per_reservation` | 1 | `Extension:186` |
| `only_enabled_tables` | an | `:37` |

### Interne Seiten und API

| Schlüssel | Vorgabe | ersetzt |
| --- | --- | --- |
| `internal_route_prefix` | `intern` | `Extension:282` |
| `internal_allowed_networks` | heutige Liste | `InternalNetworkOnly:27` |
| `trusted_proxies` | heutige Liste | `Extension:67` |
| `internal_booking_horizon_days` | 365 | `INTERN_VORLAUF_TAGE` |
| `internal_allow_same_day` | an | `:54-55` |

`trusted_proxies` und `internal_allowed_networks` sind heute **dieselbe Liste**,
meinen aber Verschiedenes — wem die App ihre Absenderadresse glaubt, und wer die
internen Seiten sehen darf. Sie werden getrennt. Zusammengelegt ist das für
fremde Installationen eine Sicherheitsfalle.

### Felder und Validierung

`max_length_first_name` 48, `max_length_last_name` 48, `max_length_email` 96,
`max_length_telephone` 40, `max_length_comment` 520 (`Extension:127-130`),
`require_phone_on_public_form` an (`:235`), `require_email` aus,
`name_rule` = „mindestens einer von Vor-/Nachname" (`:128`).

### Räume, Blatt, Druck

| Schlüssel | Vorgabe | ersetzt |
| --- | --- | --- |
| `rooms_area_name` | `Räume` | `Rooms:23` |
| `split_time` | 15:00 | `Tagesdaten:26` + `env('INTERN_DRUCK_TRENNZEIT')` |
| `max_print_range_days` | 92 | `Tagesblatt::MAX_TAGE` |

### Sonstiges

`reply_to_address` und `reply_to_name` (heute `env(...)` mit dem Gasthaus als
Vorgabe im Code, `Extension:93,98`), `admin_rate_limit` (`:73`),
`public_form_fields` (der Fingerabdruck `['firstName','lastName','telephone']`,
`:43`, mit dem das öffentliche Buchungsformular erkannt wird).

Statuszuordnungen werden **nicht** dupliziert: `confirmed_reservation_status`,
`canceled_reservation_status` und `default_reservation_status` sind TastyIgniter-
Kerneinstellungen und werden weiter von dort gelesen.

## Gesperrte Tage und Sperrvermerke

Zwei Modelle, zwei Masken, zwei Menüpunkte.

### `BlockedDate`

Eigene Tabelle statt JSON-Blob. Felder: `location_id`, `date`, `reason`,
`allow_online` (Vorgabe aus), Zeitstempel.

Wirkung unverändert: als Ausnahme in `WorkingSchedule` („geschlossen"), außer
`allow_online` ist gesetzt. Vergangene Einträge bleiben stehen — **Historie
statt stillschweigendem Löschen**.

### `ClosureNote`

Ersetzt den Freitext-Mechanismus. Felder: `location_id`, `date`, `time_from`,
`time_to` (leer = ganztägig), `max_guests` (leer = vollständig gesperrt),
`allow_online`, `reason` (nur noch informativ), Zeitstempel.

Der Grund steuert nichts mehr. Was der Vermerk bewirkt, steht in Feldern.

**Achtung, Verhaltensänderung:** Heute erreicht `max_guests` die Online-Buchung
**nicht**. `maxPax()`/`maxPaxJeZeit()` werden ausschließlich vom Tagesblatt
(Papier) und von der Telefonannahme gelesen; online wirkt nur die binäre Sperre
(ganztags oder Zeitfenster). Eine Obergrenze beschränkt also heute nur das
Personal. Ob `max_guests` künftig auch online greift, entscheidet die
Einstellung `apply_max_guests_online` — Vorgabe **aus**, also wie bisher.

### Migration der Bestandsdaten

Der deutsche Parser bleibt erhalten, aber nur noch als `LegacyGermanNoteParser`,
benutzt ausschließlich von einem einmaligen Konsolenbefehl:

```
php artisan reservationcontrol:migrate-notes [--dry-run] [--location=]
```

Ablauf: Reservierungen finden, deren `guest_num` die Hauskapazität übersteigt →
Kommentar durch den Parser → `ClosureNote` je Treffer. **Vorgabe ist der
Trockenlauf** mit einem Bericht Zeile für Zeile: Ausgangskommentar, erkanntes
Zeitfenster, erkannte Obergrenze, ganztägig ja/nein. Geschrieben wird erst nach
ausdrücklicher Bestätigung.

Die alten Pseudo-Reservierungen werden **nicht gelöscht**, sondern als überführt
markiert. Löschen wäre sauberer, ist aber unumkehrbar, und die Tagesblätter der
Vergangenheit würden sich rückwirkend ändern.

Geprobt wird gegen eine Kopie der Produktionsdatenbank (`/opt/reservierung/backup`).

### Zwei Namen stecken in den Daten

- `Erfassung/Anlegen.php:24` schreibt `MARKER = 'reservetweaks-cli'` an jede über
  die Konsole erfasste Reservierung.
- `BlockedDates.php:22` speichert unter `reservetweaks_blocked_dates`.

Beide tragen den alten Namen. Die Migration hebt sie mit: Marker umschreiben,
Blob in die neue Tabelle überführen und die Settings-Zeile danach entfernen.

## Entkopplung vom Orange-Theme

Heute greift die Erweiterung direkt auf `Igniter\Orange\Livewire\Booking` zu
(`Extension.php:7`), um zu erfahren, wie viele Gäste der Besucher ins öffentliche
Formular getippt hat — `BookingContext.php:34-37` liest die Livewire-Eigenschaft
live mit, und `Extension.php:213` horcht auf deren Änderung. Dazu kommt der
Fingerabdruck `['firstName','lastName','telephone']` (`:43`), an dem das
Buchungsformular erkannt wird. Wer ein anderes Theme fährt, kann die Erweiterung
nicht benutzen.

**Die Bindung ist schmaler als sie wirkt:** gebraucht wird genau eine Zahl, und
der Fall „unbekannt" ist bereits definiert — ohne Gästezahl gilt die Buchung als
normal, nicht als Gesellschaft (`LargePartyBookingManager:171-176`).

Daraus wird eine Schnittstelle:

```php
interface GuestCountResolver
{
    /** Gäste der laufenden Buchung, oder null wenn unbekannt. */
    public function guestCount(): ?int;
}
```

- `OrangeThemeGuestCount` ist die mitgelieferte Umsetzung und wird **nur
  registriert, wenn die Klasse vorhanden ist** (`class_exists`). Verhalten für
  das Gasthaus damit unverändert.
- Fehlt sie, liefert ein `NullGuestCount` schlicht `null`. Die Erweiterung läuft
  weiter, Gesellschaften werden dann nicht automatisch erkannt — die
  Telefonannahme setzt die Zahl ohnehin selbst über `forceGuestCount()`.
- Fremde Themes registrieren ihre eigene Umsetzung über den Container.

Gleiches gilt für den Formular-Fingerabdruck: er wird zur Einstellung
`public_form_fields`, und der Validator-Haken hängt sich nur ein, wenn das
Formular auch erkannt wurde.

In der `composer.json` wandert das Orange-Theme damit von einer stillschweigend
harten Abhängigkeit zu `suggest`.

## Umbenennung auf Englisch

| heute | künftig |
| --- | --- |
| `Sperrvermerke` | `ClosureNotes` |
| `BlockedDates` | `BlockedDates` (bleibt) |
| `Tagesblatt` | `DailySheet` |
| `Tagesdaten` | `DayData` |
| `Annahme` | `Intake` |
| `Erfassung\Anlegen` | `Entry\CreateReservation` |
| `Erfassung\Eingabe` | `Entry\Prompt` |
| `Erfassung\Tischwahl` | `Entry\TableChoice` |
| `Rooms` | `Rooms` (bleibt) |
| `LargePartyBookingManager` | `LargePartyBookingManager` (bleibt) |
| `InternApi` | `InternalApiController` |
| `InternalBooking` | `InternalBookingController` |
| `Console\ReservierungErfassen` | `Console\EnterReservation` |
| `Console\ReservierungImport` | `Console\ImportReservations` |

Routennamen und URL-Pfade bleiben, wo sie sind: `/intern`, `/intern/druck` und
die JSON-Endpunkte hängen an literalen Strings, nicht am Erweiterungscode. Der
Pfadbestandteil wird über `internal_route_prefix` einstellbar, Vorgabe `intern`.

## Sprachdateien

`resources/lang/en/default.php` und `resources/lang/de/default.php`, geladen über
`loadTranslationsFrom`. Englisch ist die Grundlage (Marktplatz-Empfehlung),
Deutsch die vollständige Übersetzung. Betroffen sind die beiden Blade-Ansichten,
die Validierungs-Attributnamen in `InternalBooking` (heute deutsch), die
Konsolenausgaben und die Einstellungsbeschriftungen.

## Dokumentation

In `docs/`:

- `settings.md` — jede Einstellung, Vorgabe, Wirkung
- `internal-pages.md` — die Seiten unter `/intern`, was sie können, wer sie sieht
- `api.md` — jeder JSON-Endpunkt: Methode, Pfad, Parameter, Antwortform
- `console.md` — die Konsolenbefehle samt Importformat
- `migration.md` — Umzug einer bestehenden `reservetweaks`-Installation

`README.md` folgt den offiziellen Erweiterungen (Einleitung + Lizenz). Das von
TastyIgniter geforderte „standardisierte Format" ist öffentlich nicht
spezifiziert — **Risiko**, vor der Einreichung prüfen.

## Prüfung

Die Erweiterung hat heute keine Tests. Neu, mit Pest (TastyIgniters Werkzeug):

- `LargePartyBookingManager` — Verhalten unter, auf und über der Schwelle;
  dass die Schwelle aus den Einstellungen kommt
- `cutoff_hours_before_closing` — 0 ändert nichts; N blendet die letzten N
  Stunden aus; greift nicht oberhalb der Schwelle
- `TableAllocator` — Überschneidung mit und ohne Umrüstpuffer
- `LegacyGermanNoteParser` — gegen echte Kommentare aus eurem Bestand, damit die
  Migration beweisbar richtig liest
- `BlockedDate` / `ClosureNote` — Wirkung auf Zeitplan und Belegung,
  `allow_online` in beide Richtungen
- `GuestCountResolver` — mit Orange-Umsetzung, mit eigener Umsetzung und ganz
  ohne; ohne Resolver muss der Normalpfad greifen, nicht ein Fehler

## Reihenfolge

1. Paket aufsetzen: Repo, `composer.json`, Lizenz, Grundgerüst, CI
2. Umbenennung auf Englisch + Sprachdateien (reine Umbenennung, Verhalten gleich)
3. Settings-Modell und alle Einstellungen, Vorgaben = heutiges Verhalten
4. Entkopplung vom Theme: `GuestCountResolver`, bedingte Registrierung
5. `BlockedDate`-Tabelle und Maske, Ablösung des JSON-Blobs
6. `ClosureNote` + `LegacyGermanNoteParser` + Migrationsbefehl
7. Die drei neuen Verhalten (Schwelle, Events online, Annahmeschluss)
8. Dokumentation
9. Umzug der Produktivinstallation, geprobt gegen die Sicherung

Schritte 2, 3 und 4 ändern **kein** Verhalten — nach jedem einzelnen muss die
Installation sich identisch verhalten. Das ist die Sicherheitsleine für den
Umzug.

## Offene Risiken

- Das „standardisierte Format" der Marktplatz-README ist nicht veröffentlicht.
- Ohne das Orange-Theme kennt die Erweiterung die Gästezahl einer laufenden
  Online-Buchung nicht und erkennt Gesellschaften dort nicht automatisch
  (s. „Entkopplung vom Theme"). Fremde Themes müssen einen eigenen
  `GuestCountResolver` beisteuern.

## Beim Lesen gefundene Mängel

Nicht Teil des Auftrags, aber beim Umbau ohnehin berührt — und einer davon
verliert still Daten:

- `Sperrvermerke.php:364-374` — passen erkannte Zeiten und Obergrenzen nicht
  eins zu eins zusammen, wird die Zuordnung je Zeitfenster **vollständig
  verworfen**, einschließlich der Obergrenzen, die aus früheren Vermerken
  derselben Schleife schon gesammelt waren. Ein Vermerk mit krummer Formulierung
  löscht damit die Obergrenzen der anderen. Im neuen Modell entfällt das, weil
  nichts mehr geparst wird.
- `Sperrvermerke.php:190-192` — ist die Hauskapazität ≤ 0 (keine Tische
  gepflegt), schaltet sich die ganze Sperrvermerk-Erkennung **stillschweigend**
  ab. Im neuen Modell irrelevant, da die Erkennung nicht mehr an der Kapazität
  hängt.
- `Tagesdaten.php:206` — das Trennzeit-Feld wird mit dem deutschen Magicwort
  `aus` abgeschaltet; ungültige Eingaben fallen still auf `15:00` zurück
  (`:210-219`). Wird ein echtes Schaltfeld plus Zeitfeld.
- Weitere fest verdrahtete deutsche Zeichenketten für die Sprachdateien:
  `Tagesblatt.php:73,77,80` (`Ganzer Tag`, `Bis … Uhr`, `Ab … Uhr`),
  `Tagesdaten.php:225` (`Kein aktiver Standort vorhanden.`), sowie die
  Raumnamen-Beispiele `Scheune, Keller, Saal` (`Tagesdaten.php:73-74`).
