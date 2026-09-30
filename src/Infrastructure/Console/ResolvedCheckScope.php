<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\ReportProjectScope;

/** Resolved analysis scope plus diagnostics computed for that exact scope. */
final readonly class ResolvedCheckScope
{
    public bool $coversProjectScope;

    /**
     * @param list<string> $warnings
     * @param ReportProjectScope $projectScope The same measurement as the report publishes it, before any configured
     *                                         value was judged: a judging run adds the values it skipped afterwards
     */
    public function __construct(
        public GitScopeResolution $scope,
        public array $warnings,
        public ProjectScopeMeasurement $measurement,
        public ReportProjectScope $projectScope,
    ) {
        $this->coversProjectScope = $measurement->state()->coversProjectScope();
    }
}
