<?php

namespace App\Ai\Insights;

use App\Access\Access;
use App\Access\Permission;
use App\Ai\AiCall;
use App\Ai\AiFeature;
use App\Ai\AiGateway;
use App\Ai\AiTask;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Notifier;
use App\Reports\ReportScope;
use Illuminate\Support\Collection;

/**
 * The morning digest (ADR-0050, plan axis 6): one short summary per owner / manager, built ONLY from the reports that
 * recipient may open, over that recipient's own scope (ReportScope: branch grants, money narrowed by P-37) — the
 * identity is the recipient's, never the system's. The model writes sentences about the numbers it is given; the
 * gateway's facts check rejects any number or date not among them, so an invalid digest is simply not sent.
 *
 * Delivered as an in-app notification (and its e-mail copy, per the recipient's preference). AI-0 has no provider
 * batch path yet (AiProvider::complete only), so the nightly run queues one job per recipient (SendDailyDigest);
 * each job is one gateway call (task digest.daily, charged per recipient). A recipient gets at most one digest per
 * facility day: the notification's dedupe key is checked before the model is called.
 */
final class DailyDigest
{
    public const SENT = 'sent';

    public const OFF = 'off';

    public const DUPLICATE = 'duplicate';

    public const NOTHING_TO_REPORT = 'nothing_to_report';

    public const FAILED = 'failed';

    public const NOTICE = 'ai.digest';

    private const SYSTEM = 'You write a short morning digest in Arabic for a business owner or manager from the report figures in <facts>. '
        .'Use ONLY those figures. Every number and date you write must appear in <facts>; never compute, estimate, round or '
        .'combine figures, and never mention a metric that is absent. Amounts named *_halalas are in halalas: write them in '
        .'riyals only if that exact value is in <facts>, otherwise leave the amount out. Start with what changed yesterday, '
        .'then the month so far, then what needs attention (overdue, late, open items). No greetings, no advice that is not '
        .'directly about a figure.';

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'headline' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'bullets' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 6, 'items' => ['type' => 'string', 'maxLength' => 300]],
        ],
        'required' => ['headline', 'bullets'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ReportTools $tools,
        private readonly Notifier $notifier,
    ) {}

    public function available(): bool
    {
        return $this->gateway->available(AiFeature::Digest);
    }

    /**
     * Active staff who read reports with money (owner, general and branch managers): P-60 and P-37, like the executive dashboard.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $ids = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');

        return User::query()->whereIn('id', $ids)->where('is_support', false)->orderBy('id')->get()
            ->filter(fn (User $user) => self::reads($user))
            ->values();
    }

    /**
     * Whether this user receives a digest right now: an active member (not support) holding P-60 and P-37.
     */
    public function isRecipient(User $user): bool
    {
        return ! $user->is_support
            && Membership::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active)->exists()
            && self::reads($user);
    }

    private static function reads(User $user): bool
    {
        return Access::can($user, Permission::P60) && Access::can($user, Permission::P37);
    }

    /**
     * Queues one job per recipient. Returns how many were queued (0 when the digest is off).
     */
    public function dispatchAll(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $recipients = $this->recipients();
        foreach ($recipients as $user) {
            SendDailyDigest::dispatch($user->id);
        }

        return $recipients->count();
    }

    /**
     * Builds and delivers one recipient's digest. Never call inside a database transaction (the gateway refuses).
     */
    public function sendTo(User $user): string
    {
        if (! $this->available()) {
            return self::OFF;
        }

        $today = InsightPeriods::today()->toDateString();
        $key = self::dedupeKey($today);
        if (UserNotification::query()->where('user_id', $user->id)->where('dedupe_key', $key)->exists()) {
            return self::DUPLICATE;
        }

        $facts = $this->facts($user);
        if ($facts === null) {
            return self::NOTHING_TO_REPORT;
        }

        $result = $this->gateway->run(new AiCall(
            task: AiTask::DigestDaily,
            system: self::SYSTEM,
            instruction: 'اكتب ملخص الصباح لهذا المستلم من الأرقام في facts فقط.',
            schema: self::SCHEMA,
            facts: $facts,
            user: $user,
        ));

        if (! $result->ok() || $result->output === null) {
            return $result->outcome->value === 'feature_off' ? self::OFF : self::FAILED;
        }

        $text = trim((string) $result->output['headline'])."\n".implode("\n", array_map(fn ($b) => '• '.trim((string) $b), (array) $result->output['bullets']));
        $this->notifier->send($user, self::NOTICE, ['date' => $today, 'text' => $text], null, $key);

        return self::SENT;
    }

    public static function dedupeKey(string $day): string
    {
        return 'ai-digest:'.$day;
    }

    /**
     * Figures for yesterday and the month so far from every report the recipient may open, summary only. Null when
     * the recipient has no report, or every figure is zero (a quiet day sends nothing and costs nothing).
     *
     * @return array<string, mixed>|null
     */
    public function facts(User $user): ?array
    {
        $reports = $this->tools->available($user);
        if ($reports === []) {
            return null;
        }

        $yesterday = InsightPeriods::range('yesterday');
        $month = InsightPeriods::range('this_month');

        $figures = [];
        $any = false;
        foreach ($reports as $key => $report) {
            $y = ReportTools::compactMetrics($report->build(new ReportScope($user, $yesterday[0], $yesterday[1], [], false))->toArray()['metrics']);
            $m = ReportTools::compactMetrics($report->build(new ReportScope($user, $month[0], $month[1], [], false))->toArray()['metrics']);
            $any = $any || array_filter($y) !== [] || array_filter($m) !== [];
            $figures[$key] = ['yesterday' => $y, 'month_to_date' => $m];
        }

        if (! $any) {
            return null;
        }

        return [
            'today' => InsightPeriods::today()->toDateString(),
            'yesterday' => ['from' => $yesterday[0], 'to' => $yesterday[1]],
            'month_to_date' => ['from' => $month[0], 'to' => $month[1]],
            'branches' => $this->tools->branches($user),
            'reports' => $figures,
        ];
    }
}
