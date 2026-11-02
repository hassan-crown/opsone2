# ADR-0034: Marketing landing page as a static site in `marketing/landing/`

Status: accepted (2026-11-02).

## Context

OpsOne needs a public marketing page that sells the product to owners of Saudi made-to-order and installation
businesses, with one action: a demo request. The repository boundary puts all Laravel code in `src/` and keeps
infrastructure, scripts and docs at the root. The product's own public website module (`src/`, per-facility site
and store) belongs to each facility's instance and is not OpsOne's own marketing site.

## Decision

- The landing page lives in a new top-level folder **`marketing/landing/`** as a self-contained static site: plain
  HTML, CSS and JavaScript, no build step, fonts and pictures stored locally. It does not touch `src/` or the
  Docker/Traefik setup and needs no Laravel instance to run or host.
- It reuses the brand from `docs/design-system/` (palette, IBM Plex Sans Arabic under the OFL, the logo generated from
  the original geometry) and real screens from `docs/screenshots/`.
- The demo request form posts to an endpoint set in one constant, `marketing/landing/config.js`
  (`demoEndpoint`, with an optional `demoEmail` mailto fallback). Until it is set, the form says plainly that requests
  are not being received; it never shows a fake success.

## Consequences

- Any static host can serve the folder as it is; deployment and the receiving endpoint are decided later and are not
  part of this decision.
- The page copies brand assets rather than linking into `docs/`; a brand change must be copied into
  `marketing/landing/assets/` as well.
- Product statements on the page must be kept true as the product changes (see the page's `README.md`, «Sample
  content to confirm before launch»).
