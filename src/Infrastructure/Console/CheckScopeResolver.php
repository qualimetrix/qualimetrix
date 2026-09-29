<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Qualimetrix\Reporting\ReportProjectScope;
use Symfony\Component\Console\Input\InputInterface;

/** Reuse the initial measurement, narrowing only after Git changed the paths. */
final readonly class CheckScopeResolver
{
    public function __construct(private GitScopeResolver $gitScopeResolver, private ScopeWarningChecker $scopeWarningChecker) {}

    public function resolve(InputInterface $input, RunConfiguration $configuration): ResolvedCheckScope
    {
        $scope = $this->gitScopeResolver->resolve(CommandLineSpelling::option($input, 'report'), $configuration);
        $measurement = $scope->reportScope === null ? $configuration->projectScope : ProjectScopeCoverage::narrow($configuration->projectScope, $scope->paths);
        $state = $measurement->state();
        $report = match ($state) {
            ProjectScopeState::Covered => ReportProjectScope::covered(),
            ProjectScopeState::Unknown => ReportProjectScope::unknown(),
            ProjectScopeState::Narrowed => ReportProjectScope::narrowed($measurement->uncoveredRoots, ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS),
            ProjectScopeState::Unmeasured => ReportProjectScope::unmeasured(ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS),
        };

        return new ResolvedCheckScope(
            $scope,
            $this->scopeWarningChecker->describe($measurement->uncoveredRoots, $measurement->prunedTargets),
            $measurement,
            $report->withReasons($measurement->reasons),
        );
    }
}
