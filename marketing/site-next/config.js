/*
 * OpsOne landing: the one file to fill before launch. Everything is empty, so nothing is sent anywhere.
 *
 * DEMO FORM
 *   demoEndpoint  URL that accepts a POST of JSON:
 *                 { name, company, phone, city, trade (what they sell: ready, custom or both), utm_source, utm_medium, utm_campaign, utm_term,
 *                   utm_content, gclid, fbclid, page, sentAt }
 *                 Any 2xx response is shown as "received"; anything else as a failure.
 *   demoEmail     Optional fallback, used only while demoEndpoint is empty: the form opens the visitor's
 *                 mail app with the request written out (mailto:).
 *   With both empty the form validates and then says plainly that requests are not being received yet.
 *
 * MARKETING TAGS (tags.js)
 *   Fill only the IDs you use. A tag loads only when its ID is set AND the visitor accepts the consent
 *   banner. The banner appears only when at least one ID is set. Google Consent Mode v2 starts "denied".
 *     ga4          Google Analytics 4 measurement ID, e.g. "G-XXXXXXXXXX"
 *     gtm          Google Tag Manager container ID, e.g. "GTM-XXXXXXX"
 *     googleAdsLead  Google Ads conversion "send_to", e.g. "AW-123456789/AbC-dEfGh" (needs ga4 or gtm loaded)
 *     metaPixel    Meta (Facebook) Pixel ID, digits
 *     tiktokPixel  TikTok Pixel ID
 *     snapPixel    Snap Pixel ID
 *     linkedin     LinkedIn Insight partner ID, digits
 *     linkedinLeadConversion  LinkedIn conversion ID for the demo request, digits
 *
 * DEVELOPER CREDIT
 *   developerUrl  Available Solutions' site (https://…). While empty, the footer credit «تطوير Available Solutions»
 *                 is plain text; once set, the name links to it in a new tab. Add the same URL as "url" on the
 *                 author/creator Organization in index.html's JSON-LD.
 */
window.OPSONE_LANDING = {
  demoEndpoint: '',
  demoEmail: '',
  developerUrl: '',
  tags: {
    ga4: '',
    gtm: '',
    googleAdsLead: '',
    metaPixel: '',
    tiktokPixel: '',
    snapPixel: '',
    linkedin: '',
    linkedinLeadConversion: '',
  },
};
