# E-signature samples (ADR-0029)

From the ShowcaseSeeder data on the `opsone_sign` stack (MariaDB), 2026-11-01.

- `signed-quote-QUO-2026-00070.pdf`, `signed-contract-CON-2026-00002.pdf` — signed PDFs: the original pages unchanged
  plus the signature certificate page (`*-p1..p3.jpg` are page renders). They were signed before two later display
  fixes and, being signed, are never regenerated: on their certificate the masked mobile shows its `+` at the end
  (now isolated LTR).
- `quote-QUO-2026-00123-ar.pdf` / `-en.pdf` — an unsigned quote in both languages (layout version 2: an Arabic
  paragraph keeps its own direction inside the English PDF).
- `portal-*` — the customer's signing panel and the signed state (desktop and 390 px); `staff-*` — the order agreement
  page with the PDF buttons, signing-link status and the signature evidence.
