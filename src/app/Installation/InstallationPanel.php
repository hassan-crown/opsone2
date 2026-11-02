<?php

namespace App\Installation;

use App\Access\Access;
use App\Access\Permission;
use App\Calendar\CalendarPresenter;
use App\Enums\AppointmentStatus;
use App\Files\AttachmentService;
use App\Models\Appointment;
use App\Models\Installation\InstallationPhoto;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\Order;
use App\Models\User;

/**
 * The installation card of the order page (ADR-0036): each installation appointment with its status (not started,
 * in progress, finished, finished with remarks), actual arrival and duration, photos, the acceptance signature and
 * the remarks, with «resolve» for P-52 holders. Files are shown only to viewers who may open them (P-24 over the
 * visit), so the card never hands out a link that would answer 404.
 */
final class InstallationPanel
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forOrder(Order $order, User $viewer): ?array
    {
        $appointments = Appointment::query()->where('order_id', $order->id)->where('kind', 'installation')
            ->whereNotIn('status', [AppointmentStatus::Proposed->value, AppointmentStatus::Cancelled->value])
            ->with(['members', 'installationVisit'])->orderBy('starts_at')->limit(20)->get();
        if ($appointments->isEmpty()) {
            return null;
        }
        $visitIds = $appointments->pluck('installationVisit.id')->filter()->values()->all();
        $photos = InstallationPhoto::query()->whereIn('installation_visit_id', $visitIds)->with('attachment')->orderBy('id')->get()->groupBy('installation_visit_id');
        $remarks = InstallationRemark::query()->whereIn('installation_visit_id', $visitIds)->orderBy('id')->get()->groupBy('installation_visit_id');

        return [
            'can_resolve' => Access::can($viewer, Permission::P52, $order),
            'visits' => array_values($appointments->map(function (Appointment $a) use ($order, $viewer, $photos, $remarks) {
                $a->setRelation('order', $order);
                $visit = $a->installationVisit;
                $visit?->setRelation('appointment', $a);
                $files = $visit !== null && Access::can($viewer, Permission::P24, $visit);
                $start = CalendarPresenter::local($a->starts_at);

                return [
                    'appointment_id' => $a->id,
                    'date' => $start->format('Y-m-d'),
                    'start' => $start->format('H:i'),
                    'team' => array_values($a->members->map(fn (User $u) => $u->name)->all()),
                    'status' => InstallationStatus::of($visit)->value,
                    'arrived_at' => $visit === null ? null : CalendarPresenter::local($visit->arrived_at)->format('Y-m-d H:i'),
                    'finished_at' => $visit?->finished_at === null ? null : CalendarPresenter::local($visit->finished_at)->format('Y-m-d H:i'),
                    'duration_minutes' => $visit?->durationMinutes(),
                    'signature' => $visit === null || ! $visit->isSigned() ? null : [
                        'name' => $visit->signer_name,
                        'method' => $visit->signature_method,
                        'signed_at' => CalendarPresenter::local($visit->signed_at ?? now())->format('Y-m-d H:i'),
                        'url' => $files ? $this->signatureUrl($visit, $viewer) : null,
                    ],
                    'photos' => ! $files ? [] : array_values(($photos[$visit->id] ?? collect())->map(fn (InstallationPhoto $p) => [
                        'id' => $p->id, 'phase' => $p->phase, 'url' => $this->files->downloadUrl($p->attachment, $viewer),
                    ])->all()),
                    'photo_count' => $visit === null ? 0 : ($photos[$visit->id] ?? collect())->count(),
                    'remarks' => $visit === null ? [] : array_values(($remarks[$visit->id] ?? collect())->map(fn (InstallationRemark $r) => FieldPresenter::remark($r))->all()),
                ];
            })->all()),
        ];
    }

    private function signatureUrl(InstallationVisit $visit, User $viewer): ?string
    {
        $image = $visit->signature()->first();

        return $image === null ? null : $this->files->downloadUrl($image, $viewer);
    }
}
