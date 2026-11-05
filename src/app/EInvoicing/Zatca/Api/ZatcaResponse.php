<?php

namespace App\EInvoicing\Zatca\Api;

/**
 * ZATCA's answer: HTTP status and decoded JSON body. Helpers read the fields the flows need.
 */
final readonly class ZatcaResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public int $status, public array $body) {}

    public function ok(): bool
    {
        return $this->status === 200 || $this->status === 202;
    }

    public function warnings(): bool
    {
        return $this->status === 202 || $this->messages('warningMessages') !== [];
    }

    public function string(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Validation messages of one kind (errorMessages, warningMessages, infoMessages), reduced to code + message.
     *
     * @return list<array{code: string, message: string}>
     */
    public function messages(string $kind): array
    {
        $results = $this->body['validationResults'] ?? [];
        $list = is_array($results) ? ($results[$kind] ?? []) : [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item)) {
                $out[] = ['code' => mb_substr((string) ($item['code'] ?? ''), 0, 80), 'message' => mb_substr((string) ($item['message'] ?? ''), 0, 500)];
            }
        }

        return $out;
    }

    /**
     * What we keep of a submission response: status, ZATCA's own status fields and the messages (no invoice body).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'http_status' => $this->status,
            'reporting_status' => $this->string('reportingStatus'),
            'clearance_status' => $this->string('clearanceStatus'),
            'errors' => $this->messages('errorMessages'),
            'warnings' => $this->messages('warningMessages'),
        ], fn ($v) => $v !== null && $v !== []);
    }
}
