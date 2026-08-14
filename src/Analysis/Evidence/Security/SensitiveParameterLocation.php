<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

/**
 * Represents a detected sensitive parameter location.
 */
final readonly class SensitiveParameterLocation
{
    public function __construct(
        public int $line,
        public string $paramName,
        public string $subjectId,
    ) {}
}
