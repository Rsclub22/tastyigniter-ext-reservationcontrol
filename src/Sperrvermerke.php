<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sperrvermerke: Pseudo-Reservierungen, die Zeit gegen die Online-Buchung
 * verriegeln, indem sie alle Tische belegen.
 *
 * Wie viel Zeit, haengt vom Vermerk ab. Ein Weihnachtsvermerk ueber die
 * Mittagszeit nimmt den ganzen Tag ein - dort wird nichts nebenher angenommen.
 * Ein Maerchenabend um 17 Uhr tut das nicht: das Haus hat mittags regulaer auf,
 * und der Mittagstisch soll weiter buchbar bleiben. Die Trennlinie ist deshalb
 * die Oeffnungszeit des Wochentags, siehe ganztags().
 *
 * Das ist bewusst kein gesperrter Tag (BlockedDates): gesperrt liest sich fuer
 * den Gast wie "geschlossen", und das stimmt nicht - das Haus hat auf, die
 * Tische sind nur fest verplant. "Ausgebucht" ist ungenau, aber naeher an der
 * Wahrheit. Solange es keinen eigenen Tischplan gibt, bleibt das so.
 *
 * Erkannt werden sie daran, dass sie mehr Gaeste fuehren als ueberhaupt ins
 * Haus passen. Das ist kein Namensvergleich und kein Merker in der Datenbank,
 * sondern eine Eigenschaft, die eine echte Gesellschaft nicht haben kann.
 */
class Sperrvermerke
{
    /**
     * Zeitangaben im Text eines Vermerks: "11 Uhr", "11:30 Uhr", "11.30 Uhr".
     * Die Zahl darf nicht Teil einer laengeren Zahl sein, sonst wuerde aus
     * "Buchung Nummer 1611 Uhr" Unsinn.
     */
    private const string ZEIT_MUSTER = '/(?<![\d:.])(\d{1,2})(?:[:.](\d{2}))?\s*Uhr\b/iu';

    /**
     * Obergrenze je Zeitfenster im Text: "max 60", "max. 60 Personen",
     * "maximal 60 Gaeste" oder kurz "60 PAX".
     *
     * Bewusst eng gefasst: entweder steht ein "max" davor, oder die Einheit
     * ist PAX. Sonst wuerde ein beilaeufiges "Tisch 5 Personen" im Fliesstext
     * die Annahme auf fuenf Gaeste deckeln.
     */
    private const string PAX_MUSTER = '/(?:max(?:imal)?\.?\s*(\d{1,4})\s*(?:pax|personen|pers\.?|g[\x{00e4}a]ste|pl[\x{00e4}a]tze)?|(\d{1,4})\s*pax)\b/iu';

    /**
     * Wortlaute, mit denen ein Vermerk den ganzen Tag beansprucht, auch wenn er
     * zeitlich neben den Oeffnungszeiten liegt. Bewusst eng: was hier nicht
     * steht, entscheidet die Uhrzeit.
     */
    private const string GANZTAGS_MUSTER = '/ganzt[\x{00e4}a]gig|ganze[rn]\s+tag|online[^.!]{0,40}nicht\s*(?:mehr\s*)?(?:verf[\x{00fc}u]gbar|m[\x{00f6}o]glich|buchbar)|keine\s+online/iu';

    /** Oeffnungszeiten je Wochentag, einmal je Anfrage geholt. */
    private static ?array $oeffnung = null;

    /** Plaetze des ganzen Hauses. Kombinationen zaehlen nicht, das waeren dieselben Plaetze doppelt. */
    /** Einmal je Anfrage genuegt - die Tischgroessen aendern sich nicht mitten im Request. */
    private static ?int $hausgroesse = null;

    public static function hausgroesse(): int
    {
        return self::$hausgroesse ??= (int) DiningTable::query()
            ->where('is_combo', 0)
            ->sum(DB::raw('max_capacity + extra_capacity'));
    }

    public static function istVermerk(Reservation $reservation, ?int $hausgroesse = null): bool
    {
        $hausgroesse ??= self::hausgroesse();

        return $hausgroesse > 0 && (int) $reservation->guest_num > $hausgroesse;
    }

    /**
     * Oeffnungszeit des Wochentags als ['HH:MM', 'HH:MM'] - null, wenn an dem
     * Tag geschlossen ist oder kein Zeitplan hinterlegt wurde.
     */
    public static function oeffnung(Carbon $date): ?array
    {
        if (self::$oeffnung === null) {
            self::$oeffnung = [];

            if ($location = Location::query()->whereIsEnabled()->first()) {
                foreach ($location->getWorkingHours() as $stunde) {
                    if ($stunde->type !== 'opening' || ! $stunde->status) {
                        continue;
                    }

                    self::$oeffnung[Carbon::parse($stunde->day)->dayOfWeek] = [
                        Carbon::parse($stunde->opening_time)->format('H:i'),
                        Carbon::parse($stunde->closing_time)->format('H:i'),
                    ];
                }
            }
        }

        return self::$oeffnung[$date->dayOfWeek] ?? null;
    }

    /**
     * Beansprucht dieser Vermerk den ganzen Tag?
     *
     * Ja, wenn sein Zeitfenster in die Oeffnungszeiten hineinreicht - dann sitzt
     * die Gesellschaft auf den Plaetzen, die sonst dem regulaeren Betrieb
     * zustuenden, und daneben laeuft nichts mehr. Ja auch, wenn es im Text
     * ausdruecklich steht.
     *
     * Nein bei einem Vermerk, der ausserhalb liegt: ein Abend um 17 Uhr macht
     * den Mittagstisch nicht zu. Er sperrt dann nur seine eigene Zeit, und das
     * erledigen ohnehin schon die belegten Tische.
     */
    public static function ganztags(Reservation $vermerk, Carbon $date): bool
    {
        if (preg_match(self::GANZTAGS_MUSTER, (string) $vermerk->comment)) {
            return true;
        }

        if (! $oeffnung = self::oeffnung($date)) {
            return false;
        }

        $beginn = Carbon::parse($vermerk->reserve_time)->format('H:i');
        $ende = Carbon::parse($vermerk->reserve_time)
            ->addMinutes(max(0, (int) $vermerk->duration))
            ->format('H:i');

        // Ueber Mitternacht hinaus: bis zum Tagesende rechnen, sonst waere das
        // Ende kleiner als der Beginn und die Pruefung ergaebe Unsinn.
        if ($ende <= $beginn) {
            $ende = '23:59';
        }

        return $beginn < $oeffnung[1] && $ende > $oeffnung[0];
    }

    /**
     * Faellt dieser Zeitpunkt in das Zeitfenster eines Vermerks?
     *
     * Gemeint ist das Fenster der Pseudo-Reservierung selbst, nicht das, was ihr
     * Text nennt: der Maerchenabend steht von 16 bis 20 Uhr, auch wenn im Text
     * "17 Uhr" als Beginn des Programms steht.
     */
    public static function verplant(iterable $vermerke, Carbon $at): bool
    {
        foreach ($vermerke as $vermerk) {
            $beginn = $at->copy()->setTimeFromTimeString(
                Carbon::parse($vermerk->reserve_time)->format('H:i'),
            );
            $ende = $beginn->copy()->addMinutes(max(0, (int) $vermerk->duration));

            if ($at >= $beginn && $at < $ende) {
                return true;
            }
        }

        return false;
    }

    /** Die Vermerke, die den ganzen Tag beanspruchen. */
    public static function ganztaegige(iterable $vermerke, Carbon $date): Collection
    {
        return collect($vermerke)
            ->filter(fn (Reservation $v): bool => self::ganztags($v, $date))
            ->values();
    }

    /**
     * Die Vermerke, die fuer eine bestimmte Uhrzeit gelten: ganztaegige immer,
     * die uebrigen nur zu den Zeiten, die ihr Text nennt.
     */
    public static function fuerZeit(iterable $vermerke, Carbon $date, string $zeit): Collection
    {
        return collect($vermerke)
            ->filter(fn (Reservation $v): bool => self::ganztags($v, $date)
                || in_array($zeit, self::zeiten([$v]), true))
            ->values();
    }

    /** Vermerke eines Tages. Storniertes zaehlt nicht mehr. */
    public static function onDate(Carbon $date): Collection
    {
        $hausgroesse = self::hausgroesse();

        if ($hausgroesse <= 0) {
            return collect();
        }

        return Reservation::query()
            ->with('tables')
            ->whereDate('reserve_date', $date->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->where('guest_num', '>', $hausgroesse)
            ->orderBy('reserve_time')
            ->get();
    }

    /**
     * Uhrzeiten, die in den Vermerken eines Tages genannt sind - etwa die zwei
     * Essenszeiten an Weihnachten.
     *
     * Der Text ist ein freies Kommentarfeld, kein Formular. Was hier nicht
     * erkannt wird, ist deshalb kein Fehlerfall: die Telefonannahme faellt dann
     * auf die gewohnten Zeitfenster zurueck. Lieber zu viele Zeiten anbieten
     * als einen Tag versehentlich unbuchbar machen.
     *
     * @return array<int, string> Uhrzeiten als HH:MM, aufsteigend
     */
    public static function zeiten(iterable $vermerke): array
    {
        $zeiten = [];

        foreach ($vermerke as $vermerk) {
            if (! $text = trim((string) $vermerk->comment)) {
                continue;
            }

            if (! preg_match_all(self::ZEIT_MUSTER, $text, $treffer, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($treffer as $t) {
                $stunde = (int) $t[1];
                $minute = isset($t[2]) ? (int) $t[2] : 0;

                if ($stunde > 23 || $minute > 59) {
                    continue;
                }

                $zeiten[] = sprintf('%02d:%02d', $stunde, $minute);
            }
        }

        $zeiten = array_values(array_unique($zeiten));
        sort($zeiten);

        return $zeiten;
    }

    /**
     * Hoechstzahl an Gaesten je Zeitfenster, wie sie im Text eines Vermerks
     * steht - null, wenn keine genannt ist.
     *
     * Mehrere Angaben: die kleinste gewinnt. Wer zwei Zahlen in denselben Text
     * schreibt, meint im Zweifel die strengere, und zu wenig anzunehmen laesst
     * sich am Telefon klaeren - zu viel nicht.
     */
    public static function maxPax(iterable $vermerke): ?int
    {
        $werte = [];

        foreach ($vermerke as $vermerk) {
            if (! $text = trim((string) $vermerk->comment)) {
                continue;
            }

            if (! preg_match_all(self::PAX_MUSTER, $text, $treffer, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($treffer as $t) {
                $zahl = (int) ($t[1] !== '' ? $t[1] : ($t[2] ?? 0));

                if ($zahl >= 1) {
                    $werte[] = $zahl;
                }
            }
        }

        return $werte === [] ? null : min($werte);
    }

    /**
     * Schon vergebene Plaetze je Uhrzeit an einem Tag.
     *
     * Die Vermerke selbst zaehlen nicht mit - sie fuehren absichtlich eine
     * unsinnige Gaestezahl. Alles andere zaehlt, auch Raeume: die Kueche
     * unterscheidet nicht, ob eine Gesellschaft im Saal oder an Tisch 3 sitzt.
     *
     * @return array<string, int> Uhrzeit (HH:MM) => Personen
     */
    public static function belegungJeZeit(Carbon $date): array
    {
        $hausgroesse = self::hausgroesse();

        $reservierungen = Reservation::query()
            ->whereDate('reserve_date', $date->toDateString())
            ->where('status_id', '!=', (int) setting('canceled_reservation_status'))
            ->when($hausgroesse > 0, fn ($q) => $q->where('guest_num', '<=', $hausgroesse))
            ->get(['reserve_time', 'guest_num']);

        $belegt = [];
        foreach ($reservierungen as $r) {
            $zeit = Carbon::parse($r->reserve_time)->format('H:i');
            $belegt[$zeit] = ($belegt[$zeit] ?? 0) + (int) $r->guest_num;
        }

        return $belegt;
    }

    /**
     * Hoechstzahlen je Uhrzeit, wenn der Text sie einzeln nennt:
     * "11 Uhr max 60 PAX, 13 Uhr max 80 PAX".
     *
     * Die Zuordnung laeuft ueber die Reihenfolge im Text: eine Zahl gehoert zu
     * der Uhrzeit, die vor ihr steht. Damit das nicht raet, muss es aufgehen -
     * genau eine Zahl hinter jeder Uhrzeit. Sonst ist gar nichts zugeordnet und
     * es bleibt bei der einen Zahl fuer alle Gaenge (maxPax).
     *
     * Dadurch bedeutet "11 Uhr und 13 Uhr. MAX 60 PAX" weiterhin 60 fuer beide
     * und nicht etwa 60 nur fuer den zweiten Gang.
     *
     * @return array<string, int> Uhrzeit (HH:MM) => Hoechstzahl; leer, wenn es nicht aufgeht
     */
    public static function maxPaxJeZeit(iterable $vermerke): array
    {
        $plan = [];

        foreach ($vermerke as $vermerk) {
            if (! $text = trim((string) $vermerk->comment)) {
                continue;
            }

            preg_match_all(self::ZEIT_MUSTER, $text, $zeitTreffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            preg_match_all(self::PAX_MUSTER, $text, $paxTreffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            if ($zeitTreffer === [] || $paxTreffer === []) {
                continue;
            }

            $zeiten = [];
            foreach ($zeitTreffer as $t) {
                $stunde = (int) $t[1][0];
                $minute = isset($t[2]) && $t[2][0] !== '' ? (int) $t[2][0] : 0;

                if ($stunde <= 23 && $minute <= 59) {
                    $zeiten[] = [sprintf('%02d:%02d', $stunde, $minute), (int) $t[0][1]];
                }
            }

            $paare = [];
            foreach ($paxTreffer as $t) {
                $zahl = (int) ($t[1][0] !== '' ? $t[1][0] : ($t[2][0] ?? 0));
                $pos = (int) $t[0][1];

                if ($zahl < 1) {
                    continue;
                }

                $davor = null;
                foreach ($zeiten as [$zeit, $zpos]) {
                    if ($zpos < $pos) {
                        $davor = $zeit;
                    }
                }

                // Zahl vor der ersten Uhrzeit, oder zwei Zahlen an derselben:
                // nicht zuzuordnen, also gilt sie fuer alle Gaenge.
                if ($davor === null || isset($paare[$davor])) {
                    return [];
                }

                $paare[$davor] = $zahl;
            }

            // Es muss fuer jede genannte Uhrzeit eine Zahl geben, sonst raten wir.
            if (count($paare) !== count(array_unique(array_column($zeiten, 0)))) {
                return [];
            }

            foreach ($paare as $zeit => $zahl) {
                $plan[$zeit] = isset($plan[$zeit]) ? min($plan[$zeit], $zahl) : $zahl;
            }
        }

        ksort($plan);

        return $plan;
    }
}
