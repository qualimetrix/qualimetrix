<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\Filter\BaselineFilter;
use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Suppression\Suppression;
use AiMessDetector\Core\Util\PathMatcher;
use AiMessDetector\Core\Violation\Filter\PathExclusionFilter;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Infrastructure\Git\GitScopeFilter;

/**
 * Pipeline that applies all violation filters in order:
 * baseline -> suppression -> path exclusion -> git scope.
 */
final readonly class ViolationFilterPipeline
{
    public function __construct(
        private BaselineLoader $baselineLoader,
        private ViolationHasher $violationHasher,
        private SuppressionFilter $suppressionFilter,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Loads per-file suppression tags into the suppression filter.
     *
     * Must be called before filter() for @aimd-ignore tags to take effect.
     *
     * @param array<string, list<Suppression>> $suppressions Per-file suppression tags
     */
    public function loadSuppressions(array $suppressions): void
    {
        $this->suppressionFilter->clearSuppressions();

        foreach ($suppressions as $file => $fileSuppression) {
            $this->suppressionFilter->setSuppressions($file, $fileSuppression);
        }
    }

    /**
     * Applies all filters to violations and returns result with metadata.
     *
     * @param list<Violation> $violations
     */
    public function filter(array $violations, ViolationFilterOptions $options): ViolationFilterResult
    {
        $baselineFilter = null;
        $baselineFiltered = 0;
        $staleKeys = [];
        $staleCount = 0;

        // 1. Baseline filter
        if ($options->baselinePath !== null && $options->baselinePath !== '') {
            $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;
            $baseline = $this->baselineLoader->load($options->baselinePath, $projectRoot);

            // Detect stale entries
            $existingCanonicals = array_values(array_unique(
                array_map(
                    fn(Violation $v) => $v->symbolPath->toCanonical(),
                    $violations,
                ),
            ));
            $staleKeys = $baseline->getStaleKeys($existingCanonicals);

            foreach ($staleKeys as $key) {
                $staleCount += \count($baseline->entries[$key] ?? []);
            }

            // Apply filter only if stale check passes (caller decides what to do with stale data)
            if ($staleKeys === [] || $options->ignoreStaleBaseline) {
                $baselineFilter = new BaselineFilter($baseline, $this->violationHasher);
                $beforeCount = \count($violations);
                $violations = array_values(array_filter(
                    $violations,
                    fn(Violation $v) => $baselineFilter->shouldInclude($v),
                ));
                $baselineFiltered = $beforeCount - \count($violations);
            }
        }

        // 2. Suppression filter
        $suppressionFiltered = 0;
        if (!$options->disableSuppression) {
            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                fn(Violation $v) => $this->suppressionFilter->shouldInclude($v),
            ));
            $suppressionFiltered = $beforeCount - \count($violations);
        }

        // 3. Path exclusion filter
        $pathExclusionFiltered = 0;
        $configPaths = $this->configurationProvider->getConfiguration()->excludePaths;
        $allPaths = array_values(array_unique([...$configPaths, ...$options->excludePaths]));

        if ($allPaths !== []) {
            $pathMatcher = new PathMatcher($allPaths);
            $filter = new PathExclusionFilter($pathMatcher);

            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                fn(Violation $v) => $filter->shouldInclude($v),
            ));
            $pathExclusionFiltered = $beforeCount - \count($violations);
        }

        // 4. Git scope filter
        $gitScopeFiltered = 0;
        if ($options->gitScope !== null && $options->gitScope->reportScope !== null) {
            $gitFilter = new GitScopeFilter(
                $options->gitScope->gitClient,
                $options->gitScope->reportScope,
                !$options->gitScope->strictMode,
            );

            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                fn(Violation $v) => $gitFilter->shouldInclude($v),
            ));
            $gitScopeFiltered = $beforeCount - \count($violations);
        }

        return new ViolationFilterResult(
            violations: $violations,
            baselineFiltered: $baselineFiltered,
            suppressionFiltered: $suppressionFiltered,
            pathExclusionFiltered: $pathExclusionFiltered,
            gitScopeFiltered: $gitScopeFiltered,
            baselineFilter: $baselineFilter,
            staleBaselineKeys: $staleKeys,
            staleBaselineCount: $staleCount,
        );
    }
}
