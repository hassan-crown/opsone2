<?php

namespace App\Site\Domain;

/**
 * Reads the TXT records of a name. Swappable so tests (and offline builds) never touch real DNS.
 */
interface DnsTxtResolver
{
    /**
     * @return list<string>|null the TXT strings, or null when the lookup itself failed
     */
    public function txt(string $name): ?array;
}
