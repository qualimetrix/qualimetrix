#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * The offline health-score bench.
 *
 * A run of `bin/qmx` over the benchmark corpus costs minutes; trying a formula
 * that way is not a search, it is a vigil. This bench replays captured raw
 * metrics — `scripts/collect-benchmark-data.php --capture-dir=<dir>`, one JSON
 * document per project — through the product's own expression engine, and
 * answers in milliseconds.
 *
 * Three properties keep it from lying about the product:
 *
 * 1. **The product evaluates, not a re-implementation.** Formulas run through
 *    {@see ComputedMetricExpression} and are resolved through
 *    {@see ComputedMetricsConfigResolver}, so a candidate file is read by the
 *    same code path a user's `qmx.yaml` is.
 * 2. **`health.*` is recomputed from non-health inputs.** Published health
 *    values are stripped from every symbol's input map before evaluation and
 *    kept only as the self-test's other side. Reading the captured
 *    `health.complexity` as an input to `health.overall` would make a candidate
 *    complexity formula invisible in the overall result, and the self-test
 *    would then pass tautologically.
 * 3. **The namespace-pooled aggregates are DERIVED from the captured
 *    children, not read.** `coupling.distance.avg` at project level is produced
 *    by
 *    {@see \Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator}
 *    before any expression runs, so it sits in the capture as a finished
 *    number. A bench that read it could not evaluate the two aggregation
 *    candidates this work exists to judge — including `(global)`, and weighting
 *    by size — and its self-test would agree with itself under any aggregation
 *    rule whatsoever. The bench therefore recomputes the aggregate from the
 *    captured namespace values under a selectable scheme, and the self-test
 *    requires the `current` scheme to reproduce the PUBLISHED aggregate. That
 *    reproduction is the one measurement that makes this instrument evidence.
 *
 *    The set that is re-derived is {@see PROJECT_DERIVED}, and it is small on
 *    purpose: `coupling.distance` is the only base the product pools from
 *    namespaces to the project. Every other project input — `complexity.ccn.p95`,
 *    `maintainability.mi.min`, `size.symbol-method-count` and the rest — is an
 *    aggregate of *callables and classes*, with no namespace-pooling step to vary;
 *    re-deriving one under a namespace weighting would put a number into the
 *    bench that no run of `bin/qmx` produces. Those inputs are therefore read from
 *    the capture verbatim and are identical under every scheme, which is why C4
 *    reports `n/p` rather than `0.00` for the dimensions whose formulas reach
 *    none of the derived keys: no drift and no pooling are different facts.
 *
 * Usage:
 *
 *   php scripts/health-calibration.php --capture-dir=DIR [options]
 *
 *   --capture-dir=DIR       directory of per-project capture documents (*.json).
 *                           Documents written by `bin/qmx --format=metrics` are
 *                           also accepted, so an anchor measured outside the
 *                           corpus needs no conversion.
 *   --projects=a,b          restrict the run to these capture ids
 *   --levels=project,namespace,class    default: project,namespace
 *   --aggregation=SCHEME    members[:weight]; see AggregationScheme. Default
 *                           `leaves:none`, aliased `current`.
 *   --self-test             reproduce the published aggregates and health.*
 *   --tolerance=0.1         self-test tolerance on a score (default 0.1)
 *   --candidates=FILE       a YAML document with a `computed_metrics:` section
 *   --criteria              print the C1..C6 verdicts
 *   --c2-max=N              declared ceiling of subjects at exactly 100, per
 *                           dimension and level (C2 has no verdict without it)
 *   --c3-min-coverage=F     the declared applicability fraction (C3 has no
 *                           verdict without it — "negligible" is not a threshold)
 *   --coverage=symbols|loc  which denominator the C3 verdict uses
 *   --c4-tolerance=T        declared tolerance for C4, in score points
 *   --top=N                 rows per movers/violators table (default 10)
 *
 * Exit codes:
 *   0 — ran, and every criterion carrying a declared threshold agreed
 *   1 — a declared criterion failed (this is the product's verdict, not the
 *       bench's health: C1 is expected to fail today)
 *   2 — usage or infrastructure error (no captures, unreadable document, a
 *       candidate file the product's resolver refuses)
 *   3 — the self-test disagrees with the capture: the INSTRUMENT is not
 *       trustworthy, and nothing else printed by this run is evidence
 */

namespace Qualimetrix\HealthCalibration;

use InvalidArgumentException;
use JsonException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDependencyGraphCalculator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\MetricLookup;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

require_once __DIR__ . '/../vendor/autoload.php';

/** The capture's spelling of the global namespace, as `bin/qmx` publishes it. */
const GLOBAL_NAMESPACE = '(global)';

/** What `--help` prints; the file docblock above carries the reasoning. */
const USAGE = <<<'TEXT'
    php scripts/health-calibration.php --capture-dir=DIR [options]

      --capture-dir=DIR       per-project capture documents (*.json); a
                              `bin/qmx --format=metrics` document is read too
      --projects=a,b          restrict the run to these capture ids
      --levels=…              project,namespace,class (default: project,namespace)
      --aggregation=SCHEME    members[:weight]; members is leaves or
                              leaves-no-global, weight is none, classes or loc.
                              Default `current` = leaves:none
      --self-test             reproduce the published aggregates and health.*
      --tolerance=0.1         self-test tolerance on a score
      --candidates=FILE       a YAML document with a `computed_metrics:` section
      --criteria              print the C1..C6 verdicts
      --c2-max=N              declared ceiling of subjects at exactly 100
      --c3-min-coverage=F     declared applicability fraction
      --coverage=symbols|loc  denominator the C3 verdict uses
      --c4-tolerance=T        declared tolerance for C4, in score points
      --top=N                 rows per violators/mismatch table (default 10)

    Exit: 0 agreed, 1 a declared criterion failed, 2 usage or input error,
    3 the self-test disagrees with the capture.
    TEXT;

/**
 * Metrics collected ON a namespace rather than aggregated up to it.
 *
 * Their project-level value cannot be recomputed from class symbols, because
 * no class carries them; it is an aggregate over namespaces, which is exactly
 * the aggregation this bench has to be able to vary. The list is the set of
 * `collectedAt: SymbolLevel::Namespace_` definitions —
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\DistanceCollector},
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\AbstractnessCollector} and the
 * namespace instability the latter's sibling writes.
 *
 * @var list<string>
 */
const NAMESPACE_COLLECTED = ['coupling.distance', 'coupling.abstractness', 'coupling.instability'];

/**
 * The namespace-collected bases the product actually aggregates to project
 * level: `DistanceCollector` declares `Project => [Average]` and nothing else
 * declares any project aggregation. Deriving a key the product never publishes
 * would put a number into the bench's input map that no run of `bin/qmx` ever
 * produces.
 *
 * @var list<string>
 */
const PROJECT_DERIVED = ['coupling.distance'];

/** The six dimensions, in report order. */
const DIMENSIONS = [
    'health.complexity',
    'health.cohesion',
    'health.coupling',
    'health.typing',
    'health.maintainability',
    'health.overall',
];

/**
 * One measured symbol: what it is called, what level it sits at, the metrics it
 * carried, and the `health.*` the product published for it.
 */
final readonly class Subject
{
    /**
     * @param array<string, float> $inputs Non-health metrics, exactly as captured
     * @param array<string, float> $published The published `health.*` values
     */
    public function __construct(
        public string $name,
        public SymbolLevel $level,
        public array $inputs,
        public array $published,
        public ?int $loc,
    ) {}

    /** The namespace a class symbol sits in, `(global)` when it has none. */
    public function containingNamespace(): string
    {
        $lastSeparator = strrpos($this->name, '\\');

        return $lastSeparator === false ? GLOBAL_NAMESPACE : substr($this->name, 0, $lastSeparator);
    }
}

/**
 * One project's capture, indexed by level.
 *
 * Containment, not the product's namespace tree, answers "what are the
 * children of this subject". The tree is what drops `(global)`, and a
 * monotonicity criterion that inherits the defect it tests for is no criterion.
 */
final readonly class Capture
{
    /**
     * @param list<Subject> $symbols
     */
    public function __construct(
        public string $id,
        public array $symbols,
    ) {}

    public function project(): ?Subject
    {
        foreach ($this->symbols as $symbol) {
            if ($symbol->level === SymbolLevel::Project) {
                return $symbol;
            }
        }

        return null;
    }

    /** @return list<Subject> */
    public function at(SymbolLevel $level): array
    {
        return array_values(array_filter(
            $this->symbols,
            static fn(Subject $symbol): bool => $symbol->level === $level,
        ));
    }

    /**
     * Namespaces with no other captured namespace below them.
     *
     * `(global)` is a leaf by construction — nothing can sit below it — and is
     * excluded only when the caller says so, because its exclusion is the
     * aggregation rule under examination, not a fact about the tree.
     *
     * @return list<Subject>
     */
    public function leafNamespaces(bool $includeGlobal): array
    {
        $namespaces = $this->at(SymbolLevel::Namespace_);
        $names = array_map(static fn(Subject $symbol): string => $symbol->name, $namespaces);

        $leaves = [];
        foreach ($namespaces as $namespace) {
            if (!$includeGlobal && $namespace->name === GLOBAL_NAMESPACE) {
                continue;
            }

            if ($namespace->name !== GLOBAL_NAMESPACE && self::hasDescendant($namespace->name, $names)) {
                continue;
            }

            $leaves[] = $namespace;
        }

        return $leaves;
    }

    /**
     * Class symbols contained in a subject: every class for the project, the
     * classes under a namespace prefix for a namespace, the class itself for a
     * class.
     *
     * @return list<Subject>
     */
    public function classesUnder(Subject $subject): array
    {
        $classes = $this->at(SymbolLevel::Class_);

        return match ($subject->level) {
            SymbolLevel::Project => $classes,
            SymbolLevel::Class_ => [$subject],
            default => array_values(array_filter(
                $classes,
                static fn(Subject $class): bool => self::isUnder($class->containingNamespace(), $subject->name),
            )),
        };
    }

    /**
     * The children of a subject for the monotonicity criterion.
     *
     * Containment of symbols, never the product's namespace tree: the tree is
     * what drops `(global)`, and its leaves do not partition the classes
     * either — a namespace holding both its own classes and sub-namespaces is
     * not a leaf, so its classes would fall outside the range the project is
     * judged against. The project's children are therefore every namespace
     * that directly contains a class, `(global)` included, and a namespace's
     * children are every class under its prefix.
     *
     * @return list<Subject>
     */
    public function childrenOf(Subject $subject): array
    {
        return match ($subject->level) {
            SymbolLevel::Project => $this->namespacesHoldingClasses(),
            SymbolLevel::Namespace_ => $this->classesUnder($subject),
            default => [],
        };
    }

    /**
     * Namespace symbols that directly contain at least one class.
     *
     * @return list<Subject>
     */
    public function namespacesHoldingClasses(): array
    {
        $holding = [];

        foreach ($this->at(SymbolLevel::Class_) as $class) {
            $holding[$class->containingNamespace()] = true;
        }

        return array_values(array_filter(
            $this->at(SymbolLevel::Namespace_),
            static fn(Subject $namespace): bool => isset($holding[$namespace->name]),
        ));
    }

    /** @param list<string> $names */
    private static function hasDescendant(string $namespace, array $names): bool
    {
        foreach ($names as $candidate) {
            if ($candidate !== $namespace && str_starts_with($candidate, $namespace . '\\')) {
                return true;
            }
        }

        return false;
    }

    private static function isUnder(string $namespace, string $ancestor): bool
    {
        return $namespace === $ancestor || str_starts_with($namespace, $ancestor . '\\');
    }
}

/**
 * How a level aggregate is formed out of its members: which members
 * contribute, and with what weight.
 *
 * Spelled `members:weight` so the two questions stay separable — "does
 * `(global)` count" and "does a 451-class namespace outweigh a seven-class one"
 * are different candidates, and a scheme name that fused them would make one
 * of them untestable. `current` is the alias of what the product does today.
 */
final readonly class AggregationScheme
{
    public const string MEMBERS_LEAVES = 'leaves';
    public const string MEMBERS_LEAVES_NO_GLOBAL = 'leaves-no-global';
    public const string WEIGHT_NONE = 'none';
    public const string WEIGHT_CLASSES = 'classes';
    public const string WEIGHT_LOC = 'loc';

    public function __construct(
        public string $members,
        public string $weight,
    ) {}

    public static function parse(string $spec): self
    {
        $normalized = $spec === 'current' ? self::MEMBERS_LEAVES . ':' . self::WEIGHT_NONE : $spec;
        $parts = explode(':', $normalized);
        $members = $parts[0];
        $weight = $parts[1] ?? self::WEIGHT_NONE;

        if (!\in_array($members, [self::MEMBERS_LEAVES, self::MEMBERS_LEAVES_NO_GLOBAL], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown aggregation members "%s"', $members));
        }

        if (!\in_array($weight, [self::WEIGHT_NONE, self::WEIGHT_CLASSES, self::WEIGHT_LOC], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown aggregation weight "%s"', $weight));
        }

        return new self($members, $weight);
    }

    /**
     * The rule the product applies today. The global namespace was excluded
     * until the tree stopped skipping it; a bench that models the superseded
     * rule reports disagreements that are its own and calls the product wrong.
     */
    public static function current(): self
    {
        return new self(self::MEMBERS_LEAVES, self::WEIGHT_NONE);
    }

    public function isCurrent(): bool
    {
        return $this->members === self::MEMBERS_LEAVES && $this->weight === self::WEIGHT_NONE;
    }

    public function toString(): string
    {
        return $this->members . ':' . $this->weight;
    }

    /** The weight one namespace carries under this scheme. */
    public function weightOf(Subject $namespace): float
    {
        return match ($this->weight) {
            self::WEIGHT_CLASSES => max(0.0, (float) ($namespace->inputs['size.class-count.sum'] ?? 0.0)),
            self::WEIGHT_LOC => max(0.0, (float) ($namespace->loc ?? 0)),
            default => 1.0,
        };
    }
}

/** What the bench derived for one aggregate key, and out of what. */
final readonly class DerivedAggregate
{
    /**
     * @param array<string, float> $values The derived `base.strategy` keys
     * @param list<string> $contributors Namespaces that carried the base metric
     * @param list<string> $members Namespaces the scheme admitted at all
     * @param array<string, float> $publishedBefore The keys the capture carried
     * @param list<string> $dropped Published keys of this base the bench does not re-derive
     */
    public function __construct(
        public string $base,
        public array $values,
        public array $contributors,
        public array $members,
        public array $publishedBefore,
        public array $dropped,
    ) {}
}

/** One dimension's outcome for one subject. */
final readonly class DimensionOutcome
{
    /**
     * @param list<string> $referencedKeys Every metric key the formula names
     * @param list<string> $absentKeys Referenced keys the subject did not carry
     * @param array<string, float> $counts The `.count` each referenced aggregate carried
     */
    public function __construct(
        public string $dimension,
        public ?float $score,
        public array $referencedKeys,
        public array $absentKeys,
        public array $counts,
    ) {}
}

/** Everything one evaluation of one capture produced. */
final readonly class Evaluation
{
    /**
     * @param array<string, array<string, DimensionOutcome>> $outcomes level => subject name => dimension => outcome,
     *                                                                 flattened to level.subject keys
     * @param list<DerivedAggregate> $derived
     */
    public function __construct(
        public Capture $capture,
        public AggregationScheme $scheme,
        public array $outcomes,
        public array $derived,
    ) {}

    public function outcome(Subject $subject, string $dimension): ?DimensionOutcome
    {
        return $this->outcomes[self::key($subject)][$dimension] ?? null;
    }

    public function scoreOf(Subject $subject, string $dimension): ?float
    {
        return $this->outcome($subject, $dimension)?->score;
    }

    public static function key(Subject $subject): string
    {
        return $subject->level->value . "\0" . $subject->name;
    }
}

/**
 * Reads one capture document.
 *
 * Two shapes are accepted, because the corpus capture and an anchor measured
 * with `--format=metrics` carry the same facts in different envelopes and
 * converting one into the other by hand is a step that can be got wrong
 * silently.
 */
final class CaptureReader
{
    /**
     * @throws RuntimeException
     */
    public static function read(string $path): Capture
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException(\sprintf('Cannot read capture "%s"', $path));
        }

        try {
            /** @var mixed $document */
            $document = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\sprintf('Capture "%s" is not valid JSON: %s', $path, $e->getMessage()));
        }

        if (!\is_array($document)) {
            throw new RuntimeException(\sprintf('Capture "%s" is not an object', $path));
        }

        // A `--format=metrics` document carries `package`, but that names the
        // ANALYSING package, not the analysed project — reading it would label
        // every anchor "qmx". The file name is the id when the capture has none.
        $id = \is_string($document['id'] ?? null) ? $document['id'] : basename($path, '.json');

        $symbols = $document['symbols'] ?? null;

        if (!\is_array($symbols)) {
            throw new RuntimeException(\sprintf('Capture "%s" carries no symbols', $path));
        }

        $subjects = [];
        foreach ($symbols as $symbol) {
            $subject = self::readSymbol($symbol);

            if ($subject !== null) {
                $subjects[] = $subject;
            }
        }

        if ($subjects === []) {
            throw new RuntimeException(\sprintf('Capture "%s" carries no project, namespace or class symbol', $path));
        }

        return new Capture($id, $subjects);
    }

    private static function readSymbol(mixed $symbol): ?Subject
    {
        if (!\is_array($symbol)) {
            return null;
        }

        $level = match ($symbol['type'] ?? null) {
            'project' => SymbolLevel::Project,
            'namespace' => SymbolLevel::Namespace_,
            'class' => SymbolLevel::Class_,
            default => null,
        };

        if ($level === null) {
            return null;
        }

        $name = \is_string($symbol['name'] ?? null) ? $symbol['name'] : '';
        $metrics = \is_array($symbol['metrics'] ?? null) ? $symbol['metrics'] : [];

        $inputs = [];
        $published = [];

        foreach ($metrics as $key => $value) {
            if (!\is_string($key) || !is_numeric($value) || \is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'health.') || str_starts_with($key, 'computed.')) {
                $published[$key] = (float) $value;

                continue;
            }

            $inputs[$key] = (float) $value;
        }

        return new Subject($name, $level, $inputs, $published, self::readLoc($symbol, $inputs));
    }

    /**
     * @param array<string, mixed> $symbol
     * @param array<string, float> $inputs
     */
    private static function readLoc(array $symbol, array $inputs): ?int
    {
        $top = $symbol['size.loc'] ?? null;

        if (is_numeric($top) && !\is_string($top)) {
            return (int) $top;
        }

        // A class symbol spells its own size `size.class-loc`; without the
        // fallback the loc denominator would silently be unavailable at the
        // level where most of the corpus's symbols live.
        $fromMetrics = $inputs['size.loc']
            ?? $inputs['size.loc.sum']
            ?? $inputs['size.class-loc']
            ?? $inputs['size.class-loc.sum']
            ?? null;

        return $fromMetrics === null ? null : (int) $fromMetrics;
    }
}

/**
 * The bench proper: derives the level aggregates, strips the published
 * `health.*`, and evaluates the definitions in dependency order.
 */
final class Bench
{
    private readonly ComputedMetricExpression $expression;
    private readonly ComputedMetricDependencyGraphCalculator $graph;

    public function __construct()
    {
        $this->expression = new ComputedMetricExpression();
        $this->graph = new ComputedMetricDependencyGraphCalculator($this->expression);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<SymbolLevel> $levels
     */
    public function run(Capture $capture, array $definitions, array $levels, AggregationScheme $scheme): Evaluation
    {
        $sorted = $this->graph->sort($definitions);

        if ($sorted === null) {
            throw new RuntimeException('The candidate definitions contain a circular dependency');
        }

        $derived = $this->deriveProjectAggregates($capture, $scheme);
        $project = $capture->project();
        $projectInputs = $project === null ? [] : self::projectInputs($project, $derived);
        $outcomes = [];

        foreach ($capture->symbols as $subject) {
            if (!\in_array($subject->level, $levels, true)) {
                continue;
            }

            $inputs = $subject === $project ? $projectInputs : $subject->inputs;
            $outcomes[Evaluation::key($subject)] = $this->evaluateSubject($subject, $inputs, $sorted);
        }

        return new Evaluation($capture, $scheme, $outcomes, $derived);
    }

    /**
     * The project's input map: what the capture carried, minus every
     * published aggregate of a namespace-collected metric, plus the one this
     * run derived.
     *
     * A key the bench drops but does not re-derive cannot leak back in; it is
     * reported as dropped instead. The capture itself is never written to —
     * one load then serves every scheme and every candidate, which is the
     * difference between a bench that runs in one pass and one that reloads
     * a corpus of captures per comparison.
     *
     * @param list<DerivedAggregate> $derived
     *
     * @return array<string, float>
     */
    private static function projectInputs(Subject $project, array $derived): array
    {
        $inputs = $project->inputs;

        foreach ($derived as $aggregate) {
            foreach (array_keys($aggregate->publishedBefore) as $key) {
                unset($inputs[$key]);
            }

            foreach ($aggregate->values as $key => $value) {
                $inputs[$key] = $value;
            }
        }

        return $inputs;
    }

    /**
     * Recomputes every project-level aggregate of a namespace-collected metric
     * from the captured namespace values, under the given scheme.
     *
     * @return list<DerivedAggregate>
     */
    private function deriveProjectAggregates(Capture $capture, AggregationScheme $scheme): array
    {
        $project = $capture->project();

        if ($project === null) {
            return [];
        }

        $members = $capture->leafNamespaces(
            includeGlobal: $scheme->members === AggregationScheme::MEMBERS_LEAVES,
        );

        $derived = [];

        foreach (PROJECT_DERIVED as $base) {
            $publishedBefore = [];

            foreach ($project->inputs as $key => $value) {
                if (MetricName::base($key) === $base) {
                    $publishedBefore[$key] = $value;
                }
            }

            $values = [];
            $contributors = [];
            $weighted = 0.0;
            $weightSum = 0.0;

            foreach ($members as $member) {
                $value = $member->inputs[$base] ?? null;

                if ($value === null) {
                    continue;
                }

                $contributors[] = $member->name;
                $weight = $scheme->weightOf($member);
                $weighted += $value * $weight;
                $weightSum += $weight;
            }

            if ($contributors !== [] && $weightSum > 0.0) {
                $values[$base . '.avg'] = $weighted / $weightSum;
                $values[$base . '.count'] = (float) \count($contributors);
            }

            $derived[] = new DerivedAggregate(
                $base,
                $values,
                $contributors,
                array_map(static fn(Subject $member): string => $member->name, $members),
                $publishedBefore,
                array_values(array_diff(array_keys($publishedBefore), array_keys($values))),
            );
        }

        return $derived;
    }

    /**
     * @param array<string, float> $values The subject's non-health inputs
     * @param list<ComputedMetricDefinition> $sorted
     *
     * @return array<string, DimensionOutcome>
     */
    private function evaluateSubject(Subject $subject, array $values, array $sorted): array
    {
        // The published health values are deliberately NOT seeded into
        // $values: a dimension that a later formula reads must have been
        // recomputed by this run, or the run says nothing about the candidate.
        $outcomes = [];

        foreach ($sorted as $definition) {
            if (!$definition->hasLevel($subject->level)) {
                continue;
            }

            $formula = $definition->getFormulaForLevel($subject->level);

            if ($formula === null) {
                continue;
            }

            $outcome = $this->evaluateFormula($definition->name, $formula, $values);
            $outcomes[$definition->name] = $outcome;

            if ($outcome->score !== null) {
                $values[$definition->name] = $outcome->score;
            }
        }

        return $outcomes;
    }

    /**
     * @param array<string, float> $values
     */
    private function evaluateFormula(string $name, string $formula, array $values): DimensionOutcome
    {
        $referenced = $this->expression->keysOf($formula);
        $absent = [];
        $counts = [];

        foreach ($referenced as $key) {
            if (!isset($values[$key])) {
                $absent[] = $key;
            }

            $countKey = MetricName::base($key) . '.count';

            if ($key !== $countKey && isset($values[$countKey])) {
                $counts[$key] = $values[$countKey];
            }
        }

        try {
            /** @var mixed $result */
            $result = $this->expression->evaluate($formula, ['m' => new MetricLookup($values)]);
        } catch (Throwable) {
            return new DimensionOutcome($name, null, $referenced, $absent, $counts);
        }

        if (!is_numeric($result) || \is_string($result)) {
            return new DimensionOutcome($name, null, $referenced, $absent, $counts);
        }

        $score = (float) $result;

        if (is_nan($score) || is_infinite($score)) {
            return new DimensionOutcome($name, null, $referenced, $absent, $counts);
        }

        return new DimensionOutcome($name, $score, $referenced, $absent, $counts);
    }
}

/**
 * How much of a subject a dimension's inputs actually covered.
 *
 * Two denominators, because the plan needs both: the share of symbols and the
 * share of lines. WordPress's structural aggregate covers 20 of 32 namespaces
 * and a much smaller share of its lines, and which of the two C3 is judged on
 * is a calibration decision, not this instrument's.
 */
final readonly class Coverage
{
    /**
     * @param string $note What the figure hides when the key is measured on
     *                     namespaces rather than on classes: the classes are still the
     *                     denominator, but the unit that contributed is a namespace
     */
    public function __construct(
        public string $population,
        public int $carriers,
        public int $total,
        public int $carrierLoc,
        public int $totalLoc,
        public string $note = '',
    ) {}

    public function bySymbols(): ?float
    {
        return $this->total === 0 ? null : $this->carriers / $this->total;
    }

    public function byLoc(): ?float
    {
        return $this->totalLoc === 0 ? null : $this->carrierLoc / $this->totalLoc;
    }

    public function share(string $denominator): ?float
    {
        return $denominator === 'loc' ? $this->byLoc() : $this->bySymbols();
    }
}

/** Coverage arithmetic, kept separate from the evaluation that needs it. */
final class CoverageCalculator
{
    /**
     * The coverage of one referenced key on one subject.
     *
     * The denominator is always the subject's classes, whatever the key is
     * measured on. A namespace-collected base is contributed by whole
     * namespaces, so a class is covered exactly when its namespace contributed
     * — and a class in a namespace the aggregation rule excluded counts as
     * uncovered, which is the CodeIgniter case stated as a number rather than
     * as an absence. Everything else is covered when the class itself carries
     * the base, which also cross-checks the published `.count`: the bench
     * counts carriers instead of believing the aggregate.
     */
    public static function forKey(
        Capture $capture,
        Subject $subject,
        string $key,
        AggregationScheme $scheme,
    ): Coverage {
        $base = MetricName::base($key);
        $classes = $capture->classesUnder($subject);

        if (!\in_array($base, NAMESPACE_COLLECTED, true) || $subject->level === SymbolLevel::Class_) {
            return self::over($classes, static fn(Subject $class): bool => self::carries($class, $base), 'classes');
        }

        $contributors = [];

        foreach ($capture->leafNamespaces($scheme->members === AggregationScheme::MEMBERS_LEAVES) as $namespace) {
            if (self::carries($namespace, $base)) {
                $contributors[$namespace->name] = true;
            }
        }

        $withClasses = [];
        foreach ($classes as $class) {
            $withClasses[$class->containingNamespace()] = true;
        }

        $contributing = \count(array_intersect_key($contributors, $withClasses));

        return self::over(
            $classes,
            static fn(Subject $class): bool => isset($contributors[$class->containingNamespace()]),
            'classes',
            // A pure parent namespace holds no symbols of its own, so the
            // denominator is the namespaces that directly contain classes.
            \sprintf('ns=%d/%d', $contributing, \count($withClasses)),
        );
    }

    /**
     * The dimension's coverage: the scarcest of the keys its formula names.
     *
     * A score is only as applicable as its least-covered input, and taking a
     * mean over keys would let four fully-covered inputs hide one that covers
     * nothing.
     *
     * @param list<string> $keys
     */
    public static function forDimension(
        Capture $capture,
        Subject $subject,
        array $keys,
        string $denominator,
        AggregationScheme $scheme,
    ): ?Coverage {
        $scarcest = null;
        $scarcestShare = null;

        foreach ($keys as $key) {
            if (str_starts_with($key, 'health.') || str_starts_with($key, 'computed.')) {
                continue;
            }

            $coverage = self::forKey($capture, $subject, $key, $scheme);
            $share = $coverage->share($denominator);

            if ($share === null) {
                continue;
            }

            if ($scarcestShare === null || $share < $scarcestShare) {
                $scarcest = $coverage;
                $scarcestShare = $share;
            }
        }

        return $scarcest;
    }

    /**
     * @param list<Subject> $population
     * @param callable(Subject): bool $isCovered
     */
    private static function over(array $population, callable $isCovered, string $label, string $note = ''): Coverage
    {
        $carriers = 0;
        $carrierLoc = 0;
        $totalLoc = 0;

        foreach ($population as $member) {
            $totalLoc += $member->loc ?? 0;

            if ($isCovered($member)) {
                $carriers++;
                $carrierLoc += $member->loc ?? 0;
            }
        }

        return new Coverage($label, $carriers, \count($population), $carrierLoc, $totalLoc, $note);
    }

    private static function carries(Subject $member, string $base): bool
    {
        if (isset($member->inputs[$base])) {
            return true;
        }

        $count = $member->inputs[$base . '.count'] ?? null;

        if ($count !== null) {
            return $count > 0.0;
        }

        foreach ($member->inputs as $key => $_value) {
            if (MetricName::base($key) === $base) {
                return true;
            }
        }

        return false;
    }
}

/** One disagreement the self-test found. */
final readonly class SelfTestMismatch
{
    public function __construct(
        public string $project,
        public string $level,
        public string $subject,
        public string $key,
        public ?float $published,
        public ?float $recomputed,
    ) {}

    public function toString(): string
    {
        return \sprintf(
            '%-22s %-9s %-52s %-26s published=%s recomputed=%s',
            $this->project,
            $this->level,
            self::shorten($this->subject, 52),
            $this->key,
            $this->published === null ? '(absent)' : \sprintf('%.6f', $this->published),
            $this->recomputed === null ? '(absent)' : \sprintf('%.6f', $this->recomputed),
        );
    }

    private static function shorten(string $value, int $width): string
    {
        return \strlen($value) <= $width ? $value : '…' . substr($value, -($width - 1));
    }
}

/**
 * The self-test: the current scheme and the default formulas must reproduce
 * what the capture published — both the derived aggregate and every score.
 */
final class SelfTest
{
    /**
     * @param list<Evaluation> $evaluations
     *
     * @return list<SelfTestMismatch>
     */
    public static function run(array $evaluations, float $tolerance): array
    {
        $mismatches = [];

        foreach ($evaluations as $evaluation) {
            foreach (self::aggregateMismatches($evaluation) as $mismatch) {
                $mismatches[] = $mismatch;
            }

            foreach (self::scoreMismatches($evaluation, $tolerance) as $mismatch) {
                $mismatches[] = $mismatch;
            }
        }

        return $mismatches;
    }

    /**
     * The half that a bench reading the aggregate out of the capture could
     * never fail — and therefore the half that makes the other one mean
     * something.
     *
     * @return list<SelfTestMismatch>
     */
    private static function aggregateMismatches(Evaluation $evaluation): array
    {
        $mismatches = [];

        foreach ($evaluation->derived as $derived) {
            $keys = array_unique([...array_keys($derived->publishedBefore), ...array_keys($derived->values)]);

            foreach ($keys as $key) {
                $published = $derived->publishedBefore[$key] ?? null;
                $recomputed = $derived->values[$key] ?? null;

                if ($published !== null && $recomputed !== null && abs($published - $recomputed) <= 1.0e-6) {
                    continue;
                }

                if ($published === null && $recomputed === null) {
                    continue;
                }

                $mismatches[] = new SelfTestMismatch(
                    $evaluation->capture->id,
                    'project',
                    '(project)',
                    $key,
                    $published,
                    $recomputed,
                );
            }
        }

        return $mismatches;
    }

    /**
     * @return list<SelfTestMismatch>
     */
    private static function scoreMismatches(Evaluation $evaluation, float $tolerance): array
    {
        $mismatches = [];

        foreach ($evaluation->capture->symbols as $subject) {
            foreach ($subject->published as $key => $published) {
                $outcome = $evaluation->outcome($subject, $key);

                if ($outcome === null) {
                    continue; // The level was not evaluated in this run.
                }

                $recomputed = $outcome->score;

                if ($recomputed !== null && abs($published - $recomputed) <= $tolerance) {
                    continue;
                }

                $mismatches[] = new SelfTestMismatch(
                    $evaluation->capture->id,
                    $subject->level->value,
                    $subject->name,
                    $key,
                    $published,
                    $recomputed,
                );
            }
        }

        return $mismatches;
    }
}

/** One parent that scored outside the range its children span. */
final readonly class MonotonicityViolation
{
    public function __construct(
        public string $project,
        public string $pair,
        public string $dimension,
        public string $parent,
        public float $parentScore,
        public float $min,
        public float $max,
        public int $children,
    ) {}

    public function toString(): string
    {
        return \sprintf(
            '%-22s %-20s %-24s %-40s parent=%7.2f children=[%7.2f, %7.2f] n=%d',
            $this->project,
            $this->pair,
            $this->dimension,
            $this->parent === '' ? '(project)' : $this->parent,
            $this->parentScore,
            $this->min,
            $this->max,
            $this->children,
        );
    }
}

/** The criteria the bench can answer from a single run. */
final class Criteria
{
    /**
     * C1, one-sided: no parent scores ABOVE the maximum of its children, for
     * any dimension and any parent/child level pair.
     *
     * The other direction is not a defect and is not reported here. A parent
     * formula carries terms the child formula has no equivalent for — a
     * namespace is scored on its distance from the main sequence and on its own
     * efferent breadth, neither of which exists for a class — so a parent below
     * all of its children is usually that, not a lost penalty.
     * `measurement/07-monotonicity-direction.md` counted the split: coupling
     * alone shows 604 below and exactly zero above, and the two-sided form
     * reddens five times out of six for the explainable reason. It is kept as a
     * printed reference figure ({@see parentsBelowChildren()}), not as a verdict.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return list<MonotonicityViolation>
     */
    public static function monotonicity(array $evaluations, float $tolerance = 1.0e-9): array
    {
        return self::outOfRange($evaluations, above: true, tolerance: $tolerance);
    }

    /**
     * The other direction, counted but never judged: parents scoring below the
     * minimum of their children.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return list<MonotonicityViolation>
     */
    public static function parentsBelowChildren(array $evaluations, float $tolerance = 1.0e-9): array
    {
        return self::outOfRange($evaluations, above: false, tolerance: $tolerance);
    }

    /**
     * @param list<Evaluation> $evaluations
     *
     * @return list<MonotonicityViolation>
     */
    private static function outOfRange(array $evaluations, bool $above, float $tolerance): array
    {
        $violations = [];

        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->capture->symbols as $parent) {
                $children = $evaluation->capture->childrenOf($parent);

                if ($children === []) {
                    continue;
                }

                $pair = $parent->level->value . '->' . $children[0]->level->value;

                foreach (DIMENSIONS as $dimension) {
                    $violation = self::violationOf($evaluation, $parent, $children, $dimension, $pair, $above, $tolerance);

                    if ($violation !== null) {
                        $violations[] = $violation;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<Subject> $children
     */
    private static function violationOf(
        Evaluation $evaluation,
        Subject $parent,
        array $children,
        string $dimension,
        string $pair,
        bool $above,
        float $tolerance,
    ): ?MonotonicityViolation {
        $parentScore = $evaluation->scoreOf($parent, $dimension);

        if ($parentScore === null) {
            return null;
        }

        $childScores = [];

        foreach ($children as $child) {
            $score = $evaluation->scoreOf($child, $dimension);

            if ($score !== null) {
                $childScores[] = $score;
            }
        }

        if ($childScores === []) {
            return null;
        }

        $min = min($childScores);
        $max = max($childScores);
        $outside = $above
            ? $parentScore > $max + $tolerance
            : $parentScore < $min - $tolerance;

        if (!$outside) {
            return null;
        }

        return new MonotonicityViolation(
            $evaluation->capture->id,
            $pair,
            $dimension,
            $parent->name,
            $parentScore,
            $min,
            $max,
            \count($childScores),
        );
    }

    /**
     * C2: how many subjects scored exactly 100, split by whether every input
     * the formula names was actually present.
     *
     * A perfect score with an absent input is vacuous — the term that would
     * have penalised it had nothing to read — and that is a different fact
     * about the model than a subject that cleared every threshold.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return array<string, array{total: int, vacuous: int}> "level dimension" => counts
     */
    public static function perfectScores(array $evaluations): array
    {
        $counts = [];

        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->capture->symbols as $subject) {
                foreach (DIMENSIONS as $dimension) {
                    $outcome = $evaluation->outcome($subject, $dimension);

                    if ($outcome?->score === null || abs($outcome->score - 100.0) > 1.0e-9) {
                        continue;
                    }

                    $key = $subject->level->value . ' ' . $dimension;
                    $counts[$key] ??= ['total' => 0, 'vacuous' => 0];
                    $counts[$key]['total']++;

                    if ($outcome->absentKeys !== []) {
                        $counts[$key]['vacuous']++;
                    }
                }
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * C4: the same project number under the current rule and under a
     * size-weighted pooling of the level's members.
     *
     * A dimension whose project formula reaches none of the re-derived
     * aggregates ({@see PROJECT_DERIVED}) is reported as null, not as 0.0. Its
     * project inputs are read from the capture verbatim under both schemes, so a
     * zero there is arithmetic, not evidence of insensitivity — and a criterion
     * that cannot tell the two apart passes vacuously.
     *
     * @return array<string, float|null> dimension => |current - weighted|, or null
     *                                   when no derived aggregate reaches it
     */
    public static function aggregateDrift(Evaluation $current, Evaluation $weighted): array
    {
        $project = $current->capture->project();
        $weightedProject = $weighted->capture->project();

        if ($project === null || $weightedProject === null) {
            return [];
        }

        $pooled = self::pooledKeys($current);
        $sensitive = self::sensitiveDimensions($current, $project, $pooled);

        $drift = [];

        foreach (DIMENSIONS as $dimension) {
            $a = $current->scoreOf($project, $dimension);
            $b = $weighted->scoreOf($weightedProject, $dimension);

            if ($a === null || $b === null) {
                continue;
            }

            $drift[$dimension] = isset($sensitive[$dimension]) ? abs($a - $b) : null;
        }

        return $drift;
    }

    /**
     * The project-level keys this run recomputed rather than read.
     *
     * @return array<string, true>
     */
    private static function pooledKeys(Evaluation $evaluation): array
    {
        $pooled = [];

        foreach ($evaluation->derived as $aggregate) {
            foreach (array_keys($aggregate->values) as $key) {
                $pooled[$key] = true;
            }
        }

        return $pooled;
    }

    /**
     * Which dimensions the aggregation scheme can move at all.
     *
     * Sensitivity is transitive: `health.overall` names no metric key, only the
     * sub-scores, and it moves exactly when one of the sub-scores it reads
     * moves. Resolved by fixed point rather than by the declaration order, so a
     * reordered DIMENSIONS cannot silently drop a dependent dimension.
     *
     * @param array<string, true> $pooledKeys
     *
     * @return array<string, true>
     */
    private static function sensitiveDimensions(Evaluation $evaluation, Subject $project, array $pooledKeys): array
    {
        $sensitive = [];

        do {
            $changed = false;

            foreach (DIMENSIONS as $dimension) {
                if (isset($sensitive[$dimension])) {
                    continue;
                }

                $outcome = $evaluation->outcome($project, $dimension);

                if ($outcome === null) {
                    continue;
                }

                foreach ($outcome->referencedKeys as $key) {
                    if (isset($pooledKeys[$key]) || isset($sensitive[$key])) {
                        $sensitive[$dimension] = true;
                        $changed = true;

                        break;
                    }
                }
            }
        } while ($changed);

        return $sensitive;
    }

    /**
     * C6's raw material: median and interquartile range per dimension, per
     * level, across everything the run evaluated.
     *
     * @param list<Evaluation> $evaluations
     * @param list<SymbolLevel> $levels
     *
     * @return array<string, array{n: int, median: float, q1: float, q3: float}>
     */
    public static function distribution(array $evaluations, array $levels): array
    {
        $samples = [];

        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->capture->symbols as $subject) {
                if (!\in_array($subject->level, $levels, true)) {
                    continue;
                }

                foreach (DIMENSIONS as $dimension) {
                    $score = $evaluation->scoreOf($subject, $dimension);

                    if ($score !== null) {
                        $samples[$subject->level->value . ' ' . $dimension][] = $score;
                    }
                }
            }
        }

        $distribution = [];

        foreach ($samples as $key => $values) {
            sort($values);
            $distribution[$key] = [
                'n' => \count($values),
                'median' => self::percentile($values, 0.5),
                'q1' => self::percentile($values, 0.25),
                'q3' => self::percentile($values, 0.75),
            ];
        }

        ksort($distribution);

        return $distribution;
    }

    /**
     * Linear-interpolated percentile over a sorted, non-empty list.
     *
     * @param non-empty-list<float> $sorted
     */
    public static function percentile(array $sorted, float $p): float
    {
        $count = \count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $rank = $p * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($rank - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * C3's verdict for one coverage figure: a dimension covering less than the
     * declared fraction of its subject does not apply, and saying so is the
     * point of the criterion — a number computed over 1 of 500 symbols is not
     * a better or worse score, it is not a score.
     */
    public static function applicability(?Coverage $coverage, string $denominator, ?float $minimum): string
    {
        if ($coverage === null) {
            return 'unknown';
        }

        $share = $coverage->share($denominator);

        if ($share === null) {
            return 'unknown';
        }

        if ($minimum === null) {
            return 'no-declared-fraction';
        }

        return $share < $minimum ? 'not-applicable' : 'applicable';
    }
}

/** The command line, read once. */
final readonly class Options
{
    /**
     * @param list<string> $projects
     * @param list<SymbolLevel> $levels
     */
    public function __construct(
        public string $captureDir,
        public array $projects,
        public array $levels,
        public AggregationScheme $scheme,
        public bool $selfTest,
        public float $tolerance,
        public ?string $candidates,
        public bool $criteria,
        public ?int $c2Max,
        public ?float $c3MinCoverage,
        public string $coverageDenominator,
        public ?float $c4Tolerance,
        public int $top,
    ) {}

    /**
     * @param list<string> $argv
     */
    public static function parse(array $argv): self
    {
        $values = [];

        foreach ($argv as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException(\sprintf('Unexpected argument "%s"', $argument));
            }

            $body = substr($argument, 2);
            $separator = strpos($body, '=');
            $values[$separator === false ? $body : substr($body, 0, $separator)]
                = $separator === false ? '' : substr($body, $separator + 1);
        }

        $known = [
            'capture-dir', 'projects', 'levels', 'aggregation', 'self-test', 'tolerance', 'candidates',
            'criteria', 'c2-max', 'c3-min-coverage', 'coverage', 'c4-tolerance', 'top', 'help',
        ];

        foreach (array_keys($values) as $name) {
            if (!\in_array($name, $known, true)) {
                throw new InvalidArgumentException(\sprintf('Unknown option "--%s"', $name));
            }
        }

        $captureDir = $values['capture-dir'] ?? '';

        if ($captureDir === '') {
            throw new InvalidArgumentException('--capture-dir=DIR is required');
        }

        return new self(
            $captureDir,
            self::listOf($values['projects'] ?? ''),
            self::levelsOf($values['levels'] ?? 'project,namespace'),
            AggregationScheme::parse($values['aggregation'] ?? 'current'),
            \array_key_exists('self-test', $values),
            (float) ($values['tolerance'] ?? '0.1'),
            ($values['candidates'] ?? '') === '' ? null : $values['candidates'],
            \array_key_exists('criteria', $values),
            ($values['c2-max'] ?? '') === '' ? null : (int) $values['c2-max'],
            ($values['c3-min-coverage'] ?? '') === '' ? null : (float) $values['c3-min-coverage'],
            ($values['coverage'] ?? 'symbols') === 'loc' ? 'loc' : 'symbols',
            ($values['c4-tolerance'] ?? '') === '' ? null : (float) $values['c4-tolerance'],
            (int) ($values['top'] ?? '10'),
        );
    }

    /** @return list<string> */
    private static function listOf(string $value): array
    {
        return $value === ''
            ? []
            : array_values(array_filter(
                array_map(trim(...), explode(',', $value)),
                static fn(string $part): bool => $part !== '',
            ));
    }

    /** @return list<SymbolLevel> */
    private static function levelsOf(string $value): array
    {
        $levels = [];

        foreach (self::listOf($value) as $name) {
            $levels[] = match ($name) {
                'project' => SymbolLevel::Project,
                'namespace' => SymbolLevel::Namespace_,
                'class' => SymbolLevel::Class_,
                default => throw new InvalidArgumentException(\sprintf('Unknown level "%s"', $name)),
            };
        }

        if ($levels === []) {
            throw new InvalidArgumentException('--levels must name at least one of project, namespace, class');
        }

        return $levels;
    }
}

/**
 * Reads the definitions a run evaluates: the product's defaults, plus a
 * candidate document read through the product's own resolver, so a candidate
 * is refused exactly as `qmx.yaml` would refuse it.
 *
 * @return list<ComputedMetricDefinition>
 */
function definitionsFor(?string $candidatesPath): array
{
    $resolver = new ComputedMetricsConfigResolver(
        new ComputedMetricFormulaValidator(),
        new HealthFormulaExcluder(),
    );

    if ($candidatesPath === null) {
        return $resolver->resolve([]);
    }

    $raw = @file_get_contents($candidatesPath);

    if ($raw === false) {
        throw new RuntimeException(\sprintf('Cannot read candidate file "%s"', $candidatesPath));
    }

    /** @var mixed $document */
    $document = Yaml::parse($raw);

    if (!\is_array($document)) {
        throw new RuntimeException(\sprintf('Candidate file "%s" is not a YAML mapping', $candidatesPath));
    }

    /** @var mixed $section */
    $section = $document['computed_metrics'] ?? $document;

    if (!\is_array($section)) {
        throw new RuntimeException(\sprintf('Candidate file "%s" has no computed_metrics mapping', $candidatesPath));
    }

    /** @var array<string, mixed> $section */
    return $resolver->resolve($section);
}

/**
 * @param list<string> $only
 *
 * @return list<Capture>
 */
function loadCaptures(string $directory, array $only): array
{
    $paths = glob(rtrim($directory, '/') . '/*.json');

    if ($paths === false || $paths === []) {
        throw new RuntimeException(\sprintf('No capture documents in "%s"', $directory));
    }

    sort($paths);
    $captures = [];

    foreach ($paths as $path) {
        $capture = CaptureReader::read($path);

        if ($only !== [] && !\in_array($capture->id, $only, true)) {
            continue;
        }

        $captures[] = $capture;
    }

    if ($captures === []) {
        throw new RuntimeException('No capture matched --projects');
    }

    return $captures;
}

/**
 * @param list<Capture> $captures
 * @param list<ComputedMetricDefinition> $definitions
 * @param list<SymbolLevel> $levels
 *
 * @return list<Evaluation>
 */
function evaluateAll(array $captures, array $definitions, array $levels, AggregationScheme $scheme): array
{
    $bench = new Bench();
    $evaluations = [];

    foreach ($captures as $capture) {
        $evaluations[] = $bench->run($capture, $definitions, $levels, $scheme);
    }

    return $evaluations;
}

/**
 * The candidate table: one row per project, one column per dimension, and
 * `current/candidate` in the cell when a candidate file was given.
 *
 * @param list<Evaluation> $evaluations
 * @param list<Evaluation>|null $candidateEvaluations Positionally aligned with $evaluations
 */
function printProjectTable(array $evaluations, ?array $candidateEvaluations): void
{
    echo "\n== project scores ==\n";
    echo \sprintf('%-24s', 'project');

    foreach (DIMENSIONS as $dimension) {
        echo \sprintf('%16s', substr($dimension, \strlen('health.')));
    }

    echo "\n";

    foreach ($evaluations as $index => $evaluation) {
        $project = $evaluation->capture->project();

        if ($project === null) {
            continue;
        }

        echo \sprintf('%-24s', $evaluation->capture->id);

        foreach (DIMENSIONS as $dimension) {
            $current = $evaluation->scoreOf($project, $dimension);
            $candidate = null;

            if ($candidateEvaluations !== null) {
                $candidateProject = $candidateEvaluations[$index]->capture->project();
                $candidate = $candidateProject === null
                    ? null
                    : $candidateEvaluations[$index]->scoreOf($candidateProject, $dimension);
            }

            echo \sprintf('%16s', formatPair($current, $candidate));
        }

        echo "\n";
    }
}

function formatPair(?float $current, ?float $candidate): string
{
    $left = $current === null ? '--' : \sprintf('%.1f', $current);

    if ($candidate === null) {
        return $left;
    }

    return $left . '/' . \sprintf('%.1f', $candidate);
}

/**
 * @param list<Evaluation> $evaluations
 * @param list<SymbolLevel> $levels
 */
function printDistribution(array $evaluations, array $levels): void
{
    echo "\n== C6 material: median and interquartile range per level ==\n";

    foreach (Criteria::distribution($evaluations, $levels) as $key => $row) {
        echo \sprintf(
            "%-34s n=%-6d median=%6.2f  IQR=[%6.2f, %6.2f]\n",
            $key,
            $row['n'],
            $row['median'],
            $row['q1'],
            $row['q3'],
        );
    }
}

/**
 * @param list<Evaluation> $evaluations
 */
function printMonotonicity(array $evaluations, int $top): bool
{
    $violations = Criteria::monotonicity($evaluations);
    $below = Criteria::parentsBelowChildren($evaluations);

    echo "\n== C1 monotonicity, one-sided (no parent above max(children)) ==\n";

    if ($violations === []) {
        echo "no violators\n";
    } else {
        echo \sprintf("%d violators; showing up to %d\n", \count($violations), $top);

        foreach (\array_slice($violations, 0, $top) as $violation) {
            echo $violation->toString() . "\n";
        }
    }

    // Printed, never judged: the below-min direction is mostly the parent
    // formula carrying terms the child formula has none of, which is why C1 is
    // one-sided (measurement/07-monotonicity-direction.md).
    echo \sprintf(
        "reference, not a verdict: %d parents below min(children)\n",
        \count($below),
    );

    return $violations === [];
}

/**
 * @param list<Evaluation> $evaluations
 */
function printPerfectScores(array $evaluations, ?int $ceiling): bool
{
    echo "\n== C2 subjects scoring exactly 100 ==\n";
    $agreed = true;

    foreach (Criteria::perfectScores($evaluations) as $key => $counts) {
        $verdict = '';

        if ($ceiling !== null && $counts['total'] > $ceiling) {
            $verdict = \sprintf('  ABOVE DECLARED %d', $ceiling);
            $agreed = false;
        }

        echo \sprintf(
            "%-34s total=%-6d of which vacuous (an input absent)=%-6d%s\n",
            $key,
            $counts['total'],
            $counts['vacuous'],
            $verdict,
        );
    }

    if ($ceiling === null) {
        echo "no --c2-max declared: counts printed, no verdict\n";
    }

    return $agreed;
}

/**
 * @param list<Evaluation> $evaluations
 */
function printCoverage(array $evaluations, Options $options): bool
{
    echo \sprintf("\n== C3 coverage (denominator: %s) ==\n", $options->coverageDenominator);
    $agreed = true;
    $shown = 0;

    foreach ($evaluations as $evaluation) {
        foreach ($evaluation->capture->symbols as $subject) {
            if ($subject->level !== SymbolLevel::Project) {
                continue; // The per-subject detail is printed for the project row; --levels drives the rest.
            }

            foreach (DIMENSIONS as $dimension) {
                $outcome = $evaluation->outcome($subject, $dimension);

                if ($outcome === null) {
                    continue;
                }

                $coverage = CoverageCalculator::forDimension(
                    $evaluation->capture,
                    $subject,
                    $outcome->referencedKeys,
                    $options->coverageDenominator,
                    $evaluation->scheme,
                );
                $verdict = isComposedOfDimensions($outcome->referencedKeys)
                    ? 'composed-of-dimensions'
                    : Criteria::applicability($coverage, $options->coverageDenominator, $options->c3MinCoverage);

                if ($verdict === 'not-applicable') {
                    $agreed = false;
                }

                echo \sprintf(
                    "%-22s %-24s %-20s symbols=%-16s loc=%-6s %s absent=[%s] counts=[%s]\n",
                    $evaluation->capture->id,
                    $dimension,
                    $verdict,
                    $coverage === null
                        ? '--'
                        : \sprintf('%d/%d %s', $coverage->carriers, $coverage->total, $coverage->population),
                    $coverage?->byLoc() === null ? '--' : \sprintf('%.3f', $coverage->byLoc()),
                    $coverage === null ? '' : $coverage->note,
                    implode(' ', $outcome->absentKeys),
                    formatCounts($outcome->counts),
                );
                $shown++;
            }
        }
    }

    if ($options->c3MinCoverage === null) {
        echo "no --c3-min-coverage declared: coverage printed, no verdict\n";
    }

    if ($shown === 0) {
        echo "no project-level outcome in this run\n";
    }

    return $agreed || $options->c3MinCoverage === null;
}

/**
 * `health.overall` names no raw metric: its coverage is whatever its
 * dimensions' coverage was, and printing "unknown" for it would read as a
 * defect rather than as composition.
 *
 * @param list<string> $keys
 */
function isComposedOfDimensions(array $keys): bool
{
    if ($keys === []) {
        return false;
    }

    foreach ($keys as $key) {
        if (!str_starts_with($key, 'health.') && !str_starts_with($key, 'computed.')) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, float> $counts
 */
function formatCounts(array $counts): string
{
    $parts = [];

    foreach ($counts as $key => $count) {
        $parts[] = \sprintf('%s=%d', $key, (int) $count);
    }

    return implode(' ', $parts);
}

/**
 * @param list<Evaluation> $current
 * @param list<Evaluation> $weighted
 */
function printAggregateDrift(array $current, array $weighted, ?float $tolerance): bool
{
    echo "\n== C4 aggregate drift: current rule vs size-weighted pooling ==\n";
    echo \sprintf(
        "judged only where a re-derived aggregate reaches the formula (%s); n/p = not pooled, read from the capture under both schemes\n",
        implode(', ', PROJECT_DERIVED),
    );
    $agreed = true;

    foreach ($current as $index => $evaluation) {
        $drift = Criteria::aggregateDrift($evaluation, $weighted[$index]);
        $parts = [];

        foreach ($drift as $dimension => $delta) {
            $short = substr($dimension, \strlen('health.'));

            if ($delta === null) {
                $parts[] = $short . '=n/p';

                continue;
            }

            $parts[] = \sprintf('%s=%.2f', $short, $delta);

            if ($tolerance !== null && $delta > $tolerance) {
                $agreed = false;
            }
        }

        echo \sprintf("%-22s %s\n", $evaluation->capture->id, implode(' ', $parts));
    }

    if ($tolerance === null) {
        echo "no --c4-tolerance declared: drift printed, no verdict\n";
    }

    return $agreed;
}

/**
 * @param list<Evaluation> $evaluations
 */
function printDerivation(array $evaluations): void
{
    echo "\n== derived level aggregates (not read from the capture) ==\n";

    foreach ($evaluations as $evaluation) {
        foreach ($evaluation->derived as $derived) {
            $values = [];

            foreach ($derived->values as $key => $value) {
                $values[] = \sprintf('%s=%.6f', $key, $value);
            }

            echo \sprintf(
                "%-22s %-20s scheme=%-26s members=%-4d contributors=%-4d %s%s\n",
                $evaluation->capture->id,
                $derived->base,
                $evaluation->scheme->toString(),
                \count($derived->members),
                \count($derived->contributors),
                $values === [] ? '(no aggregate)' : implode(' ', $values),
                $derived->dropped === [] ? '' : ' dropped=[' . implode(' ', $derived->dropped) . ']',
            );
        }
    }
}

/**
 * The run.
 *
 * @param list<string> $argv
 */
function runHealthCalibration(array $argv): int
{
    if (\in_array('--help', $argv, true)) {
        echo USAGE . "\n";

        return 0;
    }

    try {
        $options = Options::parse($argv);
        $definitions = definitionsFor(null);
        $candidateDefinitions = $options->candidates === null ? null : definitionsFor($options->candidates);
        $captures = loadCaptures($options->captureDir, $options->projects);
    } catch (InvalidArgumentException | RuntimeException $e) {
        fwrite(\STDERR, 'error: ' . $e->getMessage() . "\n");

        return 2;
    }

    $evaluations = evaluateAll($captures, $definitions, $options->levels, $options->scheme);
    $candidateEvaluations = $candidateDefinitions === null
        ? null
        : evaluateAll($captures, $candidateDefinitions, $options->levels, $options->scheme);

    // The criteria judge the candidate when there is one: a candidate that
    // does not have to face them would be a formula nothing rejects.
    $judged = $candidateEvaluations ?? $evaluations;
    $judgedDefinitions = $candidateDefinitions ?? $definitions;

    printDerivation($evaluations);
    printProjectTable($evaluations, $candidateEvaluations);
    printDistribution($judged, $options->levels);

    $status = 0;

    if ($options->selfTest) {
        // Always against the product's own defaults: the self-test asks
        // whether the INSTRUMENT agrees with the published measurement, and a
        // candidate is by construction expected to disagree with it.
        $status = max($status, reportSelfTest($options, $evaluations));
    }

    if ($options->criteria) {
        $status = max($status, reportCriteria($options, $judged, $judgedDefinitions, $captures));
    }

    return $status;
}

/**
 * @param list<Evaluation> $evaluations
 */
function reportSelfTest(Options $options, array $evaluations): int
{
    echo "\n== self-test: captured inputs, default formulas, current aggregation ==\n";

    if (!$options->scheme->isCurrent()) {
        echo \sprintf(
            "refused: the self-test compares against what the product published, so it only means something under\n"
            . "the current aggregation rule; this run used %s\n",
            $options->scheme->toString(),
        );

        // A usage error, not a disagreement: the comparison did not run, and
        // reporting 3 would make "my bench is broken" indistinguishable from
        // "I passed the wrong flag".
        return 2;
    }

    if ($options->candidates !== null) {
        echo "note: candidate formulas are loaded; the self-test still runs against the product's defaults\n";
    }

    $mismatches = SelfTest::run($evaluations, $options->tolerance);

    if ($mismatches === []) {
        echo \sprintf(
            "reproduced every published aggregate (1e-6) and every published score (%.3f) in %d project(s)\n",
            $options->tolerance,
            \count($evaluations),
        );

        return 0;
    }

    echo \sprintf("%d disagreement(s); showing up to %d\n", \count($mismatches), $options->top);

    foreach (\array_slice($mismatches, 0, $options->top) as $mismatch) {
        echo $mismatch->toString() . "\n";
    }

    return 3;
}

/**
 * @param list<Evaluation> $evaluations
 * @param list<ComputedMetricDefinition> $definitions
 * @param list<Capture> $captures
 */
function reportCriteria(Options $options, array $evaluations, array $definitions, array $captures): int
{
    $agreed = printMonotonicity($evaluations, $options->top);
    $agreed = printPerfectScores($evaluations, $options->c2Max) && $agreed;
    $agreed = printCoverage($evaluations, $options) && $agreed;

    // C4 is a statement about the project number, so the second evaluation
    // stays at project level: repeating 5000 class evaluations to compare two
    // project aggregates would cost more memory than the whole rest of the run.
    $weighted = evaluateAll(
        $captures,
        $definitions,
        [SymbolLevel::Project],
        new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_LOC),
    );
    $agreed = printAggregateDrift($evaluations, $weighted, $options->c4Tolerance) && $agreed;

    echo "\nC5 (ordering against the frozen a-priori ranking) is not answered here:"
        . " the ranking is P2's artefact and does not exist yet.\n";

    return $agreed ? 0 : 1;
}

// Include-safe: requiring this file from a test defines the classes and
// functions above without running a bench.
if (realpath((string) ($_SERVER['argv'][0] ?? '')) === realpath(__FILE__)) {
    // The whole corpus, decoded and evaluated at three levels, does not fit in
    // the 128M default — the same wall `measurement/00-collect-oom.txt` records
    // for the collector. Raised here rather than left to the caller: a bench
    // that dies halfway prints a partial table that still looks like a result.
    ini_set('memory_limit', '1024M');

    $arguments = array_map(strval(...), \array_slice((array) ($_SERVER['argv'] ?? []), 1));

    exit(runHealthCalibration(array_values($arguments)));
}
