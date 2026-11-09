<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * Provider or transport failure. The message never carries prompt text or provider bodies.
 */
final class AiProviderUnavailable extends RuntimeException {}
