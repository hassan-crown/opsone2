<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Task;
use App\Notifications\NotificationTargets;
use Illuminate\Support\ServiceProvider;

/**
 * P5 workflow engine (ADR-0012): registers task and appointment notification targets. Both open the order page,
 * where the task or appointment is shown only after the Gate re-checks P-24 on the live record.
 */
class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $targets = $this->app->make(NotificationTargets::class);

        $targets->register(
            (new Task)->getMorphClass(),
            fn (int $id) => Task::query()->with('order')->find($id),
            // The task's own page: an executor may hold the task without P-16 on the whole order.
            fn (object $task) => route('tasks.show', ['task' => $task instanceof Task ? $task->id : 0]),
        );
        // ADR-0038: a late workshop purchase order opens the purchase order page (P-46 re-checked on open).
        $targets->register(
            (new PurchaseOrder)->getMorphClass(),
            fn (int $id) => PurchaseOrder::query()->find($id),
            fn (object $po) => route('purchases.show', ['purchase' => $po instanceof PurchaseOrder ? $po->id : 0]),
        );
        $targets->register(
            (new Appointment)->getMorphClass(),
            fn (int $id) => Appointment::query()->with('order')->find($id),
            fn (object $appointment) => route('appointments.show', ['appointment' => $appointment instanceof Appointment ? $appointment->id : 0]),
        );
    }
}
