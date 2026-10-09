{{-- Texts are typed by staff and shown on a public page: only ever {{ }}, never {!! !!}. --}}
<div class="reservationcontrol-evenings mb-3">
    <p class="mb-1"><strong>{{ __('reservationcontrol::default.evenings_heading') }}</strong></p>
    <ul class="list-unstyled mb-0">
        @foreach ($evenings as $evening)
            <li>
                {{ $evening['label'] }} &ndash; {{ $evening['text'] }}
                @if ($evening['bookable'])
                    &ndash; <a href="?date={{ $evening['date'] }}">{{ __('reservationcontrol::default.evenings_book') }}</a>
                @else
                    &ndash; {{ __('reservationcontrol::default.evenings_by_phone') }}@if ($telephone !== ''): <a href="tel:{{ preg_replace('/[^0-9+]/', '', $telephone) }}">{{ $telephone }}</a>@endif
                @endif
            </li>
        @endforeach
    </ul>
</div>
