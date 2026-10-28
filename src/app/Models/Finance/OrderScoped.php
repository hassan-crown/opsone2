<?php

namespace App\Models\Finance;

use App\Models\Order;

/**
 * Finance records follow their order's scope: a grant over the order is a grant over its money records.
 *
 * @property-read Order $order
 */
trait OrderScoped
{
    public function accessBranchIds(): array
    {
        return $this->order->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return $this->order->accessDepartmentId();
    }

    public function accessAssigneeIds(): array
    {
        return $this->order->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->accessOwnerId();
    }
}
