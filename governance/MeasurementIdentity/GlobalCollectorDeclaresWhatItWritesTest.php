<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\MeasurementIdentity;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * A metric is aggregated from the values of the collector that declares it.
 *
 * Aggregation runs twice: once over every collector's definitions, and again
 * over the global collectors' definitions after those collectors have
 * overwritten class-level values. A global collector that writes a metric
 * without declaring it is therefore skipped by the second pass, and the
 * report's namespace and project rollups keep summarising values the collector
 * has already replaced. Nothing else notices: the class-level numbers are
 * right, every unit test of the collector passes, and only the aggregates lie.
 *
 * `design.dit` reached a release in exactly that state, its definition left on
 * the per-file collector by a comment saying the metric was "already declared".
 *
 * What this control does NOT check: that a declaration is *adequate*. A
 * definition carrying the right name but the wrong `collectedAt`, or missing a
 * level's aggregation strategies, satisfies this test while still leaving that
 * level unaggregated. There is no declared spec of required levels to check a
 * definition against, so the gap is named here rather than papered over.
 *
 * The definitions are read by calling the methods, not by reading the source:
 * neither `provides()` nor `getMetricDefinitions()` is required to return
 * literals, and a sweep over the source text silently returns nothing for the
 * ones that compute.
 */
final class GlobalCollectorDeclaresWhatItWritesTest extends TestCase
{
    #[Test]
    public function itFindsEveryGlobalCollectorDeclaringTheMetricsItWrites(): void
    {
        $collectors = self::globalCollectors();

        self::assertNotSame([], $collectors, 'No global collectors were discovered, so this control proves nothing');

        $undeclared = [];

        foreach ($collectors as $class) {
            $collector = (new ReflectionClass($class))->newInstanceWithoutConstructor();

            $declared = array_map(
                static fn(MetricDefinition $definition): string => $definition->name,
                $collector->getMetricDefinitions(),
            );

            foreach (array_diff($collector->provides(), $declared) as $metric) {
                $undeclared[] = $class . ' writes ' . $metric . ' but does not declare it';
            }
        }

        self::assertSame([], $undeclared, implode("\n", $undeclared));
    }

    /**
     * Two collectors declaring one metric is the other way for ownership to be
     * unclear, and it is not harmless: both definitions enter the first
     * aggregation pass, which iterates definitions rather than metric names, so
     * every strategy is computed over a doubled population.
     */
    #[Test]
    public function itGivesEveryMetricASingleDeclaringCollector(): void
    {
        $owners = [];

        foreach (self::collectorsDeclaringMetrics() as $class) {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod('getMetricDefinitions');

            // Invoked through reflection rather than called on the instance:
            // the set swept here is "everything declaring metrics", which no
            // one interface spans.
            /** @var iterable<MetricDefinition> $definitions */
            $definitions = $method->invoke($reflection->newInstanceWithoutConstructor());

            foreach ($definitions as $definition) {
                $owners[$definition->name][$class] = true;
            }
        }

        $shared = [];

        foreach ($owners as $metric => $declaring) {
            if (\count($declaring) > 1) {
                $shared[] = $metric . ' is declared by ' . implode(' and ', array_keys($declaring));
            }
        }

        self::assertSame([], $shared, implode("\n", $shared));
    }

    /**
     * @return list<class-string<GlobalContextCollectorInterface>>
     */
    private static function globalCollectors(): array
    {
        $collectors = [];

        foreach (self::productionClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(GlobalContextCollectorInterface::class)) {
                continue;
            }

            /** @var class-string<GlobalContextCollectorInterface> $class */
            $collectors[] = $class;
        }

        sort($collectors);

        return $collectors;
    }

    /**
     * Every collector that can declare a metric, global or not — the pairing
     * this control refuses is between any two of them, not only between two
     * global ones.
     *
     * @return list<class-string>
     */
    private static function collectorsDeclaringMetrics(): array
    {
        $collectors = [];

        foreach (self::productionClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->hasMethod('getMetricDefinitions')) {
                continue;
            }

            if ($reflection->getMethod('getMetricDefinitions')->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $collectors[] = $class;
        }

        sort($collectors);

        return $collectors;
    }

    /**
     * @return list<class-string>
     */
    private static function productionClasses(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $classes = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $class = 'Qualimetrix\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
