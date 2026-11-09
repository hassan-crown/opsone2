<?php

namespace App\Ai\Insights;

use RuntimeException;

/**
 * A tool call the model asked for that the server refuses (unknown tool, a report the user may not open, bad
 * parameters, a branch outside the user's scope). The message names the rule, never the offending value.
 */
final class InvalidToolCall extends RuntimeException {}
