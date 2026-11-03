<?php

namespace App\Agreements;

use App\Configuration\ConfigReleases;
use App\Enums\DocumentKind;
use App\Models\Agreements\DocumentClause;
use App\Models\Agreements\DocumentClauseVersion;
use App\Models\Facility;
use App\Models\FinanceSettings;
use App\Models\Order;
use App\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * The clause library in documents (ADR-0037). When a quote or contract is prepared, the clauses for its kind that
 * the order's configuration release (ADR-0040) holds «included by default» are rendered with the closed variable set and copied into the document content
 * (`clauses`: texts; `clause_versions`: key and version of each), so they enter the content hash and stay as they
 * were once the document is sent or signed. A contract with no library clauses keeps the built-in v1 clauses.
 */
final class DocumentClauses
{
    public const VARIABLES = ['customer', 'order', 'facility', 'total', 'execution_days', 'validity_days', 'date'];

    public const KINDS = ['quote', 'contract'];

    public const MAX_ACTIVE = 40;

    /**
     * @param  array<string, mixed>  $content  the document content being prepared (totals, execution days)
     * @return array{clauses: list<string>, clause_versions: list<array{key: string, version: int}>}
     */
    public static function forDocument(DocumentKind $kind, Order $order, array $content, string $language): array
    {
        /** @var list<array<string, mixed>> $released */
        $released = ConfigReleases::forOrder($order)['clauses'] ?? [];
        $clauses = collect($released)
            ->filter(fn (array $c) => $c['included_by_default'] && in_array($kind->value, $c['applies_to'], true))
            ->sortBy([['position', 'asc'], ['id', 'asc']])->values();
        $values = self::values($order, $content, $language);

        return [
            'clauses' => array_values($clauses->map(fn (array $c) => self::render(self::text($c['body'], $language), $values))->all()),
            'clause_versions' => array_values($clauses->map(fn (array $c) => ['key' => (string) $c['key'], 'version' => (int) $c['version']])->all()),
        ];
    }

    /**
     * A draft's clauses rendered again from the versions it already holds, with its new content (a re-priced quote
     * shows its new total). The versions never change while the document exists.
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $content
     * @return array{clauses: list<string>, clause_versions: list<array{key: string, version: int}>}|array{}
     */
    public static function rerender(array $previous, Order $order, array $content, string $language): array
    {
        $pinned = $previous['clause_versions'] ?? null;
        if (! is_array($pinned) || $pinned === []) {
            return array_intersect_key($previous, array_flip(['clauses', 'clause_versions']));
        }
        $values = self::values($order, $content, $language);
        $texts = [];
        foreach ($pinned as $ref) {
            $version = DocumentClauseVersion::query()->where('version', (int) $ref['version'])
                ->whereIn('document_clause_id', DocumentClause::query()->where('key', (string) $ref['key'])->select('id'))->first();
            if ($version !== null) {
                $texts[] = self::render((string) $version->body->get($language), $values);
            }
        }

        return ['clauses' => $texts, 'clause_versions' => array_values(array_map(fn (array $r) => ['key' => (string) $r['key'], 'version' => (int) $r['version']], $pinned))];
    }

    /**
     * @param  array{ar: string, en?: string|null}  $body
     */
    private static function text(array $body, string $language): string
    {
        return $language === 'en' && isset($body['en']) && $body['en'] !== '' ? (string) $body['en'] : (string) $body['ar'];
    }

    public static function language(): string
    {
        return Facility::current()?->default_locale === 'en' ? 'en' : 'ar';
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m) => $values[$m[1]] ?? $m[0], $text);
    }

    public static function checkVariables(string $text, string $path): void
    {
        preg_match_all('/\{([^}]*)\}/', $text, $matches);
        $unknown = array_values(array_diff($matches[1], self::VARIABLES));
        if ($unknown !== []) {
            throw ValidationException::withMessages([$path => __('rules.messages.validation.variables', ['names' => '{'.implode('}, {', $unknown).'}'])]);
        }
    }

    /**
     * Sample values for the editor's preview.
     *
     * @return array<string, string>
     */
    public static function samples(string $language): array
    {
        return [
            'customer' => $language === 'ar' ? 'سارة العتيبي' : 'Sara Alotaibi', 'order' => 'ORD-2026-00042',
            'facility' => $language === 'ar' ? 'معرض المطابخ الحديثة' : 'Modern Kitchens', 'total' => '37,500.00',
            'execution_days' => '30', 'validity_days' => '14', 'date' => now()->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, string>
     */
    private static function values(Order $order, array $content, string $language): array
    {
        $facility = Facility::current();
        $total = $content['totals']['total_minor'] ?? null;

        return [
            'customer' => (string) $order->customer->name,
            'order' => (string) $order->number,
            'facility' => (string) ($language === 'en' && (string) $facility?->name_en !== '' ? $facility?->name_en : $facility?->name_ar),
            'total' => is_int($total) ? Money::of($total)->toInput() : '',
            'execution_days' => isset($content['execution_days']) ? (string) $content['execution_days'] : '',
            'validity_days' => (string) FinanceSettings::current()->quote_validity_days,
            'date' => now()->toDateString(),
        ];
    }
}
