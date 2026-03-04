<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Fixture;

/**
 * RFC-008 Test Fixture: Small class with only 1-2 methods.
 *
 * Characteristics:
 * - isReadonly = false
 * - isDataClass = false (has business logic)
 * - methodCount = 2
 *
 * This class should be EXCLUDED from:
 * - LCOM checks when minMethods > 2
 */
class SmallClass
{
    public function __construct(
        private readonly string $value,
    ) {}

    public function process(): string
    {
        return strtoupper($this->value);
    }

    public function validate(): bool
    {
        return $this->value !== '';
    }
}
