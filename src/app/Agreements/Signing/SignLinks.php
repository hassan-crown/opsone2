<?php

namespace App\Agreements\Signing;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\StateMachine;
use App\Models\Agreements\AgreementDocument;
use App\Models\Agreements\DocumentSignature;
use App\Models\Agreements\DocumentSignLink;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Outbox;
use App\Support\LockingTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Signing links staff send to the customer (P-30, ADR-0029). A link carries a random 48-character token (only its
 * sha256 is stored), expires after LINK_DAYS or at the end of the document's validity, whichever is first, and opens
 * the document in the customer's own workspace — the customer still signs in, and only the order's customer gets
 * past it. A new link revokes the previous ones; replacing, cancelling or deciding the document ends them all.
 */
final class SignLinks
{
    public const LINK_DAYS = 7;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly SmsChannel $sms,
    ) {}

    /**
     * @return array{link: DocumentSignLink, url: string}
     */
    public function send(User $actor, AgreementDocument $document): array
    {
        return LockingTransaction::run(function () use ($actor, $document) {
            $document = AgreementDocument::query()->with('order.customer')->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $order = $document->order;
            Access::authorize($actor, Permission::P30, $order);
            $customer = $order->customer;
            if (! DocumentSheet::supports($document)) {
                throw new InvalidTransition(StateMachine::M08, 'T02', 'not_signable');
            }
            if ($document->status !== DocumentStatus::AwaitingCustomer || DocumentSignature::query()->where('agreement_document_id', $document->id)->exists()) {
                throw new InvalidTransition(StateMachine::M08, 'T02', 'not_awaiting_customer');
            }
            if (! $customer->portal_login_enabled) {
                throw new InvalidTransition(StateMachine::M08, 'T02', 'portal_disabled');
            }
            $email = (string) $customer->email;
            $bySms = $this->sms->isConfigured();
            if (! $bySms && $email === '') {
                throw new InvalidTransition(StateMachine::M08, 'T02', 'no_recipient');
            }

            $this->revokeLive($document);
            $token = Str::random(48);
            $link = DocumentSignLink::query()->create([
                'agreement_document_id' => $document->id,
                'token_hash' => self::hash($token),
                'created_by' => $actor->id,
                'channel' => $bySms && $email !== '' ? 'both' : ($bySms ? 'sms' : 'email'),
                'expires_at' => self::expiry($document),
            ]);
            $url = route('customer.sign-links.open', ['token' => $token]);

            $facility = Facility::current();
            $locale = $facility === null ? 'ar' : $facility->default_locale;
            $params = [
                'facility' => $facility === null ? (string) config('app.name') : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar),
                'document' => $document->number, 'link' => $url, 'date' => $link->expires_at->toDateString(),
            ];
            if ($bySms) {
                $this->outbox->enqueueSms($customer->mobile, __('esign.link.sms', $params, $locale), "esign-link:{$link->id}:sms");
            }
            if ($email !== '') {
                $this->outbox->enqueueMail($email, __('esign.link.mail_subject', $params, $locale), [__('esign.link.mail_line', $params, $locale)],
                    ['label' => __('esign.link.mail_action', [], $locale), 'url' => $url], $locale, "esign-link:{$link->id}:mail");
            }
            Audit::record(AuditAction::DocumentSignLinkSent, $order, $actor, after: [
                'document' => $document->number, 'channel' => $link->channel, 'expires_at' => $link->expires_at->toIso8601String(),
            ], label: $order->number);

            return ['link' => $link, 'url' => $url];
        });
    }

    public function revoke(User $actor, AgreementDocument $document): int
    {
        return LockingTransaction::run(function () use ($actor, $document) {
            $document = AgreementDocument::query()->with('order')->whereKey($document->id)->lockForUpdate()->firstOrFail();
            Access::authorize($actor, Permission::P30, $document->order);
            $count = $this->revokeLive($document);
            if ($count > 0) {
                Audit::record(AuditAction::DocumentSignLinksRevoked, $document->order, $actor, after: ['document' => $document->number, 'links' => $count],
                    label: $document->order->number);
            }

            return $count;
        });
    }

    /**
     * The link of a token, live or not (the caller tells an expired link from a working one); null when unknown.
     */
    public static function find(string $token): ?DocumentSignLink
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return DocumentSignLink::query()->with('document.order')->where('token_hash', self::hash($token))->first();
    }

    /**
     * Does this link still lead to a signature: unused, not revoked or expired, and its document awaits the customer?
     */
    public static function usable(DocumentSignLink $link): bool
    {
        return $link->isLive() && $link->document->status === DocumentStatus::AwaitingCustomer
            && ($link->document->valid_until === null || ! $link->document->valid_until->copy()->endOfDay()->isPast());
    }

    public static function live(AgreementDocument $document): ?DocumentSignLink
    {
        return DocumentSignLink::query()->where('agreement_document_id', $document->id)->whereNull('used_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    private function revokeLive(AgreementDocument $document): int
    {
        return DocumentSignLink::query()->where('agreement_document_id', $document->id)->whereNull('used_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private static function expiry(AgreementDocument $document): CarbonInterface
    {
        $limit = now()->addDays(self::LINK_DAYS);
        $validity = $document->valid_until?->copy()->endOfDay();

        return $validity !== null && $validity->lt($limit) ? $validity : $limit;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
