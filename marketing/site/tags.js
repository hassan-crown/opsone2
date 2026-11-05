/* OpsOne site: consent-aware marketing tags and events.
   Nothing loads until a tag ID is set in config.js AND the visitor consents. Consent has two purposes, asked separately
   (Saudi PDPL: specific, informed, withdrawable): «القياس» (analytics: GA4, GTM) and «الإعلانات» (ads: Google Ads
   conversions, Meta, TikTok, Snap, LinkedIn). Declining (the default) loads nothing. The choice is stored in this browser
   with its date; «إعدادات الخصوصية» in the footer reopens it, and withdrawing a purpose reloads the page without its tags.
   Google Consent Mode v2 starts with everything denied.

   Events (sent only to tags that are loaded): page_view (GA4 config / GTM), sector_view (sector pages), scroll_depth
   (25/50/75/100), demo_cta_click (any [data-cta]), click_whatsapp, click_call (the [data-contact] buttons), and the lead
   (window.opsTrack('lead'), called by the form on success: generate_lead / demo_request / platform lead events).
   Campaign parameters (utm_*, gclid, fbclid) are read from the URL on any page and kept for the visit (sessionStorage
   «ops-<name>», the keys the demo form sends), and ride, with the sector, on every event. */
(() => {
  const ids = (window.OPSONE_LANDING && window.OPSONE_LANDING.tags) || {};
  const sector = ((window.OPSONE_PAGE || {}).sector || '').trim();
  const CAMPAIGN = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
  const params = new URLSearchParams(location.search);
  const campaign = {};
  CAMPAIGN.forEach((k) => {
    let v = (params.get(k) || '').slice(0, 200);
    try { if (v) sessionStorage.setItem('ops-' + k, v); else v = sessionStorage.getItem('ops-' + k) || ''; } catch { /* private mode */ }
    if (v && k.startsWith('utm_')) campaign[k] = v;
  });
  const ctx = (o = {}) => ({ ...o, ...(sector ? { sector } : {}), ...campaign });

  const KEY = 'opsone-consent-v2';
  const analyticsIds = ['ga4', 'gtm'].filter((k) => ids[k]);
  const adsIds = ['googleAdsLead', 'metaPixel', 'tiktokPixel', 'snapPixel', 'linkedin'].filter((k) => ids[k]);
  const store = {
    get: () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch { return null; } },
    set: (v) => { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch { /* private mode */ } },
  };

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag() { window.dataLayer.push(arguments); };
  gtag('consent', 'default', { ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', wait_for_update: 500 });

  const script = (src) => { const s = document.createElement('script'); s.async = true; s.src = src; document.head.appendChild(s); };
  const on = { analytics: false, ads: false };
  let gtagLoaded = false;
  const loadGtag = (id) => { if (gtagLoaded) return; gtagLoaded = true; gtag('js', new Date()); script(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`); };
  const adsAccount = (ids.googleAdsLead || '').split('/')[0];

  const load = (choice) => {
    const analytics = choice.analytics && !on.analytics, ads = choice.ads && !on.ads;
    if (!analytics && !ads) return;
    on.analytics = on.analytics || choice.analytics; on.ads = on.ads || choice.ads;
    gtag('consent', 'update', {
      analytics_storage: on.analytics ? 'granted' : 'denied',
      ad_storage: on.ads ? 'granted' : 'denied', ad_user_data: on.ads ? 'granted' : 'denied', ad_personalization: on.ads ? 'granted' : 'denied',
    });
    if ((analytics || (ads && !on.analytics)) && ids.gtm && !window.__opsGtm) {
      window.__opsGtm = true; window.dataLayer.push(ctx({ event: 'ops_context' }));
      window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' }); script(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(ids.gtm)}`);
    }
    if (analytics && ids.ga4) { loadGtag(ids.ga4); gtag('config', ids.ga4, ctx({})); }
    if (ads) {
      if (adsAccount) { loadGtag(adsAccount); gtag('config', adsAccount); }
      if (ids.metaPixel) {
        const f = window.fbq = function fbq() { f.callMethod ? f.callMethod.apply(f, arguments) : f.queue.push(arguments); };
        if (!window._fbq) window._fbq = f; f.push = f; f.loaded = true; f.version = '2.0'; f.queue = [];
        script('https://connect.facebook.net/en_US/fbevents.js'); fbq('init', ids.metaPixel); fbq('track', 'PageView', ctx({}));
      }
      if (ids.tiktokPixel) {
        const t = window.ttq = window.ttq || []; ['page', 'track', 'identify'].forEach((n) => { t[n] = function () { t.push([n, ...arguments]); }; });
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
    }
    if (sector) send('sector_view', {}, { meta: ['ViewContent', { content_category: sector }] });
  };

  // one event to every loaded tag: GA4 (gtag) and GTM (dataLayer) for analytics, platform events for ads
  const send = (name, detail = {}, platforms = {}) => {
    if (!on.analytics && !on.ads) return;
    if (on.analytics && ids.ga4) gtag('event', name, ctx(detail));
    if (window.__opsGtm) window.dataLayer.push(ctx({ event: name, ...detail }));
    if (!on.ads) return;
    if (platforms.ads && ids[platforms.ads]) gtag('event', 'conversion', { send_to: ids[platforms.ads] });
    if (platforms.meta && window.fbq) fbq('track', platforms.meta[0], platforms.meta[1] || {});
    if (platforms.tiktok && window.ttq && ttq.track) ttq.track(platforms.tiktok, sector ? { content_category: sector } : {});
    if (platforms.snap && window.snaptr) snaptr('track', platforms.snap, sector ? { item_category: sector } : {});
    if (platforms.linkedin && window.lintrk && ids[platforms.linkedin]) lintrk('track', { conversion_id: +ids[platforms.linkedin] });
  };

  window.opsTrack = (name, detail = {}) => {
    if (name === 'lead') send('generate_lead', { form_name: 'demo_request' }, { ads: 'googleAdsLead', meta: ['Lead', sector ? { content_category: sector } : {}], tiktok: 'SubmitForm', snap: 'SIGN_UP', linkedin: 'linkedinLeadConversion' });
    if (name === 'lead' && window.__opsGtm) window.dataLayer.push(ctx({ event: 'demo_request' }));
    if (name === 'cta') send('demo_cta_click', { cta_location: detail.location || '' });
  };

  document.addEventListener('click', (e) => {
    const cta = e.target.closest('[data-cta]');
    if (cta) window.opsTrack('cta', { location: cta.dataset.cta });
    const contact = e.target.closest('[data-contact]');
    if (contact) {
      const kind = contact.dataset.contact, where = { contact_location: contact.dataset.where || '' };
      if (kind === 'whatsapp') send('click_whatsapp', where, { ads: 'googleAdsContact', meta: ['Contact', { method: 'whatsapp' }], tiktok: 'Contact', snap: 'CUSTOM_EVENT_1' });
      if (kind === 'call') send('click_call', where, { ads: 'googleAdsContact', meta: ['Contact', { method: 'phone' }], tiktok: 'Contact', snap: 'CUSTOM_EVENT_2' });
    }
  });

  // scroll depth: 25, 50, 75 and 100% of the page, once each per page view
  const marks = [25, 50, 75, 100];
  let raf = 0;
  const depth = () => {
    raf = 0;
    const h = document.documentElement.scrollHeight - innerHeight;
    const pct = h > 0 ? Math.round((scrollY / h) * 100) : 100;
    while (marks.length && pct >= marks[0]) send('scroll_depth', { percent_scrolled: marks.shift() });
    if (!marks.length) removeEventListener('scroll', onScroll);
  };
  const onScroll = () => { if (!raf) raf = requestAnimationFrame(depth); };
  addEventListener('scroll', onScroll, { passive: true });

  /* ---------- the consent banner ---------- */
  const banner = document.querySelector('[data-consent]');
  const reopen = document.querySelectorAll('[data-consent-open]');
  if (!banner || (!analyticsIds.length && !adsIds.length)) return;
  reopen.forEach((b) => { b.hidden = false; });
  const boxes = { analytics: banner.querySelector('[data-consent-purpose="analytics"]'), ads: banner.querySelector('[data-consent-purpose="ads"]') };
  // a purpose with no tag configured is not asked about
  if (!analyticsIds.length && boxes.analytics) boxes.analytics.closest('label').hidden = true;
  if (!adsIds.length && boxes.ads) boxes.ads.closest('label').hidden = true;
  const prefs = banner.querySelector('[data-consent-prefs]');
  const show = (withPrefs) => {
    const c = store.get() || {};
    if (boxes.analytics) boxes.analytics.checked = !!c.analytics;
    if (boxes.ads) boxes.ads.checked = !!c.ads;
    if (prefs) prefs.hidden = !withPrefs;
    banner.hidden = false;
  };
  const decide = (choice) => {
    const before = store.get() || {};
    store.set({ analytics: !!choice.analytics, ads: !!choice.ads, at: new Date().toISOString(), v: 2 });
    banner.hidden = true;
    if ((before.analytics && !choice.analytics) || (before.ads && !choice.ads)) { location.reload(); return; }
    load(choice);
  };
  banner.querySelector('[data-consent-yes]').addEventListener('click', () => decide({ analytics: true, ads: true }));
  banner.querySelector('[data-consent-no]').addEventListener('click', () => decide({ analytics: false, ads: false }));
  const prefsBtn = banner.querySelector('[data-consent-settings]');
  if (prefsBtn) prefsBtn.addEventListener('click', () => { prefs.hidden = !prefs.hidden; prefsBtn.setAttribute('aria-expanded', String(!prefs.hidden)); });
  const save = banner.querySelector('[data-consent-save]');
  if (save) save.addEventListener('click', () => decide({ analytics: boxes.analytics && boxes.analytics.checked, ads: boxes.ads && boxes.ads.checked }));
  reopen.forEach((b) => b.addEventListener('click', () => show(true)));
  const choice = store.get();
  if (choice) load(choice); else show(false);
})();
