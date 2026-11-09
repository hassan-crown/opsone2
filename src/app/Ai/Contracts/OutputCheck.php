<?php

namespace App\Ai\Contracts;

/**
 * A feature's own check of a model answer, run by the gateway after the general OutputValidator and before the call is
 * charged (an answer a check rejects is `invalid` and costs nothing). It sees the output and the facts as sent, i.e.
 * still redacted. Problems name paths and kinds only, never the offending text.
 */
interface OutputCheck
{
    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function check(array $output, array $facts): array;
}
