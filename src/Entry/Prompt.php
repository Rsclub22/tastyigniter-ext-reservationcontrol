<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl\Entry;

use Carbon\Carbon;
use Igniter\Admin\Models\Status;

/**
 * Turn free text from keyboard and spreadsheet into values.
 *
 * The public form gets its values from a date picker and a select field, which
 * need nothing of this. On the phone and in a list exported from Excel, by
 * contrast, you find "20.9.", "18 Uhr", "Mueller, Hans" - hence this class.
 *
 * The literals that are matched against input stay German (plus their English
 * equivalents where they already existed): they are what people actually type
 * and what the column headers of a German list contain. Adding or removing one
 * of them would change which input is understood, which is not part of a
 * rename.
 */
class Prompt
{
    /** Comparison form: lower case, without umlauts, without punctuation. */
    public static function normalized(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a',
        ]);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /** First number in the text. "5 Personen" and "ca. 12" yield 5 and 12. */
    public static function number(string $value): ?int
    {
        return preg_match('/(\d+)/', $value, $matches) ? (int) $matches[1] : null;
    }

    /**
     * Date as Y-m-d. Allows "heute"/"today", "morgen"/"tomorrow",
     * "uebermorgen", ISO, DD.MM.YYYY, DD.MM.YY and DD.MM. (then the next
     * occurrence).
     */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $days = ['heute' => 0, 'today' => 0, 'morgen' => 1, 'tomorrow' => 1, 'uebermorgen' => 2];
        if (array_key_exists($key = self::normalized($value), $days)) {
            return date('Y-m-d', strtotime('+'.$days[$key].' days'));
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return self::validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})\.?$#', $value, $m)) {
            $year = (int) $m[3];

            return self::validDate($year < 100 ? $year + 2000 : $year, (int) $m[2], (int) $m[1]);
        }

        // Without a year, take the next occurrence: on the phone in December
        // people book for January without saying the year.
        if (preg_match('#^(\d{1,2})[./-](\d{1,2})\.?$#', $value, $m)) {
            foreach ([(int) date('Y'), (int) date('Y') + 1] as $year) {
                $date = self::validDate($year, (int) $m[2], (int) $m[1]);
                if ($date !== null && $date >= date('Y-m-d')) {
                    return $date;
                }
            }
        }

        return null;
    }

    /** Time as H:i. Allows "18:30", "18.30", "1830", "18", "18 Uhr". */
    public static function time(string $value): ?string
    {
        $value = trim(str_ireplace('uhr', '', $value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[:.\s]?(\d{2})$/', $value, $m)) {
            [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{1,2})$/', $value, $m)) {
            [$hour, $minute] = [(int) $m[1], 0];
        } else {
            return null;
        }

        return ($hour > 23 || $minute > 59) ? null : sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Split first and last name.
     *
     * @return array{0: string, 1: string} [first name, last name]
     */
    public static function name(string $full, string $firstName = '', string $lastName = ''): array
    {
        // Separate columns win. If only one of them is filled, that is the last
        // name: in other people's lists the columns are rarely named the way
        // they are meant.
        if ($firstName !== '' || $lastName !== '') {
            return [$firstName, $lastName !== '' ? $lastName : $firstName];
        }

        $full = trim(preg_replace('/\s+/', ' ', $full) ?? '');
        if ($full === '') {
            return ['', ''];
        }

        if (str_contains($full, ',')) {
            [$last, $first] = array_map('trim', explode(',', $full, 2));

            return [$first, $last];
        }

        $parts = explode(' ', $full);
        if (count($parts) === 1) {
            return ['', $parts[0]];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * "Sa, 19.09.2026" - in day-to-day business people look for the weekday.
     *
     * Weekday names and the order of the parts follow the current locale.
     */
    public static function longDate(string $date): string
    {
        return Carbon::parse($date)
            ->locale(app()->getLocale())
            ->isoFormat(__('reservationcontrol::default.format_console_date'));
    }

    /** Reservation status: name, short form or id. */
    public static function status(string $value): ?int
    {
        // Short forms a person types, mapped onto the normalised status names
        // as they stand in the database of a German installation.
        $short = [
            'bestaetigt' => 'bestaetigt', 'bestatigt' => 'bestaetigt', 'confirmed' => 'bestaetigt',
            'ok' => 'bestaetigt', 'ja' => 'bestaetigt', 'fix' => 'bestaetigt', 'b' => 'bestaetigt',
            'ausstehend' => 'ausstehend', 'offen' => 'ausstehend', 'pending' => 'ausstehend', 'a' => 'ausstehend',
            'storniert' => 'storniert', 'abgesagt' => 'storniert', 'canceled' => 'storniert',
            'cancelled' => 'storniert', 'nein' => 'storniert', 's' => 'storniert',
        ];

        $available = self::statusList();
        $key = $short[self::normalized($value)] ?? self::normalized($value);

        if (isset($available[$key])) {
            return $available[$key];
        }

        $value = trim($value);

        return (ctype_digit($value) && in_array((int) $value, $available, true)) ? (int) $value : null;
    }

    /** @return array<string, int> normalised status name => id */
    public static function statusList(): array
    {
        static $list = null;

        if ($list === null) {
            $list = Status::query()
                ->where('status_for', 'reservation')
                ->get()
                ->mapWithKeys(fn (Status $s): array => [self::normalized((string) $s->status_name) => (int) $s->getKey()])
                ->all();
        }

        return $list;
    }

    /**
     * Occasion. The order is the one of Reservation::getOccasionOptions(), the
     * index is the stored value - index 0 is "no occasion".
     */
    public static function occasion(string $value): ?int
    {
        return [
            'geburtstag' => 1, 'birthday' => 1,
            'jubilaeum' => 2, 'hochzeitstag' => 2, 'anniversary' => 2,
            'feier' => 3, 'feierlichkeit' => 3, 'celebration' => 3,
            'junggesellinnenabschied' => 4,
            'junggesellenabschied' => 5, 'jga' => 5,
        ][self::normalized($value)] ?? null;
    }

    private static function validDate(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
