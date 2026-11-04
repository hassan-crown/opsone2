<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Calendar\CalendarPresenter;
use App\Enums\AppointmentRequestStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\InterestStatus;
use App\Files\AttachmentService;
use App\Installation\FieldPresenter;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\FieldSales\FieldAssignment;
use App\Models\FieldSales\SalesVisit;
use App\Models\FieldSales\SalesVisitPhoto;
use App\Models\Interest;
use App\Models\OrderMeasurement;
use App\Models\User;
use App\Organization\OrgPresenter;
use Carbon\CarbonInterface;

/**
 * What the field app shows a sales rep (ADR-0041): their open leads and appointment requests with the response time,
 * their sales visits, and one visit or lead with the actions they may take. Allow-list payloads only.
 */
final class FieldSalesPresenter
{
    public const MAX_ROWS = 100;

    public function __construct(private readonly AttachmentService $files) {}

    /**
     * Open leads and appointment requests that follow the rep, oldest response time first.
     *
     * @return array{leads: list<array<string, mixed>>, requests: list<array<string, mixed>>}
     */
    public static function work(User $rep): array
    {
        $due = FieldAssignment::query()->where('rep_id', $rep->id)->where('status', FieldAssignment::OPEN)->get()
            ->keyBy(fn (FieldAssignment $a) => $a->subject_type.':'.$a->subject_id);

        $leads = Interest::query()->with('branch')->where('assignee_id', $rep->id)
            ->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])
            ->orderBy('id')->limit(self::MAX_ROWS)->get()
            ->filter(fn (Interest $i) => Access::canRead($rep, Permission::P15, $i) || Access::canRead($rep, Permission::P09, $i));
        $requests = AppointmentRequest::query()->where('assignee_id', $rep->id)
            ->whereIn('status', [AppointmentRequestStatus::New->value, AppointmentRequestStatus::Assigned->value])
            ->orderBy('id')->limit(self::MAX_ROWS)->get()
            ->filter(fn (AppointmentRequest $r) => Access::canRead($rep, Permission::P54, $r));

        $byDue = fn (array $a, array $b) => [$a['due_at'] ?? '9999', $a['id']] <=> [$b['due_at'] ?? '9999', $b['id']];
        $leadRows = array_values($leads->map(fn (Interest $i) => self::leadRow($i, $due->get(FieldAssignment::INTEREST.':'.$i->id)))->all());
        usort($leadRows, $byDue);
        $requestRows = array_values($requests->map(fn (AppointmentRequest $r) => [
            'id' => $r->id, 'reference' => $r->reference, 'kind' => $r->kind, 'name' => $r->name, 'phone' => $r->phone,
            'city' => $r->city, 'address' => $r->address, 'note' => $r->note,
            'preferred_from' => $r->preferred_from?->toDateString(), 'preferred_to' => $r->preferred_to?->toDateString(),
            'time_of_day' => $r->time_of_day,
        ] + self::due($r->due_at))->all());
        usort($requestRows, $byDue);

        return ['leads' => $leadRows, 'requests' => $requestRows];
    }

    /**
     * @return array<string, mixed>
     */
    public static function leadRow(Interest $interest, ?FieldAssignment $assignment): array
    {
        return [
            'id' => $interest->id, 'reference' => $interest->reference, 'status' => $interest->status->value,
            'name' => $interest->name, 'mobile' => $interest->mobile, 'need' => $interest->need,
            'channel' => $interest->channel->value, 'branch' => OrgPresenter::name($interest->branch),
            'note' => $assignment?->note,
        ] + self::due($assignment?->due_at);
    }

    /**
     * The lead screen: the row plus what the rep may do with it.
     *
     * @return array<string, mixed>
     */
    public static function lead(Interest $interest, User $viewer): array
    {
        $interest->loadMissing('branch');
        $open = $interest->isOpen();

        return self::leadRow($interest, FieldDispatch::openFor(FieldAssignment::INTEREST, $interest->id)) + [
            'email' => $interest->email,
            'close_reason' => $interest->close_reason?->value,
            'converted_order_id' => $interest->converted_order_id,
            'can' => [
                'follow' => $interest->status === InterestStatus::New && Access::can($viewer, Permission::P09, $interest),
                'close' => $open && Access::can($viewer, Permission::P09, $interest),
                'convert' => $open && Access::can($viewer, Permission::P15, $interest),
            ],
        ];
    }

    /**
     * A sales visit in the list (same shape as an installation row, plus its kind and progress).
     *
     * @return array<string, mixed>
     */
    public static function visitRow(Appointment $a): array
    {
        $visit = $a->salesVisit;

        return array_replace(FieldPresenter::row($a), [
            'kind' => $a->kind,
            'status' => $visit === null ? 'not_started' : ($visit->isFinished() ? 'finished' : 'in_progress'),
        ]);
    }

    /**
     * The sales visit screen.
     *
     * @return array<string, mixed>
     */
    public function visit(Appointment $a, User $viewer): array
    {
        $order = $a->order;
        $visit = $a->salesVisit;
        $photos = $visit === null ? collect() : SalesVisitPhoto::query()->where('sales_visit_id', $visit->id)->with('attachment')->orderBy('id')->get();
        $documents = AgreementDocument::query()->where('order_id', $order->id)
            ->whereIn('kind', [DocumentKind::Quote->value, DocumentKind::Contract->value])
            ->whereNotIn('status', [DocumentStatus::Replaced->value, DocumentStatus::Cancelled->value])->orderBy('id')->get();
        $measurements = OrderMeasurement::query()->where('order_id', $order->id)->orderBy('id')->limit(200)->get();
        $lead = Interest::query()->where('converted_order_id', $order->id)->first();
        $customer = $order->customer;
        $canQuote = Access::can($viewer, Permission::P29, $order);
        $canSend = Access::can($viewer, Permission::P30, $order);
        $running = $visit !== null && ! $visit->isFinished();

        return array_replace(self::visitRow($a), [
            'order' => ['id' => $order->id, 'number' => $order->number, 'channel' => $order->sales_channel->value],
            'visit' => $visit === null ? null : [
                'id' => $visit->id, 'lock_version' => $visit->lock_version, 'note' => $visit->note, 'outcome' => $visit->outcome,
                'arrived_at' => CalendarPresenter::local($visit->arrived_at)->format('Y-m-d H:i'),
                'finished_at' => $visit->finished_at === null ? null : CalendarPresenter::local($visit->finished_at)->format('Y-m-d H:i'),
            ],
            'photos' => array_values($photos->map(fn (SalesVisitPhoto $p) => [
                'id' => $p->id, 'client_uuid' => $p->client_uuid, 'url' => $this->files->downloadUrl($p->attachment, $viewer),
            ])->all()),
            'max_photos' => SalesVisit::MAX_PHOTOS,
            'measurements' => array_values($measurements->map(fn (OrderMeasurement $m) => [
                'id' => $m->id, 'location' => $m->location, 'name' => $m->name, 'value' => $m->value, 'unit' => $m->unit, 'kind' => $m->kind,
            ])->all()),
            'units' => OrderMeasurement::UNITS,
            'documents' => array_values($documents->map(fn (AgreementDocument $d) => [
                'id' => $d->id, 'kind' => $d->kind->value, 'number' => $d->number, 'status' => $d->status->value,
                'lock_version' => $d->lock_version, 'valid_until' => $d->valid_until?->toDateString(),
                'can_send' => $canSend && $d->status === DocumentStatus::Draft,
                'can_sign_link' => $canSend && $d->status === DocumentStatus::AwaitingCustomer && $customer->portal_login_enabled,
            ])->all()),
            'lead' => $lead === null ? null : ['id' => $lead->id, 'reference' => $lead->reference],
            'customer_portal' => (bool) $customer->portal_login_enabled,
            'can' => [
                'start' => $visit === null && $a->status->value === 'confirmed',
                'work' => $running,
                'measure' => $running && Access::can($viewer, Permission::P14, $order),
                'create_quote' => $canQuote && $documents->where('kind', DocumentKind::Quote)->isEmpty(),
                'edit_quote' => $canQuote,
                'portal_invite' => $customer->portal_login_enabled && Access::can($viewer, Permission::P10, $customer),
            ],
        ]);
    }

    /**
     * @return array{due_at: string|null, overdue: bool}
     */
    private static function due(?CarbonInterface $due): array
    {
        return [
            'due_at' => $due === null ? null : CalendarPresenter::local($due)->format('Y-m-d H:i'),
            'overdue' => $due !== null && $due->lte(now()),
        ];
    }
}
