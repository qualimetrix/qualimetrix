<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\ReportProjectScope;

/** Resolved analysis scope plus diagnostics computed for that exact scope. */
final readonly class ResolvedCheckScope
{
    /**
     * @param list<string> $warnings
     * @param bool $coversProjectScope Whether a whole-project channel may judge the resolved paths: they cover every
     *                                 autoload target the run's `AutoloadDevPolicy` counts, or the manifest declares
     *                                 none and the paths are the project
     * @param ReportProjectScope $projectScope The same measurement as the report publishes it, before any configured
     *                                         value was judged: a judging run adds the values it skipped afterwards
     */
    public function __construct(
        public GitScopeResolution $scope,
        public array $warnings,
        public bool $coversProjectScope,
        public ReportProjectScope $projectScope,
    ) {}
}
