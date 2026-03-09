<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

use AiMessDetector\Analysis\Discovery\FinderFileDiscovery;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use InvalidArgumentException;
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

        $analyzeScope = $this->resolveAnalyzeScope($input);
        $reportScope = $this->resolveReportScope($input, $analyzeScope);

        $gitClient = null;
        if ($analyzeScope !== null || $reportScope !== null) {
            $gitClient = new GitClient($resolved->analysis->projectRoot);
        }

        $fileDiscovery = $analyzeScope !== null && $gitClient !== null
            ? new GitFileDiscovery($gitClient, $analyzeScope, $resolved->paths->excludes)
            : new FinderFileDiscovery($resolved->paths->excludes);

        return new GitScopeResolution(
            paths: $paths,
            fileDiscovery: $fileDiscovery,
            gitClient: $gitClient,
            analyzeScope: $analyzeScope,
            reportScope: $reportScope,
        );
    }

    /**
     * Resolves the analyze scope from CLI options.
     *
     * Returns null if no analyze scope is specified (full analysis).
     */
    private function resolveAnalyzeScope(InputInterface $input): ?GitScope
    {
        $analyze = $input->getOption('analyze');
        if (\is_string($analyze) && $analyze !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($analyze);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid analyze scope: %s. Expected format: git:<ref>', $analyze),
                );
            }

            return $scope;
        }

        return null;
    }

    /**
     * Resolves the report scope from CLI options.
     *
     * Returns null if no report scope is specified.
     * If analyze scope is set but report scope is not, report scope equals analyze scope (implicit).
     */
    private function resolveReportScope(InputInterface $input, ?GitScope $analyzeScope): ?GitScope
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

        // Implicit: if analyze scope is set, report scope equals analyze scope
        return $analyzeScope;
    }
}
