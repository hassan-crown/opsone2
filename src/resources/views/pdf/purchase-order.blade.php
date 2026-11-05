@php
    /** @var array<string, mixed> $v */
    $t = fn (string $key, array $params = []) => __('purchase_document.pdf.'.$key, $params, $v['locale']);
    $end = $v['dir'] === 'rtl' ? 'left' : 'right';
    $start = $v['dir'] === 'rtl' ? 'right' : 'left';
@endphp
<html dir="{{ $v['dir'] }}" lang="{{ $v['locale'] }}">
<head>
<style>
    body { font-family: plex; font-size: 9pt; color: #1f2933; }
    .muted { color: #6b7280; }
    .small { font-size: 7.5pt; }
    h1 { font-size: 17pt; font-weight: bold; margin: 0; color: {{ $v['brand'] }}; }
    table { border-collapse: collapse; width: 100%; }
    .rule { border-bottom: 2pt solid {{ $v['brand'] }}; height: 4pt; margin-bottom: 8pt; }
    .card { padding: 6pt 8pt; background-color: #f5f7f8; border: 0.6pt solid #e3e7ea; vertical-align: top; }
    .label { color: #6b7280; font-size: 7.5pt; }
    .items th { background-color: {{ $v['brand'] }}; color: #ffffff; font-weight: bold; padding: 4pt; font-size: 8pt; text-align: {{ $start }}; }
    .items td { padding: 4pt; border-bottom: 0.6pt solid #e3e7ea; vertical-align: top; }
    .num { text-align: {{ $end }}; white-space: nowrap; }
    .detail { font-size: 7.5pt; color: #374151; }
    .box { border: 0.8pt solid #9ca3af; height: 60pt; padding: 4pt; vertical-align: top; }
    .note { padding: 6pt 8pt; border: 0.6pt solid #e3e7ea; background-color: #fff8eb; }
    .section { margin-top: 8pt; }
</style>
</head>
<body>
<htmlpagefooter name="footer">
    <table class="small muted" style="border-top: 0.6pt solid #e3e7ea;">
        <tr>
            <td style="padding-top: 4pt;">{{ $v['facility']['name'] }} — <span dir="ltr">{{ $v['number'] }}</span> — {{ $t('template_version', ['version' => $v['template_version']]) }}</td>
            <td style="padding-top: 4pt; text-align: {{ $end }};">{PAGENO} / {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

<table>
    <tr>
        <td style="width: 60%; vertical-align: top;">
            @if ($v['has_logo'])<img src="var:logo" style="height: 42pt; max-width: 150pt;" /><br />@endif
            <h1>{{ $v['title'] }}</h1>
        </td>
        <td style="width: 40%; text-align: {{ $end }}; vertical-align: top;" class="small">
            {{ $t('number') }}: <span dir="ltr">{{ $v['number'] }}</span><br />
            {{ $t('issued') }}: <span dir="ltr">{{ $v['issued'] }}</span>
            @if ($v['status'] === 'draft')<br /><b>{{ $t('draft') }}</b>@endif
        </td>
    </tr>
</table>
<div class="rule"></div>

@foreach ($v['blocks'] as $block)
    @switch($block)
        @case('facility')
            <table class="section"><tr><td class="card">
                <div class="label">{{ $t('facility') }}</div>
                <div style="font-weight: bold;">{{ $v['facility']['name'] }}</div>
                @if ($v['flags']['facility_contact'])
                    @if ($v['facility']['phone'])<div class="small">{{ $t('phone') }}: <span dir="ltr">{{ $v['facility']['phone'] }}</span></div>@endif
                    @if ($v['facility']['email'])<div class="small">{{ $t('email') }}: <span dir="ltr">{{ $v['facility']['email'] }}</span></div>@endif
                    @if ($v['facility']['address'])<div class="small">{{ $v['facility']['address'] }}</div>@endif
                    @if ($v['facility']['vat_number'])<div class="small">{{ $t('vat_number') }}: <span dir="ltr">{{ $v['facility']['vat_number'] }}</span></div>@endif
                    @if ($v['facility']['cr'])<div class="small">{{ $t('cr') }}: <span dir="ltr">{{ $v['facility']['cr'] }}</span></div>@endif
                @endif
            </td></tr></table>
            @break
        @case('branch')
            <table class="section"><tr><td class="card">
                <div class="label">{{ $t('branch') }}</div>
                <div style="font-weight: bold;">{{ $v['branch'] }}</div>
            </td></tr></table>
            @break
        @case('supplier')
            <table class="section"><tr><td class="card">
                <div class="label">{{ $t('supplier') }}</div>
                <div style="font-weight: bold;">{{ $v['supplier']['name'] }}</div>
                @if ($v['supplier']['contact'])<div class="small">{{ $t('contact') }}: {{ $v['supplier']['contact'] }}</div>@endif
                @if ($v['supplier']['phone'])<div class="small">{{ $t('phone') }}: <span dir="ltr">{{ $v['supplier']['phone'] }}</span></div>@endif
                @if ($v['supplier']['email'])<div class="small">{{ $t('email') }}: <span dir="ltr">{{ $v['supplier']['email'] }}</span></div>@endif
                @if ($v['supplier']['vat_number'])<div class="small">{{ $t('vat_number') }}: <span dir="ltr">{{ $v['supplier']['vat_number'] }}</span></div>@endif
            </td></tr></table>
            @break
        @case('order_reference')
            @if ($v['orders'] !== [])
                <table class="section"><tr><td class="card">
                    <div class="label">{{ $t('orders') }}</div>
                    @foreach ($v['orders'] as $order)
                        <div><span dir="ltr">{{ $order['number'] }}</span>@if ($order['customer']) — {{ $order['customer'] }}@endif @if ($order['phone']) — <span dir="ltr">{{ $order['phone'] }}</span>@endif</div>
                    @endforeach
                </td></tr></table>
            @endif
            @break
        @case('expected_on')
            <div class="section"><span class="label">{{ $t('expected_on') }}:</span> <b dir="ltr">{{ $v['expected_on'] ?? '—' }}</b></div>
            @break
        @case('delivery_address')
            @if ($v['delivery']['location'] || $v['delivery']['address'])
                <div class="section"><span class="label">{{ $t('delivery') }}:</span> {{ $v['delivery']['location'] }}@if ($v['delivery']['location'] && $v['delivery']['address']) — @endif{{ $v['delivery']['address'] }}</div>
            @endif
            @break
        @case('lines')
            <table class="items section">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: {{ $v['prices'] ? 47 : 67 }}%;">{{ $t('item') }}</th>
                        <th style="width: 14%;">{{ $t('quantity') }}</th>
                        <th style="width: 14%;">{{ $t('order') }}</th>
                        @if ($v['prices'])
                            <th class="num" style="width: 10%;">{{ $t('unit_cost') }}</th>
                            <th class="num" style="width: 10%;">{{ $t('total') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($v['lines'] as $line)
                        <tr>
                            <td>{{ $line['no'] }}</td>
                            <td>
                                <b>{{ $line['name'] }}</b>
                                @foreach ($line['details']['options'] as $option)
                                    <div class="detail">{{ $option['label'] }}: {{ $option['value'] }}</div>
                                @endforeach
                                @foreach ($line['details']['dimensions'] as $dim)
                                    <div class="detail">{{ $dim['location'] }} — {{ $dim['name'] }}: <span dir="ltr">{{ $dim['value'] }}</span></div>
                                @endforeach
                                @if ($line['details']['notes'])<div class="detail muted">{{ $line['details']['notes'] }}</div>@endif
                                @if ($line['details']['images'] !== [])
                                    <div>@foreach ($line['details']['images'] as $img)<img src="var:{{ $img }}" style="height: 70pt; max-width: 120pt; margin: 3pt;" />@endforeach</div>
                                @endif
                            </td>
                            <td><span dir="ltr">{{ $line['quantity'] }}</span> {{ $line['unit'] }}</td>
                            <td><span dir="ltr">{{ $line['order'] ?? '—' }}</span></td>
                            @if ($v['prices'])
                                <td class="num"><span dir="ltr">{{ $line['unit_cost'] ?? '—' }}</span></td>
                                <td class="num"><span dir="ltr">{{ $line['gross'] ?? '—' }}</span></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($v['prices'] && $v['total'])
                <div class="section" style="text-align: {{ $end }};"><b>{{ $t('grand_total') }}: <span dir="ltr">{{ $v['total'] }} SAR</span></b></div>
            @endif
            @break
        @case('notes')
            @if ($v['notes'])
                <div class="note section"><span class="label">{{ $t('notes') }}:</span> {{ $v['notes'] }}</div>
            @endif
            @break
        @case('terms')
            @if ($v['terms'])
                <div class="section"><div class="label">{{ $t('terms') }}</div><div class="small">{!! nl2br(e($v['terms'])) !!}</div></div>
            @endif
            @break
        @case('signatures')
            @if ($v['flags']['signature'] || $v['flags']['stamp'])
                <table class="section" style="margin-top: 14pt;">
                    <tr>
                        @if ($v['flags']['signature'])<td class="box" style="width: 48%;"><span class="label">{{ $t('signature') }}</span></td>@endif
                        <td style="width: 4%;"></td>
                        @if ($v['flags']['stamp'])<td class="box" style="width: 48%;"><span class="label">{{ $t('stamp') }}</span></td>@endif
                    </tr>
                </table>
            @endif
            @break
    @endswitch
@endforeach
</body>
</html>
