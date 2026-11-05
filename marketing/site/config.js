/*
 * OpsOne marketing site: the one file to fill before launch. Everything is empty, so nothing is sent anywhere and
 * nothing extra is shown. See README «قبل الإطلاق».
 *
 * DEMO FORM
 *   demoEndpoint  URL that accepts a POST of JSON:
 *                 { name, company, phone, city, trade (what they sell: ready, custom or both), sector (sector pages only, e.g. "kitchens"),
 *                   utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, page, sentAt }
 *                 Campaign values are kept for the visit, so a lead sent from another page still carries the landing UTM.
 *                 Any 2xx response shows the thank-you state and reports the lead; anything else (or 15 s) shows a failure.
 *                 The endpoint must allow this site's origin (CORS) and validate again on the server.
 *   demoEmail     Optional fallback, used only while demoEndpoint is empty: the form opens the visitor's
 *                 mail app with the request written out (mailto:).
 *   thanksUrl     Optional. After a successful request, go to this page instead of showing the thank-you state in place
 *                 (for ad platforms that count a conversion by URL). The site ships one: "/thanks/" (noindex).
 *   With demoEndpoint and demoEmail empty the form validates and then says plainly that requests are not received yet.
 *
 * CONTACT (each button stays hidden until its number is set; digits with country code, e.g. "9665XXXXXXXX")
 *   whatsapp      WhatsApp number for the floating button and the footer.
 *   whatsappText  Optional first message; the sector page's name is added to it.
 *   phone         Phone number for «اتصل» in the footer (tel:).
 *
 * PRICING («الباقات» on the home page, ADR-0043; how prices are announced is open, Q-74)
 *   pricing.enabled  false keeps the section hidden. true shows the three plans with their limits and «اطلب عرض سعر».
 *   pricing.amounts  Monthly amount per plan as text, e.g. "1,150". Empty keeps «اطلب عرض سعر» for that plan.
 *                    Shown amounts must be VAT-inclusive (ADR-0043). Fill only after the product owner decides Q-74.
 *
 * MARKETING TAGS (tags.js)
 *   Fill only the IDs you use. A tag loads only when its ID is set AND the visitor consents to its purpose in the banner
 *   (analytics: ga4, gtm; ads: the rest). The banner appears only when at least one ID is set.
 *   Google Consent Mode v2 starts "denied".
 *     ga4                     Google Analytics 4 measurement ID, e.g. "G-XXXXXXXXXX"
 *     gtm                     Google Tag Manager container ID, e.g. "GTM-XXXXXXX"
 *     googleAdsLead           Google Ads conversion "send_to" for the demo request, e.g. "AW-123456789/AbC-dEfGh"
 *     googleAdsContact        Google Ads conversion "send_to" for WhatsApp/call clicks (optional)
 *     metaPixel               Meta (Facebook) Pixel ID, digits
 *     tiktokPixel             TikTok Pixel ID
 *     snapPixel               Snap Pixel ID
 *     linkedin                LinkedIn Insight partner ID, digits
 *     linkedinLeadConversion  LinkedIn conversion ID for the demo request, digits
 *
 * DEVELOPER CREDIT
 *   developerUrl  Available Solutions' site (https://…). While empty, the footer credit «تطوير Available Solutions»
 *                 is plain text; once set, the name links to it in a new tab.
 */
window.OPSONE_LANDING = {
  demoEndpoint: '',
  demoEmail: '',
  thanksUrl: '',
  whatsapp: '',
  whatsappText: '',
  phone: '',
  developerUrl: '',
  pricing: {
    enabled: false,
    amounts: { basic: '', growth: '', scale: '' },
  },
  tags: {
    ga4: '',
    gtm: '',
    googleAdsLead: '',
    googleAdsContact: '',
    metaPixel: '',
    tiktokPixel: '',
    snapPixel: '',
    linkedin: '',
    linkedinLeadConversion: '',
  },
};
