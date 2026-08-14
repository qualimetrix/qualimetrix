<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

final readonly class ArchitectureConfigurationWarning
{
    /** @param array<string, mixed> $context */
    public function __construct(public string $message, public array $context = []) {}
}
