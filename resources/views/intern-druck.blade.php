{{--
    Tagesblatt zum Abheften. Bewusst eine eigene, sehr schlichte Seite statt
    eines Druck-Stylesheets ueber der Telefonannahme: dort stehen Belegung,
    Formular und Sperren, die auf Papier nur Platz kosten.

    Aufbau: $tage sind die zu druckenden Tage, jeder Tag hat ein oder zwei
    "Blaetter" (Abschnitte, siehe Trennzeit im Controller). Leere Abschnitte
    kommen gar nicht erst hier an - wer mittags zu hat, soll kein leeres
    Mittagsblatt im Hefter haben.
--}}
@php
    $l = 'reservationcontrol::default.';
    $loc = app()->getLocale();
    $titel = $sammeldruck
        ? $von->locale($loc)->isoFormat(__($l.'format_range_from')) . '–' . $bis->locale($loc)->isoFormat(__($l.'format_range_to'))
        : $von->locale($loc)->isoFormat(__($l.'format_weekday_date_year'));
    // Ohne Tage wird trotzdem ein Blatt gedruckt - das mit dem Hinweis darauf.
    $blattZahl = max(1, collect($tage)->sum(fn(array $t): int => max(1, count($t['blaetter']))));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $loc) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $titel }} – {{ $standort->location_name }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm 11mm 10mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0; padding: 0; background: #F2EFEC; color: #000;
            font: 11pt/1.35 "Segoe UI", system-ui, sans-serif;
        }

        /* Die Leiste ist nur zum Nachdrucken da und gehoert nicht aufs Papier. */
        .leiste {
            background: #60210F; color: #fff; padding: 10px 16px;
            display: flex; gap: 14px; align-items: center; font-size: 10pt;
            position: sticky; top: 0;
        }
        .leiste a, .leiste button {
            color: #fff; background: rgba(255,255,255,.18); border: 0;
            padding: 6px 14px; border-radius: 3px; font: inherit;
            text-decoration: none; cursor: pointer;
        }
        .leiste .fuellen { margin-left: auto; opacity: .75; }

        .blatt {
            background: #fff; width: 210mm; min-height: 297mm;
            margin: 14px auto; padding: 12mm 11mm 10mm;
            box-shadow: 0 1px 6px rgba(0,0,0,.18);
        }

        .kopf {
            display: flex; align-items: flex-start; gap: 12mm;
            border-bottom: 2pt solid #000; padding-bottom: 3mm; margin-bottom: 4mm;
        }
        .kopf .tag { font-size: 17pt; font-weight: 700; line-height: 1.15; }
        .kopf .ort { font-size: 10pt; margin-top: 1mm; }
        .kopf .rechts { margin-left: auto; text-align: right; }
        .kopf .abschnitt { font-size: 13pt; font-weight: 700; }
        .kopf .zahlen { font-size: 10pt; margin-top: 1mm; }
        .kopf .seite { font-size: 9pt; margin-top: 1mm; }

        .sperrhinweis {
            border: 1.5pt solid #000; padding: 2mm 3mm; margin-bottom: 4mm;
            font-weight: 700; font-size: 10pt;
        }

        /*
            Sperrvermerk. Steht ueber der Tabelle und muss beim Durchblaettern
            ins Auge springen, denn er traegt haeufig die einzige Angabe, die
            das System selbst nicht kennt (etwa feste Essenszeiten). Deshalb
            schwarzer Balken statt eines weiteren duennen Rahmens - auf einem
            Blatt voller feiner Linien ist Flaeche das einzige, was auffaellt.
            print-color-adjust, sonst spart der Treiber die Flaeche weg.
        */
        .vermerk {
            border: 2.5pt solid #000; margin-bottom: 5mm;
            break-inside: avoid; page-break-inside: avoid;
        }
        .vermerk-kopf {
            background: #000; color: #fff;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
            padding: 1.6mm 3mm; font-size: 9pt; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            display: flex; gap: 3mm; align-items: baseline;
        }
        .vermerk-kopf .wann { margin-left: auto; letter-spacing: .04em; }
        .vermerk-text {
            padding: 3mm; font-size: 13pt; font-weight: 700; line-height: 1.3;
        }
        .vermerk-fuss {
            padding: 0 3mm 2.5mm; font-size: 8.5pt; font-weight: 400;
        }

        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        th, td {
            border-bottom: .5pt solid #999; padding: 2.2mm 1.5mm;
            text-align: left; vertical-align: top;
        }
        th {
            border-bottom: 1pt solid #000; font-size: 8.5pt; text-transform: uppercase;
            letter-spacing: .05em; padding-bottom: 1.5mm;
        }

        .s-haken  { width: 9mm; }
        .s-zeit   { width: 22mm; white-space: nowrap; }
        .s-pers   { width: 13mm; text-align: right; }
        .s-tisch  { width: 34mm; }
        .s-tel    { width: 33mm; white-space: nowrap; }
        .s-nr     { width: 11mm; text-align: right; font-size: 8.5pt; color: #555; }

        td.s-pers { font-weight: 700; }
        .kasten { display: block; width: 4.5mm; height: 4.5mm; border: 1pt solid #000; }
        .name { font-weight: 700; }
        .bis { color: #444; font-size: 9pt; }

        /* Kein Tisch ist hier oft Absicht (Raum vereinbart, Theke, Rest).
           Deshalb ausdruecklich beschriftet und nicht als Strich - ein Strich
           liest sich wie ein Versehen, das jemand "noch nachtragen" will. */
        .ohne {
            display: inline-block; border: .5pt dashed #666; border-radius: 2pt;
            padding: 0 1.5mm; font-size: 8.5pt; color: #444;
        }

        .status {
            display: inline-block; border: 1pt solid #000; border-radius: 2pt;
            padding: 0 1.5mm; font-size: 8pt; font-weight: 700;
            text-transform: uppercase; margin-left: 2mm;
        }

        .notiz { font-size: 9.5pt; }

        .fuss {
            margin-top: 5mm; padding-top: 2mm; border-top: .5pt solid #999;
            font-size: 8.5pt; color: #444; display: flex; gap: 8mm;
        }
        .fuss .rechts { margin-left: auto; }

        .leer { padding: 8mm 0; font-size: 11pt; }

        @media print {
            body { background: #fff; }
            .leiste { display: none; }
            .blatt {
                width: auto; min-height: 0; margin: 0; padding: 0;
                box-shadow: none;
            }
            /* Jeder Abschnitt auf ein eigenes Blatt, das letzte ohne Umbruch -
               sonst wirft der Drucker eine leere Seite hinterher. */
            .blatt { break-after: page; page-break-after: always; }
            .blatt:last-of-type { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>

<div class="leiste">
    <button type="button" onclick="window.print()">{{ __($l.'print_button') }}</button>
    <a href="/intern?datum={{ $von->toDateString() }}">{{ __($l.'print_back') }}</a>
    <span class="fuellen">
        {{ $titel }} &middot;
        {{ trans_choice($l.'count_sheets', $blattZahl) }}
        @if ($sammeldruck) &middot; {{ trans_choice($l.'count_days', count($tage)) }} @endif
        @if ($trennzeit) &middot; {{ __($l.'print_split_at', ['time' => $trennzeit]) }} @else &middot; {{ __($l.'print_no_split') }} @endif
    </span>
</div>

@forelse ($tage as $tag)
    {{-- Ein Tag ohne Gastzeilen bekommt trotzdem ein Blatt: entweder weil er
         einzeln angefordert wurde, oder weil ein Sperrvermerk darauf liegt. --}}
    @php($abschnitte = $tag['blaetter'] ?: [null])

    @foreach ($abschnitte as $i => $blatt)
        @php($gaeste = $blatt ? $blatt['reservierungen']->sum('guest_num') : 0)
        <div class="blatt">
            <div class="kopf">
                <div>
                    <div class="tag">{{ $tag['datum']->locale($loc)->isoFormat(__($l.'format_weekday_date_year')) }}</div>
                    <div class="ort">{{ $standort->location_name }}</div>
                </div>
                <div class="rechts">
                    <div class="abschnitt">{{ $blatt['titel'] ?? __($l.'print_no_reservations') }}</div>
                    @if ($blatt)
                        <div class="zahlen">
                            {{ trans_choice($l.'count_reservations', $blatt['reservierungen']->count()) }}
                            &middot; {{ trans_choice($l.'count_guests', $gaeste) }}
                        </div>
                    @endif
                    <div class="seite">{{ __($l.'print_sheet_of', ['number' => $i + 1, 'total' => count($abschnitte)]) }}</div>
                </div>
            </div>

            @if ($tag['gesperrt'])
                <div class="sperrhinweis">
                    {{ __($l.($tag['online'] ? 'print_day_online' : 'print_day_blocked')) }}
                    @if ($tag['grund']) {{ __($l.'print_reason', ['reason' => $tag['grund']]) }} @endif
                </div>
            @endif

            {{-- Sperrvermerk: kein Gast, sondern ein Riegel gegen die Online-
                 Buchung. Sein Text ist haeufig die wichtigste Angabe des Tages
                 und steht deshalb auf jedem Blatt dieses Tages. --}}
            @foreach ($tag['sperrvermerke'] as $v)
                <div class="vermerk">
                    <div class="vermerk-kopf">
                        <span>{{ __($l.'note_heading') }}</span>
                        <span class="wann">
                            {{ \Carbon\Carbon::parse($v->reserve_time)->format('H:i') }}–{{ $v->reservation_end_datetime->format('H:i') }}
                        </span>
                    </div>
                    @if ($v->comment)
                        <div class="vermerk-text">{{ $v->comment }}</div>
                    @endif
                    <div class="vermerk-fuss">
                        @if ($tag['paxJeZeit'])
                            @php($eintraege = collect($tag['paxJeZeit'])->map(fn ($zahl, $zeit) => __($l.'max_pax_entry', ['time' => $zeit, 'count' => $zahl]))->implode(', '))
                            <strong>{{ __($l.'max_pax_at_times', ['entries' => $eintraege]) }}</strong>
                        @elseif ($tag['maxPax'])
                            <strong>{{ __($l.'max_pax_per_time', ['count' => $tag['maxPax']]) }}</strong>
                        @endif
                        {{ __($l.'print_note_footer', ['id' => $v->getKey()]) }}
                        @if (trim($v->first_name.' '.$v->last_name))
                            &middot; {{ trim($v->first_name.' '.$v->last_name) }}
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($blatt)
                <table>
                    <thead>
                    <tr>
                        <th class="s-haken">{{ __($l.'col_arrived') }}</th>
                        <th class="s-zeit">{{ __($l.'col_time') }}</th>
                        <th>{{ __($l.'col_name') }}</th>
                        <th class="s-pers">{{ __($l.'col_persons') }}</th>
                        <th class="s-tisch">{{ __($l.'col_table_room') }}</th>
                        <th class="s-tel">{{ __($l.'col_telephone') }}</th>
                        <th>{{ __($l.'col_note') }}</th>
                        <th class="s-nr">{{ __($l.'col_number') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($blatt['reservierungen'] as $r)
                        <tr>
                            <td class="s-haken"><span class="kasten"></span></td>
                            <td class="s-zeit">
                                <strong>{{ \Carbon\Carbon::parse($r->reserve_time)->format('H:i') }}</strong>
                                <span class="bis">–{{ $r->reservation_end_datetime->format('H:i') }}</span>
                            </td>
                            <td>
                                <span class="name">{{ trim($r->first_name.' '.$r->last_name) ?: '—' }}</span>
                                @if ((int)$r->status_id !== $bestaetigt)
                                    <span class="status">{{ $status[(int)$r->status_id] ?? __($l.'print_no_status') }}</span>
                                @endif
                            </td>
                            <td class="s-pers">{{ $r->guest_num }}</td>
                            <td class="s-tisch">
                                @php($tische = $r->tables->pluck('name')->implode(', '))
                                @if ($tische)
                                    {{ $tische }}
                                @else
                                    <span class="ohne">{{ __($l.'print_no_table') }}</span>
                                @endif
                            </td>
                            <td class="s-tel">{{ $r->telephone ?: '' }}</td>
                            <td class="notiz">{{ $r->comment }}</td>
                            <td class="s-nr">{{ $r->getKey() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p class="leer">{{ __($l.'print_no_reservation_day') }}</p>
            @endif

            <div class="fuss">
                <span>{{ __($l.'print_printed_at', ['date' => $gedruckt->locale($loc)->isoFormat(__($l.'format_printed_at'))]) }}</span>
                <span>{{ __($l.'print_canceled_not_listed') }}</span>
                <span class="rechts">{{ $standort->location_name }}</span>
            </div>
        </div>
    @endforeach
@empty
    <div class="blatt">
        <div class="kopf">
            <div>
                <div class="tag">{{ $titel }}</div>
                <div class="ort">{{ $standort->location_name }}</div>
            </div>
            <div class="rechts">
                <div class="abschnitt">{{ __($l.'print_no_reservations') }}</div>
                <div class="seite">{{ __($l.'print_sheet_of', ['number' => 1, 'total' => 1]) }}</div>
            </div>
        </div>

        <p class="leer">{{ __($l.'print_no_reservation_period') }}</p>

        <div class="fuss">
            <span>{{ __($l.'print_printed_at', ['date' => $gedruckt->locale($loc)->isoFormat(__($l.'format_printed_at'))]) }}</span>
            <span class="rechts">{{ $standort->location_name }}</span>
        </div>
    </div>
@endforelse

</body>
</html>
