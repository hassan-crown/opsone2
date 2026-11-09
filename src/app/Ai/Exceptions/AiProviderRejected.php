<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider refused the request itself (bad request, key, permission, size: 4xx other than 408/409/429). A
 * configuration or request problem, not an outage, so it does not count toward the circuit breaker.
 */
final class AiProviderRejected extends RuntimeException {}
