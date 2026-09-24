<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;

/**
 * Resolves analysis scope, file discovery strategy and git client from the
 * `--report` value.
 *
 * Stateless service — no DI registration needed, instantiate via `new`.
 */
final class GitScopeResolver
{
    public function __construct(
        private readonly FileDiscoveryFactoryInterface $fileDiscoveryFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param ?string $report the `--report` value as a command line spells it; null when not written
     */
    public function resolve(?string $report, RunConfiguration $configuration): GitScopeResolution
    {
        // ADR 0015 Phase 2: convert raw CLI `paths` strings into AbsolutePath VOs
        // at the boundary, against the current working directory captured here.
        // `Application::doRun()` has already applied `--working-dir`, so this
        // matches the `getcwd()` value used by `CheckCommand::resolveConfiguration()`.
        $paths = $configuration->paths;
        $projectRoot = $configuration->projectRoot;

        $reportScope = $this->resolveReportScope($report);

        $gitClient = $reportScope !== null
            ? new GitClient($projectRoot, $this->logger)
            : null;

        if ($gitClient !== null) {
            $gitClient->validateScope($reportScope->ref);
        }

        $fileDiscovery = $this->fileDiscoveryFactory->create($projectRoot, $configuration->pathExcludes);

        return new GitScopeResolution(
            paths: $paths,
            fileDiscovery: $fileDiscovery,
            gitClient: $gitClient,
            reportScope: $reportScope,
            projectRoot: $projectRoot,
        );
    }

    /**
     * Returns null if no report scope is specified.
     */
    private function resolveReportScope(?string $report): ?GitScope
    {
        if ($report !== null && $report !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($report);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid report scope: %s. Expected format: git:<ref>', $report),
                );
            }

            return $scope;
        }

        return null;
    }
}
