<?php

use App\Actions\StateMachines\M08Document\T00CreateQuote;
use App\Actions\StateMachines\M08Document\T01SendDocument;
use App\Actions\StateMachines\M08Document\T02RecordExternalApproval;
use App\Actions\StateMachines\M08Document\UpdateDocumentDraft;
use App\Actions\StateMachines\M09Change\T00CreateChange;
use App\Actions\StateMachines\M09Change\T01PresentChange;
use App\Actions\StateMachines\M09Change\T04ApplyChange;
use App\Actions\StateMachines\M09Change\UpdateChangeDraft;
use App\Agreements\CollectedAmounts;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Agreements\AgreementDocument;
use App\Models\DiscountLimit;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Org;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/**
 * Agreement fixture: the workflow fixture (custom order in branch A opened by sales, default flow) plus a manager,
 * a supervisor (P-31 over branch A, no P-37), a finance officer and an executor. Discount limits per role: sales 10%,
 * supervisor approves up to 15%, manager 20% / approves 20%.
 *
 * @return object{org: Org, owner: User, sales: User, manager: User, supervisor: User, finance: User, executor: User, order: Order}
 */
function agreementFixture(array $custom = []): object
{
    $f = workflowFixture($custom);
    $org = $f->org;
    $f->owner = $org->owner;
    $f->manager = $org->member('manager', [$org->a]);
    $f->supervisor = $org->member('supervisor', [$org->a], $org->d1, 'branches');
    $f->finance = $org->member('finance', [$org->a]);
    $f->executor = $org->member('executor', [$org->a], $org->d1);
    agrLimit('sales', 1000);
    agrLimit('supervisor', 0, 1500);
    agrLimit('manager', 2000, 2000);

    return $f;
}

function agrLimit(string $role, int $customBp, int $approvalBp = 0): void
{
    DiscountLimit::query()->updateOrCreate(
        ['role_template_id' => RoleTemplate::query()->where('key', $role)->value('id')],
        ['custom_bp' => $customBp, 'ready_bp' => 0, 'approval_bp' => $approvalBp],
    );
}

function agrSettings(int $rateBp = 1500, bool $inclusive = true): void
{
    FinanceSettings::query()->delete();
    FinanceSettings::query()->create([...FinanceSettings::DEFAULTS, 'vat_rate_bp' => $rateBp, 'prices_include_vat' => $inclusive]);
}

function agrKey(): string
{
    return (string) Str::uuid();
}

/**
 * Prototype AgrQuote numbers: 6 kitchens at 14,000.00 and 6 installations at 1,250.00 → base 91,500.00.
 *
 * @return array<string, mixed>
 */
function agrQuoteData(array $discount = ['type' => 'none'], ?array $lines = null): array
{
    return [
        'lines' => $lines ?? [
            ['description' => 'مطبخ علوي وسفلي لشقة', 'quantity' => '6', 'unit' => 'unit', 'unit_price' => '14000'],
            ['description' => 'تركيب في الموقع', 'quantity' => '6', 'unit' => 'unit', 'unit_price' => '1250', 'commissionable' => false],
        ],
        'discount' => $discount,
        'terms' => 'دفعة أولى 40% عند الاتفاق.',
        'execution_days' => 35,
    ];
}

function agrQuote(User $actor, Order $order, ?array $data = null): AgreementDocument
{
    $quote = app(T00CreateQuote::class)->handle($actor, $order, agrKey());

    return app(UpdateDocumentDraft::class)->handle($actor, $quote, $data ?? agrQuoteData(), $quote->lock_version, agrKey());
}

function agrSend(User $actor, AgreementDocument $document): AgreementDocument
{
    return app(T01SendDocument::class)->handle($actor, $document->refresh(), null, $document->lock_version, agrKey());
}

function agrPdf(string $name = 'evidence.pdf', string $body = 'evidence'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body);
}

function agrApprove(User $actor, AgreementDocument $document, ?string $key = null): AgreementDocument
{
    $document->refresh();

    return app(T02RecordExternalApproval::class)->handle($actor, $document, [
        'channel' => 'message', 'decided_on' => now()->toDateString(), 'note' => 'واتساب', 'content_hash' => (string) $document->content_hash,
    ], agrPdf(), $document->lock_version, $key ?? agrKey());
}

/**
 * An order with an approved agreement of the given lines (prices inclusive, no discount).
 *
 * @param  list<array<string, mixed>>  $lines
 */
function agrAgreed(object $f, array $lines): Agreement
{
    $quote = agrSend($f->sales, agrQuote($f->sales, $f->order, agrQuoteData(lines: $lines)));
    agrApprove($f->owner, $quote);

    return Agreement::query()->where('order_id', $f->order->id)->where('status', 'active')->firstOrFail();
}

/**
 * Creates, prices and presents a change; returns it awaiting approval.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  list<string>  $affected
 */
function agrChange(User $actor, Order $order, array $lines, array $affected = [], int $delayDays = 0, array $discount = ['type' => 'none']): AgreementChange
{
    $change = app(T00CreateChange::class)->handle($actor, $order, 'تعديل البنود', agrKey());
    $change = app(UpdateChangeDraft::class)->handle($actor, $change, [
        ...agrQuoteData($discount, $lines), 'description' => 'تعديل البنود', 'affected' => $affected, 'delay_days' => $delayDays,
    ], $change->lock_version, agrKey());

    return app(T01PresentChange::class)->handle($actor, $change, $change->lock_version, agrKey());
}

function agrApplyChange(User $actor, AgreementChange $change, ?string $key = null): Agreement
{
    $change->refresh();

    return app(T04ApplyChange::class)->handle($actor, $change, [
        'channel' => 'phone', 'decided_on' => now()->toDateString(), 'content_hash' => (string) $change->content_hash,
    ], agrPdf(), $change->lock_version, $key ?? agrKey());
}

/**
 * Binds what finance «collected» for the order (P6b owns the real ledger).
 */
function agrCollected(int $minor): void
{
    app()->instance(CollectedAmounts::class, new class($minor) implements CollectedAmounts
    {
        public function __construct(private readonly int $minor) {}

        public function collectedFor(Order $order): Money
        {
            return Money::of($this->minor);
        }
    });
}

/**
 * Rows a refused agreement write must not touch.
 *
 * @return array<string, mixed>
 */
function agrSnapshot(): array
{
    return [
        ...workflowSnapshot(),
        'documents' => DB::table('agreement_documents')->get()->map(fn ($d) => [$d->id, $d->status, $d->content_hash, $d->lock_version])->all(),
        'decisions' => DB::table('customer_decisions')->count(),
        'agreements' => DB::table('agreements')->get()->map(fn ($a) => [$a->id, $a->status, $a->total_minor])->all(),
        'changes' => DB::table('agreement_changes')->get()->map(fn ($c) => [$c->id, $c->status, $c->lock_version])->all(),
        'discounts' => DB::table('discount_decisions')->get()->map(fn ($d) => [$d->id, $d->status])->all(),
        'attachments' => DB::table('attachments')->count(),
        'idempotency' => DB::table('idempotency_keys')->count(),
    ];
}

function agrRefused(Closure $attempt, string $exception, ?string $condition = null): void
{
    $before = agrSnapshot();
    try {
        $attempt();
        test()->fail('The write was not refused.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception);
        if ($condition !== null) {
            expect($e->condition ?? null)->toBe($condition);
        }
    }
    expect(agrSnapshot())->toBe($before);
}
