{{-- Tag config of the page (ADR-0045): IDs and this page's events, no personal data. The loader sets Consent Mode v2
     to "denied" first and loads the ad platforms only after the visitor agrees. --}}
@if ($marketing['tracking'])
    <script type="application/json" id="opsone-tracking">{!! \App\Site\Seo\JsonLd::encode($marketing['tracking']) !!}</script>
    @vite(['resources/js/site-analytics.ts'])
@endif
