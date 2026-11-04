<?php

namespace App\Automation;

use App\Audit\AuditAction;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\Finance\OrderDue;
use App\Models\Interest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * The closed catalogue of events a rule may react to (ADR-0042, analysis §4.1). Every trigger reuses a fact the
 * application already records — a state-machine transition (`state_transitions`), an audit event (`audit_events`,
 * for changes that are not a machine) — or a timer the scheduler evaluates on live data (due dates, idle leads,
 * late tasks). Nothing listens to arbitrary model changes.
 *
 * Each trigger names its source, its subject model, the fact groups its conditions may use and, for a timer, its one
 * numeric parameter («3 days before», «48 hours»). Domains add triggers here (field sales: `lead.*`).
 */
final class AutomationTriggers
{
    public const TRANSITION = 'transition';

    public const AUDIT = 'audit';

    public const TIMER = 'timer';

    /**
     * @var array<string, array{group: string, source: string, machine?: string, transition?: string, action?: string, kind?: string, subject: class-string<Model>, facts: list<string>, param?: array{name: string, min: int, max: int, default: int}}>
     */
    public const DEFINITIONS = [
        'order.stage_started' => ['group' => 'orders', 'source' => self::TRANSITION, 'machine' => 'M-06', 'transition' => 'T01', 'subject' => OrderStage::class, 'facts' => ['order', 'customer', 'stage']],
        'order.stage_completed' => ['group' => 'orders', 'source' => self::TRANSITION, 'machine' => 'M-06', 'transition' => 'T04', 'subject' => OrderStage::class, 'facts' => ['order', 'customer', 'stage']],
        'quote.sent' => ['group' => 'documents', 'source' => self::TRANSITION, 'machine' => 'M-08', 'transition' => 'T01', 'kind' => 'quote', 'subject' => AgreementDocument::class, 'facts' => ['order', 'customer', 'document']],
        'quote.expired' => ['group' => 'documents', 'source' => self::TRANSITION, 'machine' => 'M-08', 'transition' => 'T05', 'kind' => 'quote', 'subject' => AgreementDocument::class, 'facts' => ['order', 'customer', 'document']],
        'document.approved' => ['group' => 'documents', 'source' => self::TRANSITION, 'machine' => 'M-08', 'transition' => 'T02', 'subject' => AgreementDocument::class, 'facts' => ['order', 'customer', 'document']],
        'payment.due_soon' => ['group' => 'finance', 'source' => self::TIMER, 'subject' => OrderDue::class, 'facts' => ['order', 'customer', 'due'], 'param' => ['name' => 'days', 'min' => 1, 'max' => 30, 'default' => 3]],
        'payment.overdue' => ['group' => 'finance', 'source' => self::TIMER, 'subject' => OrderDue::class, 'facts' => ['order', 'customer', 'due'], 'param' => ['name' => 'days', 'min' => 1, 'max' => 90, 'default' => 7]],
        'appointment_request.created' => ['group' => 'appointments', 'source' => self::AUDIT, 'action' => 'appointment_request.submitted', 'subject' => AppointmentRequest::class, 'facts' => ['request']],
        'appointment.scheduled' => ['group' => 'appointments', 'source' => self::TRANSITION, 'machine' => 'M-16', 'transition' => 'T00', 'subject' => Appointment::class, 'facts' => ['order', 'customer', 'appointment']],
        'appointment.confirmed' => ['group' => 'appointments', 'source' => self::TRANSITION, 'machine' => 'M-16', 'transition' => 'T02', 'subject' => Appointment::class, 'facts' => ['order', 'customer', 'appointment']],
        'installation.remark_opened' => ['group' => 'installation', 'source' => self::AUDIT, 'action' => 'installation.remark_recorded', 'subject' => Order::class, 'facts' => ['order', 'customer']],
        'installation.remark_resolved' => ['group' => 'installation', 'source' => self::AUDIT, 'action' => 'installation.remark_resolved', 'subject' => Order::class, 'facts' => ['order', 'customer']],
        'lead.created' => ['group' => 'leads', 'source' => self::TRANSITION, 'machine' => 'M-03', 'transition' => 'T00', 'subject' => Interest::class, 'facts' => ['lead']],
        'lead.assigned' => ['group' => 'leads', 'source' => self::TRANSITION, 'machine' => 'M-03', 'transition' => 'T01', 'subject' => Interest::class, 'facts' => ['lead']],
        'lead.stale' => ['group' => 'leads', 'source' => self::TIMER, 'subject' => Interest::class, 'facts' => ['lead'], 'param' => ['name' => 'hours', 'min' => 1, 'max' => 720, 'default' => 48]],
        'task.created' => ['group' => 'tasks', 'source' => self::TRANSITION, 'machine' => 'M-07', 'transition' => 'T00', 'subject' => Task::class, 'facts' => ['order', 'customer', 'task']],
        'task.sla_breached' => ['group' => 'tasks', 'source' => self::TIMER, 'subject' => Task::class, 'facts' => ['order', 'customer', 'task'], 'param' => ['name' => 'hours', 'min' => 0, 'max' => 720, 'default' => 24]],
    ];

    /**
     * @return array{group: string, source: string, machine?: string, transition?: string, action?: string, kind?: string, subject: class-string<Model>, facts: list<string>, param?: array{name: string, min: int, max: int, default: int}}|null
     */
    public static function get(string $trigger): ?array
    {
        return self::DEFINITIONS[$trigger] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * The triggers a transition feeds (one transition may feed several, e.g. by document kind).
     *
     * @return list<string>
     */
    public static function forTransition(string $machine, string $transition): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $d) {
            if ($d['source'] === self::TRANSITION && $d['machine'] === $machine && $d['transition'] === $transition) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function forAudit(string $action): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => $d) {
            if ($d['source'] === self::AUDIT && $d['action'] === $action) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function timers(): array
    {
        return array_keys(array_filter(self::DEFINITIONS, fn (array $d) => $d['source'] === self::TIMER));
    }

    /**
     * Whether the subject is the one the trigger means (a quote, not a contract).
     */
    public static function accepts(string $trigger, Model $subject): bool
    {
        $kind = self::DEFINITIONS[$trigger]['kind'] ?? null;

        return $kind === null || ($subject instanceof AgreementDocument && $subject->kind->value === $kind);
    }

    /**
     * The order an event's subject belongs to, if any.
     */
    public static function orderIdOf(Model $subject): ?int
    {
        if ($subject instanceof Order) {
            return $subject->id;
        }
        if ($subject instanceof Interest) {
            return $subject->converted_order_id;
        }
        $id = $subject->getAttribute('order_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The timer parameter of a rule (or the trigger's default), clamped to its range; null for non-timers.
     *
     * @param  array<string, mixed>  $params
     */
    public static function param(string $trigger, array $params): ?int
    {
        $param = self::DEFINITIONS[$trigger]['param'] ?? null;
        if ($param === null) {
            return null;
        }

        return max($param['min'], min($param['max'], (int) ($params[$param['name']] ?? $param['default'])));
    }

    /** The audit action names the catalogue uses exist (guards against a renamed audit action). */
    public static function auditAction(string $trigger): ?AuditAction
    {
        $action = self::DEFINITIONS[$trigger]['action'] ?? null;

        return $action === null ? null : AuditAction::from($action);
    }
}
