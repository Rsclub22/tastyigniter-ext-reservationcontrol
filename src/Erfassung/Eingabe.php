<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Erfassung;

use Igniter\Admin\Models\Status;

/**
 * Freitext aus Tastatur und Tabelle in Werte umsetzen.
 *
 * Das oeffentliche Formular bekommt seine Werte aus Datepicker und Auswahlfeld,
 * die brauchen so etwas nicht. Am Telefon und in einer aus Excel exportierten
 * Liste steht dagegen "20.9.", "18 Uhr", "Mueller, Hans" - deshalb hier.
 */
class Eingabe
{
    /** Vergleichsform: klein, ohne Umlaute, ohne Satzzeichen. */
    public static function normalisiert(string $wert): string
    {
        $wert = mb_strtolower(trim($wert));
        $wert = strtr($wert, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $wert) ?? '';
    }

    /** Erste Zahl im Text. "5 Personen" und "ca. 12" ergeben 5 bzw. 12. */
    public static function zahl(string $wert): ?int
    {
        return preg_match('/(\d+)/', $wert, $treffer) ? (int) $treffer[1] : null;
    }

    /**
     * Datum als Y-m-d. Erlaubt "heute", "morgen", "uebermorgen", ISO,
     * TT.MM.JJJJ, TT.MM.JJ und TT.MM. (dann das naechste Vorkommen).
     */
    public static function datum(string $wert): ?string
    {
        $wert = trim($wert);
        if ($wert === '') {
            return null;
        }

        $tage = ['heute' => 0, 'today' => 0, 'morgen' => 1, 'tomorrow' => 1, 'uebermorgen' => 2];
        if (array_key_exists($schluessel = self::normalisiert($wert), $tage)) {
            return date('Y-m-d', strtotime('+'.$tage[$schluessel].' days'));
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $wert, $t)) {
            return self::gueltig((int) $t[1], (int) $t[2], (int) $t[3]);
        }

        if (preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})\.?$#', $wert, $t)) {
            $jahr = (int) $t[3];

            return self::gueltig($jahr < 100 ? $jahr + 2000 : $jahr, (int) $t[2], (int) $t[1]);
        }

        // Ohne Jahresangabe das naechste Vorkommen nehmen: am Telefon wird im
        // Dezember fuer den Januar gebucht, ohne das Jahr dazuzusagen.
        if (preg_match('#^(\d{1,2})[./-](\d{1,2})\.?$#', $wert, $t)) {
            foreach ([(int) date('Y'), (int) date('Y') + 1] as $jahr) {
                $datum = self::gueltig($jahr, (int) $t[2], (int) $t[1]);
                if ($datum !== null && $datum >= date('Y-m-d')) {
                    return $datum;
                }
            }
        }

        return null;
    }

    /** Uhrzeit als H:i. Erlaubt "18:30", "18.30", "1830", "18", "18 Uhr". */
    public static function zeit(string $wert): ?string
    {
        $wert = trim(str_ireplace('uhr', '', $wert));
        if ($wert === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[:.\s]?(\d{2})$/', $wert, $t)) {
            [$stunde, $minute] = [(int) $t[1], (int) $t[2]];
        } elseif (preg_match('/^(\d{1,2})$/', $wert, $t)) {
            [$stunde, $minute] = [(int) $t[1], 0];
        } else {
            return null;
        }

        return ($stunde > 23 || $minute > 59) ? null : sprintf('%02d:%02d', $stunde, $minute);
    }

    /**
     * Vor- und Nachname trennen.
     *
     * @return array{0: string, 1: string} [Vorname, Nachname]
     */
    public static function name(string $voll, string $vorname = '', string $nachname = ''): array
    {
        // Getrennte Spalten gewinnen. Steht nur eine davon, ist das der Nachname:
        // die Spalten heissen in fremden Listen selten so, wie sie gemeint sind.
        if ($vorname !== '' || $nachname !== '') {
            return [$vorname, $nachname !== '' ? $nachname : $vorname];
        }

        $voll = trim(preg_replace('/\s+/', ' ', $voll) ?? '');
        if ($voll === '') {
            return ['', ''];
        }

        if (str_contains($voll, ',')) {
            [$nach, $vor] = array_map('trim', explode(',', $voll, 2));

            return [$vor, $nach];
        }

        $teile = explode(' ', $voll);
        if (count($teile) === 1) {
            return ['', $teile[0]];
        }

        $nach = array_pop($teile);

        return [implode(' ', $teile), $nach];
    }

    /** "Sa, 19.09.2026" - im Tagesgeschaeft wird nach dem Wochentag gesucht. */
    public static function datumLang(string $datum): string
    {
        $tage = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $zeit = strtotime($datum);

        return $tage[(int) date('N', $zeit) - 1].', '.date('d.m.Y', $zeit);
    }

    /** Reservierungs-Status: Name, Kurzform oder ID. */
    public static function status(string $wert): ?int
    {
        $kurz = [
            'bestaetigt' => 'bestaetigt', 'bestatigt' => 'bestaetigt', 'confirmed' => 'bestaetigt',
            'ok' => 'bestaetigt', 'ja' => 'bestaetigt', 'fix' => 'bestaetigt', 'b' => 'bestaetigt',
            'ausstehend' => 'ausstehend', 'offen' => 'ausstehend', 'pending' => 'ausstehend', 'a' => 'ausstehend',
            'storniert' => 'storniert', 'abgesagt' => 'storniert', 'canceled' => 'storniert',
            'cancelled' => 'storniert', 'nein' => 'storniert', 's' => 'storniert',
        ];

        $verfuegbar = self::statusListe();
        $schluessel = $kurz[self::normalisiert($wert)] ?? self::normalisiert($wert);

        if (isset($verfuegbar[$schluessel])) {
            return $verfuegbar[$schluessel];
        }

        $wert = trim($wert);

        return (ctype_digit($wert) && in_array((int) $wert, $verfuegbar, true)) ? (int) $wert : null;
    }

    /** @return array<string, int> normalisierter Statusname => ID */
    public static function statusListe(): array
    {
        static $liste = null;

        if ($liste === null) {
            $liste = Status::query()
                ->where('status_for', 'reservation')
                ->get()
                ->mapWithKeys(fn (Status $s): array => [self::normalisiert((string) $s->status_name) => (int) $s->getKey()])
                ->all();
        }

        return $liste;
    }

    /**
     * Anlass. Die Reihenfolge ist die von Reservation::getOccasionOptions(),
     * der Index ist der gespeicherte Wert - Index 0 ist "kein Anlass".
     */
    public static function anlass(string $wert): ?int
    {
        return [
            'geburtstag' => 1, 'birthday' => 1,
            'jubilaeum' => 2, 'hochzeitstag' => 2, 'anniversary' => 2,
            'feier' => 3, 'feierlichkeit' => 3, 'celebration' => 3,
            'junggesellinnenabschied' => 4,
            'junggesellenabschied' => 5, 'jga' => 5,
        ][self::normalisiert($wert)] ?? null;
    }

    private static function gueltig(int $jahr, int $monat, int $tag): ?string
    {
        return checkdate($monat, $tag, $jahr) ? sprintf('%04d-%02d-%02d', $jahr, $monat, $tag) : null;
    }
}
