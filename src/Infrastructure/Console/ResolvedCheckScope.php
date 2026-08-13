<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Git\GitScopeResolution;

/** Resolved analysis scope plus diagnostics computed for that exact scope. */
final readonly class ResolvedCheckScope
{
    /** @param list<string> $warnings */
    public function __construct(
        public GitScopeResolution $scope,
        public array $warnings,
    ) {}
}
