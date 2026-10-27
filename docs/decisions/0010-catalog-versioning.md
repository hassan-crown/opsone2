# ADR-0010 — Catalog: bilingual JSON, versions and soft-disable (COM-04..06, Q-28)

Status: accepted (Q-28 default (a))

## Decision

- **Bilingual text** (names, descriptions, option labels) is one JSON column `{ar,en}` with the shared cast
  `App\Support\Translatable\TranslatableString` (value `Translated`); `get($locale)` falls back to the other language.
  Arabic is required in forms; English is optional.
- **Money** is an unsigned integer of minor units (halalas) in `*_minor` columns. `App\Catalog\MinorUnits` parses the
  decimal text a person types (≤ 2 decimals, string arithmetic) and rejects anything else instead of rounding. An unknown
  reference price is `NULL`, never `0`.
- **Soft-disable only.** Products, materials, ready products and variants are never deleted and no route deletes. Status
  `disabled` (and `draft` for ready goods) removes a row from `selectable()` — new work cannot pick it — while every
  past use (an option group's material, an order line's snapshot) keeps working. A material or variant already inside
  a product's groups may stay; a disabled one cannot be newly added.
- **Versions.** `catalog_versions` is an append-only history (`versionable`, `version`, `payload`): version 1 on create
  and a new one whenever the *specification* changes (name, description, category, price basis, reference price, option
  groups; for variants code, name, price). A no-op save and a status change write none. Internal fields (supplier,
  quantity tracking) are not in the payload, so they are never frozen into an order. The model refuses update/delete.
  Later order lines snapshot a version id and read `payload`, so catalog edits never change an agreed line.
- **Ready goods** need the license capability `ready_goods` (the Gate's license step on P-12) and sell through variants:
  one default variant **or** several named ones, a unique code per facility, a price in minor units, own status. Stock is
  owned by the inventory domain (P7), not stored here.
- **Authorization:** P-11 (custom products), P-13 (materials), P-12 (ready goods). All three are facility-wide
  (`tenant` scope), so catalog rows implement `ScopedRecord` with no branch. Images use the private attachments service
  (upload needs the row's permission; downloads are signed links that re-check it).
- **Audit area `catalog`**: create / update (before-after of the specification) / disable / enable / publish, inside the
  change's transaction (`App\Catalog\CatalogWriter` is the single write path).

## Consequences

- Option groups are plain JSON inside the product and its versions (fixed kinds: single, multiple, text, number+unit,
  attachment) — there is no logic editor, per COM-04.
- Removing an image is not offered yet (the attachments service has no delete); revisit with P-62/retention.
