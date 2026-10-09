# Entscheidungen während der Umsetzung von Plan 1

Stand: 2026-10-09. Alles, was ich bei der Ausführung stellvertretend entschieden
habe, mit Begründung und dem Preis, falls die Entscheidung falsch war. Aus dem
Ausführungs-Logbuch übernommen, damit es dessen Löschung überlebt.

## Umzugs-Voraussetzungen (zuerst lesen)

| | Voraussetzung | Wenn vergessen |
| --- | --- | --- |
| a | `app.locale` auf `de` | Personal und Tagesblätter stehen auf Englisch |
| b | alte Erweiterung `wagnersnetz.reservetweaks` abschalten, **bevor** die neue installiert wird | gleiche URL-Pfade, Laravel lässt still die letzte Registrierung gewinnen |
| c | Reply-To-Adresse setzen | Gastantworten laufen ins Leere |
| d | Konsolenbefehle heißen jetzt `reservation:enter` / `reservation:import`, Option `--dry-run` | Cron-Einträge oder Notizen brechen still |
| e | `internal_allowed_networks` explizit setzen | niemand kommt mehr auf `/intern` |
| f | `trusted_proxies` explizit setzen | Drosselung und Protokollierung sehen die Container-Adresse |

## Die Entscheidungen

1. Repo + erster Commit (Spec, Plan) als Aufbau vor Task 1 angelegt, damit das SDD-Arbeitsverzeichnis einen Repo-Wurzel hat und Spec/Plan wie zugesagt der erste Commit sind. Task-1-Implementierer beginnt bei Step 2. Kosten falls falsch: ein überflüssiger Commit, trivial rückgängig.

2. Arbeit auf Branch 'fundament' statt main. Neues Repo, main traegt nur Spec+Plan. Kosten falls falsch: keine, Branch laesst sich jederzeit mergen.

3. T6 besitzt LargePartyBookingManager vollständig, einschließlich Zeile 41 (internal_booking_horizon_days). T7 fasst die Datei nicht an. Grund: eine Datei, ein Besitzer – sonst überschreiben sich zwei Implementierer. Kosten falls falsch: eine Einstellung landet in der falschen Task, rein kosmetisch.

4. T8 besitzt src/Extension.php Zeilen 7, 43 und 213 (die Theme-Kopplung inkl. BOOKING_FIELDS). T7 fasst nur 67, 73, 93, 98, 127-130 und 235 an. Grund: :43 gehört fachlich zur Entkopplung, nicht zur Einstellungsflaeche. Kosten falls falsch: public_form_fields kommt eine Task später.

5. T7s SettingsCoverageTest wird umgeschrieben. Statt Settings::get($key, $default) – was tautologisch immer den Default zurückgibt und nichts prüft – muss er die Leser-Methoden gegen die dokumentierten Vorgaben prüfen, nach dem Muster aus T6. Grund: ein Test, der nichts behauptet, ist schlimmer als keiner, weil er Sicherheit vortäuscht. Kosten falls falsch: T7 braucht eine Fix-Runde mehr.

6. Werkzeugkette ueber Docker statt Systempakete. Der Entwicklungsrechner hat weder PHP noch Composer noch MySQL; php:8.3-cli bringt nur pdo_sqlite und keine der von TastyIgniter verlangten Erweiterungen. Systempakete zu installieren braucht sudo und waere ein Eingriff ausserhalb des Arbeitsbereichs. Als Task 0 in den Plan eingefuegt und committet (948622d). Kosten falls falsch: eine ueberfluessige Dockerfile.dev im Repo, loeschbar.

7. Der tautologische Test in Task 7 wurde im PLANTEXT ersetzt (93c289f), nicht nur im Logbuch beurteilt. Ein Ruling, das den Plan nicht aendert, erreicht den Implementierer nicht - die Briefings werden aus dem Plan erzeugt. Kosten falls falsch: keine, der neue Test prueft mehr als der alte.

8. Extension.php:186 (max_tables_per_reservation) stand in keiner Dateiliste und haette keinen Besitzer gehabt. Task 7 bekommt :186, Task 8 behaelt :43 (54d4ca8). Kosten falls falsch: die Einstellung landet in der falschen Task.

9. Eigener Fehler - git add -A im Steuerkontext waehrend ein Implementierer im selben Repo arbeitete hat dessen halbfertige Dateien in meine Plan-Commits gezogen. Branch war lokal, daher Historie neu geschnitten (c090862 Plankorrekturen, 27d65d4 Task 0); Inhalt byteidentisch geprueft. Ab jetzt im Steuerkontext nur noch gezieltes git add einzelner Pfade, nie -A, solange ein Implementierer laeuft. Kosten falls falsch: keine, der Inhalt war nachweislich unveraendert.

10. Der Implementierer berichtete, phpunit.xml.dist duerfe keine DB_*-Werte setzen, weil compose sie liefert. Der Pruefer weist nach, dass das verkehrt herum ist: PHPUnit <env> ueberschreibt bereits gesetzte Variablen nur mit force="true". Task 1 setzt DB_*-Vorgaben OHNE force, damit das Paket auch ausserhalb von Docker testbar bleibt. Grund: der Pruefer argumentiert aus dem PHPUnit-Verhalten, der Implementierer aus einer Vermutung. Kosten falls falsch: Tests ziehen ausserhalb von Docker die falsche Datenbank, faellt sofort auf.

11. Erweiterungskennung von rsclub22 auf wagnersnetz geaendert (cc545ed) - vom Benutzer entschieden, nicht von mir. Grund: TastyIgniter validiert code gegen /^[A-Za-z]+(\.?)+[A-Za-z]+$/ (SystemHelper.php:155), Ziffern sind verboten; und da getNamePath() aus dem code den Installationspfad ableitet, muessen code, Namensraum und Paketname uebereinstimmen. Alle acht Briefings neu erzeugt. Kosten falls falsch: eine mechanische Umbenennung.

12. extra.tastyigniter-extension.description ist laut SystemHelper.php:160 Pflicht (max 255) und fehlte in der composer.json des Plans. Task 1 ergaenzt sie. Kosten falls falsch: keine, die Validierung verlangt sie.

13. Umbenennung der Konsolen-Registrierungsschluessel auf reservationcontrol.* ist richtig und bleibt. Selbst geprueft: der Artisan-Name kommt aus $signature (reservierung:erfassen), der Schluessel in registerConsoleCommand ist eine interne Container-Bindung. Kosten falls falsch: keine, nichts von aussen Sichtbares haengt daran.

14. loadViewsFrom('reservetweaks') von Hand nachgezogen - die sed des Briefings griff nicht, weil dort kein :: folgt. Ohne die Korrektur haette sich reservationcontrol::intern nicht aufloesen lassen. Guter Fund des Implementierers. Kosten falls falsch: keine.

15. BlockedDates::SETTING ist private, der Pin-Test liest sie per Reflexion. Akzeptiert: der Test ist eine Stolperdrahtsicherung gegen versehentliches Umbenennen von Daten tragenden Werten, kein Produktionscode. Die Alternative waere, die Konstante fuer einen Test oeffentlich zu machen - das waere der schlechtere Tausch. Kosten falls falsch: der Test bricht, wenn die Konstante spaeter wandert; genau dann soll er brechen.

16. Die zwei irrefuehrenden Testnamen in SmokeTest werden als Important behandelt und gehen in eine Fix-Runde, obwohl der Pruefer sie eher als Minor fuehrt. Grund: ein Test, dessen Name mehr behauptet als er prueft, taeuscht Deckung vor - genau der Mangel, den ich in dieser Sitzung schon einmal im eigenen Plan korrigiert habe. In einem Paket, das veroeffentlicht wird, ist das teurer als die Fix-Runde. Kosten falls falsch: eine zusaetzliche Runde fuer kosmetische Umbenennungen.

17. Review Focus 4 (alte und neue Erweiterung gleichzeitig installiert) ist durch einen Test NICHT abbildbar - Laravels Router laesst die letzte Registrierung still gewinnen, es gibt kein lautes Scheitern zu behaupten. Wird stattdessen als Anforderung an die Installations-/Umzugsanleitung gefuehrt (Plan 3). Der Test wird auf das reduziert, was er wirklich zeigt. Kosten falls falsch: die Kollision faellt erst beim Umzug auf, und genau dafuer ist die Anleitung da.

18. Die drei von Pint entfernten Importe in InternalBooking.php (Collection, Sperrvermerke, TableAllocator) sind unbedenklich - selbst geprueft, sie waren bereits im Original importiert und nirgends verwendet. Kosten falls falsch: keine.

19. Der Implementierer hatte task-2-report.md mit git add -f ins Repo gezwungen. Per git rm --cached entfernt und den Commit amendiert - das SDD-Arbeitsverzeichnis ist git-ignoriertes Geruest und gehoert nicht ins veroeffentlichte Paket. Datei liegt unveraendert auf Platte. Kosten falls falsch: keine.

20. Finding 3 (Leerzeile nach declare in tests/SmokeTest.php) wird als Minor zurueckgestellt statt in eine zweite Korrekturrunde zu gehen. Selbst geprueft: jede portierte Quelldatei hat dort eine Leerzeile, die Testdatei nicht - es ist ein echter Bruch der Hauskonvention, aber eine Runde plus Nachpruefung fuer eine Leerzeile ist unverhaeltnismaessig. Task 3 fasst dieselbe Datei ohnehin an und erledigt es mit. Kosten falls falsch: eine Leerzeile fehlt laenger.

21. Die 60 PHPStan-Fehler sind selbst nachgeprueft und echt - durchweg Eloquent-Falschmeldungen (Access to an undefined property Model::$x), die Larastan ohne Modell-Stubs nicht aufloest. Sie kamen mit Task 2s Port, nicht mit Task 3. Mein Fehler: Task 2s Pruefauftrag enthielt keine statische Analyse, obwohl der Plan sie als Abnahmekriterium fuehrt. Loesung: phpstan-baseline.neon erzeugen, wie es die offizielle ti-ext-reservation auch tut - friert die 60 geerbten Fehler ein, laesst aber jeden NEUEN Fehler durchfallen. Die Alternative waere, 60 Eloquent-Meldungen zu reparieren; das ist kein Umbenennungs-Auftrag. Kosten falls falsch: die geerbten Mangel bleiben sichtbar in einer Datei stehen statt behoben zu werden.

22. PHPStan stuerzt im Parallelbetrieb ab (Child process error 255), laeuft mit -d memory_limit=1G durch. Wird in phpstan.neon.dist bzw. docs/development.md festgehalten. Kosten falls falsch: keine.

23. Die CLI-Optionen wurden mit uebersetzt (--probe -> --dry-run usw.). Gedeckt durch meine Anweisung "any other German CLI text". Von aussen sichtbar, aber das Paket hat ausser euch noch keine Nutzer. Kosten falls falsch: wer die Befehle skriptet, muss die Optionsnamen anpassen - steht im Bericht.

24. Nicht umbenannt wurden JSON-Schluessel, Array-Schluessel der Blade-Ansichten, Request-Parameter, Routennamen, Rooms::AREA, die deutschen Regexe und die Eingabe-Token. Richtig so - die JSON-Schluessel liest die Reservierungs-App am Tresen, ein Umbenennen haette sie im Betrieb zerlegt. Jede Stelle traegt jetzt einen englischen Kommentar mit der Begruendung.

25. Mein Vorschlag zur Absturzursache (Parallelbetrieb) war falsch. Der Implementierer hat ihn ausprobiert, widerlegt, zurueckgenommen und die echte Ursache gefunden: php:8.3-cli hat keine php.ini, memory_limit steht auf 128M, PHPStan reicht das an Kindprozesse weiter. Behoben per conf.d/zz-dev.ini. Richtig gehandelt - haette er meinen Vorschlag blind umgesetzt, waere der Absturz geblieben.

26. Der Code bleibt wie er ist - einer Spracheinstellung zu folgen ist fuer ein allgemeines Paket das richtige Verhalten, und fest verdrahtetes Deutsch waere der Sinn der ganzen Uebung zuwider. Die Sprachwahl wird stattdessen zur ausdruecklichen, lauten Voraussetzung des Umzugs. Dem Benutzer vorgelegt, ob zusaetzlich eine Einstellung interface_locale gewuenscht ist, die die Erweiterung unabhaengig von app.locale auf Deutsch haelt. Kosten falls falsch: beim Umzug steht das Personal vor englischen Seiten - deshalb gemeldet statt still entschieden.

27. changeField() verzweigte ueber den deutschen Label-Text und wurde auf den Auswahlschluessel umgestellt. Das war durch die Uebersetzung erzwungen, nicht optional - mit uebersetzten Labels haette die Verzweigung gar nicht mehr gegriffen. Deckt zugleich den Verdacht ab, den Task 3 gemeldet hatte. Kosten falls falsch: der Zweig verhaelt sich anders als frueher; der Pruefer soll gezielt darauf sehen.

28. splitTime() akzeptiert zusaetzlich 'off' neben 'aus'. Rein additiv, weil der englische Hinweistext 'off' nennt. Kosten falls falsch: keine.

29. Mein Umlaut-Grep als Suchmethode fuer verbliebene deutsche Strings war unzureichend - "Datum" hat keine Umlaute und blieb unentdeckt. Fuer die restlichen Tasks nicht auf Umlaute pruefen, sondern gezielt die Stellen durchgehen (validate()-Attributarrays, Auswahl-Labels, Konsolenausgaben). Kosten falls falsch: weitere deutsche Reste bleiben liegen.

30. Nachweis per absichtlichem Bruch eingefordert, obwohl der Test "fertig" gemeldet war. Zwei der sechs Pruefungen deckten echte Luecken auf - die Markup-Regel suchte nur <strong>, ein <b> lief durch alle Tests. Bestaetigt die Haltung: ein Test, den nie jemand hat scheitern sehen, ist eine Behauptung. Kosten falls falsch: eine zusaetzliche Runde fuer einen Test, der schon gut war.

31. Der fehlende afterEach in SettingsTest wird NICHT in eine eigene Runde geschickt. Stattdessen bekommt Task 6 beides mit: einen eigenen beforeEach(clearInternalCache) in SEINER Testdatei - damit verteidigt sich die gefaehrdete Task selbst, statt auf fremde Disziplin zu hoffen - und den afterEach in SettingsTest, da sie ohnehin im Bereich arbeitet. Kosten falls falsch: ein isolierter --filter-Lauf laesst 25 im Cache stehen, bis Task 6 es schliesst.

32. Eskalation von Haiku auf Sonnet. Der Blocker verlangt Integrationsverstaendnis, nicht Abschrift - das war meine Fehleinschaetzung bei der Modellwahl, nicht ein Versagen des Implementierers. Kosten falls falsch: ein teurerer Durchlauf.

33. large_party_all_weekdays und large_party_skip_table_check hatten im Plan keine beschriebene Wirkung - eine Luecke in meinem Plan, nicht im Briefing. Festgelegt: beide schalten das heute implizite Verhalten (Fenster an allen sieben Tagen; Tischpruefung oberhalb der Schwelle ueberspringen), Vorgabe an = wie bisher. Kosten falls falsch: zwei Schalter verhalten sich anders als erwartet, durch Tests gedeckt.

34. Die Reihenfolge von large_party_open/close wird geprueft (close muss nach open liegen), sonst Rueckfall auf die Vorgaben. Vom Implementierer als Luecke gemeldet. Kosten falls falsch: eine unsinnige Eingabe wird stillschweigend akzeptiert.

35. Meine Formulierung zu Fix-Befund 2 war invertiert. Ich schrieb, bei abgeschaltetem large_party_all_weekdays solle die gewoehnliche Oeffnungszeit erscheinen. Tatsaechlich steuert der Schalter nur, an welchen TAGEN das Gesellschaftsfenster gilt - das Fenster bleibt 10:00-22:00. Der Implementierer hat den richtigen Test geschrieben, der Pruefer hat meinen Fehler gefunden. Kein Code zu aendern. Kosten falls falsch: haette der naechste Bearbeiter einen korrekten Test "repariert".

36. Der Mehrtisch-Algorithmus (max_tables_per_reservation > 1 legt freie Einzeltische zusammen) wird AUS DIESER TASK ENTFERNT. Empfehlung des Pruefers angenommen, Begruendung: (1) Scope Creep - das Briefing verlangte nur den Leser; (2) "nur begrenzen statt neu erfinden" ist keine Option, weil der heutige Vergabe-Mechanismus genau einen Tisch vergibt, es gibt nichts zu begrenzen; (3) der Algorithmus ignoriert min_capacity (ein Gast bekaeme den groessten freien Tisch), ist gierig statt passend (10 Gaeste auf 8+6 statt 8+2) und kennt keine Nachbarschaft - er wuerde einen Tisch im Saal mit einem im Keller zusammenlegen; (4) getestet nur an drei gleich grossen Tischen, und der Test ruft allocateTables() direkt auf, nie ueber die Einstellung oder den Haken. In einem Paket, das fremde Haeuser installieren und das echte Tischvergaben entscheidet, ist das zu wenig. Der Leser und die Vorgabe 1 bleiben, das Feld wird als noch wirkungslos gekennzeichnet, der Algorithmus bekommt eine eigene Task mit eigenem Entwurf. Kosten falls falsch: eine Funktion, die niemand bestellt hat, kommt spaeter statt jetzt.

37. internal_route_prefix wird aus Spec und Globalen Vorgaben GESTRICHEN statt nachgebaut. Begruendung des Pruefers uebernommen: das Praefix ist keine einzelne Zeichenkette - die Weiterleitungen bauen '/intern?' selbst zusammen, die API-Pfade sind eigene Literale; eine Einstellung, die eines aendert und die anderen nicht, ist schlimmer als keine. Dazu: Routen registrieren in boot(), wo die Einstellung noch nicht verlaesslich lesbar ist, und ein Tippfehler setzt die gesamte Telefonannahme auf 404 ohne Weg zurueck. Der Zweck (alte+neue Erweiterung nebeneinander) ist durch Umzugsvoraussetzung (b) geloest. Kosten falls falsch: fremde Installationen koennen das Praefix nicht aendern.

38. Sicherheitsvorgaben werden verschaerft, OBWOHL das die Verhaltensneutralitaet bricht. internal_allowed_networks faellt auf ['127.0.0.1','::1'] zurueck statt auf das ganze private Netz; trusted_proxies ebenso. Begruendung: als Paket fuer fremde Server ist die heutige Vorgabe eine offene Tuer - ein unauthentifizierter Endpunkt, der Reservierungen anlegt und Gastdaten druckt, erreichbar aus dem gesamten LAN; und ueber die weite TrustProxies-Liste laesst sich X-Forwarded-For faelschen, womit die IP-Sperre davor wertlos wird. Beides wandert als Voraussetzung (e) und (f) in die Umzugs-Checkliste - der Tresenrechner muss die Werte explizit setzen. Kosten falls falsch: beim Umzug kommt niemand auf /intern, bis die Einstellung gesetzt ist - laut und sofort sichtbar, nicht still.

39. SettingValue::isNetwork() akzeptiert Maske 0, also besteht 0.0.0.0/0 die Pruefung und oeffnet /intern ins Internet. Wird zurueckgewiesen. Kosten falls falsch: keine, /0 ist in einer Allowlist nie beabsichtigt.

