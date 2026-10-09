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
    $titel = $sammeldruck
        ? $von->locale('de')->isoFormat('D.M.') . '–' . $bis->locale('de')->isoFormat('D.M.YYYY')
        : $von->locale('de')->isoFormat('dddd, D. MMMM YYYY');
    // Ohne Tage wird trotzdem ein Blatt gedruckt - das mit dem Hinweis darauf.
    $blattZahl = max(1, collect($tage)->sum(fn(array $t): int => max(1, count($t['blaetter']))));
@endphp
<!DOCTYPE html>
<html lang="de">
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
    <button type="button" onclick="window.print()">Drucken</button>
    <a href="/intern?datum={{ $von->toDateString() }}">Zurück zur Telefonannahme</a>
    <span class="fuellen">
        {{ $titel }} &middot;
        {{ $blattZahl }} {{ $blattZahl === 1 ? 'Blatt' : 'Blätter' }}
        @if ($sammeldruck) &middot; {{ count($tage) }} {{ count($tage) === 1 ? 'Tag' : 'Tage' }} @endif
        @if ($trennzeit) &middot; Trennung {{ $trennzeit }} Uhr @else &middot; ohne Trennung @endif
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
                    <div class="tag">{{ $tag['datum']->locale('de')->isoFormat('dddd, D. MMMM YYYY') }}</div>
                    <div class="ort">{{ $standort->location_name }}</div>
                </div>
                <div class="rechts">
                    <div class="abschnitt">{{ $blatt['titel'] ?? 'Keine Reservierungen' }}</div>
                    @if ($blatt)
                        <div class="zahlen">
                            {{ $blatt['reservierungen']->count() }}
                            {{ $blatt['reservierungen']->count() === 1 ? 'Reservierung' : 'Reservierungen' }}
                            &middot; {{ $gaeste }} {{ $gaeste === 1 ? 'Gast' : 'Gäste' }}
                        </div>
                    @endif
                    <div class="seite">Blatt {{ $i + 1 }} von {{ count($abschnitte) }}</div>
                </div>
            </div>

            @if ($tag['gesperrt'])
                <div class="sperrhinweis">
                    Dieser Tag ist gesperrt – online sind keine Reservierungen möglich.
                    @if ($tag['grund']) Grund: {{ $tag['grund'] }} @endif
                </div>
            @endif

            {{-- Sperrvermerk: kein Gast, sondern ein Riegel gegen die Online-
                 Buchung. Sein Text ist haeufig die wichtigste Angabe des Tages
                 und steht deshalb auf jedem Blatt dieses Tages. --}}
            @foreach ($tag['sperrvermerke'] as $v)
                <div class="vermerk">
                    <div class="vermerk-kopf">
                        <span>Achtung – Sperrvermerk</span>
                        <span class="wann">
                            {{ \Carbon\Carbon::parse($v->reserve_time)->format('H:i') }}–{{ $v->reservation_end_datetime->format('H:i') }}
                        </span>
                    </div>
                    @if ($v->comment)
                        <div class="vermerk-text">{{ $v->comment }}</div>
                    @endif
                    <div class="vermerk-fuss">
                        @if ($tag['paxJeZeit'])
                            <strong>Höchstens
                                @foreach ($tag['paxJeZeit'] as $zeit => $zahl){{ $zeit }} Uhr: {{ $zahl }}@if (!$loop->last), @endif @endforeach
                                Personen.</strong>
                        @elseif ($tag['maxPax'])
                            <strong>Höchstens {{ $tag['maxPax'] }} Personen je Zeit.</strong>
                        @endif
                        Blockiert die Online-Buchung dieses Tages &middot; kein Gast &middot;
                        nicht in den Zahlen oben enthalten &middot; Nr. {{ $v->getKey() }}
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
                        <th class="s-haken">Da</th>
                        <th class="s-zeit">Zeit</th>
                        <th>Name</th>
                        <th class="s-pers">Pers.</th>
                        <th class="s-tisch">Tisch / Raum</th>
                        <th class="s-tel">Telefon</th>
                        <th>Notiz</th>
                        <th class="s-nr">Nr.</th>
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
                                    <span class="status">{{ $status[(int)$r->status_id] ?? 'ohne Status' }}</span>
                                @endif
                            </td>
                            <td class="s-pers">{{ $r->guest_num }}</td>
                            <td class="s-tisch">
                                @php($tische = $r->tables->pluck('name')->implode(', '))
                                @if ($tische)
                                    {{ $tische }}
                                @else
                                    <span class="ohne">ohne Tisch</span>
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
                <p class="leer">An diesem Tag liegt keine Reservierung vor.</p>
            @endif

            <div class="fuss">
                <span>Gedruckt {{ $gedruckt->locale('de')->isoFormat('D.MM.YYYY, HH:mm') }} Uhr</span>
                <span>Stornierte Reservierungen sind nicht aufgeführt.</span>
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
                <div class="abschnitt">Keine Reservierungen</div>
                <div class="seite">Blatt 1 von 1</div>
            </div>
        </div>

        <p class="leer">In diesem Zeitraum liegt keine Reservierung vor.</p>

        <div class="fuss">
            <span>Gedruckt {{ $gedruckt->locale('de')->isoFormat('D.MM.YYYY, HH:mm') }} Uhr</span>
            <span class="rechts">{{ $standort->location_name }}</span>
        </div>
    </div>
@endforelse

</body>
</html>
