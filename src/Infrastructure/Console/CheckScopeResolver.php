<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Symfony\Component\Console\Input\InputInterface;

/** Resolves the check scope before deriving warnings from that exact scope. */
final readonly class CheckScopeResolver
{
    public function __construct(
        private GitScopeResolver $gitScopeResolver,
        private ScopeWarningChecker $scopeWarningChecker,
    ) {}

    public function resolve(
        InputInterface $input,
        RunConfiguration $configuration,
    ): ResolvedCheckScope {
        $scope = $this->gitScopeResolver->resolve($input, $configuration);

        return new ResolvedCheckScope(
            $scope,
            $this->scopeWarningChecker->check($scope->projectRoot, $scope->paths),
        );
    }

}
