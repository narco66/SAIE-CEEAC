<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $model['title'] }} — {{ $model['reference'] }}</title>
<style>
    @page { margin: 18mm 15mm 20mm 15mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10pt; color: #1b2738; }
    header { border-bottom: 2px solid #0b1f3a; padding-bottom: 8px; margin-bottom: 12px; }
    header table { width: 100%; border-collapse: collapse; }
    header td { vertical-align: middle; border: 0; padding: 0; }
    .logo { width: 52px; height: 52px; }
    .inst { font-size: 8.5pt; letter-spacing: .6px; color: #0969a8; font-weight: bold; }
    h1 { font-size: 15pt; margin: 2px 0 2px; color: #0b1f3a; }
    .ref { font-size: 9pt; color: #667386; }
    .class { text-align: right; }
    .class span { display: inline-block; padding: 4px 8px; border: 1px solid #0b1f3a; font-size: 9pt; font-weight: bold; letter-spacing: .8px; }
    .banner { background: #fff7e8; border: 1px solid #c67a10; color: #7a4a06; padding: 7px 10px; font-weight: bold; margin-bottom: 10px; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .meta td { padding: 4px 6px; border-bottom: 1px solid #e4e9ef; font-size: 10pt; }
    .meta td.k { width: 32%; color: #667386; }
    .summary { width: 100%; margin-bottom: 12px; border-collapse: separate; border-spacing: 6px 0; }
    .summary td { background: #f4f6f9; border: 1px solid #dfe5ec; padding: 8px; text-align: center; }
    .summary b { display: block; font-size: 14pt; color: #0b1f3a; }
    h2 { font-size: 11.5pt; color: #0b1f3a; margin: 14px 0 6px; page-break-after: avoid; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead { display: table-header-group; }
    table.data th { background: #0b1f3a; color: #fff; font-size: 9pt; text-align: left; padding: 5px; }
    table.data td { border-bottom: 1px solid #e4e9ef; padding: 4px 5px; font-size: 10pt; vertical-align: top; }
    table.data tr { page-break-inside: avoid; }
    table.data tbody tr:nth-child(even) td { background: #f8fafc; }
    .empty { color: #667386; font-style: italic; padding: 6px 0; }
    .notes { margin-top: 16px; border-top: 1px solid #dfe5ec; padding-top: 8px; font-size: 9pt; color: #4b5668; }
    .qr { float: right; text-align: center; font-size: 8pt; color: #667386; margin-left: 10px; }
    .qr img { width: 90px; height: 90px; }
</style>
</head>
<body>
<header>
    <table>
        <tr>
            <td style="width:62px"><img class="logo" src="{{ $logo }}" alt="CEEAC"></td>
            <td>
                <div class="inst">COMMISSION DE LA CEEAC · SAIE-CEEAC</div>
                <h1>{{ $model['title'] }}</h1>
                <div class="ref">Référence {{ $model['reference'] }} · données figées le {{ $generatedAt }} ({{ $timezone }})</div>
            </td>
            <td class="class"><span>{{ mb_strtoupper($model['classification']) }}</span></td>
        </tr>
    </table>
</header>

@if (! empty($model['banner']))
    <div class="banner">{{ $model['banner'] }}</div>
@endif

@if ($qr)
    <div class="qr"><img src="{{ $qr }}" alt="QR"><br>Fiche authentifiée</div>
@endif

<table class="meta">
    @foreach ($model['meta'] ?? [] as [$label, $value])
        <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
    @endforeach
</table>

@if (! empty($model['summary']))
    <table class="summary"><tr>
        @foreach ($model['summary'] as [$label, $value])
            <td><b>{{ $value }}</b>{{ $label }}</td>
        @endforeach
    </tr></table>
@endif

@foreach ($model['sections'] ?? [] as $section)
    <h2>{{ $section['title'] }}</h2>
    @if (isset($section['text']))
        <p>{{ $section['text'] }}</p>
    @endif
    @if (isset($section['table']))
        @if (count($section['table']['rows']) === 0)
            <div class="empty">{{ $section['empty'] ?? 'Aucune donnée pour ce périmètre.' }}</div>
        @else
            <table class="data">
                <thead><tr>@foreach ($section['table']['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($section['table']['rows'] as $row)
                    <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif
@endforeach

@if (! empty($model['notes']))
    <div class="notes">
        @foreach ($model['notes'] as $note)
            <div>• {{ $note }}</div>
        @endforeach
        <div>• Document généré par le SAIE-CEEAC à partir des données de la base ; aucune signature, cachet ou approbation n’y est apposé.</div>
    </div>
@endif
</body>
</html>
