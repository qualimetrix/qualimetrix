<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Resolves analysis scope, file discovery strategy and git client from CLI input.
 *
 * Stateless service — no DI registration needed, instantiate via `new`.
 */
final class GitScopeResolver
{
    /**
     * Resolves analysis scope, file discovery strategy and git client from CLI input.
     */
    public function resolve(InputInterface $input, ResolvedConfiguration $resolved): GitScopeResolution
    {
        $paths = $resolved->paths->paths;

        $reportScope = $this->resolveReportScope($input);

        $gitClient = null;
        if ($reportScope !== null) {
            $gitClient = new GitClient($resolved->analysis->projectRoot);
        }

        $fileDiscovery = new FinderFileDiscovery($resolved->paths->excludes);

        return new GitScopeResolution(
            paths: $paths,
            fileDiscovery: $fileDiscovery,
            gitClient: $gitClient,
            reportScope: $reportScope,
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
