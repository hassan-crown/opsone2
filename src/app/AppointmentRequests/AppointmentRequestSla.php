<?php

namespace App\AppointmentRequests;

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use App\Models\WorkSettings;
use App\Notifications\Notifier;
use Carbon\CarbonInterface;

/**
 * The response time of an assigned request (ADR-0039): once `due_at` passed and it is still not booked or closed,
 * `appointment_request.overdue` goes to the assignee and to whoever assigned it, once per request and due time,
 * inside the reminder hours. Hourly from `appointment-requests:remind`.
 */
final class AppointmentRequestSla
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }
        $sent = 0;
        $late = AppointmentRequest::query()->where('status', AppointmentRequestStatus::Assigned->value)
            ->whereNotNull('due_at')->where('due_at', '<=', $now)->orderBy('id')->limit(500)->get();
        $users = User::query()->whereIn('id', $late->pluck('assignee_id')->merge($late->pluck('assigned_by'))->filter()->unique()->all())->get()->keyBy('id');
        foreach ($late as $request) {
            $stamp = $request->due_at?->getTimestamp();
            $params = ['reference' => (string) $request->reference, 'name' => $request->name];
            foreach (array_unique(array_filter([$request->assignee_id, $request->assigned_by])) as $id) {
                $user = $users->get($id);
                if ($user !== null && AppointmentRequests::canManage($user, $request)) {
                    $sent += (int) ($this->notifier->send($user, 'appointment_request.overdue', $params, $request, "appointment_request:{$request->id}:overdue:{$stamp}") !== null);
                }
            }
        }

        return $sent;
    }
}
