<?php

namespace App\Automation;

use App\Enums\InterestStatus;
use App\Finance\OpenDues;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\Automation\AutomationEvent;
use App\Models\Customer;
use App\Models\Finance\OrderDue;
use App\Models\Interest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Task;
use App\Workflow\AppointmentBooking;
use App\Workflow\StageEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What an event is about, read live when a rule is evaluated (ADR-0042): the subject record, its order and customer
 * when it has them, and the typed facts conditions compare (`facts()`, keyed like AutomationFields). Timers re-read
 * the same facts at run time, so a due paid in the meantime no longer matches «amount > 0».
 */
final class AutomationSubject
{
    /** @var array<string, mixed>|null */
    private ?array $facts = null;

    private function __construct(
        public readonly AutomationEvent $event,
        public readonly ?Model $record,
        public readonly ?Order $order,
        public readonly ?Customer $customer,
    ) {}

    public static function of(AutomationEvent $event): self
    {
        $class = Relation::getMorphedModel($event->subject_type) ?? $event->subject_type;
        /** @var Model|null $record */
        $record = class_exists($class) ? $class::query()->find($event->subject_id) : null;
        $orderId = $event->order_id ?? ($record === null ? null : AutomationTriggers::orderIdOf($record));
        $order = $orderId === null ? null : Order::query()->with('customer')->find($orderId);
        $customer = $order?->customer;
        if ($customer === null && $record instanceof Interest && $record->customer_id !== null) {
            $customer = Customer::query()->find($record->customer_id);
        }
        if ($customer === null && $record instanceof AppointmentRequest && $record->customer_id !== null) {
            $customer = Customer::query()->find($record->customer_id);
        }

        return new self($event, $record, $order, $customer);
    }

    /** The branch the subject belongs to (scope of role recipients and the `branch` condition). */
    public function branchId(): ?int
    {
        if ($this->order !== null) {
            return $this->order->branch_id;
        }

        return match (true) {
            $this->record instanceof Interest, $this->record instanceof AppointmentRequest => (int) $this->record->branch_id,
            default => null,
        };
    }

    /** The person responsible for the subject: the task's assignee, the lead's or request's, else the order's. */
    public function responsibleId(): ?int
    {
        $id = match (true) {
            $this->record instanceof Task, $this->record instanceof Interest, $this->record instanceof AppointmentRequest => $this->record->assignee_id,
            default => null,
        };

        return $id ?? $this->order?->assignee_id;
    }

    /** A short readable name of the subject for notices and logs. */
    public function label(): string
    {
        return match (true) {
            $this->record instanceof Interest => (string) ($this->record->reference ?? $this->record->name),
            $this->record instanceof AppointmentRequest => (string) ($this->record->reference ?? $this->record->name),
            default => (string) ($this->order->number ?? ''),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }
        $now = CarbonImmutable::now();
        $facts = ['branch' => $this->branchId()];

        if ($this->order !== null) {
            $stage = app(StageEngine::class)->activeStage($this->order);
            $facts += [
                'order.total' => $this->order->total_minor,
                'order.kind' => $this->order->kind->value,
                'order.stage' => $stage?->key,
                'order.age_days' => (int) $this->order->created_at->diffInDays($now),
                'order.assigned' => $this->order->assignee_id !== null,
            ];
        }
        if ($this->customer !== null) {
            $facts += [
                'customer.kind' => $this->customer->kind->value,
                'customer.has_email' => trim((string) $this->customer->email) !== '',
                'customer.whatsapp_opt_out' => $this->customer->whatsapp_opt_out_at !== null,
            ];
        }
        $record = $this->record;
        if ($record instanceof OrderStage) {
            $facts['stage.key'] = $record->key;
        }
        if ($record instanceof AgreementDocument) {
            $facts['document.kind'] = $record->kind->value;
        }
        if ($record instanceof Appointment) {
            $facts['appointment.kind'] = $record->kind;
        }
        if ($record instanceof Interest) {
            $facts += [
                'lead.channel' => $record->channel->value,
                'lead.status' => $record->status->value,
                'lead.age_hours' => (int) $record->created_at->diffInHours($now),
                'lead.assigned' => $record->assignee_id !== null,
                'lead.open' => in_array($record->status, [InterestStatus::New, InterestStatus::Following], true),
            ];
        }
        if ($record instanceof AppointmentRequest) {
            $facts += ['request.kind' => $record->kind, 'request.source' => $record->source];
        }
        if ($record instanceof Task) {
            $facts += [
                'task.hours_late' => $record->due_at === null || $record->due_at->greaterThan($now) ? 0 : (int) $record->due_at->diffInHours($now),
                'task.assigned' => $record->assignee_id !== null,
            ];
        }
        if ($record instanceof OrderDue) {
            $due = $this->due();
            $facts += [
                'due.amount' => $due['remaining'] ?? 0,
                'due.days_late' => $due === null || $due['due_on'] === null ? 0
                    : max(0, (int) CarbonImmutable::parse($due['due_on'])->addDays($record->grace_days)->diffInDays(CarbonImmutable::parse(AppointmentBooking::today()), false)),
                'due.reference' => $record->reference,
            ];
        }

        return $this->facts = $facts;
    }

    /**
     * The open due row (date and remaining amount) of a due subject, read live.
     *
     * @return array{due: OrderDue, order: Order, due_on: string|null, remaining: int}|null
     */
    public function due(): ?array
    {
        if (! $this->record instanceof OrderDue) {
            return null;
        }
        foreach (OpenDues::of(Order::query()->whereKey($this->record->order_id)) as $row) {
            if ($row['due']->id === $this->record->id) {
                return $row;
            }
        }

        return null;
    }
}
