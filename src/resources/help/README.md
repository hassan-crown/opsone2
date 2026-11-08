# Knowledge base files (ADR-0048)

`php artisan help:sync` (also run by `HelpSeeder`) mirrors these files into `help_articles`. It is all or nothing:
one invalid file stops the sync and nothing changes. `--dry-run` validates without writing.

```
ar/{staff|customer}/{slug}.md     Arabic, authoritative: frontmatter + Markdown body
en/{staff|customer}/{slug}.md     optional English: slug, title, summary + body (the rest comes from the Arabic file)
images/{slug}-{n}.png             png, jpg/jpeg or webp; referenced as ![description](images/{slug}-{n}.png)
```

Frontmatter (a small YAML subset: `key: value`, flow lists `[a, b]`, `#` comments):

```
slug: create-quote            # required, = file name, lowercase latin words joined by «-»
title: كيف أنشئ عرض سعر         # required
summary: سطر واحد              # required
category: daily_work          # required: getting_started | daily_work | settings | faq | role_guide
roles: [owner, manager]       # optional, keys of PermissionCatalogue::ROLES; «My guide» only, never access
permissions: [P-12]           # optional, shown to whoever holds ANY of them; empty = every reader of the audience
capability: ready_goods       # optional: ready_goods | branch_comparisons | marketing_reports
page_key: quotes.index        # optional: route name of the page whose «?» opens this article
order: 10                     # optional, 0–65535 (stored in `sort`)
```

Rules checked by `help:sync` and `tests/Feature/Help/KnowledgeBaseTest.php`:

- Unknown keys, roles, permissions, capabilities, route names and missing images are errors.
- Customer articles take no `permissions` or `roles`, and their `page_key` must be a `customer.*` route; staff
  articles must not point at a `customer.*` route.
- When `capability` is omitted and every listed permission needs the same capability (e.g. P-12 → `ready_goods`),
  that capability applies. Unlicensed: only the owner and managers see the article, badged «متاحة في باقة أعلى».
- Link another article with `[title](article:slug)` (same audience only; it opens on the reader's side:
  `/help/{slug}` for staff, `/portal/help/{slug}` for customers). Never hard-code `/help/...`.
- Raw HTML in the body is stripped; images may only be files from `images/` named after the article.
- Removing a file deletes its article at the next sync.
