@php
    /** @var array<string, mixed> $c */
    $t = fn (string $key, array $params = []) => __('esign.pdf.'.$key, $params, $c['locale']);
    $end = $c['dir'] === 'rtl' ? 'left' : 'right';
@endphp
<html dir="{{ $c['dir'] }}" lang="{{ $c['locale'] }}">
<head>
<style>
    body { font-family: plex; font-size: 9.5pt; color: #1f2933; }
    h1 { font-size: 16pt; font-weight: bold; margin: 0 0 4pt 0; color: {{ $c['brand'] }}; }
    .muted { color: #6b7280; }
    .small { font-size: 8pt; }
    table { border-collapse: collapse; width: 100%; }
    .facts td { padding: 5pt 6pt; border-bottom: 0.6pt solid #e3e7ea; vertical-align: top; }
    .facts td.k { width: 32%; color: #6b7280; }
    .hash { font-size: 7.5pt; }
    .box { border: 1pt solid {{ $c['brand'] }}; padding: 10pt; margin-top: 10pt; }
    .seal { color: #ffffff; background-color: {{ $c['brand'] }}; padding: 3pt 8pt; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>{{ $t('certificate.title') }}</h1><div class="muted">{{ $c['facility'] }}</div></td>
        <td style="text-align: {{ $end }};"><span class="seal">{{ $t('certificate.signed') }}</span></td>
    </tr>
</table>
<p class="muted small" style="margin-top: 6pt;">{{ $t('certificate.intro') }}</p>

<table class="facts" style="margin-top: 8pt;">
    <tr><td class="k">{{ $t('certificate.document') }}</td><td>{{ $t('title.'.$c['kind']) }} — <span dir="ltr">{{ $c['number'] }}</span></td></tr>
    <tr><td class="k">{{ $t('order') }}</td><td><span dir="ltr">{{ $c['order'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.signer') }}</td><td><b>{{ $c['signer_name'] }}</b></td></tr>
    <tr><td class="k">{{ $t('certificate.account') }}</td><td>{{ $c['customer'] }} — <span dir="ltr">{{ $c['mobile'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.signed_at') }}</td><td><span dir="ltr">{{ $c['signed_at'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.verification') }}</td><td>{{ $t('certificate.otp', ['channel' => $t('certificate.channel.'.$c['otp_channel'])]) }} — <span dir="ltr">{{ $c['otp_reference'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.ip') }}</td><td><span dir="ltr">{{ $c['ip'] ?? '—' }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.device') }}</td><td class="small"><span dir="ltr">{{ $c['user_agent'] ?? '—' }}</span></td></tr>
    <tr><td class="k">{{ $t('content_hash') }}</td><td class="hash"><span dir="ltr">{{ $c['content_hash'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.pdf_hash') }}</td><td class="hash"><span dir="ltr">{{ $c['pdf_sha256'] }}</span></td></tr>
    <tr><td class="k">{{ $t('certificate.reference') }}</td><td><span dir="ltr">{{ $c['reference'] }}</span></td></tr>
</table>

<div class="box">
    <div class="muted small">{{ $t('certificate.consent') }}</div>
    <p style="margin: 4pt 0 8pt 0;">{{ $c['consent_text'] }}</p>
    <div class="muted small">{{ $t('certificate.signature') }}</div>
    <img src="var:signature" style="height: 70pt; max-width: 260pt;" />
</div>

<p class="muted small" style="margin-top: 10pt;">{{ $t('certificate.footer') }}</p>
</body>
</html>
