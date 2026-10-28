<?php

namespace App\Workflow;

use App\Models\Flow;
use App\Support\StaleRecord;

/**
 * Locks the facility flow for an M-23 Action and checks the editor's `lock_version`.
 */
final class FlowLoader
{
    public function __construct(private readonly FlowProvisioner $provisioner) {}

    public function lock(?int $expectedVersion): Flow
    {
        $id = $this->provisioner->ensure()->id;
        $flow = Flow::query()->lockForUpdate()->findOrFail($id);

        if ($expectedVersion !== null && $flow->lock_version !== $expectedVersion) {
            throw new StaleRecord($flow->lock_version);
        }

        return $flow;
    }
}
