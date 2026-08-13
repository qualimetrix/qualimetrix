<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassMetricsProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ClassWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Executes derived collectors for exact callable and class declarations.
 *
 * Base AST traversal stays in CompositeCollector. This runner orders derived
 * collectors by their declared collector dependencies, accumulates each
 * result into the next calculation, and writes only metrics whose definitions
 * target the current exact subject level.
 */
final readonly class DerivedCollectorRunner
{
    /** @var list<DerivedCollectorInterface> */
    private array $collectors;

    /** @param list<DerivedCollectorInterface> $collectors */
    public function __construct(array $collectors)
    {
        $this->collectors = array_values($collectors);
    }

    /**
     * Applies all derived metrics to their exact declaration keys.
     *
     * @param list<MetricCollectorInterface> $baseCollectors
     */
    public function apply(MetricBag $baseBag, array $baseCollectors, RelativePath $file): MetricBag
    {
        $sorted = (new DerivedCollectorExecutionPlan($this->collectors, $baseCollectors))->ordered();
        $result = $this->applyToCallables($baseBag, $baseCollectors, $file, $sorted);

        return $this->applyToClasses($result, $baseCollectors, $file, $sorted);
    }

    /**
     * @param list<DerivedCollectorInterface> $collectors
     *
     * @return array<string, true>
     */
    private function metricNamesFor(array $collectors, SymbolLevel $level): array
    {
        $names = [];
        foreach ($collectors as $collector) {
            foreach ($collector->getMetricDefinitions() as $definition) {
                if ($definition instanceof MetricDefinition && $definition->collectedAt === $level) {
                    $names[$definition->name] = true;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<MetricCollectorInterface> $collectors
     * @param list<DerivedCollectorInterface> $sorted
     */
    private function applyToCallables(MetricBag $result, array $collectors, RelativePath $file, array $sorted): MetricBag
    {
        foreach ($this->callableMetricsByDeclaration($collectors, $file) as $callable) {
            $working = $callable->metrics;
            foreach ($sorted as $collector) {
                $names = $this->metricNamesFor([$collector], SymbolLevel::Callable);
                if ($names === []) {
                    continue;
                }

                $derived = MetricBag::fromArray(array_intersect_key($collector->calculate($working)->all(), $names));
                $working = $working->merge($derived);
                $subject = MetricSubject::declaration($callable->declarationPath);
                foreach ($derived->all() as $name => $value) {
                    $result = $result->with($this->metricKey($name, $subject, $callable->kind), $value);
                }
            }
        }

        return $result;
    }

    /**
     * @param list<MetricCollectorInterface> $collectors
     * @param list<DerivedCollectorInterface> $sorted
     */
    private function applyToClasses(MetricBag $result, array $collectors, RelativePath $file, array $sorted): MetricBag
    {
        foreach ($this->classMetricsByDeclaration($collectors, $file) as $class) {
            $working = $class->metrics;
            foreach ($sorted as $collector) {
                $names = $this->metricNamesFor([$collector], SymbolLevel::Class_);
                if ($names === []) {
                    continue;
                }

                $derived = MetricBag::fromArray(array_intersect_key($collector->calculate($working)->all(), $names));
                $working = $working->merge($derived);
                foreach ($derived->all() as $name => $value) {
                    $result = $result->with($this->metricKey($name, $class->subject), $value);
                }
            }
        }

        return $result;
    }

    /** @param list<MetricCollectorInterface> $collectors
     *  @return array<string, CallableWithMetrics> */
    private function callableMetricsByDeclaration(array $collectors, RelativePath $file): array
    {
        $callables = [];
        foreach ($collectors as $collector) {
            if (!$collector instanceof CallableMetricsProviderInterface) {
                continue;
            }

            foreach ($collector->getCallablesWithMetrics($file) as $callable) {
                $key = $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
                $existing = $callables[$key] ?? null;
                $callables[$key] = $existing === null ? $callable : new CallableWithMetrics(
                    $existing->declarationPath,
                    $existing->kind,
                    $existing->anonymousSyntax,
                    $existing->lexicalClassContext,
                    $existing->classAggregationOwner,
                    $existing->metrics->merge($callable->metrics),
                    $existing->sourceLine,
                );
            }
        }

        return $callables;
    }

    /** @param list<MetricCollectorInterface> $collectors
     *  @return array<string, ClassWithMetrics> */
    private function classMetricsByDeclaration(array $collectors, RelativePath $file): array
    {
        $classes = [];
        foreach ($collectors as $collector) {
            if (!$collector instanceof ClassMetricsProviderInterface) {
                continue;
            }

            foreach ($collector->getClassesWithMetrics($file) as $class) {
                $key = $class->subject->toCanonical();
                $existing = $classes[$key] ?? null;
                $classes[$key] = $existing === null ? $class : new ClassWithMetrics(
                    $existing->declarationPath,
                    $existing->line,
                    $existing->metrics->merge($class->metrics),
                );
            }
        }

        return $classes;
    }

    private function metricKey(string $metricName, MetricSubject $subject, ?CallableKind $callableKind = null): string
    {
        return $metricName . ':' . ($callableKind === null ? '' : $callableKind->value . ':') . $subject->toCanonical();
    }

}

/** @internal Derived collector dependency plan, loaded with its runner. */
final readonly class DerivedCollectorExecutionPlan
{
    /** @var array{base: list<MetricCollectorInterface>, derived: list<DerivedCollectorInterface>} */
    private array $collectorSets;

    /**
     * @param list<DerivedCollectorInterface> $derivedCollectors
     * @param list<MetricCollectorInterface> $baseCollectors
     */
    public function __construct(array $derivedCollectors, array $baseCollectors)
    {
        $this->collectorSets = [
            'base' => $baseCollectors,
            'derived' => $derivedCollectors,
        ];
    }

    /** @return list<DerivedCollectorInterface> */
    public function ordered(): array
    {
        $baseCollectors = $this->indexBaseCollectors();
        $derivedCollectors = $this->indexDerivedCollectors($baseCollectors);
        [$inDegree, $dependents] = $this->dependencyGraph($derivedCollectors, $baseCollectors);

        return $this->topologicalOrder($derivedCollectors, $inDegree, $dependents);
    }

    /** @return array<string, MetricCollectorInterface> */
    private function indexBaseCollectors(): array
    {
        $byName = [];
        foreach ($this->collectorSets['base'] as $collector) {
            $name = $collector->getName();
            if (isset($byName[$name])) {
                throw new LogicException(\sprintf('Duplicate base collector name: %s', $name));
            }
            $byName[$name] = $collector;
        }

        return $byName;
    }

    /**
     * @param array<string, MetricCollectorInterface> $baseCollectorsByName
     *
     * @return array<string, DerivedCollectorInterface>
     */
    private function indexDerivedCollectors(array $baseCollectorsByName): array
    {
        $byName = [];
        foreach ($this->collectorSets['derived'] as $collector) {
            $name = $collector->getName();
            if (isset($byName[$name]) || isset($baseCollectorsByName[$name])) {
                throw new LogicException(\sprintf('Duplicate collector name: %s', $name));
            }
            $byName[$name] = $collector;
        }

        return $byName;
    }

    /**
     * @param array<string, DerivedCollectorInterface> $byName
     * @param array<string, MetricCollectorInterface> $baseCollectorsByName
     *
     * @return array{array<string, int>, array<string, list<string>>}
     */
    private function dependencyGraph(array $byName, array $baseCollectorsByName): array
    {
        $inDegree = array_fill_keys(array_keys($byName), 0);
        $dependents = array_fill_keys(array_keys($byName), []);
        $known = $byName + $baseCollectorsByName;
        foreach ($byName as $name => $collector) {
            $required = array_values(array_unique($collector->requires()));
            $missing = array_diff($required, array_keys($known));
            if ($missing !== []) {
                throw new LogicException(\sprintf('Derived collector "%s" requires unknown collector "%s"', $name, reset($missing)));
            }
            foreach (array_intersect($required, array_keys($byName)) as $provider) {
                $inDegree[$name]++;
                $dependents[$provider][] = $name;
            }
        }

        $this->validateLevelDependencies($byName, $known);

        return [$inDegree, $dependents];
    }

    /**
     * @param array<string, DerivedCollectorInterface> $derivedCollectors
     * @param array<string, MetricCollectorInterface|DerivedCollectorInterface> $collectors
     */
    private function validateLevelDependencies(array $derivedCollectors, array $collectors): void
    {
        foreach ($derivedCollectors as $name => $collector) {
            foreach ($collector->getMetricDefinitions() as $definition) {
                if (!$definition instanceof MetricDefinition) {
                    continue;
                }
                foreach (array_unique($collector->requires()) as $provider) {
                    if (!$this->participatesAtLevel($collectors[$provider], $definition->collectedAt)) {
                        throw new LogicException(\sprintf(
                            'Derived collector "%s" requires "%s" at %s level, but its provider has no definition at that level',
                            $name,
                            $provider,
                            $definition->collectedAt->value,
                        ));
                    }
                }
            }
        }
    }

    private function participatesAtLevel(MetricCollectorInterface|DerivedCollectorInterface $collector, SymbolLevel $level): bool
    {
        foreach ($collector->getMetricDefinitions() as $definition) {
            if ($definition instanceof MetricDefinition && $definition->collectedAt === $level) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, DerivedCollectorInterface> $byName
     * @param array<string, int> $inDegree
     * @param array<string, list<string>> $dependents
     *
     * @return list<DerivedCollectorInterface>
     */
    private function topologicalOrder(array $byName, array $inDegree, array $dependents): array
    {
        $ready = $this->readyNames($inDegree);
        $sorted = [];
        while ($ready !== []) {
            $name = array_shift($ready);
            $inDegree[$name] = -1;
            $sorted[] = $byName[$name];
            foreach ($dependents[$name] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
            $ready = $this->readyNames($inDegree);
        }

        if (\count($sorted) !== \count($byName)) {
            $names = array_keys(array_filter($inDegree, static fn(int $degree): bool => $degree > 0));
            sort($names, \SORT_STRING);
            throw new LogicException(\sprintf('Cyclic dependency detected between derived collectors: %s', implode(', ', $names)));
        }

        return $sorted;
    }

    /**
     * @param array<string, int> $inDegree
     *
     * @return list<string>
     */
    private function readyNames(array $inDegree): array
    {
        $names = array_keys(array_filter($inDegree, static fn(int $degree): bool => $degree === 0));
        sort($names, \SORT_STRING);

        return $names;
    }
}
