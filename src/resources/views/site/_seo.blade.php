{{-- Search and sharing tags of a public document (ADR-0021, ADR-0045). `$seo`: title, description, robots, canonical (null
     on error pages), alternates, site_name, image (path or URL), optional og_type, product ['amount', 'currency'],
     json_ld (list of already encoded JSON-LD objects). --}}
    <title>{{ $seo['title'] }}</title>
    @if (($seo['description'] ?? '') !== '')
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    <meta name="robots" content="{{ $seo['robots'] }}">
    @if ($seo['canonical'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    @foreach ($seo['alternates'] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    @if (($seo['description'] ?? '') !== '')
        <meta property="og:description" content="{{ $seo['description'] }}">
    @endif
    @if ($seo['canonical'])<meta property="og:url" content="{{ $seo['canonical'] }}">@endif
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_SA' : 'en_US' }}">
    <meta property="og:locale:alternate" content="{{ $locale === 'ar' ? 'en_US' : 'ar_SA' }}">
    @if ($seo['image'])
        <meta property="og:image" content="{{ url($seo['image']) }}">
        <meta property="og:image:alt" content="{{ $seo['image_alt'] ?? $seo['title'] }}">
    @endif
    @if (! empty($seo['product']))
        <meta property="product:price:amount" content="{{ $seo['product']['amount'] }}">
        <meta property="product:price:currency" content="{{ $seo['product']['currency'] }}">
    @endif
    <meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    @if (($seo['description'] ?? '') !== '')
        <meta name="twitter:description" content="{{ $seo['description'] }}">
    @endif
    @if ($seo['image'])
        <meta name="twitter:image" content="{{ url($seo['image']) }}">
    @endif
    @foreach ($seo['json_ld'] ?? [] as $jsonLd)
        <script type="application/ld+json">{!! $jsonLd !!}</script>
    @endforeach
