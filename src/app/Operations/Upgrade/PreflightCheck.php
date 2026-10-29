<?php

namespace App\Operations\Upgrade;

final readonly class PreflightCheck
{
    private function __construct(public string $name, public string $result, public string $message) {}

    public static function pass(string $name, string $message): self
    {
        return new self($name, 'pass', $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, 'warn', $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, 'fail', $message);
    }

    public function failed(): bool
    {
        return $this->result === 'fail';
    }
}
