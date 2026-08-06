<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Filter\NamespaceExclusionFilter;
use Qualimetrix\Core\Violation\Filter\PathExclusionFilter;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Git\GitScopeFilter;

/**
 * Pipeline that applies all violation filters in order:
 * baseline -> suppression -> path exclusion -> namespace exclusion -> git scope.
 *
 * @qmx-threshold complexity.cognitive error=35 — Preserves the ordered multi-stage violation filtering flow.
 * Multi-stage filter pipeline — cognitive complexity is structural.
 */
final readonly class ViolationFilterPipeline
{
    public function __construct(
        private BaselineLoader $baselineLoader,
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

            // Detect stale entries — keyed on the complete identity (symbol,
            // channel, dependency edge), not on the symbol alone, so a
            // repaired finding strands its own entry instead of its
            // neighbours'.
            $staleEntries = $baseline->staleEntries(BaselineFilter::measuredIdentityKeys($violations));
            $staleKeys = array_map(
                static fn(BaselineEntry $entry) => \sprintf(
                    '%s [%s]',
                    $entry->identity->describe(),
                    $entry->selector()->value,
                ),
                $staleEntries,
            );
            $staleCount = \count($staleEntries);

            // A stale entry is reported, never acted on: it does not fail the
            // run and it does not disable its neighbours (§5.7). Under the
            // per-identity key of §5.1 an entry goes stale the moment one
            // channel of a symbol is repaired, so the old "any stale key
            // skips the filter" rule would answer the first repair by
            // resurfacing every accepted finding at once — the tool punishing
            // an improvement.
            $baselineFilter = new BaselineFilter($baseline);
            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                fn(Violation $v) => $baselineFilter->shouldInclude($v),
            ));
            $baselineFiltered = $beforeCount - \count($violations);
        }

        // 2. Suppression filter
        $suppressedViolations = [];
        if (!$options->disableSuppression) {
            $included = [];
            foreach ($violations as $v) {
                if ($this->suppressionFilter->shouldInclude($v)) {
                    $included[] = $v;
                } else {
                    $suppressedViolations[] = $v;
                }
            }
            $violations = $included;
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

        // 4. Namespace exclusion filter (architecture.* rules are exempt — see NamespaceExclusionFilter)
        $namespaceExclusionFiltered = 0;
        $configNamespaces = $this->configurationProvider->getConfiguration()->excludeNamespaces;
        $allNamespaces = array_values(array_unique([...$configNamespaces, ...$options->excludeNamespaces]));
        $namespaceMatcher = new NamespaceMatcher($allNamespaces);

        if (!$namespaceMatcher->isEmpty()) {
            $filter = new NamespaceExclusionFilter($namespaceMatcher);

            $beforeCount = \count($violations);
            $violations = array_values(array_filter(
                $violations,
                fn(Violation $v) => $filter->shouldInclude($v),
            ));
            $namespaceExclusionFiltered = $beforeCount - \count($violations);
        }

        // 5. Git scope filter
        $gitScopeFiltered = 0;
        if ($options->gitScope !== null && $options->gitScope->reportScope !== null) {
            $gitFilter = new GitScopeFilter(
                $options->gitScope->gitClient,
                $options->gitScope->reportScope,
                $options->gitScope->projectRoot,
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
            suppressionFiltered: \count($suppressedViolations),
            pathExclusionFiltered: $pathExclusionFiltered,
            namespaceExclusionFiltered: $namespaceExclusionFiltered,
            gitScopeFiltered: $gitScopeFiltered,
            baselineFilter: $baselineFilter,
            staleBaselineKeys: $staleKeys,
            staleBaselineCount: $staleCount,
            suppressedViolations: $suppressedViolations,
        );
    }
}
