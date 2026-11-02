/*
 * OpsOne landing: the one place to wire the demo request form.
 *
 * TO WIRE BEFORE LAUNCH
 *   demoEndpoint  URL that accepts a POST of JSON:
 *                 { name, company, phone, city, trade, branches, page, sentAt }
 *                 Any 2xx response is shown as "received"; anything else as a failure.
 *   demoEmail     Optional fallback. Used only while demoEndpoint is empty: the form then opens
 *                 the visitor's mail app with the request written out (mailto:).
 *
 * With both empty the form validates and then says plainly that requests are not being received yet.
 * It never shows a success it did not get.
 */
window.OPSONE_LANDING = {
  demoEndpoint: '',
  demoEmail: '',
};
