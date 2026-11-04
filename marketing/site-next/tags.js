/* OpsOne landing: consent-aware marketing tags.
   Nothing loads until a tag ID is set in config.js AND the visitor accepts the banner. Declining (the default)
   loads nothing. The choice is remembered in this browser; «إعدادات الخصوصية» in the footer reopens the banner.
   window.opsTrack('lead') reports the demo request to whichever tags are loaded. */
(() => {
  const ids = (window.OPSONE_LANDING && window.OPSONE_LANDING.tags) || {};
  // sector pages set window.OPSONE_PAGE = { sector: 'kitchens' }; the sector rides on page_view and on the lead
  const sector = ((window.OPSONE_PAGE || {}).sector || '').trim();
  const withSector = (o) => (sector ? { ...o, sector } : o);
  const KEY = 'opsone-consent';
  const any = ['ga4', 'gtm', 'metaPixel', 'tiktokPixel', 'snapPixel', 'linkedin'].some((k) => ids[k]);
  const store = { get: () => { try { return localStorage.getItem(KEY); } catch { return null; } }, set: (v) => { try { localStorage.setItem(KEY, v); } catch { /* private mode */ } } };

  // Google Consent Mode v2: everything denied until the visitor accepts (no request is made by this line).
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
  gtag('consent', 'default', { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', wait_for_update: 500 });

  const script = (src) => { const s = document.createElement('script'); s.async = true; s.src = src; document.head.appendChild(s); };
  let loaded = false;
  const load = () => {
    if (loaded) return; loaded = true;
    gtag('consent', 'update', { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
    if (ids.gtm) { if (sector) window.dataLayer.push({ sector }); window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' }); script(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(ids.gtm)}`); }
    if (ids.ga4) { gtag('js', new Date()); gtag('config', ids.ga4, withSector({})); script(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ids.ga4)}`); }
    if (ids.metaPixel) {
      const f = window.fbq = function fbq() { f.callMethod ? f.callMethod.apply(f, arguments) : f.queue.push(arguments); };
      if (!window._fbq) window._fbq = f; f.push = f; f.loaded = true; f.version = '2.0'; f.queue = [];
      script('https://connect.facebook.net/en_US/fbevents.js'); fbq('init', ids.metaPixel); fbq('track', 'PageView', withSector({}));
    }
    if (ids.tiktokPixel) {
      const t = window.ttq = window.ttq || []; const m = ['page', 'track', 'identify'];
      m.forEach((n) => { t[n] = function () { t.push([n, ...arguments]); }; });
      script(`https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${encodeURIComponent(ids.tiktokPixel)}&lib=ttq`); t.page();
    }
    if (ids.snapPixel) {
      const s = window.snaptr = function snaptr() { s.handleRequest ? s.handleRequest.apply(s, arguments) : s.queue.push(arguments); }; s.queue = [];
      script('https://sc-static.net/scevent.min.js'); snaptr('init', ids.snapPixel); snaptr('track', 'PAGE_VIEW');
    }
    if (ids.linkedin) {
      window._linkedin_partner_id = ids.linkedin; window._linkedin_data_partner_ids = [ids.linkedin];
      window.lintrk = window.lintrk || function lintrk(a, b) { window.lintrk.q.push([a, b]); }; window.lintrk.q = window.lintrk.q || [];
      script('https://snap.licdn.com/li.lms-analytics/insight.min.js');
    }
  };

  // the demo request, reported once to each loaded tag
  window.opsTrack = (name) => {
    if (!loaded || name !== 'lead') return;
    gtag('event', 'generate_lead', withSector({ form_name: 'demo_request' }));
    if (ids.googleAdsLead) gtag('event', 'conversion', { send_to: ids.googleAdsLead });
    if (ids.gtm) window.dataLayer.push(withSector({ event: 'demo_request' }));
    if (window.fbq) fbq('track', 'Lead', sector ? { content_category: sector } : {});
    if (window.ttq && ttq.track) ttq.track('SubmitForm', sector ? { content_category: sector } : {});
    if (window.snaptr) snaptr('track', 'SIGN_UP', sector ? { item_category: sector } : {});
    if (window.lintrk && ids.linkedinLeadConversion) lintrk('track', { conversion_id: +ids.linkedinLeadConversion });
  };

  const banner = document.querySelector('[data-consent]');
  const reopen = document.querySelector('[data-consent-open]');
  if (!any || !banner) return;
  if (reopen) reopen.hidden = false;
  const show = () => { banner.hidden = false; };
  const decide = (yes) => { store.set(yes ? 'granted' : 'denied'); banner.hidden = true; if (yes) load(); else if (loaded) location.reload(); };
  banner.querySelector('[data-consent-yes]').addEventListener('click', () => decide(true));
  banner.querySelector('[data-consent-no]').addEventListener('click', () => decide(false));
  if (reopen) reopen.addEventListener('click', show);
  const choice = store.get();
  if (choice === 'granted') load(); else if (choice !== 'denied') show();
})();
