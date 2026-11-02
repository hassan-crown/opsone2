<?php

namespace App\EInvoicing\Zatca\Api;

use RuntimeException;

/**
 * ZATCA could not be reached or answered 429/5xx: retryable (the outbox backs off). The message never carries a
 * credential or the invoice.
 */
final class ZatcaUnavailable extends RuntimeException {}
