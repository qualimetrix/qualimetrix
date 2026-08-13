<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves analysis scope, file discovery strategy and git client from CLI input.
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
     * Resolves analysis scope, file discovery strategy and git client from CLI input.
     */
    public function resolve(InputInterface $input, TransitionalResolvedConfiguration $resolved): GitScopeResolution
    {
        // ADR 0015 Phase 2: convert raw CLI `paths` strings into AbsolutePath VOs
        // at the boundary, against the current working directory captured here.
        // `Application::doRun()` has already applied `--working-dir`, so this
        // matches the `getcwd()` value used by `CheckCommand::resolveConfiguration()`.
        $cwd = AbsolutePath::fromString((string) getcwd());
        $paths = array_map(
            static fn(string $raw): AbsolutePath => PathFactory::fromCliArgument($raw, $cwd),
            $resolved->paths,
        );

        // ADR 0015 Phase 5: $projectRoot is already an AbsolutePath in the configuration.
        $projectRoot = $resolved->runtime->projectRoot;

        $reportScope = $this->resolveReportScope($input);

        $gitClient = $reportScope !== null
            ? new GitClient($projectRoot, $this->logger)
            : null;

        if ($gitClient !== null) {
            $gitClient->validateScope($reportScope->ref);
        }

        $fileDiscovery = $this->fileDiscoveryFactory->create($resolved->pathExcludes);

        return new GitScopeResolution(
            paths: $paths,
            fileDiscovery: $fileDiscovery,
            gitClient: $gitClient,
            reportScope: $reportScope,
            projectRoot: $projectRoot,
        );
    }

    /**
     * Resolves the report scope from CLI options.
     *
     * Returns null if no report scope is specified.
     */
    private function resolveReportScope(InputInterface $input): ?GitScope
    {
        $report = $input->getOption('report');
        if (\is_string($report) && $report !== '') {
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
