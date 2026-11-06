@php
    /** @var array<string, mixed> $v */
    $t = fn (string $key, array $params = []) => __('esign.pdf.'.$key, $params, $v['locale']);
    $start = $v['dir'] === 'rtl' ? 'right' : 'left';
    $end = $v['dir'] === 'rtl' ? 'left' : 'right';
    $currency = $t('currency');
    // Free text takes its own direction (an Arabic term inside the English PDF and the reverse).
    $dirOf = fn (string $text) => preg_match('/\p{Arabic}/u', $text) === 1 ? 'rtl' : 'ltr';
@endphp
<html dir="{{ $v['dir'] }}" lang="{{ $v['locale'] }}">
<head>
<style>
    body { font-family: plex; font-size: 9.5pt; color: #1f2933; }
    .brand { color: {{ $v['brand'] }}; }
    .muted { color: #6b7280; }
    .small { font-size: 8pt; }
    h1 { font-size: 18pt; font-weight: bold; margin: 0; color: {{ $v['brand'] }}; }
    h2 { font-size: 11pt; font-weight: bold; margin: 14pt 0 5pt 0; color: {{ $v['brand'] }}; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; }
    .rule { border-bottom: 2pt solid {{ $v['brand'] }}; height: 4pt; margin-bottom: 10pt; }
    .cards td { width: 50%; vertical-align: top; padding: 7pt 9pt; background-color: #f5f7f8; border: 0.6pt solid #e3e7ea; }
    .label { color: #6b7280; font-size: 8pt; }
    .items th { background-color: {{ $v['brand'] }}; color: #ffffff; font-weight: bold; padding: 5pt 4pt; font-size: 8.5pt; text-align: {{ $start }}; }
    .items td { padding: 5pt 4pt; border-bottom: 0.6pt solid #e3e7ea; vertical-align: top; }
    .items tr.alt td { background-color: #fafbfc; }
    .num { text-align: {{ $end }}; white-space: nowrap; }
    .totals td { padding: 3pt 6pt; }
    .totals .grand td { font-weight: bold; font-size: 11pt; border-top: 1pt solid {{ $v['brand'] }}; color: {{ $v['brand'] }}; padding-top: 5pt; }
    .schedule td, .schedule th { padding: 4pt; border-bottom: 0.6pt solid #e3e7ea; text-align: {{ $start }}; }
    .schedule th { font-size: 8.5pt; color: #6b7280; font-weight: bold; }
    .bank td { padding: 3pt 6pt; vertical-align: top; }
    .bank { background-color: #f5f7f8; border: 0.6pt solid #e3e7ea; page-break-inside: avoid; }
    .badge { color: #ffffff; background-color: {{ $v['brand'] }}; padding: 2pt 6pt; font-size: 8pt; }
    .draft { color: #b45309; font-weight: bold; }
    .sign td { width: 50%; padding: 8pt; vertical-align: top; border: 0.6pt solid #e3e7ea; height: 70pt; }
    .designs td { width: 50%; padding: 4pt; text-align: center; vertical-align: middle; }
    ol { margin: 0; padding-{{ $start }}: 14pt; }
    li { margin-bottom: 3pt; }
</style>
</head>
<body>
<htmlpagefooter name="footer">
    <table class="small muted" style="border-top: 0.6pt solid #e3e7ea;">
        <tr>
            <td style="padding-top: 4pt;">{{ $v['facility']['name'] }} — <span dir="ltr">{{ $v['number'] }}</span></td>
            <td style="padding-top: 4pt; text-align: {{ $end }};">{{ $t('page') }} {PAGENO} / {nbpg}</td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="footer" value="on" />

<table class="head">
    <tr>
        <td style="width: 55%;">
            @if ($v['has_logo'])
                <img src="var:logo" style="height: 48pt; max-width: 160pt;" /><br />
            @endif
            <div style="font-size: 12pt; font-weight: bold; margin-top: 4pt;">{{ $v['facility']['name'] }}</div>
            @if ($v['facility']['address'])<div class="muted small">{{ $v['facility']['address'] }}</div>@endif
            <div class="muted small">
                @if ($v['facility']['phone'])<span dir="ltr">{{ $v['facility']['phone'] }}</span>@endif
                @if ($v['facility']['email']) · <span dir="ltr">{{ $v['facility']['email'] }}</span>@endif
            </div>
            @if ($v['facility']['commercial_registration'])<div class="muted small">{{ $t('cr') }}: <span dir="ltr">{{ $v['facility']['commercial_registration'] }}</span></div>@endif
            @if ($v['facility']['vat_number'])<div class="muted small">{{ $t('vat_number') }}: <span dir="ltr">{{ $v['facility']['vat_number'] }}</span></div>@endif
        </td>
        <td style="width: 45%; text-align: {{ $end }};">
            <h1>{{ $t('title.'.$v['kind']) }}</h1>
            <div style="font-size: 11pt; margin-top: 2pt;"><span dir="ltr">{{ $v['number'] }}</span></div>
            @if ($v['draft'])<div class="draft">{{ $t('draft') }}</div>@endif
            <div class="small" style="margin-top: 6pt;">
                @if ($v['sent_on']){{ $t('issued_on') }}: <span dir="ltr">{{ $v['sent_on'] }}</span><br />@endif
                @if ($v['valid_until']){{ $t('valid_until') }}: <span dir="ltr">{{ $v['valid_until'] }}</span><br />@endif
                @if ($v['agreement_version']){{ $t('agreement_version') }}: <span dir="ltr">{{ $v['agreement_version'] }}</span>@endif
            </div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="cards">
    <tr>
        <td>
            <div class="label">{{ $t('customer') }}</div>
            <div style="font-weight: bold; font-size: 10.5pt;">{{ $v['customer']['name'] }}</div>
            <div><span dir="ltr">{{ $v['customer']['mobile'] }}</span></div>
            @if ($v['customer']['email'])<div><span dir="ltr">{{ $v['customer']['email'] }}</span></div>@endif
            @if ($v['customer']['commercial_registration'])<div class="small">{{ $t('cr') }}: <span dir="ltr">{{ $v['customer']['commercial_registration'] }}</span></div>@endif
            @if ($v['customer']['vat_number'])<div class="small">{{ $t('vat_number') }}: <span dir="ltr">{{ $v['customer']['vat_number'] }}</span></div>@endif
        </td>
        <td>
            <div class="label">{{ $t('order') }}</div>
            <div style="font-weight: bold; font-size: 10.5pt;"><span dir="ltr">{{ $v['order']['number'] }}</span></div>
            <div class="small">{{ $t('opened_on') }}: <span dir="ltr">{{ $v['order']['opened_on'] }}</span></div>
            @if ($v['execution_days'])<div class="small">{{ $t('execution_days', ['days' => $v['execution_days']]) }}</div>@endif
        </td>
    </tr>
</table>

@if ($v['description'])
    <h2>{{ $t('description') }}</h2>
    <p dir="{{ $dirOf($v['description']) }}">{!! nl2br(e($v['description'])) !!}</p>
@endif

<h2>{{ $t('items') }}</h2>
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 37%;">{{ $t('item') }}</th>
            <th style="width: 10%;">{{ $t('quantity') }}</th>
            <th class="num" style="width: 13%;">{{ $t('unit_price') }}</th>
            <th class="num" style="width: 11%;">{{ $t('discount') }}</th>
            <th class="num" style="width: 11%;">{{ $t('vat') }}</th>
            <th class="num" style="width: 13%;">{{ $t('line_total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($v['lines'] as $i => $line)
            <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                <td>{{ $i + 1 }}</td>
                <td>{{ $line['description'] }}</td>
                <td><span dir="ltr">{{ $line['quantity'] }}</span> {{ $line['unit'] }}</td>
                <td class="num">{{ $line['unit_price'] ?? '—' }}</td>
                <td class="num">{{ $line['discount'] ?? '—' }}</td>
                <td class="num">{{ $line['vat'] ?? '—' }}</td>
                <td class="num">{{ $line['total'] ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 8pt;">
    <tr>
        <td style="width: 50%; vertical-align: top;" class="small muted">
            {{ $v['vat_inclusive'] ? $t('vat_inclusive_note', ['rate' => $v['vat_rate']]) : $t('vat_exclusive_note', ['rate' => $v['vat_rate']]) }}<br />
            {{ $t('amounts_in', ['currency' => $currency]) }}
        </td>
        <td style="width: 50%;">
            <table class="totals">
                @if ($v['totals']['base'])<tr><td>{{ $t('subtotal') }}</td><td class="num">{{ $v['totals']['base'] }} {{ $currency }}</td></tr>@endif
                @if ($v['totals']['discount'])<tr><td>{{ $t('discount') }}</td><td class="num">-{{ $v['totals']['discount'] }} {{ $currency }}</td></tr>@endif
                <tr><td>{{ $t('net') }}</td><td class="num">{{ $v['totals']['net'] ?? '—' }} {{ $currency }}</td></tr>
                <tr><td>{{ $t('vat_line', ['rate' => $v['vat_rate']]) }}</td><td class="num">{{ $v['totals']['vat'] ?? '—' }} {{ $currency }}</td></tr>
                <tr class="grand"><td>{{ $t('total') }}</td><td class="num">{{ $v['totals']['total'] ?? '—' }} {{ $currency }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if ($v['schedule'] !== [])
    <h2>{{ $t('schedule') }}</h2>
    <table class="schedule">
        <tr><th style="width: 40%;">{{ $t('installment') }}</th><th style="width: 35%;">{{ $t('due') }}</th><th class="num" style="width: 25%;">{{ $t('amount') }}</th></tr>
        @foreach ($v['schedule'] as $row)
            <tr><td>{{ $row['name'] }}</td><td>{{ $row['when'] }}</td><td class="num">{{ $row['amount'] ?? '—' }} {{ $currency }}</td></tr>
        @endforeach
    </table>
@endif

@if ($v['bank'] !== null)
    <h2>{{ $t('bank.title') }}</h2>
    <table class="bank">
        <tr><td class="label" style="width: 30%;">{{ $t('bank.bank') }}</td><td>{{ $v['bank']['bank_name'] }}</td></tr>
        <tr><td class="label">{{ $t('bank.beneficiary') }}</td><td>{{ $v['bank']['beneficiary_name'] }}</td></tr>
        <tr><td class="label">IBAN</td><td><span dir="ltr">{{ $v['bank']['iban'] }}</span></td></tr>
        @if ($v['bank']['account_number'])<tr><td class="label">{{ $t('bank.account_number') }}</td><td><span dir="ltr">{{ $v['bank']['account_number'] }}</span></td></tr>@endif
        @if ($v['bank']['swift_code'])<tr><td class="label">SWIFT / BIC</td><td><span dir="ltr">{{ $v['bank']['swift_code'] }}</span></td></tr>@endif
        <tr><td class="label">{{ $t('bank.reference') }}</td><td><span dir="ltr">{{ $v['order']['number'] }}</span></td></tr>
    </table>
    @if ($v['bank']['transfer_note'])<p class="small muted" dir="{{ $dirOf($v['bank']['transfer_note']) }}">{{ $v['bank']['transfer_note'] }}</p>@endif
@endif

@if ($v['designs'] !== [])
    <h2>{{ $t('design') }}</h2>
    <table class="designs">
        @foreach (array_chunk($v['designs'], 2) as $pair)
            <tr>
                @foreach ($pair as $name)
                    <td><img src="var:{{ $name }}" style="max-height: 150pt; max-width: 240pt;" /></td>
                @endforeach
                @if (count($pair) === 1)<td></td>@endif
            </tr>
        @endforeach
    </table>
@endif

@if ($v['terms'] !== [] || $v['clauses'] !== [])
    <h2>{{ $t('terms') }}</h2>
    @foreach ($v['terms'] as $term)
        <p dir="{{ $dirOf($term) }}">{!! nl2br(e($term)) !!}</p>
    @endforeach
    @if ($v['clauses'] !== [])
        <ol>
            @foreach ($v['clauses'] as $clause)<li dir="{{ $dirOf($clause) }}">{{ $clause }}</li>@endforeach
        </ol>
    @endif
@endif

<h2>{{ $t('signatures') }}</h2>
<table class="sign" style="page-break-inside: avoid;">
    <tr>
        <td>
            <div class="label">{{ $t('first_party') }}</div>
            <div style="font-weight: bold;">{{ $v['facility']['name'] }}</div>
        </td>
        <td>
            <div class="label">{{ $t('second_party') }}</div>
            <div style="font-weight: bold;">{{ $v['customer']['name'] }}</div>
            <div class="small muted" style="margin-top: 20pt;">{{ $t('esign_note') }}</div>
        </td>
    </tr>
</table>
@if ($v['content_hash'])
    <p class="small muted" style="margin-top: 6pt;">{{ $t('content_hash') }}: <span dir="ltr" style="font-size: 7pt;">{{ $v['content_hash'] }}</span></p>
@endif
</body>
</html>
