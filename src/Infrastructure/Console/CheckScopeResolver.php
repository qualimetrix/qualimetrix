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
        $scope = $this->gitScopeResolver->resolve(CommandLineSpelling::option($input, 'report'), $configuration);

        // Taken once, for the resolved paths rather than the configured ones:
        // `--report=git:...` narrows the run after the configuration was
        // resolved, and the wider answer would be wrong in the direction that
        // makes a scope-conditioned channel speak.
        //
        // One measurement, two answers, and they are not the same answer: a
        // project whose counted autoload sections declare nothing readable has no
        // uncovered target to warn about and no licence to judge either, so
        // reading the verdict off the empty warning list would silently call
        // it a whole-project run.
        $measurement = $this->projectScopeCoverage->measure($scope->projectRoot, $scope->paths, $configuration->autoloadDevPolicy);

        return new ResolvedCheckScope(
            $scope,
            $this->scopeWarningChecker->describe($measurement->uncoveredRoots, $measurement->prunedTargets),
            $measurement->covers(),
        );
    }

}
