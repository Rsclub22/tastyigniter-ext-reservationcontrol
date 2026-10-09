<?php

declare(strict_types=1);

namespace Wagnersnetz\ReservationControl;

use Carbon\Carbon;
use Igniter\Local\Classes\WorkingSchedule;
use Illuminate\Support\Collection;
use Igniter\Reservation\Classes\BookingManager;

/**
 * Reservierungen laufen grundsätzlich innerhalb der Öffnungszeiten
 * (Zeitplan "opening"). Ab einer bestimmten Gruppengröße gilt das nicht mehr,
 * weil größere Gesellschaften nach Absprache auch außerhalb bewirtet werden.
 */
class LargePartyBookingManager extends BookingManager
{
    /** Ab dieser Gästezahl gelten die Öffnungszeiten nicht mehr. */
    public const int LARGE_PARTY_FROM = 20;

    /** Zeitfenster, das großen Gesellschaften stattdessen angeboten wird. */
    public const string LARGE_PARTY_OPEN = '10:00';

    public const string LARGE_PARTY_CLOSE = '22:00';

    private ?int $forcedGuestCount = null;

    /**
     * Vorlauf und Horizont der oeffentlichen Buchung uebergehen - gesetzt von
     * der Telefonannahme.
     */
    private bool $intern = false;

    /**
     * Wie weit die Telefonannahme voraus buchen darf. Der oeffentliche Horizont
     * (derzeit 60 Tage) gilt dort nicht: Weihnachten und Silvester werden im
     * Herbst angenommen, und ein Tag ohne angebotene Zeiten ist am Telefon
     * nichts, was sich erklaeren laesst.
     */
    public const int INTERN_VORLAUF_TAGE = 365;

    private const array WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    public function getSchedule($days = null)
    {
        // Vorlauf: oeffentlich gilt die Standorteinstellung (derzeit 2 Tage
        // bis 60 Tage), an der Telefonannahme nicht - dort wird fuer heute
        // angenommen und ebenso fuer den Maerchenabend in zwei Monaten. Die
        // Sperre fuer bereits vergangene Uhrzeiten bleibt davon unberuehrt, die
        // steckt in makeTimeSlots().
        $days ??= $this->intern
            ? [0, self::INTERN_VORLAUF_TAGE]
            : [
                $this->location->getMinReservationAdvanceTime(),
                $this->location->getMaxReservationAdvanceTime(),
            ];

        if (!$this->isLargeParty()) {
            return parent::getSchedule($days);
        }

        $periods = [];
        foreach (self::WEEKDAYS as $weekday) {
            $periods[$weekday] = [[self::LARGE_PARTY_OPEN, self::LARGE_PARTY_CLOSE]];
        }

        $schedule = WorkingSchedule::create($days, $periods);
        // newWorkingSchedule() setzt den Typ ebenfalls; ohne ihn wirft getType().
        $schedule->setType('opening');

        // Dieser Zweig umgeht newWorkingSchedule() und damit das Ereignis, an dem
        // die Sperrtage sonst haengen - hier also von Hand nachziehen. Sonst
        // waere ein gesperrter Tag fuer grosse Gesellschaften weiter buchbar.
        if ($exceptions = BlockedDates::asScheduleExceptions()) {
            $schedule->setExceptions($exceptions);
        }

        return $schedule;
    }

    /**
     * Fuer grosse Gesellschaften gibt es keinen passenden Einzeltisch. Die
     * Belegungspruefung meldet dann *alle* Zeitfenster als ausgebucht
     * (Reservation::listFullyBookedTimeslots gibt bei 0 passenden Tischen die
     * komplette Liste zurueck). Solche Reservierungen laufen ohnehin ueber
     * Absprache, daher entfaellt die Pruefung.
     */
    public function isTimeslotsFullyBookedOn(Collection $timeslots, Carbon $date, ?int $noOfGuest = null): array
    {
        // Ein Vermerk, der den ganzen Tag beansprucht, schliesst die
        // Online-Buchung ganz - auch fuer grosse Gesellschaften, die gleich
        // darunter an der Tischpruefung vorbeilaufen. Ohne das koennte sich an
        // Weihnachten online eine Gesellschaft dazwischensetzen, obwohl der Tag
        // laengst verplant ist.
        //
        // Ein Vermerk neben den Oeffnungszeiten faellt nicht darunter: dessen
        // Zeiten sperren schon die belegten Tische, und der Mittagstisch
        // desselben Tages bleibt buchbar.
        if (Sperrvermerke::ganztaegige(Sperrvermerke::onDate($date), $date)->isNotEmpty()) {
            return $timeslots
                ->map(fn($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i'))->toDateTimeString())
                ->values()
                ->all();
        }

        // Was im Zeitfenster eines Vermerks liegt, ist vergeben - auch fuer
        // grosse Gesellschaften, die gleich darunter an der Tischpruefung
        // vorbeilaufen. Ohne das koennte sich online eine Gesellschaft in den
        // Maerchenabend setzen, der ausdruecklich nur am Telefon vergeben wird.
        $vermerke = Sperrvermerke::onDate($date);

        $verplant = $vermerke->isEmpty() ? [] : $timeslots
            ->map(fn($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
            ->filter(fn(Carbon $at): bool => Sperrvermerke::verplant($vermerke, $at))
            ->map(fn(Carbon $at) => $at->toDateTimeString())
            ->values()
            ->all();

        if ($this->isLargeParty()) {
            return $verplant;
        }

        $locationId = (int)$this->location->location_id;
        $guests = max(1, (int)$noOfGuest);
        $candidates = TableAllocator::candidates($locationId);

        // Passt die Gruppe in gar keinen Tisch, waere sonst jeder Slot gesperrt -
        // auch ohne eine einzige Buchung. Solche Gruppen platzieren wir von Hand.
        if ($candidates->isEmpty() || TableAllocator::pick($candidates, $guests) === null) {
            return $verplant;
        }

        $reservations = TableAllocator::reservationsOn($locationId, $date);
        $duration = (int)$this->location->getReservationStayTime();

        return $timeslots
            ->map(fn($slot) => $date->copy()->setTimeFromTimeString($slot->format('H:i')))
            ->filter(fn(Carbon $at): bool => TableAllocator::pick(
                TableAllocator::freeAt($candidates, $at, $duration, $reservations), $guests,
            ) === null)
            ->map(fn(Carbon $at) => $at->toDateTimeString())
            ->merge($verplant)
            ->unique()
            ->values()
            ->all();
    }

    /** Vorlauf und Horizont uebergehen - nur fuer die interne Telefonannahme. */
    public function allowSameDay(bool $allow = true): static
    {
        $this->intern = $allow;

        return $this;
    }

    /**
     * Die Telefonannahme laeuft ohne Livewire, dort gibt es keine Komponente,
     * aus der BookingContext die Gaestezahl lesen koennte. Sie wird deshalb
     * direkt gesetzt und hat dann Vorrang.
     */
    public function forceGuestCount(?int $guests): static
    {
        $this->forcedGuestCount = $guests;

        return $this;
    }

    public function isLargeParty(): bool
    {
        $guests = $this->forcedGuestCount ?? BookingContext::guestCount();

        return !is_null($guests) && $guests >= self::LARGE_PARTY_FROM;
    }
}
