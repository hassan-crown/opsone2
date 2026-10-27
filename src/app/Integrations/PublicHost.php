<?php

namespace App\Integrations;

/**
 * SSRF guard for hosts the server connects to on a facility's behalf (SMTP). A name passes only when every address
 * it resolves to is public: a public-looking name that points at 10.x, 127.x, 169.254.x (cloud metadata) or the
 * Docker network is refused. Checked when the settings are saved and again right before each connection, since
 * DNS can change in between. Local development and tests skip the check.
 */
class PublicHost
{
    public function allows(string $host): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        $addresses = $this->resolve(strtolower(trim($host)));
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_string($record[$field] ?? null)) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return $addresses;
    }
}
