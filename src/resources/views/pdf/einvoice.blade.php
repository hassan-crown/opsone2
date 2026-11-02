@php
    /** @var array<string, mixed> $v */
    $ar = fn (string $key, array $params = []) => __('einvoicing.pdf.'.$key, $params, 'ar');
    $en = fn (string $key, array $params = []) => __('einvoicing.pdf.'.$key, $params, 'en');
    $title = $v['kind'] === 'invoice' ? 'title.'.$v['type'] : 'title.'.$v['kind'];
    $totalKey = $v['kind'] === 'invoice' ? 'total' : 'total_'.$v['kind'];
    $party = fn (array $p) => trim(implode('، ', array_filter([
        trim(($p['building_number'] ?? '').' '.($p['street'] ?? '')),
        $p['district'] ?? null, $p['city'] ?? null, $p['postal_code'] ?? null,
    ])));
@endphp
<html dir="rtl" lang="ar">
<head>
<style>
    body { font-family: plex; font-size: 9pt; color: #1f2933; }
    .muted { color: #6b7280; }
    .small { font-size: 7.5pt; }
    h1 { font-size: 17pt; font-weight: bold; margin: 0; color: {{ $v['brand'] }}; }
    table { border-collapse: collapse; width: 100%; }
    .rule { border-bottom: 2pt solid {{ $v['brand'] }}; height: 4pt; margin-bottom: 8pt; }
    .cards td { width: 50%; vertical-align: top; padding: 6pt 8pt; background-color: #f5f7f8; border: 0.6pt solid #e3e7ea; }
    .label { color: #6b7280; font-size: 7.5pt; }
    .items th { background-color: {{ $v['brand'] }}; color: #ffffff; font-weight: bold; padding: 4pt; font-size: 8pt; text-align: right; }
    .items td { padding: 4pt; border-bottom: 0.6pt solid #e3e7ea; vertical-align: top; }
    .num { text-align: left; white-space: nowrap; }
    .totals td { padding: 3pt 6pt; }
    .totals .grand td { font-weight: bold; font-size: 10.5pt; border-top: 1pt solid {{ $v['brand'] }}; color: {{ $v['brand'] }}; }
    .note { padding: 6pt 8pt; border: 0.6pt solid #e3e7ea; background-color: #fff8eb; }
</style>
</head>
<body>
<htmlpagefooter name="footer">
    <table class="small muted" style="border-top: 0.6pt solid #e3e7ea;">
        <tr>
            <td style="padding-top: 4pt;">{{ $v['seller']['name'] ?? '' }} — <span dir="ltr">{{ $v['number'] }}</span> — UUID <span dir="ltr">{{ $v['uuid'] }}</span></td>
            <td style="padding-top: 4pt; text-align: left;">{PAGENO} / {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

<table>
    <tr>
        <td style="width: 60%; vertical-align: top;">
            @if ($v['has_logo'])<img src="var:logo" style="height: 42pt; max-width: 150pt;" /><br />@endif
            <div style="font-size: 12pt; font-weight: bold; margin-top: 3pt;">{{ $v['seller']['name'] ?? '' }}</div>
            @if (! empty($v['seller']['name_en']))<div class="muted"><span dir="ltr">{{ $v['seller']['name_en'] }}</span></div>@endif
            <div class="small">{{ $ar('vat_number') }} / {{ $en('vat_number') }}: <span dir="ltr">{{ $v['seller']['vat_number'] ?? '' }}</span></div>
            <div class="small">{{ $ar('cr') }} / {{ $en('cr') }}: <span dir="ltr">{{ $v['seller']['commercial_registration'] ?? '' }}</span></div>
            <div class="small muted">{{ $party($v['seller']) }}</div>
        </td>
        <td style="width: 40%; text-align: left; vertical-align: top;">
            <img src="var:qr" style="width: 92pt; height: 92pt;" />
        </td>
    </tr>
</table>
<div class="rule"></div>

<table>
    <tr>
        <td style="vertical-align: top;">
            <h1>{{ $ar($title) }}</h1>
            <div dir="ltr" class="muted" style="text-align: right;">{{ $en($title) }}</div>
        </td>
        <td style="text-align: left; vertical-align: top;" class="small">
            {{ $ar('number') }} / {{ $en('number') }}: <span dir="ltr">{{ $v['number'] }}</span><br />
            {{ $ar('issued') }} / {{ $en('issued') }}: <span dir="ltr">{{ $v['issued'] }}</span><br />
            {{ $ar('order') }} / {{ $en('order') }}: <span dir="ltr">{{ $v['order'] }}</span>
            @if ($v['original'])<br />{{ $ar('original') }} / {{ $en('original') }}: <span dir="ltr">{{ $v['original'] }}</span>@endif
        </td>
    </tr>
</table>

<table class="cards" style="margin-top: 8pt;">
    <tr>
        <td>
            <div class="label">{{ $ar('seller') }} / {{ $en('seller') }}</div>
            <div style="font-weight: bold;">{{ $v['seller']['name'] ?? '' }}</div>
            <div class="small">{{ $party($v['seller']) }}</div>
        </td>
        <td>
            <div class="label">{{ $ar('buyer') }} / {{ $en('buyer') }}</div>
            <div style="font-weight: bold;">{{ $v['buyer']['name'] ?? '' }}</div>
            @if (! empty($v['buyer']['vat_number']))<div class="small">{{ $ar('vat_number') }}: <span dir="ltr">{{ $v['buyer']['vat_number'] }}</span></div>@endif
            @if (! empty($v['buyer']['commercial_registration']))<div class="small">{{ $ar('cr') }}: <span dir="ltr">{{ $v['buyer']['commercial_registration'] }}</span></div>@endif
            @if ($party($v['buyer']) !== '')<div class="small">{{ $party($v['buyer']) }}</div>@endif
        </td>
    </tr>
</table>

@if ($v['reason'])
    <div class="note" style="margin-top: 8pt;"><span class="label">{{ $ar('reason') }} / {{ $en('reason') }}:</span> {{ $v['reason'] }}</div>
@endif

<table class="items" style="margin-top: 10pt;">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: {{ $v['has_discount'] ? 29 : 41 }}%;">{{ $ar('item') }}<br /><span dir="ltr">{{ $en('item') }}</span></th>
            <th style="width: 10%;">{{ $ar('quantity') }}<br /><span dir="ltr">{{ $en('quantity') }}</span></th>
            @if ($v['has_discount'])<th class="num" style="width: 12%;">{{ $ar('discount') }}<br /><span dir="ltr">{{ $en('discount') }}</span></th>@endif
            <th class="num" style="width: 15%;">{{ $ar('net') }}<br /><span dir="ltr">{{ $en('net') }}</span></th>
            <th class="num" style="width: 13%;">{{ $ar('vat', ['rate' => $v['rate']]) }}<br /><span dir="ltr">{{ $en('vat', ['rate' => $v['rate']]) }}</span></th>
            <th class="num" style="width: 16%;">{{ $ar('gross') }}<br /><span dir="ltr">{{ $en('gross') }}</span></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($v['lines'] as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $line['description'] }}</td>
                <td><span dir="ltr">{{ $line['quantity'] }}</span></td>
                @if ($v['has_discount'])<td class="num"><span dir="ltr">{{ $line['discount'] }}</span></td>@endif
                <td class="num"><span dir="ltr">{{ $line['net'] }}</span></td>
                <td class="num"><span dir="ltr">{{ $line['vat'] }}</span></td>
                <td class="num"><span dir="ltr">{{ $line['gross'] }}</span></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 8pt;">
    <tr>
        <td style="width: 50%;"></td>
        <td style="width: 50%;">
            <table class="totals">
                @if ($v['has_discount'])
                    <tr><td>{{ $ar('subtotal') }} / <span dir="ltr">{{ $en('subtotal') }}</span></td><td class="num"><span dir="ltr">{{ $v['subtotal'] }} SAR</span></td></tr>
                    <tr><td>{{ $ar('total_discount') }} / <span dir="ltr">{{ $en('total_discount') }}</span></td><td class="num"><span dir="ltr">−{{ $v['discount'] }} SAR</span></td></tr>
                @endif
                <tr><td>{{ $ar('total_net') }} / <span dir="ltr">{{ $en('total_net') }}</span></td><td class="num"><span dir="ltr">{{ $v['net'] }} SAR</span></td></tr>
                <tr><td>{{ $ar('total_vat', ['rate' => $v['rate']]) }} / <span dir="ltr">{{ $en('total_vat', ['rate' => $v['rate']]) }}</span></td><td class="num"><span dir="ltr">{{ $v['vat'] }} SAR</span></td></tr>
                <tr class="grand"><td>{{ $ar($totalKey) }} / <span dir="ltr">{{ $en($totalKey) }}</span></td><td class="num"><span dir="ltr">{{ $v['total'] }} SAR</span></td></tr>
            </table>
        </td>
    </tr>
</table>

<p class="small muted" style="margin-top: 14pt;">{{ $ar('footer.'.$v['status']) }} — <span dir="ltr">{{ $en('footer.'.$v['status']) }}</span></p>
</body>
</html>
