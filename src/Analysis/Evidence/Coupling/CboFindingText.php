<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * The words of a `coupling.cbo` finding: which coupling direction dominates,
 * and the message and recommendation that name it. {@see CboRule} decides
 * whether there is a finding; this class only says it.
 */
final class CboFindingText
{
    private function __construct() {}

    /**
     * Determines coupling direction and builds a direction-aware finding message.
     *
     * When $isAppScope is true, labels the metric as "CBO_APP" and appends
     * framework exclusion count so users understand the decomposition.
     */
    public static function message(string $cbo, int $ca, int $ce, int $threshold, bool $isAppScope, ?int $ceFramework): string
    {
        $direction = self::direction($ca, $ce);
        $label = $isAppScope ? 'CBO_APP' : 'CBO';
        $frameworkSuffix = $isAppScope && $ceFramework !== null
            ? \sprintf(', framework: %d classes excluded', $ceFramework)
            : '';

        return match ($direction) {
            'efferent' => \sprintf(
                'Efferent coupling too high: depends on %d classes (%s: %s, threshold: %d%s)',
                $ce,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
            'afferent' => \sprintf(
                'Afferent coupling too high: %d classes depend on this (%s: %s, threshold: %d%s)',
                $ca,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
            default => \sprintf(
                'Coupling too high: %d inbound + %d outbound (%s: %s, threshold: %d%s)',
                $ca,
                $ce,
                $label,
                $cbo,
                $threshold,
                $frameworkSuffix,
            ),
        };
    }

    /**
     * Builds a direction-aware recommendation, prefixed with a class's top
     * dependencies when the run has a dependency graph.
     *
     * @param array{applicationScope: bool, frameworkCe: ?int, namespaceLevel: bool} $presentation
     */
    public static function recommendation(
        string $cbo,
        int $ca,
        int $ce,
        int $threshold,
        ?SymbolPath $symbolPath,
        AnalysisContext $context,
        array $presentation,
    ): string {
        $direction = self::direction($ca, $ce);
        $label = $presentation['applicationScope'] ? 'CBO_APP' : 'CBO';
        $subject = $presentation['namespaceLevel'] ? 'namespace' : 'class';

        $base = match ($direction) {
            'efferent' => \sprintf(
                '%s: %s (threshold: %d) — extract dependencies to reduce outbound coupling',
                $label,
                $cbo,
                $threshold,
            ),
            'afferent' => \sprintf(
                '%s: %s (threshold: %d) — this %s is a coupling magnet, consider if it is a healthy abstraction point',
                $label,
                $cbo,
                $threshold,
                $subject,
            ),
            default => \sprintf(
                '%s: %s (threshold: %d) — reduce both inbound and outbound coupling',
                $label,
                $cbo,
                $threshold,
            ),
        };

        $topDeps = self::topDependencies($symbolPath, $context);
        if ($topDeps !== '') {
            return $topDeps . '. ' . $base;
        }

        return $base;
    }

    /**
     * Returns a formatted string of top-5 efferent dependencies for a class, sorted by occurrence count.
     *
     * Only works for class-level SymbolPaths when the dependency graph is available.
     */
    private static function topDependencies(?SymbolPath $symbolPath, AnalysisContext $context): string
    {
        $dependencyGraph = $context->dependencyGraph;
        if ($symbolPath === null || $dependencyGraph === null) {
            return '';
        }

        if ($symbolPath->getType() !== SymbolType::Class_) {
            return '';
        }

        $dependencies = $dependencyGraph->getClassDependencies($symbolPath);
        if ($dependencies === []) {
            return '';
        }

        // Count occurrences per target class (a class may be referenced multiple times)
        $counts = [];
        $targetNames = [];
        foreach ($dependencies as $dep) {
            $targetKey = $dep->targetLogical()->toCanonical();
            $counts[$targetKey] = ($counts[$targetKey] ?? 0) + 1;
            $targetNames[$targetKey] = $dep->targetLogical()->type ?? $targetKey;
        }

        // Sort by occurrence count descending
        arsort($counts);

        $topKeys = \array_slice(array_keys($counts), 0, 5);
        $topNames = array_map(static fn(string $targetKey): string => $targetNames[$targetKey], $topKeys);

        return 'Top dependencies: ' . implode(', ', $topNames);
    }

    /**
     * Determines coupling direction: 'afferent', 'efferent', or 'balanced'.
     *
     * Uses a 2:1 ratio threshold: a direction dominates when it accounts
     * for more than twice the other direction.
     */
    private static function direction(int $ca, int $ce): string
    {
        if ($ca > $ce * 2) {
            return 'afferent';
        }

        if ($ce > $ca * 2) {
            return 'efferent';
        }

        return 'balanced';
    }
}
