<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/** Raw per-rule options resolved from configuration sources. */
final readonly class RuleOptionsDocument
{
    /** @param array<string, mixed> $rules */
    public function __construct(public array $rules = []) {}
}
