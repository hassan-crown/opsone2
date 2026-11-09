<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider did not answer within the task timeout.
 */
final class AiProviderTimeout extends RuntimeException {}
