<?php

namespace App\Site\Domain;

final class SystemDnsTxtResolver implements DnsTxtResolver
{
    public function txt(string $name): ?array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return null;
        }

        return array_map(fn (array $r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))), $records);
    }
}
