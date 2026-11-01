<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DocSign submit (ADR-0029). The customer is authorized by the portal guard and PortalAccess in the controller; this
 * only shapes the input. Business checks (consent, signature image, PDF hash, code) are the signing service's.
 */
class SignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signer_name' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string', 'max:420000'],
            'consent' => ['accepted'],
            'code' => ['required', 'string', 'max:12'],
            'content_hash' => ['required', 'string', 'size:64'],
            'pdf_sha256' => ['required', 'string', 'size:64'],
            'locale' => ['required', 'in:ar,en'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['consent.accepted' => __('esign.error.consent')];
    }

    /**
     * @return array{signer_name: string, signature: string, consent: bool, code: string, content_hash: string, pdf_sha256: string, locale: string}
     */
    public function signing(): array
    {
        return [
            'signer_name' => (string) $this->validated('signer_name'),
            'signature' => (string) $this->validated('signature'),
            'consent' => $this->boolean('consent'),
            'code' => (string) preg_replace('/\D/', '', (string) $this->validated('code')),
            'content_hash' => (string) $this->validated('content_hash'),
            'pdf_sha256' => (string) $this->validated('pdf_sha256'),
            'locale' => (string) $this->validated('locale'),
        ];
    }
}
