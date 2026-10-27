<?php

namespace App\Http\Requests\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Integrations\PublicHost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSmtpRequest extends FormRequest
{
    /** Standard submission and relay ports only. */
    public const PORTS = [25, 465, 587, 2525];

    public function authorize(): bool
    {
        return Access::can($this->user(), Permission::P02);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', $this->publicHost(...)],
            'port' => ['required', 'integer', Rule::in(self::PORTS)],
            'encryption' => ['required', Rule::in(['tls', 'ssl'])],
            'username' => ['nullable', 'string', 'max:255'],
            // Blank keeps the saved password; it is never echoed back.
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'string', 'email:strict', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * The test and the sender connect from the server: refuse hosts that point inside the network (SSRF) outside
     * local development.
     */
    private function publicHost(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower((string) $value);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if (! $public && ! app()->environment('local', 'testing')) {
                $fail(__('integrations.smtp.host_private'));
            }

            return;
        }

        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1
            || (! app()->environment('local', 'testing') && (str_ends_with($host, '.local') || str_ends_with($host, '.internal')))) {
            $fail(__('integrations.smtp.host_invalid'));

            return;
        }

        // The name must also resolve only to public addresses (a public name can point inside the network).
        if (! app(PublicHost::class)->allows($host)) {
            $fail(__('integrations.smtp.host_private'));
        }
    }
}
