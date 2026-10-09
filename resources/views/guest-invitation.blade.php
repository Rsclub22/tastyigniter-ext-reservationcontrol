{{-- The number is a setting of the restaurant: only ever {{ }}. --}}
<p class="reservationcontrol-invitation mb-3">
    {{ __('reservationcontrol::default.invitation_call') }}
    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $telephone) }}">{{ $telephone }}</a>
</p>
