{{-- Texts are typed by staff and shown on a public page: only ever {{ }}, never {!! !!}. --}}
<div class="alert alert-info reservationcontrol-notice" role="note">
    @foreach ($notices as $notice)
        <p class="mb-0">{{ $notice }}</p>
    @endforeach
</div>
