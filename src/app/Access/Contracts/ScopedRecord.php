<?php

namespace App\Access\Contracts;

/**
 * Implemented by every domain record that is authorized with a data scope (order, task, customer, ...).
 * The scope resolver matches a grant against these facts; it never trusts ids sent by the client.
 */
interface ScopedRecord
{
    /**
     * Branches the record belongs to. A record spanning several branches is in a `branches`/`region`
     * scope only when ALL of them are covered. An empty list means the record is outside every branch scope.
     *
     * @return list<int>
     */
    public function accessBranchIds(): array;

    public function accessDepartmentId(): ?int;

    /**
     * Users currently assigned to the record (`assigned` scope).
     *
     * @return list<int>
     */
    public function accessAssigneeIds(): array;

    /**
     * User who owns the record (`own` scope), e.g. the creator of a sales basket.
     */
    public function accessOwnerId(): ?int;
}
