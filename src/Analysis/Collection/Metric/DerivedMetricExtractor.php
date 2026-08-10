<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Transfers derived metrics from a file bag to existing typed repository subjects.
 *
 * A callable key is `{metric}:{callable-kind}:{MetricSubject::declaration()->toCanonical()}`;
 * a class key omits the callable-kind segment. Only a key constructed from the
 * supplied exact declaration subject is accepted. There is no FQN, source-line,
 * logical-class, or repository-creation fallback.
 */
final readonly class DerivedMetricExtractor
{
    public function __construct(
        private CompositeCollector $compositeCollector,
    ) {}

    /**
     * Extracts derived metrics from file-level MetricBag
     * and registers them as symbols in the repository.
     *
     * @param list<CallableWithMetrics> $callables
     * @param list<ClassWithMetrics> $classes
     */
    public function extract(
        MetricRepositoryInterface $repository,
        MetricBag $fileBag,
        array $callables,
        RelativePath $filePath,
        array $classes = [],
    ): void {
        $callableMetricNames = $this->derivedMetricNamesAt(SymbolLevel::Callable);
        foreach ($callables as $callable) {
            $subject = MetricSubject::declaration($callable->declarationPath);
            $derivedBag = $this->derivedBagFor(
                $fileBag,
                $callableMetricNames,
                $subject,
                $callable->kind->value,
            );

            if ($derivedBag->all() !== [] && $repository->hasSubject($subject)) {
                $repository->addSubject(
                    $subject,
                    $derivedBag,
                    $filePath,
                    $callable->sourceLine,
                );
            }
        }

        $classMetricNames = $this->derivedMetricNamesAt(SymbolLevel::Class_);
        foreach ($classes as $class) {
            $derivedBag = $this->derivedBagFor($fileBag, $classMetricNames, $class->subject);
            if ($derivedBag->all() !== [] && $repository->hasSubject($class->subject)) {
                $repository->addSubject($class->subject, $derivedBag, $filePath, $class->line);
            }
        }
    }

    /** @return array<string, true> */
    private function derivedMetricNamesAt(SymbolLevel $level): array
    {
        $names = [];
        foreach ($this->compositeCollector->getDerivedCollectors() as $collector) {
            foreach ($collector->getMetricDefinitions() as $definition) {
                if ($definition instanceof MetricDefinition && $definition->collectedAt === $level) {
                    $names[$definition->name] = true;
                }
            }
        }

        return $names;
    }

    /** @param array<string, true> $metricNames */
    private function derivedBagFor(
        MetricBag $fileBag,
        array $metricNames,
        MetricSubject $subject,
        ?string $callableKind = null,
    ): MetricBag {
        $bag = new MetricBag();
        foreach ($metricNames as $metricName => $_) {
            $key = $metricName . ':' . ($callableKind === null ? '' : $callableKind . ':') . $subject->toCanonical();
            if ($fileBag->has($key)) {
                $bag = $bag->with($metricName, $fileBag->require($key));
            }
        }

        return $bag;
    }

}
