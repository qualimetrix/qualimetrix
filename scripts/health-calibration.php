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
 *    children, not read.** `coupling.distance-own.avg` at project level is produced
 *    by
 *    {@see \Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator}
 *    before any expression runs, so it sits in the capture as a finished
 *    number. The project publisher weights eligible namespace distances by
 *    their own eligible type counts. Reading that finished value would hide
 *    alternative membership and weighting schemes, and make the self-test
 *    agree with itself under any aggregation
 *    rule whatsoever. The bench therefore recomputes the aggregate from the
 *    captured namespace values under a selectable scheme, and the self-test
 *    requires the `current` scheme to reproduce the PUBLISHED aggregate. That
 *    reproduction is the one measurement that makes this instrument evidence.
 *
 *    The set that is re-derived is {@see PROJECT_DERIVED}, and it is small on
 *    purpose: `coupling.distance-own` is the only base the product pools from
 *    namespaces to the project. Every other project input — `complexity.ccn.p95`,
 *    `maintainability.mi.min`, `size.symbol-method-count` and the rest — comes
 *    from captured callable/class measurements, with no namespace-pooling step to vary;
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
 *                           `declaring:types`, aliased `current`.
 *   --self-test             reproduce the published aggregates and health.*
 *   --tolerance=0.1         self-test tolerance on a score (default 0.1)
 *   --candidates=FILE       a YAML document with a `computed_metrics:` section
 *   --criteria              judge C2-C5; print descriptive C1 and C6 distributions
 *   --c2-max=N              optional diagnostic ceiling for subjects at 100
 *   --c4-tolerance=T        numeric identity precision for C4 (default 1e-6)
 *   --top=N                 rows per movers/violators table (default 10)
 *
 * Exit codes:
 *   0 — ran, binding C2-C5 passed
 *   1 — a binding criterion failed or lacked its required witness
 *   2 — usage or infrastructure error (no captures, unreadable document, a
 *       candidate file the product's resolver refuses)
 *   3 — the self-test disagrees with the capture: the INSTRUMENT is not
 *       trustworthy, and nothing else printed by this run is evidence
 */

namespace Qualimetrix\HealthCalibration;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDependencyGraphCalculator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricOutcome;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricSubjectEvaluation;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\CoverageUnit;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDecompositionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\CoverageReader;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

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
      --aggregation=SCHEME    members[:weight]; members is declaring, leaves
                              or leaves-no-global, weight is none, classes, loc or
                              types. Default `current` = declaring:types
      --self-test             reproduce the published aggregates and health.*
      --tolerance=0.1         self-test tolerance on a score
      --candidates=FILE       a YAML document with a `computed_metrics:` section
      --criteria              judge C2-C5; print descriptive C1 and C6 distributions
      --c2-max=N              optional diagnostic ceiling for subjects at 100
      --c4-tolerance=T        numeric identity precision for C4 (default 1e-6)
      --top=N                 rows per violators/mismatch table (default 10)

    Exit: 0 C2-C5 passed, 1 a binding criterion failed or is unjudged, 2 input error,
    3 the self-test disagrees with the capture.
    TEXT;

/**
 * Metrics collected ON a namespace rather than aggregated up to it.
 *
 * Their project-level value cannot be recomputed from class symbols, because
 * no class carries them; it is an aggregate over namespaces, which is exactly
 * the aggregation this bench has to be able to vary. The list is the set of
 * `collectedAt: SymbolLevel::Namespace_` definitions —
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\Distance\DistanceCollector},
 * {@see \Qualimetrix\Analysis\Evidence\Coupling\AbstractnessCollector} and the
 * namespace instability the latter's sibling writes.
 *
 * @var list<string>
 */
const NAMESPACE_COLLECTED = [
    'coupling.distance',
    'coupling.abstractness',
    'coupling.instability',
    'coupling.distance-own',
    'coupling.abstractness-own',
    'coupling.instability-own',
];

/**
 * The only namespace-collected base pooled at project level is own-scope
 * distance. The project publisher weights eligible namespace distances by
 * their own classes, traits, interfaces and implementing enums; unmeasured
 * and zero-edge namespaces do not contribute.
 *
 * Pooling the subtree rollup instead would count a parent's declarations
 * again inside its ancestors. Deriving other namespace-collected keys would
 * invent project inputs the product does not publish.
 *
 * @var list<string>
 */
const PROJECT_DERIVED = ['coupling.distance-own'];

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
        public ?string $identity = null,
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
 * children of this subject", keeping `(global)` in the diagnostic population.
 */
final readonly class Capture
{
    /**
     * @param list<Subject> $symbols
     */
    public function __construct(
        public string $id,
        public array $symbols,
        public ?bool $complete = null,
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
     * The namespaces a scheme pools a namespace-collected key over.
     *
     * `declaring` admits every captured namespace; measurement, edge
     * eligibility and own-type weight decide which distances contribute. It
     * skips the ones carrying no value. The leaf schemes are the superseded
     * rules, kept as candidates to measure against.
     *
     * @return list<Subject>
     */
    public function pooledNamespaces(AggregationScheme $scheme): array
    {
        return match ($scheme->members) {
            AggregationScheme::MEMBERS_DECLARING => $this->at(SymbolLevel::Namespace_),
            AggregationScheme::MEMBERS_LEAVES_NO_GLOBAL => $this->leafNamespaces(includeGlobal: false),
            default => $this->leafNamespaces(includeGlobal: true),
        };
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
     * The children of a subject for the score distribution diagnostic.
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
    public const string MEMBERS_DECLARING = 'declaring';
    public const string MEMBERS_LEAVES = 'leaves';
    public const string MEMBERS_LEAVES_NO_GLOBAL = 'leaves-no-global';
    public const string WEIGHT_NONE = 'none';
    public const string WEIGHT_CLASSES = 'classes';
    public const string WEIGHT_LOC = 'loc';
    public const string WEIGHT_TYPES = 'types';

    public function __construct(
        public string $members,
        public string $weight,
    ) {}

    public static function parse(string $spec): self
    {
        $normalized = $spec === 'current' ? self::MEMBERS_DECLARING . ':' . self::WEIGHT_TYPES : $spec;
        $parts = explode(':', $normalized);
        $members = $parts[0];
        $weight = $parts[1] ?? self::WEIGHT_NONE;

        if (!\in_array($members, [self::MEMBERS_DECLARING, self::MEMBERS_LEAVES, self::MEMBERS_LEAVES_NO_GLOBAL], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown aggregation members "%s"', $members));
        }

        if (!\in_array($weight, [self::WEIGHT_NONE, self::WEIGHT_CLASSES, self::WEIGHT_LOC, self::WEIGHT_TYPES], true)) {
            throw new InvalidArgumentException(\sprintf('Unknown aggregation weight "%s"', $weight));
        }

        return new self($members, $weight);
    }

    /**
     * The product pools measured, edge-eligible own-scope distances with
     * own-type weights, admitting parents and leaves as well as `(global)`.
     * Own declarations belong to one namespace, so this does not duplicate
     * subtree rollups. A bench that models a superseded rule — the global namespace
     * excluded, or only leaves admitted — reports disagreements that are its
     * own and calls the product wrong.
     */
    public static function current(): self
    {
        return new self(self::MEMBERS_DECLARING, self::WEIGHT_TYPES);
    }

    public function isCurrent(): bool
    {
        return $this->members === self::MEMBERS_DECLARING && $this->weight === self::WEIGHT_TYPES;
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
            self::WEIGHT_TYPES => self::ownTypeCount($namespace),
            self::WEIGHT_LOC => max(0.0, (float) ($namespace->loc ?? 0)),
            default => 1.0,
        };
    }

    /** The own type population in AbstractnessCollector's denominator. */
    private static function ownTypeCount(Subject $namespace): float
    {
        return max(0.0, ($namespace->inputs['size.class-count'] ?? 0.0)
            + ($namespace->inputs['size.trait-count'] ?? 0.0)
            + ($namespace->inputs['size.interface-count'] ?? 0.0)
            + ($namespace->inputs['size.implementing-enum-count'] ?? 0.0));
    }
}

/** What the bench derived for one aggregate key, and out of what. */
final readonly class DerivedAggregate
{
    /**
     * @param array<string, float> $values The derived `base.strategy` keys
     * @param list<string> $contributors Namespaces admitted as measured, edge-eligible contributors
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
        public string $kind = ComputedMetricOutcome::NO_VALUE,
        public ?string $reason = null,
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
        return $subject->level->value . "\0" . ($subject->identity ?? $subject->name);
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
        $seen = [];
        foreach ($symbols as $symbol) {
            $subject = self::readSymbol($symbol);

            if ($subject !== null) {
                // A legacy capture without exact identity may still be read if
                // names are unique; refuse collisions instead of losing a row.
                $key = Evaluation::key($subject);
                if (isset($seen[$key])) {
                    throw new RuntimeException(\sprintf('Capture "%s" repeats exact subject "%s"', $path, $key));
                }
                $seen[$key] = true;
                $subjects[] = $subject;
            }
        }

        if ($subjects === []) {
            throw new RuntimeException(\sprintf('Capture "%s" carries no project, namespace or class symbol', $path));
        }

        return new Capture($id, $subjects, \is_bool($document['coverage']['complete'] ?? null) ? $document['coverage']['complete'] : null);
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

        return new Subject(
            $name,
            $level,
            $inputs,
            $published,
            self::readLoc($symbol, $inputs),
            \is_string($symbol['subject'] ?? null) ? $symbol['subject'] : null,
        );
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
    private readonly ComputedMetricSubjectEvaluation $subjectEvaluation;
    private readonly ComputedMetricDependencyGraphCalculator $graph;

    public function __construct()
    {
        $this->expression = new ComputedMetricExpression();
        $this->subjectEvaluation = new ComputedMetricSubjectEvaluation($this->expression);
        $this->graph = new ComputedMetricDependencyGraphCalculator($this->expression);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<SymbolLevel> $levels
     */
    public function run(
        Capture $capture,
        array $definitions,
        array $levels,
        AggregationScheme $scheme,
        bool $keepPublishedProjectInputs = false,
    ): Evaluation {
        $sorted = $this->graph->sort($definitions);

        if ($sorted === null) {
            throw new RuntimeException('The candidate definitions contain a circular dependency');
        }

        $derived = $keepPublishedProjectInputs ? [] : $this->deriveProjectAggregates($capture, $scheme);
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

        $members = $capture->pooledNamespaces($scheme);

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

                if ($value === null || (
                    ($member->inputs['coupling.ca-own'] ?? null) === 0.0
                    && ($member->inputs['coupling.ce-own'] ?? null) === 0.0
                )) {
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

            $outcome = $this->evaluateFormula($definition, $subject->level, $values);
            $outcomes[$definition->name] = $outcome;

            if ($outcome->score !== null) {
                $values[$definition->name] = $outcome->score;
            }
        }

        return $outcomes;
    }

    /** @param array<string, float> $values */
    private function evaluateFormula(ComputedMetricDefinition $definition, SymbolLevel $level, array $values): DimensionOutcome
    {
        $formula = $definition->getFormulaForLevel($level)
            ?? throw new RuntimeException('A reporting level must have an effective formula');
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

        $result = $this->subjectEvaluation->evaluate($definition, $level, $values);

        return new DimensionOutcome(
            $definition->name,
            $result->kind === ComputedMetricOutcome::VALUE ? (float) $result->value : null,
            $referenced,
            $absent,
            $counts,
            $result->kind,
            $result->reason,
        );
    }
}

/** Predeclared probes: candidate formulas never decide which sources are tested. */
final class EarnedTop
{
    /**
     * Neutral/adverse values fixed before fitting, from the shipped metric
     * domains and formula knees. A new catalog source without a profile refuses
     * adjudication instead of shrinking the tested set.
     *
     * @return array{float, float}
     */
    public static function values(string $key, SymbolLevel $level): array
    {
        if (str_starts_with($key, 'health.')) {
            return [100.0, 0.0];
        }
        if (str_starts_with($key, 'design.type-coverage.')) {
            return str_contains($key, '.typed.') || $key === 'design.type-coverage.all'
                ? [$key === 'design.type-coverage.all' ? 100.0 : 10.0, 0.0]
                : [10.0, 10.0];
        }
        return match ($key) {
            'size.symbol-method-count', 'size.method-count', 'cohesion.tcc-method-count' => [10.0, 10.0],
            'cohesion.pure-method-count' => [0.0, 0.0],
            'complexity.ccn.avg' => [1.0, 3.0],
            'complexity.cognitive.avg' => [0.0, 2.0],
            'complexity.ccn.sum' => [10.0, 30.0],
            'complexity.cognitive.sum' => [0.0, 20.0],
            'complexity.ccn.max' => [1.0, $level === SymbolLevel::Class_ ? 11.0 : 21.0],
            'complexity.cognitive.max' => [0.0, 16.0],
            'complexity.ccn.p95' => [1.0, 6.0],
            'complexity.cognitive.p95' => [0.0, 7.0],
            'cohesion.tcc', 'cohesion.tcc.avg' => [1.0, 0.0],
            'cohesion.lcom', 'cohesion.lcom.avg' => [1.0, 6.0],
            'coupling.distance', 'coupling.distance-own.avg' => [0.0, 1.0],
            'coupling.ce-packages', 'coupling.ce-packages.avg' => [0.0, 3.0],
            'coupling.ce.avg', 'coupling.ce.max' => [0.0, 31.0],
            'coupling.ce' => [0.0, $level === SymbolLevel::Class_ ? 31.0 : 51.0],
            'coupling.cbo.avg' => [0.0, 9.0],
            'coupling.cbo.p95' => [0.0, 16.0],
            'coupling.cbo.max' => [0.0, 31.0],
            'maintainability.mi.avg' => [85.0, 84.0],
            'maintainability.mi.min' => [$level === SymbolLevel::Class_ ? 65.0 : 5.0, $level === SymbolLevel::Class_ ? 64.0 : 0.0],
            'maintainability.mi.p5' => [65.0, 64.0],
            default => throw new RuntimeException('C2 has no frozen profile for ' . $key),
        };
    }

    /** @return list<list<string>> */
    public static function groups(string $dimension, SymbolLevel $level): array
    {
        static $cache = [];
        $cacheKey = $dimension . ' ' . $level->value;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $expression = new ComputedMetricExpression();
        $catalog = new HealthDecompositionCatalog($expression);
        $groups = [];
        foreach ($catalog->inputsFor($dimension, $level) as $input) {
            $groups[implode("\0", $input['sources'])] = $input['sources'];
        }
        if ($groups === []) {
            $default = self::indexed(definitionsFor(null))[$dimension];
            $keys = $expression->keysOf($default->getFormulaForLevel($level) ?? '');
            if ($dimension === 'health.typing') {
                foreach (['param', 'return', 'property'] as $part) {
                    $groups[$part] = array_values(array_filter($keys, static fn(string $key): bool => str_contains($key, '.' . $part . '.')));
                }
            } else {
                foreach ($keys as $key) {
                    $groups[$key] = [$key];
                }
            }
        }
        $default = self::indexed(definitionsFor(null))[$dimension];
        $covered = $groups === [] ? [] : array_merge(...array_values($groups));
        foreach ($expression->keysOf($default->getFormulaForLevel($level) ?? '') as $key) {
            if (!\in_array($key, $covered, true)) {
                $groups[$key] = [$key];
            }
        }
        return $cache[$cacheKey] = array_values($groups);
    }

    /** @param list<ComputedMetricDefinition> $definitions
     * @return array<string, ComputedMetricDefinition>
     */
    private static function indexed(array $definitions): array
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->name] = $definition;
        }
        return $indexed;
    }

    /** @param list<ComputedMetricDefinition> $definitions
     * @return list<array{dimension: string, level: string, sources: list<string>, neutral: ?float, adverse: ?float, kind: string, verdict: string}>
     */
    public static function judge(array $definitions): array
    {
        $indexed = self::indexed($definitions);
        $engine = new ComputedMetricSubjectEvaluation(new ComputedMetricExpression());
        $rows = [];
        foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            foreach (DIMENSIONS as $dimension) {
                $groups = self::groups($dimension, $level);
                $neutral = [];
                foreach ($groups as $group) {
                    foreach ($group as $key) {
                        $neutral[$key] = self::values($key, $level)[0];
                    }
                }
                $definition = $indexed[$dimension] ?? null;
                $candidateKeys = (new ComputedMetricExpression())->keysOf($definition?->getFormulaForLevel($level) ?? '');
                $unprofiled = array_values(array_diff($candidateKeys, array_keys($neutral)));
                if ($unprofiled !== []) {
                    $rows[] = ['dimension' => $dimension, 'level' => $level->value, 'sources' => $unprofiled, 'neutral' => null, 'adverse' => null, 'kind' => 'unprofiled-input', 'verdict' => 'unjudged'];
                }
                foreach ($groups as $group) {
                    $adverse = $neutral;
                    foreach ($group as $key) {
                        $adverse[$key] = self::values($key, $level)[1];
                    }
                    $good = $definition === null ? null : $engine->evaluate($definition, $level, $neutral);
                    $bad = $definition === null ? null : $engine->evaluate($definition, $level, $adverse);
                    $goodScore = $good?->kind === ComputedMetricOutcome::VALUE ? (float) $good->value : null;
                    $badScore = $bad?->kind === ComputedMetricOutcome::VALUE ? (float) $bad->value : null;
                    $rows[] = [
                        'dimension' => $dimension, 'level' => $level->value, 'sources' => $group,
                        'neutral' => $goodScore, 'adverse' => $badScore, 'kind' => $bad === null ? 'missing-definition' : $bad->kind,
                        'verdict' => $goodScore === null || $badScore === null ? 'unjudged'
                            : ($badScore < 100.0 - 1.0e-9 && $badScore < $goodScore - 1.0e-9 ? 'pass' : 'fail'),
                    ];
                }
            }
        }
        return $rows;
    }
    /** Perfect scores matching a predeclared adverse profile are unearned.
     * Other perfect counts are descriptive: this does not claim an exhaustive
     * proof of the multidimensional domain between the fixed probes.
     *
     * @param list<Evaluation> $evaluations
     *
     * @return list<string>
     */
    public static function unearned(array $evaluations): array
    {
        $violations = [];
        $defaults = self::indexed(definitionsFor(null));
        $engine = new ComputedMetricSubjectEvaluation(new ComputedMetricExpression());
        foreach ($evaluations as $evaluation) {
            foreach ($evaluation->capture->symbols as $subject) {
                $values = $subject->inputs;
                if ($subject->level === SymbolLevel::Project) {
                    foreach ($evaluation->derived as $aggregate) {
                        foreach ($aggregate->publishedBefore as $key => $_) {
                            unset($values[$key]);
                        }
                        $values += $aggregate->values;
                    }
                }
                foreach (DIMENSIONS as $dimension) {
                    $score = $evaluation->scoreOf($subject, $dimension);
                    if ($score !== null) {
                        $values[$dimension] = $score;
                    }
                }
                foreach (DIMENSIONS as $dimension) {
                    if (abs(($values[$dimension] ?? -1.0) - 100.0) > 1.0e-9) {
                        continue;
                    }
                    $groups = self::groups($dimension, $subject->level);
                    $baseline = [];
                    foreach ($groups as $sources) {
                        foreach ($sources as $key) {
                            $baseline[$key] = self::values($key, $subject->level)[0];
                        }
                    }
                    foreach ($groups as $group) {
                        $matches = true;
                        foreach ($group as $key) {
                            [$neutral, $adverse] = self::values($key, $subject->level);
                            if ($neutral === $adverse) {
                                continue;
                            }
                            $actual = $values[$key] ?? null;
                            // Normalize the two means to the predeclared ten
                            // callable profile; never compare raw sums across
                            // projects of different size.
                            if ($key === 'complexity.ccn.sum' || $key === 'complexity.cognitive.sum') {
                                $population = $values['size.symbol-method-count'] ?? 0.0;
                                $actual = $population > 0.0 && $actual !== null ? $actual / $population * 10.0 : null;
                            }
                            $matches = $matches && $actual !== null && ($adverse > $neutral ? $actual >= $adverse : $actual <= $adverse);
                        }
                        if ($matches) {
                            $profile = $baseline;
                            foreach ($group as $key) {
                                if (isset($values[$key])) {
                                    $profile[$key] = $values[$key];
                                } else {
                                    unset($profile[$key]);
                                }
                            }
                            // A large pure-method correction can remove the
                            // LCOM/TCC penalty. The shipped engine adjudicates
                            // actual grouped sources and adjusters, so crossing
                            // one raw bound alone is not an accusation.
                            $reference = $engine->evaluate($defaults[$dimension], $subject->level, $profile);
                            if ($reference->kind !== ComputedMetricOutcome::VALUE || (float) $reference->value >= 100.0 - 1.0e-9) {
                                continue;
                            }
                            $violations[] = $evaluation->capture->id . ' ' . $subject->level->value . ' ' . ($subject->identity ?? $subject->name) . ' ' . $dimension . ' [' . implode(' ', $group) . ']';
                        }
                    }
                }
            }
        }
        return $violations;
    }

}

/** Coverage stated with the population owned by each input, without a cutoff. */
final class StatedCoverage
{
    /** @return list<array{basis: string, state: string, symbols: string, loc: string, reason: string}> */
    public static function rows(Evaluation $evaluation, Subject $subject, string $dimension): array
    {
        $outcome = $evaluation->outcome($subject, $dimension);
        if ($outcome === null) {
            return [['basis' => $dimension, 'state' => 'absent', 'symbols' => '--', 'loc' => '--', 'reason' => 'score absent']];
        }
        $catalog = new HealthDecompositionCatalog(new ComputedMetricExpression());
        $inputs = $catalog->inputsFor($dimension, $subject->level);
        if ($dimension === 'health.typing' && $subject->level !== SymbolLevel::Class_) {
            return self::typingRows($evaluation->capture, $subject, $outcome->referencedKeys);
        }
        $values = $subject->inputs;
        if ($subject->level === SymbolLevel::Project) {
            foreach ($evaluation->derived as $aggregate) {
                foreach ($aggregate->publishedBefore as $key => $_) {
                    unset($values[$key]);
                }
                $values += $aggregate->values;
            }
        }
        $read = static fn(string $key): ?float => $values[$key] ?? null;
        $rows = [];
        $covered = [];
        foreach ($inputs as $input) {
            if (array_intersect($input['sources'], $outcome->referencedKeys) === []) {
                continue;
            }
            $covered = array_merge($covered, $input['sources']);
            $spec = $input['coverage'];
            if ($spec === null) {
                $present = isset($values[$input['key']]) ? [$input['key']] : [];
                $rows[] = [
                    'basis' => $input['key'], 'state' => $present === [] ? 'absent' : 'measured',
                    'symbols' => $present === [] ? '0/1 exact subject' : '1/1 exact subject',
                    'loc' => $subject->loc === null ? 'unavailable: subject has no line metric' : ($present === [] ? '0/' : $subject->loc . '/') . $subject->loc,
                    'reason' => 'own subject input; no aggregate population',
                ];
                continue;
            }
            try {
                $coverage = (new CoverageReader($catalog))->read($dimension, $read, [$input]);
                $aggregateSources = array_values(array_filter($input['sources'], static fn(string $key): bool => MetricName::base($key) === MetricName::base($input['key'])));
                if (\count($aggregateSources) !== 1) {
                    throw new LogicException(\sprintf('Aggregate "%s" has no unique own source in its coverage metadata.', $input['key']));
                }
                if (($read($aggregateSources[0]) !== null) !== (($read($spec['count']) ?? 0) > 0)) {
                    throw new LogicException(\sprintf('Aggregate source "%s" and contributor count "%s" disagree on measured input presence.', $aggregateSources[0], $spec['count']));
                }
                $lines = self::lines($evaluation, $subject, $input, $coverage->measured, $coverage->eligible);
                $rows[] = [
                    'basis' => $spec['count'], 'state' => $lines['reason'] === null ? $coverage->state : 'unjudged',
                    'symbols' => $coverage->applicable ? $coverage->measured . '/' . $coverage->eligible . ' ' . $coverage->unit?->value : '--',
                    'loc' => $lines['loc'],
                    'reason' => $lines['reason'] ?? $coverage->reason ?? '',
                ];
            } catch (LogicException $error) {
                $rows[] = ['basis' => $spec['count'], 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => $error->getMessage()];
            }
        }
        foreach (array_diff($outcome->referencedKeys, $covered) as $key) {
            if (!str_starts_with($key, 'health.')) {
                $rows[] = ['basis' => $key, 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => 'input has no declared coverage population'];
            }
        }
        if ($rows !== []) {
            return $rows;
        }
        foreach ($outcome->referencedKeys as $key) {
            if (str_starts_with($key, 'health.')) {
                foreach (self::rows($evaluation, $subject, $key) as $row) {
                    $row['basis'] = $key . ':' . $row['basis'];
                    $rows[] = $row;
                }
            }
        }
        if ($rows !== []) {
            return $rows;
        }
        return [['basis' => $dimension, 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => 'effective formula has no declared aggregate coverage; exact input participation needs adjudication']];
    }

    /** @param list<string> $keys
     * @return list<array{basis: string, state: string, symbols: string, loc: string, reason: string}>
     */
    private static function typingRows(Capture $capture, Subject $subject, array $keys): array
    {
        $members = $capture->classesUnder($subject);
        $eligible = $subject->inputs[CoverageUnit::Classes->populationMetric()] ?? null;
        $rows = [];
        foreach ($keys as $key) {
            if (!str_ends_with($key, '.sum')) {
                $rows[] = ['basis' => $key, 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => 'typing input is not a captured class-owned sum'];
                continue;
            }
            $base = substr($key, 0, -4);
            if ($eligible === null || $capture->complete !== true || $eligible !== (float) \count($members)
                || array_filter($members, static fn(Subject $member): bool => $member->identity === null) !== []) {
                $rows[] = ['basis' => $key, 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => 'exact class population is not complete'];
                continue;
            }
            $carriers = 0;
            $sum = 0.0;
            $totalLoc = 0;
            $carrierLoc = 0;
            $hasLoc = true;
            foreach ($members as $member) {
                $hasLoc = $hasLoc && $member->loc !== null;
                $totalLoc += $member->loc ?? 0;
                if (isset($member->inputs[$base])) {
                    $carriers++;
                    $sum += $member->inputs[$base];
                    $carrierLoc += $member->loc ?? 0;
                }
            }
            $published = $subject->inputs[$key] ?? null;
            $consistent = $published === null ? $carriers === 0 : abs($sum - $published) < 1.0e-6;
            $rows[] = [
                'basis' => $key, 'state' => !$consistent || !$hasLoc ? 'unjudged' : ($eligible === 0.0 ? 'not-applicable' : ($carriers === 0 ? 'not-measured' : 'measured')),
                'symbols' => $consistent ? $carriers . '/' . (int) $eligible . ' classes' : '--',
                'loc' => !$consistent ? 'unjudged: captured sum differs' : ($hasLoc ? $carrierLoc . '/' . $totalLoc . ' lines' : 'unjudged: class LOC unavailable'),
                'reason' => !$consistent ? 'captured sum differs from subject aggregate' : (!$hasLoc ? 'class LOC unavailable' : 'captured class-owned input participation; zero is measured'),
            ];
        }
        return $rows === [] ? [['basis' => 'health.typing', 'state' => 'unjudged', 'symbols' => '--', 'loc' => 'unjudged', 'reason' => 'candidate exposes no typing inputs']] : $rows;
    }

    /**
     * @param array{key: string, sources: list<string>, coverage: array{count: string, unit: CoverageUnit}|null} $input
     *
     * @return array{loc: string, reason: ?string}
     */
    private static function lines(Evaluation $evaluation, Subject $subject, array $input, ?int $measured, ?int $eligible): array
    {
        $capture = $evaluation->capture;
        $unit = $input['coverage']['unit'] ?? null;
        if ($unit === CoverageUnit::Callables) {
            return ['loc' => 'not-applicable: callable LOC is not measured', 'reason' => null];
        }
        if ($eligible === null || $measured === null) {
            return ['loc' => 'not-applicable: empty population', 'reason' => null];
        }
        $members = $unit === CoverageUnit::Classes ? $capture->classesUnder($subject) : $capture->namespacesHoldingClasses();
        if (\count($members) !== $eligible || $capture->complete !== true) {
            return ['loc' => 'unjudged: exact population is not complete', 'reason' => 'exact population is not complete'];
        }
        $base = MetricName::base($input['key']);
        $pooledContributors = null;
        if ($unit === CoverageUnit::DeclaringNamespaces && $subject->level === SymbolLevel::Project) {
            foreach ($evaluation->derived as $aggregate) {
                if ($aggregate->base === $base) {
                    $pooledContributors = array_fill_keys($aggregate->contributors, true);
                }
            }
        }
        $total = 0;
        $covered = 0;
        $carriers = 0;
        foreach ($members as $member) {
            if ($member->identity === null || $member->loc === null) {
                return ['loc' => 'unjudged: exact identity or LOC is unavailable', 'reason' => 'exact identity or LOC is unavailable'];
            }
            $total += $member->loc;
            if ($pooledContributors === null ? isset($member->inputs[$base]) : isset($pooledContributors[$member->name])) {
                $carriers++;
                $covered += $member->loc;
            }
        }
        if ($carriers !== $measured) {
            return ['loc' => 'unjudged: captured carriers disagree with aggregate count', 'reason' => 'captured carriers disagree with aggregate count'];
        }
        return ['loc' => $covered . '/' . $total . ' lines', 'reason' => null];
    }
}

/** The frozen formal ranking pairs; unrelated anchors remain diagnostic. */
final class FormalRanking
{
    public const array LOWER = ['codeigniter', 'wordpress'];
    public const array HIGHER = ['flysystem', 'phpunit', 'symfony-http-foundation', 'symfony-http-kernel', 'symfony-console', 'symfony-routing', 'monolog', 'doctrine-orm', 'doctrine-dbal', 'php-parser', 'guzzle', 'symfony-di'];

    /** @param list<Evaluation> $evaluations
     * @return list<array{lower: string, higher: string, low: ?float, high: ?float, verdict: string}>
     */
    public static function judge(array $evaluations): array
    {
        $scores = [];
        foreach ($evaluations as $evaluation) {
            $project = $evaluation->capture->project();
            if ($project !== null) {
                $scores[$evaluation->capture->id] = $evaluation->scoreOf($project, 'health.overall');
            }
        }
        $rows = [];
        foreach (self::LOWER as $lower) {
            foreach (self::HIGHER as $higher) {
                $low = $scores[$lower] ?? null;
                $high = $scores[$higher] ?? null;
                $rows[] = ['lower' => $lower, 'higher' => $higher, 'low' => $low, 'high' => $high,
                    'verdict' => $low === null || $high === null ? 'unjudged' : ($low <= $high ? 'pass' : 'fail')];
            }
        }
        return $rows;
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
            foreach ($evaluation->outcomes[Evaluation::key($subject)] ?? [] as $key => $outcome) {
                $published = $subject->published[$key] ?? null;
                $recomputed = $outcome->score;

                if ($published === null && $recomputed === null) {
                    continue;
                }
                if ($published !== null && $recomputed !== null && abs($published - $recomputed) <= $tolerance) {
                    continue;
                }

                $mismatches[] = new SelfTestMismatch(
                    $evaluation->capture->id,
                    $subject->level->value,
                    ($subject->identity ?? $subject->name),
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
     * C1 diagnostic: parents scoring above every measured child, for every
     * dimension and containment pair. A nonlinear formula can legitimately
     * improve on complementary child inputs even with identical populations
     * and scales; neither direction alone proves lost inputs or poor quality.
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
     * The lower direction of the same diagnostic distribution.
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
     * Descriptive perfect counts, with input absence reported separately.
     * Absence alone does not prove a score unearned: optional metrics and
     * measured neutral values have different product applicability semantics.
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

                    if ($outcome === null) {
                        continue;
                    }
                    $key = $subject->level->value . ' ' . $dimension;
                    $counts[$key] ??= ['total' => 0, 'vacuous' => 0];
                    if ($outcome->score === null || abs($outcome->score - 100.0) > 1.0e-9) {
                        continue;
                    }
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
     * C4: the project score using its published input against an independent
     * declaring/type-weighted derivation from the captured namespaces.
     *
     * A dimension whose project formula reaches none of the re-derived
     * aggregates ({@see PROJECT_DERIVED}) is reported as null, not as 0.0. Its
     * The report judges a zero drift only when a measured project score reaches
     * a derived input and a discriminating type-weighted pool is present.
     *
     * @return array<string, float|null> dimension => |published - weighted|, or null
     *                                   when no derived aggregate reaches it
     */
    public static function aggregateDrift(Evaluation $published, Evaluation $weighted): array
    {
        $project = $published->capture->project();
        $weightedProject = $weighted->capture->project();

        if ($project === null || $weightedProject === null) {
            return [];
        }

        $pooled = self::pooledKeys($weighted);
        $sensitive = self::sensitiveDimensions($weighted, $weightedProject, $pooled);

        $drift = [];

        foreach (DIMENSIONS as $dimension) {
            $a = $published->scoreOf($project, $dimension);
            $b = $weighted->scoreOf($weightedProject, $dimension);

            if ($a === null || $b === null) {
                continue;
            }

            $drift[$dimension] = isset($sensitive[$dimension]) ? abs($a - $b) : null;
        }

        return $drift;
    }

    /** A pool that would expose an accidental return to the unweighted mean. */
    public static function hasDiscriminatingPool(Capture $capture): bool
    {
        $scheme = new AggregationScheme(AggregationScheme::MEMBERS_DECLARING, AggregationScheme::WEIGHT_TYPES);
        $values = [];
        $weightedSum = 0.0;
        $weightSum = 0.0;

        foreach ($capture->pooledNamespaces($scheme) as $namespace) {
            $distance = $namespace->inputs['coupling.distance-own'] ?? null;
            if ($distance === null || (
                ($namespace->inputs['coupling.ca-own'] ?? null) === 0.0
                && ($namespace->inputs['coupling.ce-own'] ?? null) === 0.0
            )) {
                continue;
            }
            $weight = $scheme->weightOf($namespace);
            if ($weight <= 0.0) {
                continue;
            }
            $values[] = [$distance, $weight];
            $weightedSum += $distance * $weight;
            $weightSum += $weight;
        }

        if (\count($values) < 2) {
            return false;
        }

        $firstWeight = $values[0][1];
        $differentWeights = false;
        $unweightedSum = 0.0;
        foreach ($values as [$distance, $weight]) {
            $differentWeights = $differentWeights || $weight !== $firstWeight;
            $unweightedSum += $distance;
        }

        return $differentWeights
            && abs($weightedSum / $weightSum - $unweightedSum / \count($values)) > 1.0e-9;
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
        public float $c4Tolerance,
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
            'criteria', 'c2-max', 'c4-tolerance', 'top', 'help',
        ];

        foreach (array_keys($values) as $name) {
            if (!\in_array($name, $known, true)) {
                throw new InvalidArgumentException(\sprintf('Unknown option "--%s"', $name));
            }
        }

        if (($values['criteria'] ?? '') !== '') {
            throw new InvalidArgumentException('--criteria is a boolean flag and takes no value');
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
            (float) ($values['c4-tolerance'] ?? '0.000001'),
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
        new HealthFormulaExcluder(new ComputedMetricExpression()),
    );

    $schema = new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]);

    if ($candidatesPath === null) {
        return $resolver->resolve(DocumentComposer::compose($schema, []));
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

    return $resolver->resolve(DocumentComposer::compose(
        $schema,
        [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $candidatesPath),
            AuthoredNode::fromPlain([ComputedMetricsSection::KEY => $section]),
        )],
    ));
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

    $distribution = Criteria::distribution($evaluations, $levels);
    foreach ($levels as $level) {
        foreach (DIMENSIONS as $dimension) {
            $key = $level->value . ' ' . $dimension;
            if (!isset($distribution[$key])) {
                echo \sprintf("%-34s n=0 median=unavailable IQR=unavailable\n", $key);
            }
        }
    }
    foreach ($distribution as $key => $row) {
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
function printMonotonicity(array $evaluations, int $top): void
{
    $violations = Criteria::monotonicity($evaluations);
    $below = Criteria::parentsBelowChildren($evaluations);

    echo "\n== C1 diagnostic: parent/child score distributions (no binding verdict) ==\n";

    foreach (['project->namespace', 'namespace->class'] as $pair) {
        foreach (DIMENSIONS as $dimension) {
            $matches = static fn(MonotonicityViolation $row): bool => $row->pair === $pair && $row->dimension === $dimension;
            echo \sprintf("%s %s above=%d below=%d\n", $pair, $dimension, \count(array_filter($violations, $matches)), \count(array_filter($below, $matches)));
        }
    }

    if ($violations === []) {
        echo "no parents above max(children)\n";
    } else {
        echo \sprintf("%d parents above max(children); showing up to %d\n", \count($violations), $top);

        foreach (\array_slice($violations, 0, $top) as $violation) {
            echo $violation->toString() . "\n";
        }
    }

    echo \sprintf(
        "%d parents below min(children); showing up to %d\n",
        \count($below),
        $top,
    );
    foreach (\array_slice($below, 0, $top) as $row) {
        echo $row->toString() . "\n";
    }
}

/**
 * @param list<Evaluation> $evaluations
 */
function printPerfectScores(array $evaluations, ?int $ceiling): void
{
    echo "\n== C2 diagnostic: subjects scoring exactly 100 ==\n";

    foreach (Criteria::perfectScores($evaluations) as $key => $counts) {
        $verdict = '';

        if ($ceiling !== null && $counts['total'] > $ceiling) {
            $verdict = \sprintf('  above diagnostic bound %d', $ceiling);
        }

        echo \sprintf(
            "%-34s total=%-6d of which an input absent=%-6d%s\n",
            $key,
            $counts['total'],
            $counts['vacuous'],
            $verdict,
        );
    }

    if ($ceiling === null) {
        echo "no --c2-max supplied; counts only\n";
    }

}

/**
 * @param list<Evaluation> $evaluations
 */
function printCoverage(array $evaluations, Options $options): bool
{
    echo "\n== C3: stated symbol and line coverage; no applicability cutoff ==\n";
    $agreed = true;
    foreach ($evaluations as $evaluation) {
        if ($evaluation->capture->complete !== true) {
            echo $evaluation->capture->id . ": unjudged: capture has no coverage.complete=true receipt\n";
            $agreed = false;
        }
        foreach ($evaluation->capture->symbols as $subject) {
            if (!\in_array($subject->level, $options->levels, true)) {
                continue;
            }
            foreach (DIMENSIONS as $dimension) {
                foreach (StatedCoverage::rows($evaluation, $subject, $dimension) as $row) {
                    echo \sprintf(
                        "%s %s %s %s score=%s subject-lines=%s %s state=%s symbols=%s loc=%s %s\n",
                        $evaluation->capture->id,
                        $subject->level->value,
                        $subject->identity ?? $subject->name,
                        $dimension,
                        $evaluation->scoreOf($subject, $dimension) ?? '--',
                        $subject->loc ?? 'unavailable',
                        $row['basis'],
                        $row['state'],
                        $row['symbols'],
                        $row['loc'],
                        $row['reason'],
                    );
                    $agreed = $row['state'] !== 'unjudged' && $agreed;
                }
            }
        }
    }
    return $agreed;
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
 * @param list<Evaluation> $published
 * @param list<Evaluation> $weighted
 */
function printAggregateDrift(array $published, array $weighted, float $tolerance): bool
{
    echo "\n== C4 aggregate drift: published project input vs independent declaring:types pooling ==\n";
    echo \sprintf(
        "judged only where a re-derived aggregate reaches the formula (%s); n/p = not pooled\n",
        implode(', ', PROJECT_DERIVED),
    );
    $agreed = true;
    $witnesses = 0;
    $judged = 0;

    foreach ($published as $index => $evaluation) {
        $drift = Criteria::aggregateDrift($evaluation, $weighted[$index]);
        if (Criteria::hasDiscriminatingPool($evaluation->capture)
            && ($drift['health.coupling'] ?? null) !== null) {
            $witnesses++;
        }
        $parts = [];

        foreach ($drift as $dimension => $delta) {
            $short = substr($dimension, \strlen('health.'));

            if ($delta === null) {
                $parts[] = $short . '=n/p';

                continue;
            }

            $judged++;
            $parts[] = \sprintf('%s=%.8f', $short, $delta);

            if ($delta > $tolerance) {
                $agreed = false;
            }
        }

        echo \sprintf("%-22s %s\n", $evaluation->capture->id, implode(' ', $parts));
    }

    echo \sprintf("measured discriminating coupling pools=%d; judged scores=%d; numeric precision=%.8f\n", $witnesses, $judged, $tolerance);
    if ($witnesses === 0 || $judged === 0) {
        echo "C4 unjudged: no measured, discriminating type-weighted project comparison\n";
    }

    return $agreed && $witnesses > 0 && $judged > 0;
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
            "reproduced every published aggregate (1e-6), score and score absence (%.3f) in %d project(s)\n",
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
    printMonotonicity($evaluations, $options->top);
    $agreed = true;
    printPerfectScores($evaluations, $options->c2Max);
    echo "\n== C2: predeclared neutral/adverse source profiles ==\n";
    foreach (EarnedTop::judge($definitions) as $row) {
        echo \sprintf("%s %s [%s] neutral=%s adverse=%s %s (%s)\n", $row['level'], $row['dimension'], implode(' ', $row['sources']), $row['neutral'] ?? '--', $row['adverse'] ?? '--', $row['verdict'], $row['kind']);
        $agreed = $row['verdict'] === 'pass' && $agreed;
    }
    $unearned = EarnedTop::unearned($evaluations);
    echo \sprintf("C2 corpus: %d perfect scores matched a frozen adverse profile; other perfect counts remain descriptive.\n", \count($unearned));
    foreach ($unearned as $violation) {
        echo 'unearned: ' . $violation . "\n";
    }
    $agreed = $unearned === [] && $agreed;
    $agreed = printCoverage($evaluations, $options) && $agreed;

    // Both C4 sides stay at project level; only the comparator re-derives D.
    $published = array_map(
        static fn(Capture $capture): Evaluation => (new Bench())->run(
            $capture,
            $definitions,
            [SymbolLevel::Project],
            AggregationScheme::current(),
            keepPublishedProjectInputs: true,
        ),
        $captures,
    );
    $weighted = evaluateAll(
        $captures,
        $definitions,
        [SymbolLevel::Project],
        AggregationScheme::current(),
    );
    $agreed = printAggregateDrift($published, $weighted, $options->c4Tolerance) && $agreed;

    echo "\n== C5: 24 frozen formal pairs (equality allowed) ==\n";
    foreach (FormalRanking::judge($evaluations) as $row) {
        echo \sprintf("%s (%s) <= %s (%s): %s\n", $row['lower'], $row['low'] ?? '--', $row['higher'], $row['high'] ?? '--', $row['verdict']);
        $agreed = $row['verdict'] === 'pass' && $agreed;
    }
    foreach (['codeigniter', 'wordpress'] as $lower) {
        foreach (['composer', 'laravel-framework'] as $higher) {
            $scores = [];
            foreach ($evaluations as $evaluation) {
                $project = $evaluation->capture->project();
                if ($project !== null) {
                    $scores[$evaluation->capture->id] = $evaluation->scoreOf($project, 'health.overall');
                }
            }
            $low = $scores[$lower] ?? null;
            $high = $scores[$higher] ?? null;
            echo \sprintf("C5 diagnostic: %s (%s) <= %s (%s): %s\n", $lower, $low ?? '--', $higher, $high ?? '--', $low === null || $high === null ? 'unjudged' : ($low <= $high ? 'holds' : 'reversed'));
        }
    }
    echo "qmx has no formal C5 pair.\n";
    echo "C6: the dimension-by-level distributions above are descriptive; no independently declared numeric bands.\n";

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
