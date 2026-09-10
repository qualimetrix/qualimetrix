<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
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
        $scope = $this->gitScopeResolver->resolve($input, $configuration);

        // Taken once, for the resolved paths rather than the configured ones:
        // `--report=git:...` narrows the run after the configuration was
        // resolved, and the wider answer would be wrong in the direction that
        // makes a scope-conditioned channel speak.
        $uncovered = $this->projectScopeCoverage->uncoveredAutoloadRoots($scope->projectRoot, $scope->paths);

        return new ResolvedCheckScope(
            $scope,
            $this->scopeWarningChecker->describe($uncovered),
            $uncovered === [],
        );
    }

}
