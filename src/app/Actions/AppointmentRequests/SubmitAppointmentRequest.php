<?php

namespace App\Actions\AppointmentRequests;

use App\Actions\Site\SubmitLead;
use App\AppointmentRequests\AppointmentRequestForm;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\Phone;
use App\Enums\AppointmentRequestStatus;
use App\Enums\InterestStatus;
use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestSettings;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Interest;
use App\Models\Order;
use App\Models\User;
use App\Notifications\Notifier;
use App\Portal\PortalAccess;
use App\Portal\PortalSession;
use App\Support\LockingTransaction;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * A customer asks for an appointment (ADR-0039); nothing is booked and nothing is promised. Exactly one request per
 * submission key: a replay (double click, retry) returns the first one. From the public site the request is linked
 * to the customer with that mobile, else to their open interest, else a new website interest is opened (M-03-T00);
 * from the workspace it belongs to the signed-in customer and optionally one of their open orders.
 */
final class SubmitAppointmentRequest
{
    /** Open requests a customer may hold at once from the workspace. */
    public const MAX_OPEN_PER_CUSTOMER = 5;

    public function __construct(private readonly SubmitLead $leads, private readonly Notifier $notifier) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function fromSite(string $submissionKey, array $input): AppointmentRequest
    {
        if (($replay = self::replay($submissionKey)) !== null) {
            return $replay;
        }
        $settings = AppointmentRequestSettings::current();
        if (! $settings->enabledFor(AppointmentRequest::SOURCE_SITE)) {
            throw ValidationException::withMessages(['form' => __('appointment_requests.unavailable')]);
        }
        $data = AppointmentRequestForm::validate($settings, AppointmentRequest::SOURCE_SITE, $input);
        $phone = Phone::normalize($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('appointment_requests.validation.phone')]);
        }

        return $this->once($submissionKey, function () use ($submissionKey, $data, $phone) {
            $customer = Customer::query()->where('mobile', $phone)->first();
            $interest = $customer === null
                ? Interest::query()->where('mobile', $phone)->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])->latest('id')->first()
                : null;
            if ($customer === null && $interest === null) {
                $interest = $this->leads->open([
                    'name' => $data['name'], 'mobile' => $phone,
                    'need' => __('appointment_requests.interest_need', ['kind' => __('appointments.kind.'.$data['kind'])], self::locale()),
                ], SubmitLead::intakeBranch());
            }

            return $this->create($submissionKey, AppointmentRequest::SOURCE_SITE, [
                ...$data, 'phone' => $phone,
                'branch_id' => $customer->branch_id ?? $interest?->branch_id,
                'customer_id' => $customer?->id, 'interest_id' => $interest?->id,
            ], null, SubmitLead::SOURCE);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function fromPortal(Customer $customer, string $submissionKey, array $input): AppointmentRequest
    {
        if (($replay = self::replay($submissionKey)) !== null) {
            abort_unless($replay->customer_id === $customer->id, 404);

            return $replay;
        }
        $settings = AppointmentRequestSettings::current();
        if (! $settings->enabledFor(AppointmentRequest::SOURCE_PORTAL)) {
            throw ValidationException::withMessages(['form' => __('appointment_requests.unavailable')]);
        }
        $data = AppointmentRequestForm::validate($settings, AppointmentRequest::SOURCE_PORTAL, $input);
        $order = null;
        if (($data['order_id'] ?? null) !== null) {
            // Only the customer's own, still open orders; another customer's id is «not found».
            $order = PortalAccess::order($customer, (int) $data['order_id']);
            if ($order->state()->isFinal()) {
                throw ValidationException::withMessages(['order_id' => __('appointment_requests.validation.order_closed')]);
            }
        }

        return $this->once($submissionKey, function () use ($customer, $submissionKey, $data, $order) {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $open = AppointmentRequest::query()->where('customer_id', $customer->id)
                ->whereIn('status', [AppointmentRequestStatus::New->value, AppointmentRequestStatus::Assigned->value])->count();
            if ($open >= self::MAX_OPEN_PER_CUSTOMER) {
                throw ValidationException::withMessages(['form' => __('appointment_requests.validation.too_many', ['max' => self::MAX_OPEN_PER_CUSTOMER])]);
            }

            return $this->create($submissionKey, AppointmentRequest::SOURCE_PORTAL, [
                ...$data, 'name' => $customer->name, 'phone' => $customer->mobile,
                'branch_id' => $order->branch_id ?? $customer->branch_id,
                'customer_id' => $customer->id, 'order_id' => $order?->id,
            ], $order, PortalSession::SOURCE);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(string $submissionKey, string $source, array $data, ?Order $order, string $auditSource): AppointmentRequest
    {
        $request = new AppointmentRequest([
            'submission_key' => $submissionKey, 'source' => $source, 'status' => AppointmentRequestStatus::New,
            'kind' => $data['kind'], 'preferred_from' => $data['preferred_from'], 'preferred_to' => $data['preferred_to'] ?? null,
            'time_of_day' => $data['time_of_day'], 'name' => $data['name'], 'phone' => $data['phone'],
            'city' => $data['city'], 'address' => $data['address'], 'note' => $data['note'],
            'branch_id' => $data['branch_id'], 'interest_id' => $data['interest_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null, 'order_id' => $data['order_id'] ?? null,
        ]);
        $request->save();
        $request->forceFill(['reference' => sprintf('APR-%04d', $request->id)])->save();

        Audit::record(AuditAction::AppointmentRequestSubmitted, $request, null, after: [
            'reference' => $request->reference, 'source' => $source, 'kind' => $request->kind,
            'interest_id' => $request->interest_id, 'customer_id' => $request->customer_id, 'order_id' => $request->order_id,
        ], source: $auditSource, label: $request->reference);

        // The order's responsible person hears of it at once; otherwise the queue shows it as new.
        if ($order !== null && $order->assignee_id !== null) {
            $assignee = User::query()->find($order->assignee_id);
            if ($assignee !== null) {
                $this->notifier->send($assignee, 'appointment_request.new', ['reference' => (string) $request->reference, 'order' => $order->number],
                    $request, "appointment_request:{$request->id}:new");
            }
        }

        return $request;
    }

    /**
     * @param  Closure(): AppointmentRequest  $create
     */
    private function once(string $submissionKey, Closure $create): AppointmentRequest
    {
        try {
            return LockingTransaction::run($create);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won: replay it.
            return AppointmentRequest::query()->where('submission_key', $submissionKey)->firstOrFail();
        }
    }

    private static function replay(string $submissionKey): ?AppointmentRequest
    {
        return AppointmentRequest::query()->where('submission_key', $submissionKey)->first();
    }

    private static function locale(): string
    {
        return Facility::current()->default_locale ?? 'ar';
    }
}
