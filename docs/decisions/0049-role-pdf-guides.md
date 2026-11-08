# ADR-0049: PDF guide per role from the knowledge base (`help:pdf`)

- Status: Accepted (task KB-P5, 2026-11-08)
- Related: ADR-0048 (knowledge base per role), ADR-0029 (branded PDFs with mPDF).

## Context

Training and handover need a printable book per role. Content lives in `src/resources/help/**` (written by other
sessions and updated over time), so the tool must work on whatever files exist and simply be run again.

## Decision

1. **Command** `./manage.sh artisan help:pdf {role|customer|--all}` (options `--path=` for another knowledge base
   folder, `--output=` for another output folder). Output: `storage/app/help-pdf/ar/{role}.pdf` (`storage/app` is not
   tracked). Roles = the keys of `PermissionCatalogue::ROLES` plus `customer`. It reads the **files** through
   `ArticleLibrary`, not the database, and refuses to print when the library has validation errors.
2. **Selection** (`Help\Pdf\RoleBookBuilder`) mirrors `HelpReader::query()`: staff roles read `staff` articles,
   `customer` reads `customer` ones; an article listing permissions is printed when the role's default grants
   (`PermissionCatalogue::forRole`, plus the «self» permissions every account holds) contain any of them; none listed =
   everyone; the owner holds everything. Capability is not filtered: the book documents the whole product. A test
   compares the selection with `HelpReader` for every role. «Role guide» = `role_guide` articles naming the role
   (every customer `role_guide` for customers), printed first; the rest follow by category
   (`getting_started`, `daily_work`, `settings`, `faq`) and `order`.
3. **Pipeline**: `HelpMarkdown::render()` (the existing safe renderer, now with caller-supplied link and image targets)
   → Blade `help/pdf/book` (cover, contents, role guide, sections; the intermediate HTML) → mPDF. `article:slug`
   becomes `#a-slug` when the article is in the book, otherwise only the link text stays. Images are read from
   `resources/help/images` into memory (`var:`), nothing is fetched from a URL.
4. **Engine: mPDF, no new dependency.** The task preferred a headless browser, but neither the app nor the Node
   container ships Chromium (the Node image is `node:24-bookworm-slim` with no browser, and infra files may not change
   for a feature), whereas mPDF 8.3 is already installed, shapes Arabic through the same IBM Plex Sans Arabic TTF files
   (`resources/fonts/pdf`, local, no CDN), and runs inside the `app` container. Layout: A4, RTL, header and footer with
   «OpsOne» and the role, page numbers restarting after the cover, PDF outline (bookmarks), images and tables kept in
   one piece (`page-break-inside: avoid`).
5. **mPDF quirks handled** (documented in `RoleBookPdf`): once the document holds Arabic text mPDF no longer registers
   `<a name>`/`id` link targets, so the HTML is fed in pieces split at `<!--anchor:id-->` markers, each anchor's page
   and position are recorded and `href="#id"` links are resolved with `SetLink()`. The same positions fill the printed
   page numbers of the contents on a second pass.
6. `PdfRenderer::engine()` became public with a `$config` override (margins) so the book reuses the platform's font
   setup instead of copying it.

## Consequences

- If the licence of mPDF (GPL-2.0, see ADR-0029) or the print quality ever becomes a problem, only `RoleBookPdf`
  changes: the intermediate HTML already carries the contents, anchors and internal links.
- Generated PDFs embed the screenshots, so they are large (a few MB for the owner).
