<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;

/** Accepted check scope, diagnostics and preflighted finding projection for that exact scope. */
final readonly class ResolvedCheckScope
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public GitScopeResolution $scope,
        public array $warnings,
        public ProjectScopeMeasurement $measurement,
        public FindingProjectionOptions $projectionOptions,
    ) {}
}
