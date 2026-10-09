@php($l = 'reservationcontrol::default.')
@php($loc = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $loc) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __($l.'intern_title') }} – {{ $standort->location_name }}</title>
    <style>
        :root {
            --braun: #60210F;
            --braun-hell: #8C4A32;
            --grund: #FAF7F4;
            --flaeche: #FFFFFF;
            --linie: #E4DAD3;
            --text: #2A1C16;
            --grau: #7A6A62;
            --frei: #3F6B4A;
            --eng: #B8860B;
            --voll: #A8321F;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--grund); color: var(--text);
            font: 16px/1.5 "Segoe UI", system-ui, sans-serif;
        }
        .kopf {
            background: var(--braun); color: #fff;
            padding: 14px 20px; display: flex; flex-wrap: wrap;
            align-items: baseline; gap: 12px;
        }
        .kopf h1 { margin: 0; font-size: 1.15rem; font-weight: 600; }
        .kopf .ort { opacity: .8; font-size: .9rem; }
        .kopf .nur-intern {
            margin-left: auto; font-size: .72rem; letter-spacing: .08em;
            text-transform: uppercase; background: rgba(255,255,255,.15);
            padding: 3px 9px; border-radius: 3px;
        }
        .huelle { max-width: 1100px; margin: 0 auto; padding: 20px 16px 64px; }
        .melder {
            background: #E8F0E9; border-left: 4px solid var(--frei);
            padding: 14px 16px; margin-bottom: 20px; border-radius: 3px;
        }
        .melder strong { font-size: 1.05rem; }
        .quittung {
            display: flex; align-items: flex-start; gap: 14px;
            background: #E8F0E9; border: 2px solid var(--frei);
            padding: 16px 18px; margin-bottom: 20px; border-radius: 4px;
        }
        .quittung .haken {
            flex: 0 0 auto; width: 38px; height: 38px; border-radius: 50%;
            background: var(--frei); color: #fff; font-size: 1.4rem;
            display: flex; align-items: center; justify-content: center;
        }
        .quittung-text { flex: 1 1 auto; }
        .quittung strong { display: block; font-size: 1.15rem; color: #2F5136; }
        .quittung .gross { margin-top: 4px; font-size: 1.1rem; }
        .quittung .zeilen { margin-top: 4px; color: #3D5742; }
        .quittung .klein { font-size: .85rem; color: #6A7A6E; }
        .quittung .schliessen {
            flex: 0 0 auto; align-self: center; font-size: .85rem;
            color: #3D5742; text-decoration: underline;
        }
        tr.soeben { background: #E8F0E9; }
        .frisch {
            margin-left: 8px; font-size: .7rem; text-transform: uppercase;
            letter-spacing: .04em; background: var(--frei); color: #fff;
            padding: 2px 7px; border-radius: 3px; white-space: nowrap;
        }
        .melder .zeilen { margin-top: 6px; color: #2F5136; }
        .fehler {
            background: #FBEAE7; border-left: 4px solid var(--voll);
            padding: 12px 16px; margin-bottom: 20px; border-radius: 3px;
        }
        .fehler ul { margin: 6px 0 0; padding-left: 18px; }
        h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .09em;
             color: var(--grau); margin: 0 0 10px; font-weight: 700; }
        .karte {
            background: var(--flaeche); border: 1px solid var(--linie);
            border-radius: 4px; padding: 16px; margin-bottom: 20px;
        }
        .tagwahl { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .tagwahl a, .tagwahl .heute {
            padding: 7px 13px; border: 1px solid var(--linie); border-radius: 3px;
            text-decoration: none; color: var(--text); background: var(--flaeche);
        }
        .tagwahl a.aktiv { background: var(--braun); color: #fff; border-color: var(--braun); }
        .tagwahl input[type=date] {
            padding: 6px 9px; border: 1px solid var(--linie); border-radius: 3px;
            font: inherit; color: var(--text);
        }
        .schlitze { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 8px; }
        .schlitz {
            display: block; text-align: left; padding: 10px 12px; border-radius: 3px;
            border: 1px solid var(--linie); background: var(--flaeche);
            cursor: pointer; font: inherit; color: var(--text);
        }
        .schlitz:hover:not(:disabled) { border-color: var(--braun-hell); }
        .schlitz .uhr { font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; }
        .schlitz .lage { font-size: .78rem; color: var(--grau); margin-top: 2px; }
        .schlitz.gewaehlt { background: var(--braun); border-color: var(--braun); color: #fff; }
        .schlitz.gewaehlt .lage { color: rgba(255,255,255,.85); }
        .schlitz.knapp { border-left: 4px solid var(--eng); }
        .schlitz:disabled { opacity: .5; cursor: not-allowed; background: #F4EFEC; }
        .schlitz:disabled .lage { color: var(--voll); }
        .leer { color: var(--grau); font-style: italic; }
        .felder { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; }
        label { display: block; font-size: .82rem; color: var(--grau); margin-bottom: 4px; font-weight: 600; }
        label .pflicht { color: var(--voll); }
        input[type=text], input[type=tel], input[type=email], input[type=number], select, textarea {
            width: 100%; padding: 9px 11px; border: 1px solid var(--linie);
            border-radius: 3px; font: inherit; background: var(--flaeche); color: var(--text);
        }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid var(--braun-hell); outline-offset: 1px; border-color: var(--braun-hell);
        }
        textarea { resize: vertical; min-height: 62px; }
        .absenden {
            margin-top: 18px; background: var(--braun); color: #fff; border: 0;
            padding: 13px 26px; font: 600 1.05rem/1 inherit; border-radius: 3px; cursor: pointer;
        }
        .absenden:hover { background: #4A190C; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th { text-align: left; font-size: .74rem; text-transform: uppercase;
             letter-spacing: .07em; color: var(--grau); padding: 6px 8px; border-bottom: 1px solid var(--linie); }
        td { padding: 8px; border-bottom: 1px solid var(--linie); vertical-align: top; }
        td.uhr { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
        .hinweis { color: var(--grau); font-size: .85rem; margin-top: 10px; }
        .zahlen { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 12px; font-size: .88rem; color: var(--grau); }
        .zahlen b { color: var(--text); font-variant-numeric: tabular-nums; }
        .sperrzeile { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--linie); }
        .sperrform { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .knopf-klein {
            padding: 7px 14px; border: 1px solid var(--braun-hell); background: var(--flaeche);
            color: var(--braun); border-radius: 3px; font: 600 .88rem/1 inherit; cursor: pointer;
        }
        .knopf-klein:hover { background: var(--braun); color: #fff; }
        .marke {
            font-size: .78rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
            padding: 4px 10px; border-radius: 3px;
        }
        .marke.gesperrt { background: #FBEAE7; color: var(--voll); }
        .vermerk {
            border: 2px solid var(--braun); border-radius: 4px;
            background: var(--flaeche); overflow: hidden; margin-bottom: 20px;
        }
        .vermerk-kopf {
            background: var(--braun); color: #fff; padding: 8px 14px;
            font-size: .76rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; display: flex; gap: 12px; align-items: baseline;
        }
        .vermerk-kopf .wann { margin-left: auto; letter-spacing: .04em; }
        .vermerk-text {
            padding: 14px 14px 10px; font-size: 1.15rem; font-weight: 700;
            line-height: 1.35;
        }
        .vermerk-fuss { padding: 0 14px 12px; font-size: .85rem; color: var(--grau); }

        .karte-kopf {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 12px; margin-bottom: 10px;
        }
        .karte-kopf h2 { margin: 0; }
        .drucken {
            margin-left: auto; display: flex; flex-direction: column;
            align-items: flex-end; gap: 6px;
            font-size: .85rem; color: var(--grau);
        }
        .drucken .zeile { display: flex; align-items: center; gap: 8px; }
        .drucken input[type=date] {
            font: inherit; padding: 5px 7px;
            border: 1px solid var(--linie); border-radius: 3px; background: #fff;
        }
        .drucken input[type=text] {
            font: inherit; padding: 5px 7px; text-align: center;
            border: 1px solid var(--linie); border-radius: 3px; background: #fff;
        }
        .drucken button {
            font: inherit; padding: 6px 14px; cursor: pointer;
            border: 1px solid var(--braun); border-radius: 3px;
            background: var(--braun); color: #fff;
        }
    </style>
</head>
<body>

<div class="kopf">
    <h1>{{ __($l.'intern_title') }}</h1>
    <span class="ort">{{ $standort->location_name }}</span>
    <span class="nur-intern">{{ __($l.'intern_internal_only') }}</span>
</div>

<div class="huelle">

    {{-- Sperrvermerk zuerst: was hier steht, muss man gelesen haben, bevor man
         eine Zeit anklickt. Deshalb ueber allem anderen und nicht als Fussnote
         bei der Belegung. --}}
    @foreach ($vermerke as $v)
        <div class="vermerk">
            <div class="vermerk-kopf">
                <span>{{ __($l.'note_heading') }}</span>
                <span class="wann">{{ \Carbon\Carbon::parse($v->reserve_time)->format('H:i') }}–{{ $v->reservation_end_datetime->format('H:i') }}</span>
            </div>
            @if ($v->comment)
                <div class="vermerk-text">{{ $v->comment }}</div>
            @endif
            <div class="vermerk-fuss">
                @if ($ganztags->contains($v))
                    {{ __($l.'note_all_day') }}
                    @if ($vermerkZeiten)
                        {{ __($l.'note_only_named_times', ['times' => implode(' '.__($l.'word_and').' ', $vermerkZeiten)]) }}
                    @else
                        {{ __($l.'note_usual_times') }}
                    @endif
                    {{ __($l.'note_without_table') }}
                @else
                    {{ __($l.'note_partial') }}
                    @if ($vermerkZeiten)
                        {{ __($l.'note_partial_phone', ['times' => __($l.'format_times', ['times' => implode(' '.__($l.'word_and').' ', $vermerkZeiten)])]) }}
                    @endif
                @endif
                @if ($paxJeZeit)
                    @php($eintraege = collect($paxJeZeit)->map(fn ($zahl, $zeit) => __($l.'max_pax_entry', ['time' => $zeit, 'count' => $zahl]))->implode(', '))
                    <strong>{{ __($l.'max_pax_at_times', ['entries' => $eintraege]) }}</strong>
                @elseif ($maxPax)
                    <strong>{{ __($l.'max_pax_per_time', ['count' => $maxPax]) }}</strong>
                @endif
                &middot; {{ __($l.'note_do_not_cancel', ['id' => $v->getKey()]) }}
            </div>
        </div>
    @endforeach


    @if ($neu)
        @php($neuTisch = $neu->tables->pluck('name')->implode(', '))
        <div class="quittung">
            <div class="haken">&#10003;</div>
            <div class="quittung-text">
                <strong>{{ __($l.'receipt_title') }}</strong>
                <div class="gross">
                    {{ trim($neu->first_name.' '.$neu->last_name) }} &middot;
                    {{ trans_choice($l.'count_persons', (int) $neu->guest_num) }} &middot;
                    {{ __($l.'receipt_when', [
                        'date' => \Carbon\Carbon::parse($neu->reserve_date)->locale($loc)->isoFormat(__($l.'format_weekday_date')),
                        'time' => \Carbon\Carbon::parse($neu->reserve_time)->format('H:i'),
                    ]) }}
                </div>
                <div class="zeilen">
                    {{ __($l.'receipt_number', ['id' => $neu->getKey()]) }} &middot; {{ __($l.'receipt_status_confirmed') }} &middot;
                    @if ($neuTisch)
                        {{ $neuTisch }}
                    @else
                        <em>{{ __($l.'receipt_no_table') }}</em>
                    @endif
                    @if ($neu->telephone) &middot; {{ $neu->telephone }} @endif
                </div>
                <div class="zeilen klein">{{ __($l.'receipt_no_mail') }}</div>
            </div>
            <a class="schliessen" href="/intern?datum={{ $datum->toDateString() }}&gaeste={{ $gaeste }}">{{ __($l.'action_close') }}</a>
        </div>
    @endif

    @if ($h = session('hinweis'))
        <div class="melder" style="background:#F3EEEA;border-left-color:#8C4A32">
            <strong>{{ $h }}</strong>
        </div>
    @endif

    @if ($errors->any())
        <div class="fehler">
            <strong>{{ __($l.'errors_check') }}</strong>
            <ul>@foreach ($errors->all() as $fehler)<li>{{ $fehler }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="karte">
        <h2>{{ __($l.'section_day') }}</h2>
        <form method="get" class="tagwahl">
            @php($heute = \Carbon\Carbon::today())
            @for ($i = 0; $i < 5; $i++)
                @php($t = $heute->copy()->addDays($i))
                <a href="/intern?datum={{ $t->toDateString() }}&gaeste={{ $gaeste }}"
                   class="{{ $t->isSameDay($datum) ? 'aktiv' : '' }}">
                    {{ $i === 0 ? __($l.'intern_today') : ($i === 1 ? __($l.'intern_tomorrow') : $t->locale($loc)->isoFormat(__($l.'format_weekday_short_date'))) }}
                </a>
            @endfor
            <input type="date" name="datum" value="{{ $datum->toDateString() }}" onchange="this.form.submit()">
            <label for="gaeste-wahl" style="margin:0 0 0 10px">{{ __($l.'label_persons') }}</label>
            <input type="number" id="gaeste-wahl" name="gaeste" min="1" max="200" value="{{ $gaeste }}"
                   style="width:80px" onchange="this.form.submit()">
            @if ($raeume->isNotEmpty())
                <label for="raum-wahl" style="margin:0 0 0 10px">{{ __($l.'label_room') }}</label>
                <select id="raum-wahl" name="raum" style="width:auto" onchange="this.form.submit()">
                    <option value="">{{ __($l.'option_table_automatic') }}</option>
                    @foreach ($raeume as $r)
                        <option value="{{ $r->id }}" @selected($raum && $raum->id === $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>
            @endif
        </form>

        <div class="sperrzeile">
            @if ($gesperrt)
                <form method="post" action="/intern/freigeben" class="sperrform">
                    @csrf
                    <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
                    <span class="marke gesperrt">{{ __($l.'day_blocked_label') }}{{ $grund ? " – ".$grund : "" }}</span>
                    <button type="submit" class="knopf-klein">{{ __($l.'action_lift_block') }}</button>
                </form>
            @else
                <form method="post" action="/intern/sperren" class="sperrform">
                    @csrf
                    <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
                    <input type="text" name="grund" placeholder="{{ __($l.'placeholder_block_reason') }}" style="max-width:280px">
                    <button type="submit" class="knopf-klein">{{ __($l.'action_block_day') }}</button>
                </form>
            @endif
        </div>

        @if (!empty($sperren))
            <p class="hinweis" style="margin-top:10px">
                {{ __($l.'blocked_list') }}
                @foreach ($sperren as $tag => $grund)<a href="/intern?datum={{ $tag }}"
                    >{{ \Carbon\Carbon::parse($tag)->locale($loc)->isoFormat(__($l.'format_weekday_short_date')) }}</a>@if ($grund) ({{ $grund }})@endif{{ !$loop->last ? ' · ' : '' }}@endforeach
            </p>
        @endif
    </div>

    <div class="karte">
        <h2>{{ __($l.'occupancy_on', ['date' => $datum->locale($loc)->isoFormat(__($l.'format_weekday_date'))]) }}</h2>
        <div class="zahlen">
            <span><b>{{ $tischeGesamt }}</b> {{ __($l.'stat_tables') }}</span>
            <span><b>{{ $plaetzeGesamt }}</b> {{ __($l.'stat_seats_total') }}</span>
            <span><b>{{ count($reservierungen) }}</b> {{ __($l.'stat_reservations_day') }}</span>
        </div>

        @if ($gesperrt)
            <p class="leer">{{ __($l.'day_blocked_message', ['reason' => $grund ? ' – '.$grund : '']) }}</p>
        @elseif (empty($belegung))
            <p class="leer">{{ __($l.'day_closed_message') }}</p>
        @else
            <div class="schlitze">
                @foreach ($belegung as $s)
                    <button type="submit" form="annahme" name="zeit" value="{{ $s['zeit'] }}"
                            class="schlitz {{ $s['frei'] === 0 ? '' : ($s['frei'] <= 2 ? 'knapp' : '') }}"
                            {{ $s['passt'] ? '' : 'disabled' }}
                            @if ($s['pax_max'])
                                title="{{ $s['passt']
                                    ? __($l.'slot_title_fits_max', ['used' => $s['pax_belegt'] + $gaeste, 'max' => $s['pax_max']])
                                    : __($l.'slot_title_not_enough', ['free' => $s['frei'], 'needed' => $gaeste]) }}"
                            @else
                                title="{{ $s['passt'] ? __($l.'slot_title_take') : __($l.'slot_title_no_table') }}"
                            @endif
                            >
                        <span class="uhr">{{ $s['zeit'] }}</span>
                        <span class="lage">
                            @if ($s['pax_max'])
                                {{ __($l.'slot_pax', ['used' => $s['pax_belegt'], 'max' => $s['pax_max']]) }}@if (!$s['passt']) · {{ __($l.'slot_full') }} @endif
                            @elseif (!$s['passt'])
                                {{ __($l.'slot_taken') }}
                            @elseif ($s['ohne_tisch'])
                                {{ __($l.'slot_no_table') }}
                            @elseif ($s['raum'])
                                {{ __($l.'slot_room_free', ['room' => $s['raum']]) }}
                            @else
                                {{ __($l.'slot_tables', ['free' => $s['frei'], 'total' => $s['gesamt'], 'largest' => $s['groesster']]) }}
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
            <p class="hinweis">
                {{ __($l.'hint_click_time') }}
                @if (!$raum && $vermerke->isNotEmpty())
                    @if ($ganztags->isNotEmpty())
                        {{ __($l.'hint_no_table_day') }}
                        @if ($vermerkZeiten)
                            {{ __($l.'hint_only_noted_times') }}
                        @endif
                    @else
                        {{ __($l.'hint_note_times_no_table') }}
                    @endif
                    {{-- Gilt in beiden Faellen: die Zahl steht unter jeder Uhrzeit,
                         die ein Vermerk deckelt. --}}
                    @if ($maxPax || $paxJeZeit)
                        {{ __($l.'hint_pax_under_time', ['persons' => trans_choice($l.'count_persons', $gaeste)]) }}
                    @endif
                @elseif ($raum)
                    {{ __($l.'hint_grey_room', ['room' => $raum->name]) }}
                @else
                    {{ __($l.'hint_grey_table', ['persons' => trans_choice($l.'count_persons', $gaeste)]) }}
                @endif
            </p>
        @endif
    </div>

    <div class="karte">
        <h2>{{ __($l.'section_guest') }}</h2>
        <form method="post" id="annahme" action="/intern">
            @csrf
            <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
            <input type="hidden" name="gaeste" value="{{ $gaeste }}">
            <input type="hidden" name="raum" value="{{ $raum?->id }}">

            <div class="felder">
                <div>
                    <label for="nachname">{{ __($l.'attribute_last_name') }} <span class="pflicht">*</span></label>
                    <input type="text" id="nachname" name="nachname" value="{{ old('nachname') }}" autofocus required>
                </div>
                <div>
                    <label for="telefon">{{ __($l.'attribute_telephone') }} <span class="pflicht">*</span></label>
                    <input type="tel" id="telefon" name="telefon" value="{{ old('telefon') }}" required>
                </div>
                <div>
                    <label for="email">{{ __($l.'attribute_email') }} <span style="font-weight:400">{{ __($l.'label_optional') }}</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}">
                </div>
            </div>

            <div style="margin-top:14px">
                <label for="notiz">{{ __($l.'attribute_note') }} <span style="font-weight:400">{{ __($l.'label_optional') }}</span></label>
                <textarea id="notiz" name="notiz">{{ old('notiz') }}</textarea>
            </div>

            <button type="submit" class="absenden" name="zeit" value="">{{ __($l.'action_accept') }}</button>
            <p class="hinweis">
                {!! __($l.'hint_saved_confirmed') !!}
                @if ($raum)
                    {!! __($l.'hint_room_assigned', ['room' => e($raum->name)]) !!}
                @else
                    {{ __($l.'hint_table_automatic') }}
                @endif
                {!! __($l.'hint_no_mail') !!}
                {{ __($l.'hint_time_missing') }}
            </p>
        </form>
    </div>

    @if ($raeume->isNotEmpty())
        <div class="karte">
            <h2>{{ __($l.'rooms_on', ['date' => $datum->locale($loc)->isoFormat(__($l.'format_day_month'))]) }}</h2>
            <table>
                <thead><tr><th>{{ __($l.'col_room') }}</th><th>{{ __($l.'col_occupied') }}</th></tr></thead>
                <tbody>
                @foreach ($raeume as $r)
                    @php($belegungen = $raumBelegung->filter(fn($x) => $x->tables->pluck('id')->contains($r->id)))
                    <tr>
                        <td><a href="/intern?datum={{ $datum->toDateString() }}&gaeste={{ $gaeste }}&raum={{ $r->id }}">{{ $r->name }}</a></td>
                        <td>
                            @forelse ($belegungen as $b)
                                <div>
                                    {{ \Carbon\Carbon::parse($b->reserve_time)->format('H:i') }}–{{ $b->reservation_end_datetime->format('H:i') }}
                                    · {{ trim($b->first_name.' '.$b->last_name) }} ({{ __($l.'persons_short', ['count' => $b->guest_num]) }})
                                </div>
                            @empty
                                <span class="leer">{{ __($l.'room_free') }}</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="hinweis">
                {{ __($l.'rooms_hint') }}
            </p>
        </div>
    @endif

    <div class="karte">
        <div class="karte-kopf">
            <h2>{{ __($l.'reservations_on', ['date' => $datum->locale($loc)->isoFormat(__($l.'format_day_month'))]) }}</h2>
            {{-- Tagesblatt zum Abheften. Die Trennzeit steht hier und nicht nur
                 in der .env: an Weihnachten gibt es nur zwei Sitzungen, deren
                 Grenze liegt woanders - das muss man im Moment des Druckens
                 aendern koennen, ohne an den Server zu muessen. --}}
            <form class="drucken" method="get" action="/intern/druck" target="_blank">
                <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
                <span class="zeile">
                    <label for="trennzeit">{{ __($l.'print_second_sheet_from') }}</label>
                    <input type="text" id="trennzeit" name="trennzeit" value="{{ $trennzeit }}"
                           size="5" inputmode="numeric" placeholder="15:00"
                           title="{{ __($l.'print_split_title') }}">
                    <button type="submit" name="modus" value="tag">{{ __($l.'print_day') }}</button>
                </span>
                <span class="zeile">
                    <label for="von">{{ __($l.'print_period') }}</label>
                    <input type="date" id="von" name="von" value="{{ $datum->toDateString() }}">
                    <span>–</span>
                    <input type="date" id="bis" name="bis" value="{{ $datum->copy()->addDays(6)->toDateString() }}">
                    <button type="submit" name="modus" value="zeitraum"
                            title="{{ __($l.'print_period_title') }}">{{ __($l.'print_period_action') }}</button>
                </span>
            </form>
        </div>
        @if ($reservierungen->isEmpty())
            <p class="leer">{{ __($l.'reservations_none_yet') }}</p>
        @else
            <table>
                <thead>
                <tr><th>{{ __($l.'col_time') }}</th><th>{{ __($l.'col_name') }}</th><th>{{ __($l.'col_persons') }}</th><th>{{ __($l.'col_table') }}</th><th>{{ __($l.'col_telephone') }}</th><th>{{ __($l.'col_note') }}</th></tr>
                </thead>
                <tbody>
                @foreach ($reservierungen as $r)
                    <tr class="{{ $neu && $neu->getKey() === $r->getKey() ? 'soeben' : '' }}">
                        <td class="uhr">{{ \Carbon\Carbon::parse($r->reserve_time)->format('H:i') }}</td>
                        <td>{{ trim($r->first_name.' '.$r->last_name) }}@if ($neu && $neu->getKey() === $r->getKey()) <span class="frisch">{{ __($l.'just_accepted') }}</span>@endif</td>
                        <td>{{ $r->guest_num }}</td>
                        <td>{{ $r->tables->pluck('name')->implode(', ') ?: '—' }}</td>
                        <td>{{ $r->telephone ?: '—' }}</td>
                        <td>{{ $r->comment ?: '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
</body>
</html>
