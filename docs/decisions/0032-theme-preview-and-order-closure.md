# ADR-0032: Private site preview, order closure and delivery remarks (acceptance gaps)

Status: accepted (2026-11-01). The four questions it left open were answered by the product owner and implemented in
[ADR-0035](0035-delivery-correction-identity-remarks-write-off.md) (Q-03, M-14-T03, several remarks, debt write-off).

Closes gaps recorded in `docs/acceptance-report.md`: scenario 18 (THM-04), integrated rows
«مخصص من البداية» (M-04-T07) and «توريد وتسليم جزئيان» (M-14-T04).

## Private preview of the draft theme and draft pages (THM-04)

- A staff member with **P-55** issues a link with `POST /site-admin/preview-link` (locale + `home | <slug> | store`). The link is a
  Laravel **temporary signed URL** (`site.preview.start`), 60 minutes by default and at most 24 hours (`config/site.php`, `site.preview`).
  The draft **theme** is included only when the issuer also holds **P-100**; a content editor's link previews draft pages with the live theme.
  Every issue is audited (`site_preview.link_issued`).
- Opening the link sets one **encrypted, expiring, HttpOnly cookie** (`site_preview`, valid until the link's expiry) in that browser and redirects
  to a whitelisted site path (`/{ar|en}`, `/{ar|en}/{slug}`, `/{ar|en}/store`). A forged, altered or expired link is 403; a forged or expired cookie is ignored.
- `SitePreviewMode` (middleware on the public site and store groups) activates `SitePreview` for a valid cookie. The site and store then read the
  **draft version of each page** (else its published copy) and the **draft theme** (else the live one) through the same code that serves visitors
  (`SiteContext::publishedVersion()` / `theme()`), so the preview is the real pages, nav, language switch, store catalogue / product / cart.
  Draft images and logos are served to that browser only (`private, no-store`); publicly they stay 404.
- Safety: every preview response carries `X-Robots-Tag: noindex, nofollow, noarchive`, a `<meta name="robots">` noindex, `Cache-Control: private, no-store` and a
  banner with an exit link (`/site-preview/exit`). The preview is **read only**: any non-GET request is 403 except the guest cart; no lead, checkout or account action runs.
  `sitemap.xml` and `robots.txt` always describe the live site. Without the cookie nothing about a draft is reachable.
- Publish and rollback stay consistent because the preview reads the same pointers: after *apply* / *publish* the preview equals the live site; *restore*
  (default Alpha as the draft) previews default while the live site is unchanged until applied; discarding or archiving falls back to published / 404.
- The editor's existing page preview (`site.admin.pages.preview`) now uses the same mechanism.

**Not done — product question (Q-03):** the applied brand colour is not shared with the staff administration (`data-surface="panel"` keeps OpsOne's identity). The
skipped test `the applied brand reaches the facility's accent places in the administration` stays skipped until the owner answers: do «المواضع المحددة في الإدارة»
mean only the facility logo and name, or a brand accent on the panel too?

## Comprehensive closure (M-04-T07 / M-05-T10)

- `POST /orders/{order}/close` (reason required) → `App\Orders\OrderClosure`: custom `execution_finished | cancelled → closed`, ready `delivered | cancelled → closed`,
  under the order row lock, **P-25 for a finished order and P-18 for a cancelled one**; the reason, the state it left and the agreement version are stored in
  `order_closures`, with the transition and audit (`order.closed`). The agreement and everything recorded stay as they are.
- It closes only when nothing blocks: a due still owed (a closure never forgives a debt), a pending refund, an open settlement follow-up, an open purchase line for the
  order, a delivery draft, a remaining deliverable quantity (finished order), an open return, an open delivery remark. All blockers are returned together (422) and shown on the order page.
- Not done: **M-04-T08** (rework after closing) — closed orders cannot be reopened yet.

## Delivery remarks (M-14-T04)

- `POST /deliveries/{note}/remarks` (P-52, confirmed note) records a blocking remark (`delivery_remarks`); `POST /delivery-remarks/{id}/resolve` records how it was settled.
  The delivery, the stock and the sale are untouched; an open remark blocks the closure above. The follow-up person is the order's follower (else its assignee).
- Decision taken: the follow-up is the remark row itself, **not a flow `Task`**, because tasks require an order stage and ready orders have none.

## Not done — product / design questions (M-14-T03, correction of a recorded delivery)

ADR-0018 still applies. A correction needs: (1) for a **ready** line, a reversing stock movement design (put the quantity back into the reservation or into stock?);
(2) the effect on the «first delivery» due date (`DueCalendar`) and on returns' delivered base; (3) the P-49 stock permission when inventory moves. These are not guessed here.
