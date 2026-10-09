<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use ReflectionProperty;
use Throwable;

/**
 * The notice a guest sees above the online booking form on a special day.
 *
 * Two sources keep a day or a window open for online booking: a blocked date
 * with its "online" flag (its hinweis is the text) and a closure note whose
 * comment carries "online buchbar" (the text after the colon). forDate() is the
 * one place that answers which text applies on a date.
 *
 * Staff type these texts and the booking form is public: the view escapes them.
 * Never print them with {!! !!}.
 */
final class GuestNotice
{
    private static bool $reported = false;

    /**
     * The texts that apply on a date (Y-m-d), possibly none.
     *
     * A day that is still blocked has no notice, whatever else is on it: it
     * cannot be booked, so a text would advertise something nobody can book.
     * On an online-open blocked day the blocked day's hinweis wins; without
     * one, the day's opted-in closure notes speak.
     *
     * @return list<string>
     */
    public static function forDate(string $date): array
    {
        $entry = BlockedDates::entries()[$date] ?? null;

        if ($entry !== null) {
            if (! $entry['online']) {
                return [];
            }

            if (($text = trim($entry['hinweis'])) !== '') {
                return [$text];
            }
        }

        $texts = [];
        foreach (ClosureNotes::onDate(Carbon::parse($date)) as $note) {
            if (($text = ClosureNotes::guestText($note)) !== '') {
                $texts[] = $text;
            }
        }

        return array_values(array_unique($texts));
    }

    /**
     * The Livewire 'render' listener. Deliberately tiny and total: it answers
     * with a callback that adds the notice, or with null, and it NEVER throws.
     *
     * The component belongs to a theme we do not control. Whatever goes wrong
     * here - an unexpected component shape, a database error, a broken view -
     * is logged and the page renders as if this extension were not installed:
     * a missing notice is an annoyance, a broken booking form costs guests.
     */
    public static function onRender(mixed $component): ?Closure
    {
        try {
            // Filled while the view renders (LargePartyBookingManager), read
            // below once it has: forget what an earlier render left behind.
            OnlineBlock::reset();
            self::$reported = false;

            if (($date = self::selectedDate($component)) === null) {
                return null;
            }

            $notice = self::guarded(static fn (): string => self::noticeHtml($component));
            $evenings = self::guarded(static fn (): string => self::eveningsHtml());
            $telephone = SpecialEvenings::telephone();

            if ($notice === '' && $evenings === '' && $telephone === '') {
                return null;
            }

            return static function (mixed $page = null) use ($date, $notice, $evenings, $telephone): ?string {
                try {
                    if (! is_string($page)) {
                        return null;
                    }

                    // Only now: the time slots - and with them what online
                    // booking removed - exist after the view has rendered.
                    $invitation = self::guarded(static fn (): string => self::invitationHtml($date, $telephone));

                    return ($html = $notice.$invitation.$evenings) === '' ? null : self::inject($page, $html);
                } catch (Throwable $e) {
                    self::report($e);

                    return null;
                }
            };
        } catch (Throwable $e) {
            self::report($e);

            return null;
        }
    }

    /** One part of the extras failing must not cost the others, let alone the page. */
    private static function guarded(Closure $part): string
    {
        try {
            return $part();
        } catch (Throwable $e) {
            self::report($e);

            return '';
        }
    }

    /** "Keine passende Zeit dabei? Rufen Sie uns an" - when online booking took a time away on $date. */
    public static function invitationHtml(string $date, string $telephone): string
    {
        if (($number = SpecialEvenings::invitation($date, $telephone)) === null) {
            return '';
        }

        /** @var view-string $view */
        $view = 'reservationcontrol::guest-invitation';

        return trim(view($view, ['telephone' => $number])->render());
    }

    /** The list of coming special evenings; '' when there are none. */
    public static function eveningsHtml(?Carbon $today = null): string
    {
        $location = SpecialEvenings::location();
        if ($location === null) {
            return '';
        }

        $evenings = SpecialEvenings::upcoming(
            $today ?? Carbon::today(),
            // Both come from the Location's LocationAction behaviour (__call), which phpstan cannot see.
            (int) $location->getMinReservationAdvanceTime(), // @phpstan-ignore method.notFound
            (int) $location->getMaxReservationAdvanceTime(), // @phpstan-ignore method.notFound
        );
        if ($evenings === []) {
            return '';
        }

        $format = (string) __('reservationcontrol::default.evenings_date_format');
        foreach ($evenings as &$evening) {
            $evening['label'] = Carbon::parse($evening['date'])->format($format);
        }
        unset($evening);

        /** @var view-string $view */
        $view = 'reservationcontrol::guest-evenings';

        return trim(view($view, ['evenings' => $evenings, 'telephone' => SpecialEvenings::telephone()])->render());
    }

    /**
     * The date the component shows, or null when it does not look like a
     * booking form: a Livewire component with an initialised public string
     * "date" (Y-m-d) next to a public "guest". Nothing else is read.
     */
    public static function selectedDate(mixed $component): ?string
    {
        if (! $component instanceof Component) {
            return null;
        }

        foreach (['date', 'guest'] as $name) {
            if (! property_exists($component, $name) || ! (new ReflectionProperty($component, $name))->isPublic()) {
                return null;
            }
        }

        $property = new ReflectionProperty($component, 'date');
        if (! $property->isInitialized($component)) {
            return null;
        }

        $date = $property->getValue($component);
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        // Strict: "2030-02-31" would otherwise overflow into March.
        return Carbon::createFromFormat('!Y-m-d', $date)?->toDateString() === $date ? $date : null;
    }

    /** The escaped notice markup for the component's date; '' when there is nothing to say. */
    public static function noticeHtml(mixed $component): string
    {
        if (($date = self::selectedDate($component)) === null) {
            return '';
        }

        if (($notices = self::forDate($date)) === []) {
            return '';
        }

        /** @var view-string $view */
        $view = 'reservationcontrol::guest-notice';

        return trim(view($view, ['notices' => $notices])->render());
    }

    /**
     * Puts the markup right inside the component's root element, as its first
     * child. A Livewire component must have exactly one root, so the notice
     * cannot sit beside it. Returns the page untouched when no plain opening
     * tag is found at the start.
     */
    public static function inject(string $page, string $notice): string
    {
        $root = '/\A\s*(?:<!--.*?-->\s*)*<[a-zA-Z][^\s\/>]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?)*\s*>/s';

        if (preg_match($root, $page, $match) !== 1) {
            return $page;
        }

        $end = strlen($match[0]);

        return substr($page, 0, $end).$notice.substr($page, $end);
    }

    private static function report(Throwable $e): void
    {
        // One line per render is enough; the parts usually fail for the same reason.
        if (self::$reported) {
            return;
        }
        self::$reported = true;

        try {
            Log::warning('reservationcontrol: guest notice skipped: '.$e->getMessage(), ['exception' => $e]);
        } catch (Throwable) {
            // Logging must not take the page down either.
        }
    }
}
