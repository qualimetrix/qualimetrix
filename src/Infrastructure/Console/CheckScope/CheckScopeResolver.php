<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckScope;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\RunSetup\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;

use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Accepts selected paths and finding projection against one resolved Git scope before analysis. */
final readonly class CheckScopeResolver
{
    public function __construct(
        private GitScopeResolver $gitScopeResolver,
        private ScopeWarningChecker $scopeWarningChecker,
        private AnalysisInputPathValidator $pathValidator,
        private FindingProjectionOptionsResolver $projectionOptionsResolver,
        private ErrorStream $errorStream,
    ) {}

    public function resolve(InputInterface $input, RunConfiguration $configuration, ConfigurationDocument $document, OutputInterface $output, ConfiguredFindingExclusions $exclusions): ResolvedCheckScope
    {
        $scope = $this->gitScopeResolver->resolve(CommandLineSpelling::option($input, 'report'), $configuration);
        $measurement = $scope->reportScope === null ? $configuration->projectScope : ($configuration->projectScope)->narrowTo($scope->paths);
        $warnings = $this->scopeWarningChecker->describe($measurement->uncoveredRoots, $measurement->universe->prunedTargets);
        $this->pathValidator->validate(
            $scope->paths,
            $document,
            $configuration->projectScope->universe->pathsAuthored ? PathsAuthorship::Authored : PathsAuthorship::Inferred,
        );
        foreach ($warnings as $warning) {
            $this->errorStream->write($output, \sprintf('<comment>Warning: %s</comment>', $warning));
        }
        $projection = $this->projectionOptionsResolver->resolve($exclusions, $input, $scope);
        return new ResolvedCheckScope($scope, $warnings, $measurement, $projection);
    }
}
