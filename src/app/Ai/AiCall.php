<?php

namespace App\Ai;

use App\Ai\Contracts\OutputCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What a feature (Proposer) asks the gateway for. The context must already be built with the acting user's grants
 * (Authorizer + ScopedQuery, ADR-0050 §2); the gateway never widens it.
 *
 * - `system`: the stable instructions (cached prefix). Never facility data that changes per request.
 * - `facts`: named, trusted values from the database (`order.stage_public`, `due.amount_halalas`). Every number,
 *   date and link in the output must come from here (plan §3.2, §3.3).
 * - `untrusted`: customer text, file names, notes — wrapped as data the model must not obey (plan §3.3).
 * - `known`: values to hide that no pattern finds (detailed address, a customer's full name), by kind.
 * - `checks`: the feature's own output checks (e.g. the inbox's financial check), run before the call is charged.
 */
final readonly class AiCall
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $untrusted
     * @param  array<string, mixed>  $schema  JSON schema of the expected output (structured output)
     * @param  array<string, list<string>>  $known  e.g. ['ADDRESS' => ['حي النرجس، شارع 12، مبنى 4']]
     * @param  list<OutputCheck>  $checks
     */
    public function __construct(
        public AiTask $task,
        public string $system,
        public string $instruction,
        public array $schema,
        public array $facts = [],
        public array $untrusted = [],
        public array $known = [],
        public ?User $user = null,
        public ?Model $subject = null,
        public bool $untrustedIsSource = false,
        public array $checks = [],
    ) {}
}
