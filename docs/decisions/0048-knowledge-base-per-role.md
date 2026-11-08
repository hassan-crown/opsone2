# ADR-0048: Knowledge base per role (files → help:sync, server-side filtering)

- Status: Accepted (product owner, 2026-11-08)
- Context: SHR-07 help center (P3 part C). The product owner approved a knowledge base per role: Arabic first, with
  images, and every reader sees only what their permissions allow.

## Decision

1. **Articles are files** in `src/resources/help/{ar,en}/{staff,customer}/{slug}.md` with a frontmatter (format in
   `src/resources/help/README.md`), images in `src/resources/help/images/{slug}-{n}.png`. `help:sync` mirrors them
   into `help_articles` (new columns `audience`, `roles`, `permissions`, `capability`, `source_path`; frontmatter
   `order` → existing `sort`). Idempotent (unchanged rows keep `updated_at`), all-or-nothing on validation errors,
   deletes rows whose file is gone (only rows with a `source_path`). `HelpSeeder` calls it.
   The frontmatter parser is a strict in-house subset because `symfony/yaml` is dev-only and the sync runs in
   production.
2. **Filtering on the server** through `App\Help\HelpReader`, used by the list, category, article, «My guide», the
   contextual drawer/redirect, global search and images:
   - audience: staff ↔ `staff`, portal customers ↔ `customer` (portal pages under `/portal/help`);
   - permissions: held = the effective grants (any scope), so a P-xx the plan lacks still counts as held;
   - capability not licensed: visible only to the owner and managers with `locked = true` («متاحة في باقة أعلى»),
     hidden from everyone else. When the file names no capability but all its permissions need the same one, that
     capability applies.
   A non-readable article answers 404, like a missing one.
3. **Rendering**: Markdown → HTML on the server (league/commonmark, raw HTML stripped, unsafe links dropped); an
   image must be `images/{slug}-{n}.{png|jpg|jpeg|webp}` and is rewritten to `help.image` / `customer.help.image`,
   which re-checks that the reader may read that slug and that the real path stays in the images folder.
4. **«My guide»** (`help/guide`): `role_guide` articles naming one of the reader's roles, then the other articles
   naming those roles, by `order`. **«Start here»**: getting-started articles the reader may read, on the home page
   (deferred prop) and the help center, until closed (`users.help_start_dismissed_at`).
5. **Contextual «?»** opens a side drawer fed by `help/panel` (JSON); `help/context` stays as the no-JS redirect.

## Consequences

- Content authors only edit files; the coverage test fails on any unknown permission, role, page key, capability or
  missing image, and on a file without the required keys.
- `LicensedCapabilities::KNOWN` is now the single list of license capabilities (plan page, help validation).
