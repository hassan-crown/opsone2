<?php

namespace App\Delivery;

/**
 * Dispatched after commit when a delivery note is confirmed. Listeners must be idempotent on `noteId`. Finance reads
 * its «at delivery» due date from the first confirmed note (`DueCalendar`), so nothing has to be written for dues; this
 * event lets notifications and reports follow without polling.
 */
final readonly class DeliveryConfirmed
{
    public function __construct(
        public int $orderId,
        public int $noteId,
        public bool $first,
        public bool $complete,
    ) {}
}
