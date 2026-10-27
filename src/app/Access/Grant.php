<?php

namespace App\Access;

use App\Access\Enums\Scope;

/**
 * One effective grant: a permission within a scope and, for organizational scopes, its target ids
 * (department, branch or region ids depending on the scope).
 */
final readonly class Grant
{
    /**
     * @param  list<int>  $targets
     */
    public function __construct(
        public Permission $permission,
        public Scope $scope,
        public array $targets = [],
    ) {}

    public function key(): string
    {
        $targets = $this->targets;
        sort($targets);

        return $this->permission->value.'|'.$this->scope->value.'|'.implode(',', $targets);
    }
}
