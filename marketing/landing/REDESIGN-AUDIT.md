# Landing redesign audit (impeccable critique, audit and detect)

Target: `marketing/landing/index.html` at commit `0d8a958`, before the redesign.
Mode: Persuade (one action: request a demo). Product context: `PRODUCT.md`.
Method: dual-agent critique. Assessment A was the design review, Assessment B the detector plus browser evidence; they ran
in isolation and are combined here. Screens were checked at 1440, 768 and 375 after scrolling the whole page.

## Scores

### Design health (Nielsen, 7 and 10 not applicable on a landing page)

| # | Heuristic | Score | Key issue |
|---|---|---|---|
| 1 | Visibility of system status | 3 | Good form states; no position cue on a 16,000 px phone page, header not sticky |
| 2 | Match with the real world | 3 | SAR, 15% VAT, 05 numbers and Arabic digits accepted; a generic Western café stock photo and a generic chat mock weaken it |
| 3 | User control and freedom | 3 | Skip link, consent decline, reduced motion; no way back to the action while reading |
| 4 | Consistency and standards | 2 | Radii from 0 to 28 px with no system; grey card titles on white tiles; «مراحل العمل» links to the settings card, not the journey |
| 5 | Error prevention | 3 | Saudi mobile check with hint and example |
| 6 | Recognition over recall | 3 | Visible labels and clear tabs |
| 7 | Flexibility and efficiency | n/a | One-action landing page |
| 8 | Aesthetic and minimalist design | 2 | 34 same-shaped icon cards in four grids; the same claims (WhatsApp, branches, payments) repeated three times |
| 9 | Error recovery | 3 | Specific Arabic messages |
| 10 | Help and documentation | n/a | Landing page |
| | **Total** | **22/32** | Acceptable: solid base, generic middle |

### Technical audit

| # | Dimension | Score | Key finding |
|---|---|---|---|
| 1 | Accessibility | 3 | Placeholder contrast 4.2:1 on the form; footer links and the hero's second link are 25–30 px tall on phones |
| 2 | Performance | 3 | Lighthouse 97 mobile; the role clips are 2–3 MB each yet unreadable at 375 |
| 3 | Theming | 2 | Brand tokens exist, but product fragments hard-code hex values that drift from the app's badge, radius and shadow |
| 4 | Responsive design | 3 | No overflow at any width; recorded clips and some fragment text (10–11 px) too small on phones |
| 5 | Implementation integrity | 2 | One template (icon tile, heading, text) carries most of the page; the corner-cut `clip-path` stops cards from having a border or shadow |
| | **Total** | **13/20** | Acceptable |

## Design specificity

Mixed. The hero order window (`ORD-2026-00178`) and the stage editor (`.cfg`) belong to OpsOne and nothing else. Their
sample data adds up: 48,300 = 2 × 24,150, and the quote and invoice totals are correct. Everything between them is a
generic SaaS template:

- `#fit`, `#benefits`, `#sales` and `#capabilities` are four grids of the same «icon tile, heading, text» card, 34 cards
  in all, at one heading size.
- The consultation photo in `#sales` is a café stock scene with no link to a showroom, a factory or Saudi Arabia.
- The corner cut is the only identity device, applied as a sticker rather than building the page.

## What works

1. The hero proves the product instead of describing it: a stage finishes, the next starts, the client is told on WhatsApp.
2. The stage editor shows the one mechanism a competitor cannot copy: versions, a draft, and running orders that stay on
   their version.
3. Arabic typography, RTL, tabular figures, reduced motion and Arabic-digit phone input are solid.

## Priority issues

| Severity | Issue | Fix |
|---|---|---|
| P0 (pre-launch config, not design) | `config.js` has an empty `demoEndpoint` and `demoEmail`, so every submission ends in «لم يُرسل طلبك» | Set the endpoint before launch (already tracked in README «Domain» and «Sample content») |
| P1 | The repeated card template dissolves the message and makes the phone page about 16,000 px long | Turn `#sales` into one journey pinned while scrolling, each step showing the screen that does it; give `.custom` its own weight; quieten `#fit`, `#benefits` and `#capabilities` into ruled lists instead of tiles |
| P1 | Fragments read as drawings of the product, not the product: no sidebar icons in the hero, a thin top bar, small chips, a marketing corner cut on the active tab, the WhatsApp toast covering the money panel, and recorded clips too small to read | Rebuild the fragments from `src/resources/js` (sidebar with the real Lucide icons, full top bar, `badge.tsx` sizing, square tabs); move the toast off the money panel; frame the clips so they can be read |
| P2 | Radii, shadows, title colours and logo size are inconsistent; there are two worlds, flat borderless marketing cards and bordered product mocks | One radius set (cut corner for marketing pictures and the CTA, 12 px for product, full for pills); ink titles; one elevation token (hairline ring plus an offset soft drop); a footer with a proper signature |
| P2 | Motion decorates more than it explains: every section has the same rise-and-fade, the hero's second beat starts at 3.4 s and never replays, the stage editor barely moves | Drop the blanket reveal; spend motion on the hero beat, the pinned journey and the release in the stage editor |

## Detector (`impeccable detect --json`)

13 findings, all in `index.html`. The detector does not report line numbers; the sources were traced by hand.

| Rule | Count | Verdict |
|---|---|---|
| low-contrast | 1 | Real: placeholder `05XXXXXXXX` at 4.2:1 (`styles.css`, `.field input::placeholder`) |
| cramped-padding | 11 | False positive: `.fit`, `.tools`, `.demo` and the `.fit__list li` cards have padding; the static scan read the cut corners and the pre-reveal state |
| dark-glow | 1 | False positive in kind: a neutral navy elevation on the WhatsApp toast, though the toast is moved in the redesign anyway |

The detector's URL scan timed out at all three widths, so the browser evidence came from Playwright: no horizontal
overflow, no console errors, every reveal completes, logo 131×32 in header and footer.

## Personas

- **A showroom owner on a phone:** the first screen convinces. Then six sections and 16,000 px pass before the form, with
  no sticky way to act; the recorded clip cannot be read; the café photo asks «is this for us?»; the form fields have no
  visible edge on teal.
- **An operations manager at a factory:** finds the stage editor convincing but buried as card 9; the production tasks in
  the hero are hidden on phones; «مراحل العمل» lands on settings, not the journey.
- **A ready-goods retailer:** reads «من المعاينة إلى التركيب» as not for them; ready selling gets one card and a 3/12 tile;
  the e-invoice says «قيد الإطلاق».

## Questions left to the product owner

1. The hero still says «من المعاينة إلى التركيب» while the approved positioning is «من أول اتصال إلى آخر توقيع». Changing
   the hero copy needs approval, so the redesign keeps it.
2. The zakat-compliant e-invoice claim in «لمن OpsOne؟» versus the «قيد الإطلاق» badge on the invoice tile.
3. The order and portal clips still show «مطبخ» from the showcase data.

## What the redesign changed in response

Recorded in `README.md` («Redesign round»). In short: the sales journey is pinned while scrolling, with the eight screens
built from the app's components and one order's numbers; the hero window has the real sidebar and top bar and the toast
off the money panel; the card grids became ruled lists; one radius and elevation system; a larger logo and a rebuilt
footer; motion only where it explains a state change.
