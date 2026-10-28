<?php

namespace App\Actions\StateMachines\M16Appointment;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\StateMachine;
use App\Models\Appointment;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentLog;
use App\Workflow\Transition;

/**
 * M-16-T01 (P-54): the proposal goes to the customer for confirmation. It still reserves nothing (the customer-portal
 * notice arrives with domain 07).
 */
final class T01SendToCustomer
{
    public function __construct(private readonly AppointmentLog $log) {}

    public function handle(User $actor, Appointment $appointment, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Appointment
    {
        return LockingTransaction::run(function () use ($actor, $appointment, $lockVersion, $source) {
            $appointment = $this->log->lock($appointment, $lockVersion);
            Access::authorize($actor, Permission::P54, $appointment);
            Transition::check(StateMachine::M16, AppointmentStatus::transitions(), $appointment->status, 'T01');

            $appointment->status = AppointmentStatus::AwaitingConfirmation;
            $this->log->record($appointment, 'T01', AppointmentStatus::Proposed, AppointmentStatus::AwaitingConfirmation, $actor, $source, AuditAction::AppointmentSent);

            return $appointment;
        });
    }
}
