<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Qualimetrix\Reporting\ReportProjectScope;
use Symfony\Component\Console\Input\InputInterface;

/** Resolves the check scope before deriving warnings from that exact scope. */
final readonly class CheckScopeResolver
{
    public function __construct(
        private GitScopeResolver $gitScopeResolver,
        private ScopeWarningChecker $scopeWarningChecker,
        private ProjectScopeCoverage $projectScopeCoverage,
    ) {}

    public function resolve(
        InputInterface $input,
        RunConfiguration $configuration,
    ): ResolvedCheckScope {
        $scope = $this->gitScopeResolver->resolve(CommandLineSpelling::option($input, 'report'), $configuration);

        // Taken once, for the resolved paths rather than the configured ones:
        // `--report=git:...` narrows the run after the configuration was
        // resolved, and the wider answer would be wrong in the direction that
        // makes a scope-conditioned channel speak.
        //
        // One measurement, and the warning list is not the verdict: an empty
        // list is `Covered` on a project whose manifest was read and `Unknown`
        // on one whose manifest declares nothing, and only the report tells
        // those two apart.
        $measurement = $this->projectScopeCoverage->measure($scope->projectRoot, $scope->paths, $configuration->autoloadDevPolicy);
        $state = $measurement->state();

        return new ResolvedCheckScope(
            $scope,
            $this->scopeWarningChecker->describe($measurement->uncoveredRoots, $measurement->prunedTargets),
            $state->coversProjectScope(),
            match ($state) {
                ProjectScopeState::Covered => ReportProjectScope::covered(),
                ProjectScopeState::Unknown => ReportProjectScope::unknown(),
                ProjectScopeState::Narrowed => ReportProjectScope::narrowed(
                    $measurement->uncoveredRoots,
                    ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS,
                ),
            },
        );
    }
}
