# ADR-0018: Delivery — delivery notes, proof, stock issue and the «at delivery» due (P7c)

- Status: accepted (2026-10-28) — Q-44 (a): proof of delivery = receiver name + photo/file + note; on-screen signature later.
- Scope: domain 06 (SRS-FUL-09, FUL-10), machine M-14 (T00 draft, T01 record, T02 withdraw a draft), the delivery side
  of M-05-T08, the P5 `record_delivery` action result, scenarios 6 and 7 (the delivered base) and «توريد وتسليم جزئيان».
  Permission P-52 over the **order** (scopes assigned / department / branches / region / tenant).
- Builds on: ADR-0017 (inventory: `IssueReadyReservation`, reservations), ADR-0012 (engine: `ActionResults`), ADR-0014
  (dues), ADR-0006 (private attachments), ADR-0011 (gapless numbers, order lock).
- Not in this phase: M-14-T03 correction of a recorded delivery and T04 blocking remarks with follow-up tasks (FUL-10
  remainder) — the returns / cancellation sessions build on `DeliveryProgress::deliveredByLine`.

## Decision

- **Delivery note** (`delivery_notes` + `delivery_note_lines`): order, status `draft | confirmed | cancelled`,
  delivered-on date, receiver name, note, actor and time. A draft holds only intended quantities per order line and
  touches nothing; it takes **no number** and can be withdrawn (T02) with a reason.
- **Recording (T01) is one transaction**: lock the **order row first** (same lock as cancellation and issue, INT-01),
  then the note; re-check each line against what is still deliverable; issue ready lines from their open reservations
  oldest first through `IssueReadyReservation` (balances locked in the global order, movement key
  `delivery:{note}:{line}:{reservation}`); take `DN-YYYY-000123` from `GaplessSequence` (6 digits, per year, one facility
  per deployment); store the proof files as private attachments (`delivery_proof`, followed by P-52 on the order); move a
  ready order `confirmed|partially_delivered → partially_delivered|delivered` (M-05-T08); write M-14 and M-05
  transitions and the audit entry. A failure anywhere (no proof, over quantity, stock) rolls everything back, including
  the number — so numbers are gapless.
- **Ceilings**: a ready line may deliver at most what its open reservations still hold (never more than reserved or
  ordered; a cancelled remainder is already released). A made-to-order line may deliver at most ordered − delivered and
  creates **no stock** (FUL-10). Delivery is accepted for a ready order that is `confirmed|partially_delivered` and a
  custom order that is `in_progress|execution_finished`.
- **Proof (Q-44 a)**: receiver name and at least one file/photo are required to record; the note is optional.
- **Idempotency and locks**: every write takes an `Idempotency-Key` (same key → first result replayed, same key with
  another payload → 422); the note carries `lock_version` (stale → 409); confirming twice with different keys is a state
  error (`not_from_confirmed`), never a second issue.
- **Completion → P5**: a delivery is *complete* when every effective quantity was delivered (or released by an approved
  cancellation) and something was delivered. A custom order then reports
  `ActionResults::verifyFromSource($order, RecordDelivery, null, 'delivery_note', $noteId)` once (idempotent on the
  source); a partial delivery reports nothing and the remainder stays visible. Ready orders have no flow.
- **Dues (P6)**: a new due event `delivery` ("at the first delivery") joins `agreement | payment_stage | date`.
  `DueCalendar` derives its date from the order's **first confirmed** note (`delivered_on`), so later deliveries never
  move it and the due falls due once; reminders already run off derived dates. Product assumption: *first* delivery,
  not complete delivery — a facility that wants «on completion» keeps using a `payment_stage` due before handover.
  Recording also dispatches `App\Delivery\DeliveryConfirmed` after commit (idempotent listeners on `noteId`).
- **Amounts**: delivery pages carry unit prices and line values only with P-37 on the order (keys omitted otherwise);
  quantities and proofs are not financial.
- **Screens**: DelList (`deliveries/index`: awaiting delivery for ready and custom + notes), DelNew (`deliveries/form`),
  DelRecord (`deliveries/show`), and a delivery card on the order page (`orders/show`).

## Consequences

- Returns (P-53) read the delivered base from `DeliveryProgress::deliveredByLine($orderId)` (confirmed notes only).
- Cancellation (P-18) keeps working on the same order lock; a delivery racing it commits or is refused as a whole.
- A correction of a recorded delivery (T03) needs a reversing movement design and is left to the returns session.
