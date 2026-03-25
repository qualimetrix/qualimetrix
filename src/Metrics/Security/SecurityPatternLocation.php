<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

/**
 * Represents a detected security pattern location.
 */
final readonly class SecurityPatternLocation
{
    public function __construct(
        public string $type,
        public int $line,
        public string $context,
    ) {}
}
