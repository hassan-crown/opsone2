<?php

namespace App\Site\Preview;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Private preview of the site before publishing (THM-04). A staff member with the draft permissions issues a signed,
 * expiring link; opening it stores a tamper-proof, expiring cookie in that browser only. While the cookie is valid the
 * request reads the draft pages (`pages`) and the draft theme (`theme`) instead of the published ones — nothing is
 * ever changed by it (the middleware refuses writes) and every response is `noindex` and uncached. Without the cookie
 * a visitor can never reach a draft.
 */
final class SitePreview
{
    public const COOKIE = 'site_preview';

    private bool $pages = false;

    private bool $theme = false;

    private ?int $expiresAt = null;

    /** @param  array{pages: bool, theme: bool, exp: int}  $grant */
    public function activate(array $grant): void
    {
        $this->pages = $grant['pages'];
        $this->theme = $grant['theme'];
        $this->expiresAt = $grant['exp'];
    }

    /** A request without a valid grant starts clean (the instance must never carry a grant over to another request). */
    public function reset(): void
    {
        $this->pages = $this->theme = false;
        $this->expiresAt = null;
    }

    public function active(): bool
    {
        return $this->pages || $this->theme;
    }

    public function pages(): bool
    {
        return $this->pages;
    }

    public function theme(): bool
    {
        return $this->theme;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /** The cookie value: encrypted JSON, valid until `exp`. */
    public static function token(bool $pages, bool $theme, int $exp): string
    {
        return Crypt::encryptString((string) json_encode(['pages' => $pages, 'theme' => $theme, 'exp' => $exp]));
    }

    /**
     * @return array{pages: bool, theme: bool, exp: int}|null null for a forged, malformed or expired cookie
     */
    public static function read(mixed $cookie): ?array
    {
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($cookie), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($data) || ! is_int($data['exp'] ?? null) || $data['exp'] <= time()) {
            return null;
        }
        $grant = ['pages' => ($data['pages'] ?? false) === true, 'theme' => ($data['theme'] ?? false) === true, 'exp' => $data['exp']];

        return $grant['pages'] || $grant['theme'] ? $grant : null;
    }
}
