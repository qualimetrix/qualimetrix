<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * What a health score was made of, and which class dragged its parent down.
 *
 * Separate from {@see HealthDimensionCatalog}, which names and labels the
 * dimensions: this one answers about the evidence behind a number, per symbol
 * level, and the two questions share no data. They lived in one class while the
 * decomposition was a single flat list; once it had to answer per level the
 * class held two subjects and the product said so.
 */
final class HealthDecompositionCatalog
{
    /**
     * What a health score at a given level was computed from.
     *
     * One list per level, because the formulas differ per level: the project
     * coupling formula reads CBO aggregates while the namespace one reads Ce
     * aggregates, and the single list that stood here printed the namespace's
     * inputs under a project score. `project` is resolved from `namespace`
     * when absent, mirroring
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition::getFormulaForLevel()}.
     *
     * `sources` names the formula keys a line stands for. It is usually the
     * displayed key itself; it differs where the formula reads a quotient the
     * report shows as an already-aggregated metric. The threshold advertised
     * for each line is checked against the formula term over these keys — see
     * HealthDecompositionAgreesWithFormulasTest.
     *
     * Denominators and adjusters are carried as `sources` of the line they
     * shape rather than shown as lines of their own: `size.symbol-method-count`
     * is not a complexity signal, it is what the sums are divided by.
     *
     * `coverage` says how much of its subject the line actually saw: the
     * `.count` the aggregate already publishes, and the population that count
     * is a share of. It is required on every line and `null` where the line is
     * not an aggregate over symbols — a class reading its own metrics, or a
     * namespace reading its own D — so that a new line has to answer the
     * question rather than omit it.
     *
     * @var array<string, array<string, list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}>>>
     */
    private const array INPUTS = [
        'health.complexity' => [
            SymbolLevel::Class_->value => [
                ['key' => 'complexity.ccn.avg', 'sources' => ['complexity.ccn.avg'], 'label' => 'CCN avg', 'direction' => 'lower', 'coverage' => null],
                ['key' => 'complexity.cognitive.avg', 'sources' => ['complexity.cognitive.avg'], 'label' => 'Cognitive avg', 'direction' => 'lower', 'coverage' => null],
                ['key' => 'complexity.ccn.max', 'sources' => ['complexity.ccn.max'], 'label' => 'CCN max', 'direction' => 'lower', 'coverage' => null],
                ['key' => 'complexity.cognitive.max', 'sources' => ['complexity.cognitive.max'], 'label' => 'Cognitive max', 'direction' => 'lower', 'coverage' => null],
            ],
            // The two average lines are the formula's own quotient: it divides
            // `complexity.ccn.sum` by `size.symbol-method-count`, and `.avg`
            // divides the same sum by `complexity.ccn.count`. Both denominators
            // count callables, so the two agree — measured equal on this tree
            // (12533 / 4842 = 2.5883932, the reported `complexity.ccn.avg`).
            SymbolLevel::Namespace_->value => [
                ['key' => 'complexity.ccn.avg', 'sources' => ['complexity.ccn.sum', MetricName::SIZE_SYMBOL_METHOD_COUNT], 'label' => 'CCN avg', 'direction' => 'lower', 'coverage' => ['count' => 'complexity.ccn.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'complexity.cognitive.avg', 'sources' => ['complexity.cognitive.sum', MetricName::SIZE_SYMBOL_METHOD_COUNT], 'label' => 'Cognitive avg', 'direction' => 'lower', 'coverage' => ['count' => 'complexity.cognitive.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'complexity.ccn.p95', 'sources' => ['complexity.ccn.p95'], 'label' => 'CCN p95', 'direction' => 'lower', 'coverage' => ['count' => 'complexity.ccn.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'complexity.cognitive.p95', 'sources' => ['complexity.cognitive.p95'], 'label' => 'Cognitive p95', 'direction' => 'lower', 'coverage' => ['count' => 'complexity.cognitive.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'complexity.ccn.max', 'sources' => ['complexity.ccn.max'], 'label' => 'CCN max', 'direction' => 'lower', 'coverage' => ['count' => 'complexity.ccn.count', 'unit' => CoverageUnit::Callables]],
            ],
        ],
        'health.cohesion' => [
            SymbolLevel::Class_->value => [
                ['key' => 'cohesion.tcc', 'sources' => ['cohesion.tcc', MetricName::SIZE_METHOD_COUNT, 'cohesion.pure-method-count'], 'label' => 'TCC', 'direction' => 'higher', 'coverage' => null],
                ['key' => MetricName::COHESION_LCOM, 'sources' => [MetricName::COHESION_LCOM, 'cohesion.pure-method-count'], 'label' => 'LCOM', 'direction' => 'lower', 'coverage' => null],
            ],
            SymbolLevel::Namespace_->value => [
                ['key' => 'cohesion.tcc.avg', 'sources' => ['cohesion.tcc.avg'], 'label' => 'TCC', 'direction' => 'higher', 'coverage' => ['count' => 'cohesion.tcc.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'cohesion.lcom.avg', 'sources' => ['cohesion.lcom.avg'], 'label' => 'LCOM', 'direction' => 'lower', 'coverage' => ['count' => 'cohesion.lcom.count', 'unit' => CoverageUnit::Classes]],
            ],
        ],
        'health.coupling' => [
            SymbolLevel::Class_->value => [
                ['key' => 'coupling.ce-packages', 'sources' => ['coupling.ce-packages', 'coupling.ce'], 'label' => 'Ce packages', 'direction' => 'lower', 'coverage' => null],
                ['key' => 'coupling.ce', 'sources' => ['coupling.ce-packages', 'coupling.ce'], 'label' => 'Ce', 'direction' => 'lower', 'coverage' => null],
            ],
            SymbolLevel::Namespace_->value => [
                ['key' => MetricName::COUPLING_DISTANCE, 'sources' => [MetricName::COUPLING_DISTANCE], 'label' => 'Distance', 'direction' => 'lower', 'coverage' => null],
                ['key' => 'coupling.ce-packages.avg', 'sources' => ['coupling.ce-packages.avg', 'coupling.ce.avg'], 'label' => 'Ce pkg (avg)', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.ce-packages.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'coupling.ce.avg', 'sources' => ['coupling.ce-packages.avg', 'coupling.ce.avg'], 'label' => 'Ce (avg)', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.ce.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'coupling.ce.max', 'sources' => ['coupling.ce.max'], 'label' => 'Ce max', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.ce.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'coupling.ce', 'sources' => ['coupling.ce'], 'label' => 'Ce (namespace)', 'direction' => 'lower', 'coverage' => null],
            ],
            SymbolLevel::Project->value => [
                ['key' => 'coupling.distance.avg', 'sources' => ['coupling.distance.avg'], 'label' => 'Distance', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.distance.count', 'unit' => CoverageUnit::LeafNamespaces]],
                ['key' => 'coupling.cbo.avg', 'sources' => ['coupling.cbo.avg'], 'label' => 'CBO (avg)', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.cbo.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'coupling.cbo.p95', 'sources' => ['coupling.cbo.p95'], 'label' => 'CBO p95', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.cbo.count', 'unit' => CoverageUnit::Classes]],
                ['key' => 'coupling.cbo.max', 'sources' => ['coupling.cbo.max'], 'label' => 'CBO max', 'direction' => 'lower', 'coverage' => ['count' => 'coupling.cbo.count', 'unit' => CoverageUnit::Classes]],
            ],
        ],
        'health.typing' => [
            SymbolLevel::Class_->value => [
                ['key' => 'design.type-coverage.all', 'sources' => ['design.type-coverage.all'], 'label' => 'Coverage', 'direction' => 'higher', 'coverage' => null],
            ],
            // Empty by measurement, not by omission: above class level the
            // formula recomputes coverage from six typed/total sums and no
            // aggregate percentage key exists to name. The report builds the
            // three percentage lines from those sums itself
            // ({@see HealthSummaryBuilder::buildTypingDecomposition()}); a
            // single-key line here could only name a metric the level does not
            // carry, which is what `design.type-coverage.all` did.
            SymbolLevel::Namespace_->value => [],
        ],
        'health.maintainability' => [
            SymbolLevel::Class_->value => [
                ['key' => 'maintainability.mi.avg', 'sources' => ['maintainability.mi.avg'], 'label' => 'MI avg', 'direction' => 'higher', 'coverage' => null],
                ['key' => 'maintainability.mi.min', 'sources' => ['maintainability.mi.min'], 'label' => 'MI min', 'direction' => 'higher', 'coverage' => null],
            ],
            SymbolLevel::Namespace_->value => [
                ['key' => 'maintainability.mi.avg', 'sources' => ['maintainability.mi.avg'], 'label' => 'MI avg', 'direction' => 'higher', 'coverage' => ['count' => 'maintainability.mi.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'maintainability.mi.p5', 'sources' => ['maintainability.mi.p5'], 'label' => 'MI p5', 'direction' => 'higher', 'coverage' => ['count' => 'maintainability.mi.count', 'unit' => CoverageUnit::Callables]],
                ['key' => 'maintainability.mi.min', 'sources' => ['maintainability.mi.min'], 'label' => 'MI min', 'direction' => 'higher', 'coverage' => ['count' => 'maintainability.mi.count', 'unit' => CoverageUnit::Callables]],
            ],
        ],
        'health.overall' => [
            SymbolLevel::Class_->value => [],
            SymbolLevel::Namespace_->value => [],
        ],
    ];

    /**
     * The class-level metrics a parent score's worst contributors are ranked
     * and reported by.
     *
     * A separate list from INPUTS because it answers a different question:
     * not "what was this score computed from" but "which class pushed its
     * parent's score down most". The project complexity score is driven by a
     * sum over callables, so the class that contributes most to it is the one
     * with the largest `complexity.ccn.sum` — not the one with the worst
     * per-method average. The first entry is the ranking key.
     *
     * @var array<string, list<array{classKey: string, label: string, direction: string}>>
     */
    private const array CONTRIBUTORS = [
        'health.complexity' => [
            ['classKey' => 'complexity.ccn.sum', 'label' => 'CCN avg', 'direction' => 'lower'],
            ['classKey' => 'complexity.cognitive.sum', 'label' => 'Cognitive avg', 'direction' => 'lower'],
            ['classKey' => 'complexity.ccn.p95', 'label' => 'CCN p95', 'direction' => 'lower'],
            ['classKey' => 'complexity.cognitive.p95', 'label' => 'Cognitive p95', 'direction' => 'lower'],
        ],
        'health.cohesion' => [
            ['classKey' => 'cohesion.tcc', 'label' => 'TCC', 'direction' => 'higher'],
            ['classKey' => MetricName::COHESION_LCOM, 'label' => 'LCOM', 'direction' => 'lower'],
        ],
        'health.coupling' => [
            ['classKey' => 'coupling.ce', 'label' => 'Ce (avg)', 'direction' => 'lower'],
            ['classKey' => 'coupling.ce-packages', 'label' => 'Ce packages', 'direction' => 'lower'],
            ['classKey' => MetricName::COUPLING_DISTANCE, 'label' => 'Distance', 'direction' => 'lower'],
        ],
        'health.typing' => [
            ['classKey' => 'design.type-coverage.all', 'label' => 'Coverage', 'direction' => 'higher'],
        ],
        'health.maintainability' => [
            ['classKey' => MetricName::MAINTAINABILITY_MI, 'label' => 'MI avg', 'direction' => 'higher'],
            ['classKey' => 'maintainability.mi.p5', 'label' => 'MI p5', 'direction' => 'higher'],
            ['classKey' => 'maintainability.mi.min', 'label' => 'MI min', 'direction' => 'higher'],
        ],
        'health.overall' => [],
    ];

    /**
     * The class-level metrics a report calls out by name.
     *
     * A constant beside the other two catalogs rather than a literal in the
     * accessor: this is settled data about the product, and the accessor that
     * hands it out has no decision left to make.
     *
     * @var list<string>
     */
    private const array NOTABLE_CLASS_METRICS = [
        MetricName::SIZE_METHOD_COUNT,
        MetricName::SIZE_PROPERTY_COUNT,
        MetricName::COUPLING_CBO,
        'complexity.ccn.avg',
        'cohesion.tcc',
        MetricName::COMPLEXITY_WMC,
        'maintainability.mi.avg',
        'size.loc',
    ];

    /** @var array<string, string> short name => the metric each dimension's score is published under */
    private const array SCORES = [
        'complexity' => 'health.complexity',
        'cohesion' => 'health.cohesion',
        'coupling' => 'health.coupling',
        'typing' => 'health.typing',
        'maintainability' => 'health.maintainability',
    ];

    /**
     * Everything the catalog answers from, assembled once.
     *
     * One table rather than five constants read directly, so that the object
     * has state its methods share: the catalog answers about one subject and
     * should read as one object, not as a namespace of statics behind a class
     * keyword.
     *
     * @var array{inputs: array<string, array<string, list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}>>>, contributors: array<string, list<array{classKey: string, label: string, direction: string}>>, notable: list<string>, scores: array<string, string>, keys: array<string, string>}
     */
    private array $decomposition;

    public function __construct()
    {
        $this->decomposition = [
            'inputs' => self::INPUTS,
            'contributors' => self::CONTRIBUTORS,
            'notable' => self::NOTABLE_CLASS_METRICS,
            'scores' => self::SCORES,
            'keys' => [
                'overall' => 'health.overall',
                'classLoc' => 'size.class-loc',
                'classCount' => 'size.class-count.sum',
            ],
        ];
    }

    /**
     * The inputs a dimension's score at `$level` was computed from.
     *
     * @return list<array{key: string, sources: list<string>, label: string, direction: string, coverage: array{count: string, unit: CoverageUnit}|null}>
     */
    public function inputsFor(string $dimension, SymbolLevel $level): array
    {
        $perLevel = $this->decomposition['inputs'][$dimension] ?? null;

        if ($perLevel === null) {
            return [];
        }

        // Project inherits the namespace list exactly where the definition
        // inherits the namespace formula, so the two cannot disagree.
        return $perLevel[$level->value]
            ?? ($level === SymbolLevel::Project ? ($perLevel[SymbolLevel::Namespace_->value] ?? []) : []);
    }

    /** @return list<string> */
    public function getDecomposition(string $dimension, SymbolLevel $level): array
    {
        return array_values(array_map(
            static fn(array $input): string => $input['key'],
            $this->inputsFor($dimension, $level),
        ));
    }

    /** @return list<array{classKey: string, label: string, direction: string}> */
    public function getDecompositionForClasses(string $dimension): array
    {
        return $this->decomposition['contributors'][$dimension] ?? [];
    }

    /**
     * @param list<array{classKey: string, direction: string}> $inputs
     * @param callable(string): (int|float|null) $readMetric
     *
     * @return array{primaryValue: float|null, contributorMetrics: array<string, int|float>}
     */
    public function selectContributorMetrics(array $inputs, callable $readMetric): array
    {
        $primaryValue = isset($inputs[0]) ? $readMetric($inputs[0]['classKey']) : null;
        $contributorMetrics = [];
        foreach ($inputs as $input) {
            $value = $readMetric($input['classKey']);
            if ($value !== null) {
                $contributorMetrics[$input['classKey']] = $value;
            }
        }

        return [
            'primaryValue' => $primaryValue === null ? null : (float) $primaryValue,
            'contributorMetrics' => $contributorMetrics,
        ];
    }

    /** @return list<string> */
    public function notableClassMetrics(): array
    {
        return $this->decomposition['notable'];
    }

    public function overallMetric(): string
    {
        return $this->decomposition['keys']['overall'];
    }

    /** @return array<string, string> short name => metric name */
    public function scoreDimensions(): array
    {
        return $this->decomposition['scores'];
    }

    public function classLocMetric(): string
    {
        return $this->decomposition['keys']['classLoc'];
    }

    public function classCountMetric(): string
    {
        return $this->decomposition['keys']['classCount'];
    }

    /**
     * Why a dimension publishes no coverage, when none of its inputs is an
     * aggregate over symbols.
     *
     * Kept beside the inputs rather than in the builder: this is a statement
     * about what the score is made of, which is this catalog's subject.
     */
    public function coverageAbsenceReason(string $dimension): string
    {
        return match ($dimension) {
            'health.overall' => 'health.overall composes the other dimensions; each of them publishes its own coverage',
            'health.typing' => 'the typing formula reads typed and total sums over declaration positions, and no .count is published for them',
            default => 'no input of this dimension publishes a measured count',
        };
    }

    /**
     * The decomposition as shipped to the HTML report: every level resolved,
     * so the page picks by the node's own level and never falls back to a
     * list belonging to another one.
     *
     * @return array<string, array{levels: array<string, list<array{key: string, label: string, direction: string}>>}>
     */
    public function healthDecomposition(): array
    {
        $result = [];

        foreach (array_keys($this->decomposition['inputs']) as $dimension) {
            $levels = [];
            foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
                // `sources` stays behind: it says which formula term a line
                // stands for, which the page has no use for. A field shipped
                // and read by nothing is what `ideal` was.
                $levels[$level->value] = array_map(
                    static fn(array $input): array => [
                        'key' => $input['key'],
                        'label' => $input['label'],
                        'direction' => $input['direction'],
                    ],
                    $this->inputsFor($dimension, $level),
                );
            }

            $result[$dimension] = ['levels' => $levels];
        }

        return $result;
    }
}
