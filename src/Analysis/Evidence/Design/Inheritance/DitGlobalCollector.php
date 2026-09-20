<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\GlobalContextCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Recalculates DIT (Depth of Inheritance Tree) using the global dependency graph.
 *
 * The per-file InheritanceDepthCollector can only see classes within a single file,
 * so it cannot traverse inheritance chains that span multiple files. This global
 * collector builds a complete parent map from the dependency graph and recalculates
 * DIT correctly for all project classes.
 *
 * How a depth is resolved belongs to {@see InheritanceDepthResolver}; what is
 * left here is the collector protocol and the repository walk — which
 * declarations carry this metric, and where each answer is written.
 */
final class DitGlobalCollector implements GlobalContextCollectorInterface
{
    private const NAME = 'dit-global';

    public function __construct(private readonly ExternalAncestry $externalAncestry) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function requires(): array
    {
        // No dependencies on other global collectors.
        // DIT is initially computed per-file by InheritanceDepthCollector;
        // this collector overwrites with correct cross-file values.
        return [];
    }

    public function provides(): array
    {
        return [MetricName::DESIGN_DIT];
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition(
                name: MetricName::DESIGN_DIT,
                collectedAt: SymbolLevel::Class_,
                aggregations: [
                    SymbolLevel::Namespace_->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                    SymbolLevel::Project->value => [
                        AggregationStrategy::Average,
                        AggregationStrategy::Max,
                        AggregationStrategy::Percentile95,
                    ],
                ],
            ),
        ];
    }

    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void {
        /** @var list<array{fqn: string, subject: MetricSubject, declaration: DeclarationPath}> $population */
        $population = [];
        $measured = [];

        foreach ($this->measuredClassDeclarations($repository) as $classFqn => $subject) {
            $declaration = $subject->declarationPath();
            \assert($declaration !== null);

            $population[] = ['fqn' => $classFqn, 'subject' => $subject, 'declaration' => $declaration];
            $measured[$declaration->toCanonical()] = true;
        }

        $resolver = InheritanceDepthResolver::fromGraph($graph, $this->projectClassNames($repository), $measured, $this->externalAncestry);

        /** @var array<string, non-empty-list<int>> $depthsByName */
        $depthsByName = [];

        foreach ($population as $entry) {
            $dit = $resolver->depthOf($entry['declaration']);

            $repository->addSubjectScalar($entry['subject'], MetricName::DESIGN_DIT, $dit);

            $depthsByName[$entry['fqn']][] = $dit;
        }

        // One value per name for the readers that only know names --
        // the aggregates, the metrics export and a user formula. It is written
        // after the per-declaration pass on purpose: `addSubject` projects each
        // declaration onto the shared logical bag, so without this the name
        // would again carry whichever declaration was stored last.
        foreach ($depthsByName as $classFqn => $depths) {
            // The resolver's answer, not the maximum of what was written: a
            // file declaring one name twice produces two `extends` edges but
            // only one measured declaration, and publishing the written half
            // would leave a child reporting a greater depth than its parent.
            $repository->addScalar(
                SymbolPath::fromClassFqn($classFqn),
                MetricName::DESIGN_DIT,
                $resolver->deepestForName($classFqn) ?? max($depths),
            );
        }
    }

    /**
     * The analysed project's own class names.
     *
     * A parent absent from the inheritance index is only "external" when it is
     * absent from here too: a class that has no parent of its own has no entry
     * in an index built from `extends` edges, so without this set every
     * in-project root was sent out to be resolved as though it belonged to
     * somebody else. Measured before the fix: 25 of 40 such lookups on
     * symfony/http-kernel, and 3 of 3 across the whole finding-gate corpus.
     *
     * @return array<string, true>
     */
    private function projectClassNames(MetricRepositoryInterface $repository): array
    {
        $names = [];

        foreach ($repository->all(SymbolLevel::Class_) as $classSymbol) {
            $names[$classSymbol->symbolPath->toString()] = true;
        }

        return $names;
    }

    /**
     * Every class declaration this metric is measured on, keyed by its name.
     *
     * The key repeats for a name declared more than once, which is the point:
     * the subjects are distinct and each gets its own depth.
     *
     * The per-file pass measures named class declarations only, so its keys
     * are DIT's population. Correcting every class-level symbol instead would
     * silently enrol interfaces, traits and enums and move the denominator of
     * every aggregate.
     *
     * @return iterable<string, MetricSubject>
     */
    private function measuredClassDeclarations(MetricRepositoryInterface $repository): iterable
    {
        foreach ($repository->allDeclarations() as $declarationSymbol) {
            $subject = $declarationSymbol->subject;
            // The enumeration also carries methods and global functions.
            $declaration = $subject?->declarationPath();

            if ($subject === null || $declaration === null || $declaration->logical->getType() !== SymbolType::Class_) {
                continue;
            }

            if (!$repository->getSubject($subject)->has(MetricName::DESIGN_DIT)) {
                continue;
            }

            yield $declaration->logical->toString() => $subject;
        }
    }

}
