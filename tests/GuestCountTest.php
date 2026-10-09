<?php

declare(strict_types=1);

use Igniter\Orange\Livewire\Booking;
use Illuminate\Support\Facades\Validator;
use Wagnersnetz\ReservationControl\BookingContext;
use Wagnersnetz\ReservationControl\Contracts\GuestCountResolver;
use Wagnersnetz\ReservationControl\Extension;
use Wagnersnetz\ReservationControl\LargePartyBookingManager;
use Wagnersnetz\ReservationControl\Models\Settings;
use Wagnersnetz\ReservationControl\Theme\NullGuestCount;
use Wagnersnetz\ReservationControl\Theme\OrangeGuestCount;

afterEach(fn () => Settings::clearInternalCache());

it('reports no guest count without a theme integration', function (): void {
    app()->instance(GuestCountResolver::class, new NullGuestCount);

    expect(app(GuestCountResolver::class)->guestCount())->toBeNull();
});

it('is not a large party when the guest count is unknown', function (): void {
    app()->instance(GuestCountResolver::class, new NullGuestCount);

    expect(app(LargePartyBookingManager::class)->isLargeParty())->toBeFalse();
});

it('is not a large party, and does not throw, when no resolver is bound at all', function (): void {
    unset(app()[GuestCountResolver::class]);
    expect(app()->bound(GuestCountResolver::class))->toBeFalse();

    expect(app(LargePartyBookingManager::class)->isLargeParty())->toBeFalse();
});

it('accepts a resolver supplied by another theme', function (): void {
    app()->instance(GuestCountResolver::class, new class implements GuestCountResolver
    {
        public function guestCount(): ?int
        {
            return 40;
        }
    });

    expect(app(LargePartyBookingManager::class)->isLargeParty())->toBeTrue();
});

it('binds the Orange resolver when the Orange theme is installed', function (): void {
    expect(class_exists(Booking::class))->toBeTrue()
        ->and(app(GuestCountResolver::class))->toBeInstanceOf(OrangeGuestCount::class)
        ->and(app(GuestCountResolver::class)->guestCount())->toBeNull();
});

it('recognises the public booking form by default fields and by a configured list', function (): void {
    expect(Extension::publicFormFields())->toBe(['firstName', 'lastName', 'telephone']);

    expect(Settings::set('public_form_fields', "name\nphone"))->toBeTrue();
    Settings::clearInternalCache();
    expect(Extension::publicFormFields())->toBe(['name', 'phone']);

    expect(Settings::set('public_form_fields', "name\nbad field!"))->toBeTrue();
    Settings::clearInternalCache();
    expect(Extension::publicFormFields())->toBe(['firstName', 'lastName', 'telephone']);
});

it('reads the guest count of the running Orange component', function (): void {
    $component = (new ReflectionClass(Booking::class))->newInstanceWithoutConstructor();
    $component->guest = 25;
    BookingContext::remember($component);

    try {
        expect(app(GuestCountResolver::class)->guestCount())->toBe(25)
            ->and(app(LargePartyBookingManager::class)->isLargeParty())->toBeTrue();
    } finally {
        BookingContext::forget($component);
    }

    expect(app(GuestCountResolver::class)->guestCount())->toBeNull();
});

it('tightens the telephone rule on the form recognised by the configured fields', function (): void {
    $rules = fn () => array_keys(Validator::make([], ['name' => 'nullable', 'phone' => 'nullable'])->getRules());

    expect($rules())->not->toContain('telephone');

    expect(Settings::set('public_form_fields', "name\nphone"))->toBeTrue();
    Settings::clearInternalCache();

    $validator = Validator::make([], ['name' => 'nullable', 'phone' => 'nullable', 'telephone' => 'nullable']);
    expect($validator->getRules()['telephone'])->toContain('required');
});

it('knows no guest count when the remembered component is not an Orange booking', function (): void {
    $foreign = new class
    {
        public int $guest = 99;
    };
    BookingContext::remember($foreign);

    try {
        expect(app(GuestCountResolver::class)->guestCount())->toBeNull();
    } finally {
        BookingContext::forget($foreign);
    }
});
