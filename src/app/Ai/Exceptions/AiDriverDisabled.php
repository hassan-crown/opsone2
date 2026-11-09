<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The AI driver refuses to send (explicit switch or key missing; ADR-0050, Q-94).
 */
final class AiDriverDisabled extends RuntimeException {}
