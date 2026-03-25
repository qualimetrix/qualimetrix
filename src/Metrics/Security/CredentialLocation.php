<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

/**
 * Represents a detected hardcoded credential location.
 */
final readonly class CredentialLocation
{
    public function __construct(
        public int $line,
        public string $pattern,
    ) {}
}
