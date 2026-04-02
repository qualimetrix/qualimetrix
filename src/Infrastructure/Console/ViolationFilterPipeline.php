<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Filter\PathExclusionFilter;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Git\GitScopeFilter;

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
     * Must be called before filter() for `@qmx-ignore` tags to take effect.
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
            $baseline = $this->baselineLoader->load($options->baselinePath);

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

        // 5. Analyze scope filter — keep only violations for files in scope
        $analyzeScopeFiltered = 0;
        if ($options->scopeFilePaths !== null) {
            $scopeSet = array_flip($options->scopeFilePaths);
            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                static fn(Violation $v) => !$v->location->isNone() && isset($scopeSet[$v->location->file]),
            ));
            $analyzeScopeFiltered = $beforeCount - \count($violations);
        }

        return new ViolationFilterResult(
            violations: $violations,
            baselineFiltered: $baselineFiltered,
            suppressionFiltered: $suppressionFiltered,
            pathExclusionFiltered: $pathExclusionFiltered,
            gitScopeFiltered: $gitScopeFiltered,
            analyzeScopeFiltered: $analyzeScopeFiltered,
            baselineFilter: $baselineFilter,
            staleBaselineKeys: $staleKeys,
            staleBaselineCount: $staleCount,
        );
    }
}
