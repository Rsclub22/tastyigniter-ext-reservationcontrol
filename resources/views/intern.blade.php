<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telefonannahme – {{ $standort->location_name }}</title>
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
    <h1>Telefonannahme</h1>
    <span class="ort">{{ $standort->location_name }}</span>
    <span class="nur-intern">nur intern</span>
</div>

<div class="huelle">

    {{-- Sperrvermerk zuerst: was hier steht, muss man gelesen haben, bevor man
         eine Zeit anklickt. Deshalb ueber allem anderen und nicht als Fussnote
         bei der Belegung. --}}
    @foreach ($vermerke as $v)
        <div class="vermerk">
            <div class="vermerk-kopf">
                <span>Achtung – Sperrvermerk</span>
                <span class="wann">{{ \Carbon\Carbon::parse($v->reserve_time)->format('H:i') }}–{{ $v->reservation_end_datetime->format('H:i') }}</span>
            </div>
            @if ($v->comment)
                <div class="vermerk-text">{{ $v->comment }}</div>
            @endif
            <div class="vermerk-fuss">
                @if ($ganztags->contains($v))
                    Online ist dieser Tag dadurch ausgebucht. Telefonisch wird weiter angenommen –
                    @if ($vermerkZeiten)
                        nur zu den im Text genannten Zeiten ({{ implode(' und ', $vermerkZeiten) }} Uhr)
                    @else
                        zu den gewohnten Zeiten
                    @endif
                    und ohne Tisch; die Verteilung macht der Tischplan.
                @else
                    Online ist nur diese Zeit ausgebucht – der übrige Tag bleibt buchbar, er liegt
                    außerhalb des Vermerks.
                    @if ($vermerkZeiten)
                        Telefonisch wird dafür {{ implode(' und ', $vermerkZeiten) }} Uhr angeboten, ohne Tisch;
                        die Verteilung macht der Tischplan.
                    @endif
                @endif
                @if ($paxJeZeit)
                    <strong>Höchstens
                        @foreach ($paxJeZeit as $zeit => $zahl){{ $zeit }} Uhr: {{ $zahl }}@if (!$loop->last), @endif @endforeach
                        Personen.</strong>
                @elseif ($maxPax)
                    <strong>Höchstens {{ $maxPax }} Personen je Zeit.</strong>
                @endif
                &middot; Reservierung Nr. {{ $v->getKey() }} nicht stornieren.
            </div>
        </div>
    @endforeach


    @if ($neu)
        @php($neuTisch = $neu->tables->pluck('name')->implode(', '))
        <div class="quittung">
            <div class="haken">&#10003;</div>
            <div class="quittung-text">
                <strong>Reservierung angenommen</strong>
                <div class="gross">
                    {{ trim($neu->first_name.' '.$neu->last_name) }} &middot;
                    {{ $neu->guest_num }} {{ $neu->guest_num == 1 ? 'Person' : 'Personen' }} &middot;
                    {{ \Carbon\Carbon::parse($neu->reserve_date)->locale('de')->isoFormat('dddd, D. MMMM') }}
                    um {{ \Carbon\Carbon::parse($neu->reserve_time)->format('H:i') }} Uhr
                </div>
                <div class="zeilen">
                    Nummer {{ $neu->getKey() }} &middot; Status bestätigt &middot;
                    @if ($neuTisch)
                        {{ $neuTisch }}
                    @else
                        <em>kein Tisch zugewiesen – bitte im Admin nachtragen</em>
                    @endif
                    @if ($neu->telephone) &middot; {{ $neu->telephone }} @endif
                </div>
                <div class="zeilen klein">Es wurde keine E-Mail verschickt.</div>
            </div>
            <a class="schliessen" href="/intern?datum={{ $datum->toDateString() }}&gaeste={{ $gaeste }}">Schließen</a>
        </div>
    @endif

    @if ($h = session('hinweis'))
        <div class="melder" style="background:#F3EEEA;border-left-color:#8C4A32">
            <strong>{{ $h }}</strong>
        </div>
    @endif

    @if ($errors->any())
        <div class="fehler">
            <strong>Bitte prüfen:</strong>
            <ul>@foreach ($errors->all() as $fehler)<li>{{ $fehler }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="karte">
        <h2>Tag</h2>
        <form method="get" class="tagwahl">
            @php($heute = \Carbon\Carbon::today())
            @for ($i = 0; $i < 5; $i++)
                @php($t = $heute->copy()->addDays($i))
                <a href="/intern?datum={{ $t->toDateString() }}&gaeste={{ $gaeste }}"
                   class="{{ $t->isSameDay($datum) ? 'aktiv' : '' }}">
                    {{ $i === 0 ? 'Heute' : ($i === 1 ? 'Morgen' : $t->locale('de')->isoFormat('dd D.M.')) }}
                </a>
            @endfor
            <input type="date" name="datum" value="{{ $datum->toDateString() }}" onchange="this.form.submit()">
            <label for="gaeste-wahl" style="margin:0 0 0 10px">Personen</label>
            <input type="number" id="gaeste-wahl" name="gaeste" min="1" max="200" value="{{ $gaeste }}"
                   style="width:80px" onchange="this.form.submit()">
            @if ($raeume->isNotEmpty())
                <label for="raum-wahl" style="margin:0 0 0 10px">Raum</label>
                <select id="raum-wahl" name="raum" style="width:auto" onchange="this.form.submit()">
                    <option value="">Tisch (automatisch)</option>
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
                    <span class="marke gesperrt">Tag gesperrt{{ $grund ? " – ".$grund : "" }}</span>
                    <button type="submit" class="knopf-klein">Sperre aufheben</button>
                </form>
            @else
                <form method="post" action="/intern/sperren" class="sperrform">
                    @csrf
                    <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
                    <input type="text" name="grund" placeholder="Grund (optional), z. B. Betriebsferien" style="max-width:280px">
                    <button type="submit" class="knopf-klein">Diesen Tag sperren</button>
                </form>
            @endif
        </div>

        @if (!empty($sperren))
            <p class="hinweis" style="margin-top:10px">
                Gesperrt:
                @foreach ($sperren as $tag => $grund)<a href="/intern?datum={{ $tag }}"
                    >{{ \Carbon\Carbon::parse($tag)->locale('de')->isoFormat('dd D.M.') }}</a>@if ($grund) ({{ $grund }})@endif{{ !$loop->last ? ' · ' : '' }}@endforeach
            </p>
        @endif
    </div>

    <div class="karte">
        <h2>Belegung am {{ $datum->locale('de')->isoFormat('dddd, D. MMMM') }}</h2>
        <div class="zahlen">
            <span><b>{{ $tischeGesamt }}</b> Tische</span>
            <span><b>{{ $plaetzeGesamt }}</b> Plätze gesamt</span>
            <span><b>{{ count($reservierungen) }}</b> Reservierungen an diesem Tag</span>
        </div>

        @if ($gesperrt)
            <p class="leer">Dieser Tag ist gesperrt{{ $grund ? " – ".$grund : "" }}. Es werden keine Zeiten angeboten, auch nicht öffentlich.</p>
        @elseif (empty($belegung))
            <p class="leer">An diesem Tag werden keine Zeiten angeboten – Ruhetag oder außerhalb des Buchungszeitraums.</p>
        @else
            <div class="schlitze">
                @foreach ($belegung as $s)
                    <button type="submit" form="annahme" name="zeit" value="{{ $s['zeit'] }}"
                            class="schlitz {{ $s['frei'] === 0 ? '' : ($s['frei'] <= 2 ? 'knapp' : '') }}"
                            {{ $s['passt'] ? '' : 'disabled' }}
                            @if ($s['pax_max'])
                                title="{{ $s['passt']
                                    ? 'Diese Zeit übernehmen – danach '.($s['pax_belegt'] + $gaeste).' von '.$s['pax_max'].' Plätzen'
                                    : 'Nicht genug freie Plätze: '.$s['frei'].' frei, '.$gaeste.' gebraucht' }}"
                            @else
                                title="{{ $s['passt'] ? 'Diese Zeit übernehmen' : 'Kein freier Tisch für diese Personenzahl' }}"
                            @endif
                            >
                        <span class="uhr">{{ $s['zeit'] }}</span>
                        <span class="lage">
                            @if ($s['pax_max'])
                                {{ $s['pax_belegt'] }}/{{ $s['pax_max'] }} Pers.@if (!$s['passt']) · voll @endif
                            @elseif (!$s['passt'])
                                belegt
                            @elseif ($s['ohne_tisch'])
                                ohne Tisch
                            @elseif ($s['raum'])
                                {{ $s['raum'] }} frei
                            @else
                                {{ $s['frei'] }}/{{ $s['gesamt'] }} Tische · max. {{ $s['groesster'] }} Pl.
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
            <p class="hinweis">
                Ein Klick auf die Uhrzeit nimmt die Reservierung mit den unten eingetragenen Daten an.
                @if (!$raum && $vermerke->isNotEmpty())
                    @if ($ganztags->isNotEmpty())
                        An diesem Tag wird ohne Tisch angenommen – die Verteilung macht der Tischplan.
                        @if ($vermerkZeiten)
                            Angeboten werden nur die im Sperrvermerk genannten Zeiten.
                        @endif
                    @else
                        Zu den Zeiten des Sperrvermerks wird ohne Tisch angenommen, sonst wie gewohnt mit Tisch.
                    @endif
                    {{-- Gilt in beiden Faellen: die Zahl steht unter jeder Uhrzeit,
                         die ein Vermerk deckelt. --}}
                    @if ($maxPax || $paxJeZeit)
                        Die Zahl unter der Uhrzeit sind die bereits vergebenen von den für diesen Gang vorgesehenen Plätzen;
                        grau bedeutet, dass {{ $gaeste }} {{ $gaeste === 1 ? 'Person' : 'Personen' }} dort nicht mehr hineinpassen.
                    @endif
                @elseif ($raum)
                    Grau bedeutet: {{ $raum->name }} ist zu dieser Zeit bereits vergeben.
                @else
                    Grau bedeutet: für {{ $gaeste }} {{ $gaeste === 1 ? 'Person' : 'Personen' }} ist kein Tisch mehr frei.
                @endif
            </p>
        @endif
    </div>

    <div class="karte">
        <h2>Gast</h2>
        <form method="post" id="annahme" action="/intern">
            @csrf
            <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
            <input type="hidden" name="gaeste" value="{{ $gaeste }}">
            <input type="hidden" name="raum" value="{{ $raum?->id }}">

            <div class="felder">
                <div>
                    <label for="nachname">Nachname <span class="pflicht">*</span></label>
                    <input type="text" id="nachname" name="nachname" value="{{ old('nachname') }}" autofocus required>
                </div>
                <div>
                    <label for="telefon">Telefon <span class="pflicht">*</span></label>
                    <input type="tel" id="telefon" name="telefon" value="{{ old('telefon') }}" required>
                </div>
                <div>
                    <label for="email">E-Mail <span style="font-weight:400">(optional)</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}">
                </div>
            </div>

            <div style="margin-top:14px">
                <label for="notiz">Notiz <span style="font-weight:400">(optional)</span></label>
                <textarea id="notiz" name="notiz">{{ old('notiz') }}</textarea>
            </div>

            <button type="submit" class="absenden" name="zeit" value="">Reservierung annehmen</button>
            <p class="hinweis">
                Wird sofort als <strong>bestätigt</strong> gespeichert.
                @if ($raum)
                    Zugewiesen wird <strong>{{ $raum->name }}</strong>, nicht automatisch ein Tisch.
                @else
                    Der Tisch wird automatisch zugewiesen.
                @endif
                Es wird <strong>keine E-Mail</strong> verschickt – weder an den Gast noch ans Haus.
                Ohne Klick auf eine Uhrzeit oben fehlt die Zeit und das Formular meldet sich.
            </p>
        </form>
    </div>

    @if ($raeume->isNotEmpty())
        <div class="karte">
            <h2>Räume am {{ $datum->locale('de')->isoFormat('D. MMMM') }}</h2>
            <table>
                <thead><tr><th>Raum</th><th>Belegt</th></tr></thead>
                <tbody>
                @foreach ($raeume as $r)
                    @php($belegungen = $raumBelegung->filter(fn($x) => $x->tables->pluck('id')->contains($r->id)))
                    <tr>
                        <td><a href="/intern?datum={{ $datum->toDateString() }}&gaeste={{ $gaeste }}&raum={{ $r->id }}">{{ $r->name }}</a></td>
                        <td>
                            @forelse ($belegungen as $b)
                                <div>
                                    {{ \Carbon\Carbon::parse($b->reserve_time)->format('H:i') }}–{{ $b->reservation_end_datetime->format('H:i') }}
                                    · {{ trim($b->first_name.' '.$b->last_name) }} ({{ $b->guest_num }} Pers.)
                                </div>
                            @empty
                                <span class="leer">frei</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="hinweis">
                Räume werden nie automatisch vergeben – weder über das öffentliche Formular noch hier.
                Sie lassen sich nur über die Auswahl oben gezielt belegen.
            </p>
        </div>
    @endif

    <div class="karte">
        <div class="karte-kopf">
            <h2>Reservierungen am {{ $datum->locale('de')->isoFormat('D. MMMM') }}</h2>
            {{-- Tagesblatt zum Abheften. Die Trennzeit steht hier und nicht nur
                 in der .env: an Weihnachten gibt es nur zwei Sitzungen, deren
                 Grenze liegt woanders - das muss man im Moment des Druckens
                 aendern koennen, ohne an den Server zu muessen. --}}
            <form class="drucken" method="get" action="/intern/druck" target="_blank">
                <input type="hidden" name="datum" value="{{ $datum->toDateString() }}">
                <span class="zeile">
                    <label for="trennzeit">Zweites Blatt ab</label>
                    <input type="text" id="trennzeit" name="trennzeit" value="{{ $trennzeit }}"
                           size="5" inputmode="numeric" placeholder="15:00"
                           title="Uhrzeit, ab der das zweite Blatt beginnt. 'aus' druckt den Tag am Stück.">
                    <button type="submit" name="modus" value="tag">Tag drucken</button>
                </span>
                <span class="zeile">
                    <label for="von">Zeitraum</label>
                    <input type="date" id="von" name="von" value="{{ $datum->toDateString() }}">
                    <span>–</span>
                    <input type="date" id="bis" name="bis" value="{{ $datum->copy()->addDays(6)->toDateString() }}">
                    <button type="submit" name="modus" value="zeitraum"
                            title="Druckt jeden Tag des Zeitraums. Tage ohne Reservierung werden übersprungen.">Zeitraum drucken</button>
                </span>
            </form>
        </div>
        @if ($reservierungen->isEmpty())
            <p class="leer">Noch keine Reservierungen an diesem Tag.</p>
        @else
            <table>
                <thead>
                <tr><th>Zeit</th><th>Name</th><th>Pers.</th><th>Tisch</th><th>Telefon</th><th>Notiz</th></tr>
                </thead>
                <tbody>
                @foreach ($reservierungen as $r)
                    <tr class="{{ $neu && $neu->getKey() === $r->getKey() ? 'soeben' : '' }}">
                        <td class="uhr">{{ \Carbon\Carbon::parse($r->reserve_time)->format('H:i') }}</td>
                        <td>{{ trim($r->first_name.' '.$r->last_name) }}@if ($neu && $neu->getKey() === $r->getKey()) <span class="frisch">soeben angenommen</span>@endif</td>
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
