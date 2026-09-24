<?php

declare(strict_types=1);

namespace Qualimetrix\HealthCalibration\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\HealthCalibration\AggregationScheme;
use Qualimetrix\HealthCalibration\Bench;
use Qualimetrix\HealthCalibration\Capture;
use Qualimetrix\HealthCalibration\CoverageCalculator;
use Qualimetrix\HealthCalibration\Criteria;
use Qualimetrix\HealthCalibration\DimensionOutcome;
use Qualimetrix\HealthCalibration\Evaluation;
use Qualimetrix\HealthCalibration\SelfTest;
use Qualimetrix\HealthCalibration\Subject;

/**
 * The verdict arithmetic of `scripts/health-calibration.php`.
 *
 * What is under test here is what the bench SAYS about numbers, not the
 * numbers the product produces: which namespaces an aggregation scheme admits,
 * whether the aggregate is derived or believed, whether a published
 * `health.*` can reach a dependent formula, and whether C1, C2 and C3 answer
 * the way their criterion is written. The corpus-scale agreement with the
 * product lives in the bench's own `--self-test`, which is a measurement and
 * not a unit test.
 */
final class HealthCalibrationBenchTest extends TestCase
{
    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/health-calibration.php';
    }

    #[Test]
    public function itCountsOnlyNamespacesWithoutDescendantsAsLeaves(): void
    {
        $capture = new Capture('fixture', [
            self::namespaceSubject('App'),
            self::namespaceSubject('App\Service'),
            self::namespaceSubject('App\Service\Mail'),
            self::namespaceSubject('Other'),
        ]);

        self::assertSame(
            ['App\Service\Mail', 'Other'],
            self::namesOf($capture->leafNamespaces(includeGlobal: true)),
        );
    }

    #[Test]
    public function itAdmitsTheGlobalNamespaceOnlyWhenTheSchemeSaysSo(): void
    {
        $capture = new Capture('fixture', [
            self::namespaceSubject('(global)'),
            self::namespaceSubject('App'),
        ]);

        self::assertSame(['(global)', 'App'], self::namesOf($capture->leafNamespaces(includeGlobal: true)));
        self::assertSame(['App'], self::namesOf($capture->leafNamespaces(includeGlobal: false)));
    }

    /**
     * The defect this bench exists to make visible: the project aggregate in
     * the capture is a finished number, and a bench that read it would agree
     * with itself under every aggregation rule.
     */
    #[Test]
    public function itDerivesTheProjectAggregateInsteadOfReadingTheCapturedOne(): void
    {
        $capture = self::distanceCapture(publishedAggregate: 0.999);

        $evaluation = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );

        $derived = $evaluation->derived[0];

        self::assertSame('coupling.distance-own', $derived->base);
        // (0.2 + 0.6 + 1.0) / 3 over all three leaves — the global namespace is
        // a leaf like any other under the current scheme; the captured 0.999
        // is discarded.
        self::assertEqualsWithDelta(0.6, $derived->values['coupling.distance-own.avg'], 1.0e-12);
        self::assertSame(3.0, $derived->values['coupling.distance-own.count']);
        self::assertSame(['App\A', 'App\B', '(global)'], $derived->contributors);
        self::assertSame(['coupling.distance-own.avg' => 0.999], $derived->publishedBefore);
    }

    /**
     * The pooled key is an own-scope value, so a namespace that both declares
     * types and has sub-namespaces carries one of its own — and the product
     * pools it. A bench left on the leaf rule drops those namespaces, and every
     * disagreement it then reports is its own.
     */
    #[Test]
    public function itPoolsAParentThatCarriesTheKeyAndTheLeafRuleDrops(): void
    {
        $capture = new Capture('parent-and-leaf', [
            new Subject('', SymbolLevel::Project, [], [], null),
            self::namespaceSubject('App', ['coupling.distance-own' => 0.2], 100),
            self::namespaceSubject('App\Sub', ['coupling.distance-own' => 1.0], 100),
        ]);

        $current = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );
        $leafOnly = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_NONE),
        );

        self::assertSame(['App', 'App\Sub'], $current->derived[0]->contributors);
        self::assertEqualsWithDelta(0.6, $current->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
        self::assertSame(2.0, $current->derived[0]->values['coupling.distance-own.count']);

        self::assertSame(['App\Sub'], $leafOnly->derived[0]->contributors);
        self::assertEqualsWithDelta(1.0, $leafOnly->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
    }

    #[Test]
    public function itReportsTheDerivedAggregateDisagreeingWithThePublishedOne(): void
    {
        $evaluation = (new Bench())->run(
            self::distanceCapture(publishedAggregate: 0.999),
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );

        $mismatches = SelfTest::run([$evaluation], 0.1);
        $keys = array_map(static fn(object $mismatch): string => $mismatch->key, $mismatches);

        self::assertContains('coupling.distance-own.avg', $keys);
    }

    #[Test]
    public function itReproducesThePublishedAggregateUnderTheCurrentScheme(): void
    {
        $evaluation = (new Bench())->run(
            self::distanceCapture(publishedAggregate: 0.6),
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );

        $aggregateMismatches = array_filter(
            SelfTest::run([$evaluation], 0.1),
            static fn(object $mismatch): bool => $mismatch->key === 'coupling.distance-own.avg',
        );

        self::assertSame([], $aggregateMismatches);
    }

    #[Test]
    public function itAdmitsTheGlobalNamespaceIntoTheAggregateWhenTheSchemeDoes(): void
    {
        $evaluation = (new Bench())->run(
            self::distanceCapture(publishedAggregate: 0.4),
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_NONE),
        );

        // (0.2 + 0.6 + 1.0) / 3 — the global namespace now carries its own weight.
        self::assertEqualsWithDelta(0.6, $evaluation->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
        self::assertSame(3.0, $evaluation->derived[0]->values['coupling.distance-own.count']);
    }

    /**
     * C4 compares two aggregation schemes, and only `coupling.distance-own` is
     * re-derived between them — every other project input is read from the
     * capture verbatim, so its drift is zero by construction. A zero printed
     * for those would be read as "this dimension is insensitive to the
     * aggregation rule", which is a claim the run never tested.
     */
    #[Test]
    public function itSaysNotPooledWhereNoDerivedAggregateReachesTheFormula(): void
    {
        $definitions = self::defaultDefinitions();
        $capture = self::distanceCapture(publishedAggregate: 0.4);

        $bench = new Bench();
        $current = $bench->run($capture, $definitions, [SymbolLevel::Project], AggregationScheme::current());
        $weighted = $bench->run(
            $capture,
            $definitions,
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_LOC),
        );

        $drift = Criteria::aggregateDrift($current, $weighted);

        // coupling reads the derived key; overall reads coupling, transitively.
        self::assertNotNull($drift['health.coupling']);
        self::assertGreaterThan(0.0, $drift['health.coupling']);
        self::assertNotNull($drift['health.overall']);

        foreach (['health.complexity', 'health.cohesion', 'health.typing', 'health.maintainability'] as $dimension) {
            self::assertArrayHasKey($dimension, $drift);
            self::assertNull(
                $drift[$dimension],
                \sprintf('%s is reported as measured drift, but nothing pooled reaches its formula', $dimension),
            );
        }
    }

    #[Test]
    public function itWeightsTheAggregateByTheSizeOfEachNamespace(): void
    {
        $definitions = self::defaultDefinitions();
        $capture = self::distanceCapture(publishedAggregate: 0.4);

        $byLoc = (new Bench())->run(
            $capture,
            $definitions,
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES_NO_GLOBAL, AggregationScheme::WEIGHT_LOC),
        );
        $byClasses = (new Bench())->run(
            $capture,
            $definitions,
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES_NO_GLOBAL, AggregationScheme::WEIGHT_CLASSES),
        );

        // loc 100 and 300: (0.2*100 + 0.6*300) / 400.
        self::assertEqualsWithDelta(0.5, $byLoc->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
        // classes 1 and 9: (0.2*1 + 0.6*9) / 10.
        self::assertEqualsWithDelta(0.56, $byClasses->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
    }

    /**
     * Deriving the aggregate is worth nothing if the SCORE still reads the
     * captured one, so the score is asserted, not only the derivation: under
     * the current scheme `coupling.distance-own.avg` is 0.6 (all three leaves,
     * global included) and the project formula gives 18/(18+3.6); the captured
     * 0.999 would give 18/(18+5.994), and the `leaves-no-global` scheme, which
     * derives 0.4 from the two non-global leaves, gives 18/(18+2.4) — proof
     * that the scheme, not the capture, is what moves the score.
     */
    #[Test]
    public function itScoresTheProjectFromTheDerivedAggregateAndNotTheCapturedOne(): void
    {
        $capture = self::distanceCapture(publishedAggregate: 0.999);

        $current = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );
        $withoutGlobal = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES_NO_GLOBAL, AggregationScheme::WEIGHT_NONE),
        );

        $project = $capture->symbols[0];

        self::assertEqualsWithDelta(83.3333, $current->scoreOf($project, 'health.coupling') ?? -1.0, 0.001);
        // The aggregation scheme has to move the score, or no candidate for it
        // could ever be judged by this bench.
        self::assertEqualsWithDelta(88.2353, $withoutGlobal->scoreOf($project, 'health.coupling') ?? -1.0, 0.001);
    }

    /**
     * The sharp form of property 2: with no definition of `health.complexity`
     * in the run at all, a formula that reads it must see nothing — the
     * published 55 in the capture is not an input.
     */
    #[Test]
    public function itNeverPutsAPublishedDimensionIntoTheVariableMap(): void
    {
        $namespace = new Subject('App', SymbolLevel::Namespace_, [], ['health.complexity' => 55.0], null);

        $evaluation = (new Bench())->run(
            new Capture('fixture', [$namespace]),
            [new ComputedMetricDefinition(
                name: 'computed.leak',
                formulas: [SymbolLevel::Namespace_->value => '(m["health.complexity"] ?? -1)'],
                description: 'Answers 55 exactly when a published dimension leaked into the inputs',
                levels: [SymbolLevel::Namespace_],
            )],
            [SymbolLevel::Namespace_],
            AggregationScheme::current(),
        );

        self::assertSame(-1.0, $evaluation->scoreOf($namespace, 'computed.leak'));
    }

    #[Test]
    public function itPublishesNoAggregateWhenNoAdmittedNamespaceCarriesTheMetric(): void
    {
        // `App` is admitted under the current scheme — it is a leaf — but it
        // does not carry `coupling.distance-own` at all, distinct from a namespace
        // the scheme excludes (that case is
        // itCountsAClassUnderAnExcludedNamespaceAsUncovered): no contributor
        // exists, not merely one the scheme declined.
        $capture = new Capture('no-carrier-shaped', [
            new Subject('', SymbolLevel::Project, ['coupling.cbo.avg' => 4.0], [], null),
            self::namespaceSubject('App'),
        ]);

        $evaluation = (new Bench())->run(
            $capture,
            self::defaultDefinitions(),
            [SymbolLevel::Project],
            AggregationScheme::current(),
        );

        self::assertSame([], $evaluation->derived[0]->values);
        self::assertSame([], $evaluation->derived[0]->contributors);
        $coupling = $evaluation->outcome($capture->symbols[0], 'health.coupling');

        self::assertNotNull($coupling);
        self::assertContains('coupling.distance-own.avg', $coupling->absentKeys);
    }

    /**
     * Property 2 of the bench: a published dimension must not reach a formula
     * that depends on it, or a candidate for that dimension would be invisible
     * in the composed score.
     */
    #[Test]
    public function itDoesNotFeedAPublishedDimensionIntoADependentFormula(): void
    {
        $namespace = new Subject(
            'App',
            SymbolLevel::Namespace_,
            [
                'complexity.ccn.sum' => 10.0,
                'complexity.cognitive.sum' => 10.0,
                'size.symbol-method-count' => 100.0,
                'cohesion.tcc.avg' => 1.0,
                'cohesion.lcom.avg' => 1.0,
                'maintainability.mi.avg' => 100.0,
                'maintainability.mi.p5' => 100.0,
                'maintainability.mi.min' => 100.0,
                'design.type-coverage.param.total.sum' => 10.0,
                'design.type-coverage.param.typed.sum' => 10.0,
                'coupling.distance' => 0.0,
            ],
            // Absurd published values: if any of them were seeded as an input,
            // health.overall would collapse towards zero.
            ['health.complexity' => 0.0, 'health.cohesion' => 0.0, 'health.overall' => 0.0],
            null,
        );

        $evaluation = (new Bench())->run(
            new Capture('fixture', [$namespace]),
            self::defaultDefinitions(),
            [SymbolLevel::Namespace_],
            AggregationScheme::current(),
        );

        self::assertEqualsWithDelta(100.0, $evaluation->scoreOf($namespace, 'health.complexity') ?? -1.0, 1.0e-9);
        self::assertGreaterThan(90.0, $evaluation->scoreOf($namespace, 'health.overall') ?? -1.0);
    }

    #[Test]
    public function itReportsAParentScoringAboveEveryChild(): void
    {
        $project = new Subject('', SymbolLevel::Project, [], [], null);
        $global = self::namespaceSubject('(global)');
        $capture = new Capture('fixture', [$project, $global, self::classSubject('Legacy')]);

        $violations = Criteria::monotonicity([self::evaluationOf($capture, [
            Evaluation::key($project) => ['health.coupling' => 100.0],
            Evaluation::key($global) => ['health.coupling' => 76.07],
        ])]);

        self::assertCount(1, $violations);
        self::assertSame('project->namespace', $violations[0]->pair);
        self::assertSame(100.0, $violations[0]->parentScore);
        self::assertSame(76.07, $violations[0]->max);
        self::assertSame(1, $violations[0]->children);
    }

    /**
     * A namespace holding both its own classes and sub-namespaces is not a
     * leaf, so the product's tree would leave its classes out of the range the
     * project is judged against. C1 is stated on containment for exactly this
     * reason, and the case has to be in the child set.
     */
    #[Test]
    public function itJudgesTheProjectAgainstEveryNamespaceThatHoldsClasses(): void
    {
        $project = new Subject('', SymbolLevel::Project, [], [], null);
        $parent = self::namespaceSubject('App');
        $leaf = self::namespaceSubject('App\Mail');
        $capture = new Capture('fixture', [
            $project,
            $parent,
            $leaf,
            self::classSubject('App\Direct'),
            self::classSubject('App\Mail\Sender'),
        ]);

        self::assertSame(['App', 'App\Mail'], self::namesOf($capture->childrenOf($project)));

        // The project sits above the leaf but inside the range once the
        // non-leaf namespace, which the tree would have dropped, is counted.
        $violations = Criteria::monotonicity([self::evaluationOf($capture, [
            Evaluation::key($project) => ['health.coupling' => 60.0],
            Evaluation::key($parent) => ['health.coupling' => 40.0],
            Evaluation::key($leaf) => ['health.coupling' => 90.0],
        ])]);

        self::assertSame([], $violations);
    }

    /**
     * C1 is one-sided. A parent below every child is the parent formula
     * carrying terms the child formula has no equivalent for — coupling alone
     * shows 604 of these and zero of the other kind across the corpus
     * (measurement/07-monotonicity-direction.md) — so it is counted as a
     * reference figure and never returned as a violation.
     */
    #[Test]
    public function itDoesNotCallAParentBelowEveryChildAViolation(): void
    {
        $namespace = self::namespaceSubject('App');
        $first = self::classSubject('App\First');
        $second = self::classSubject('App\Second');
        $capture = new Capture('fixture', [$namespace, $first, $second]);

        $evaluations = [self::evaluationOf($capture, [
            Evaluation::key($namespace) => ['health.cohesion' => 40.0],
            Evaluation::key($first) => ['health.cohesion' => 77.0],
            Evaluation::key($second) => ['health.cohesion' => 90.0],
        ])];

        self::assertSame([], Criteria::monotonicity($evaluations));

        $below = Criteria::parentsBelowChildren($evaluations);

        self::assertCount(1, $below);
        self::assertSame('namespace->class', $below[0]->pair);
        self::assertSame(77.0, $below[0]->min);
        self::assertSame(90.0, $below[0]->max);
        self::assertSame(2, $below[0]->children);
    }

    /**
     * The upward direction is the one the criterion judges, and it is not in
     * the reference count.
     */
    #[Test]
    public function itKeepsTheTwoDirectionsApart(): void
    {
        $namespace = self::namespaceSubject('App');
        $only = self::classSubject('App\Only');
        $capture = new Capture('fixture', [$namespace, $only]);

        $evaluations = [self::evaluationOf($capture, [
            Evaluation::key($namespace) => ['health.coupling' => 100.0],
            Evaluation::key($only) => ['health.coupling' => 76.07],
        ])];

        self::assertCount(1, Criteria::monotonicity($evaluations));
        self::assertSame([], Criteria::parentsBelowChildren($evaluations));
    }

    #[Test]
    public function itAcceptsAParentInsideTheRangeItsChildrenSpan(): void
    {
        $namespace = self::namespaceSubject('App');
        $first = self::classSubject('App\First');
        $second = self::classSubject('App\Second');
        $capture = new Capture('fixture', [$namespace, $first, $second]);

        $violations = Criteria::monotonicity([self::evaluationOf($capture, [
            Evaluation::key($namespace) => ['health.cohesion' => 80.0],
            Evaluation::key($first) => ['health.cohesion' => 70.0],
            Evaluation::key($second) => ['health.cohesion' => 90.0],
        ])]);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itCountsPerfectScoresAndMarksTheOnesAnAbsentInputBought(): void
    {
        $earned = self::classSubject('App\Earned');
        $vacuous = self::classSubject('App\Vacuous');
        $capture = new Capture('fixture', [$earned, $vacuous]);

        $evaluation = new Evaluation($capture, AggregationScheme::current(), [
            Evaluation::key($earned) => [
                'health.complexity' => new DimensionOutcome('health.complexity', 100.0, ['complexity.ccn.avg'], [], []),
            ],
            Evaluation::key($vacuous) => [
                'health.complexity' => new DimensionOutcome(
                    'health.complexity',
                    100.0,
                    ['complexity.ccn.avg'],
                    ['complexity.ccn.avg'],
                    [],
                ),
            ],
        ], []);

        self::assertSame(
            ['class health.complexity' => ['total' => 2, 'vacuous' => 1]],
            Criteria::perfectScores([$evaluation]),
        );
    }

    /**
     * The plan's own test case: an aggregate carrying 1 of 500 is not a better
     * or worse score, it is not a score.
     */
    #[Test]
    public function itReportsADimensionAsNotApplicableBelowTheDeclaredFraction(): void
    {
        $capture = self::sparseCapture(classes: 500, carriers: 1);
        $project = $capture->symbols[0];

        $coverage = CoverageCalculator::forDimension(
            $capture,
            $project,
            ['cohesion.tcc.avg'],
            'symbols',
            AggregationScheme::current(),
        );

        self::assertNotNull($coverage);
        self::assertSame(1, $coverage->carriers);
        self::assertSame(500, $coverage->total);
        self::assertSame('not-applicable', Criteria::applicability($coverage, 'symbols', 0.05));
    }

    #[Test]
    public function itRefusesToJudgeCoverageWithoutADeclaredFraction(): void
    {
        $capture = self::sparseCapture(classes: 500, carriers: 1);

        $coverage = CoverageCalculator::forDimension(
            $capture,
            $capture->symbols[0],
            ['cohesion.tcc.avg'],
            'symbols',
            AggregationScheme::current(),
        );

        self::assertSame('no-declared-fraction', Criteria::applicability($coverage, 'symbols', null));
    }

    #[Test]
    public function itExpressesCoverageByLinesAsWellAsBySymbols(): void
    {
        // Two classes of ten lines carry the metric; one of 980 does not.
        $capture = new Capture('fixture', [
            new Subject('', SymbolLevel::Project, [], [], null),
            new Subject('App\Small', SymbolLevel::Class_, ['cohesion.tcc' => 0.5], [], 10),
            new Subject('App\AlsoSmall', SymbolLevel::Class_, ['cohesion.tcc' => 0.5], [], 10),
            new Subject('App\Huge', SymbolLevel::Class_, [], [], 980),
        ]);

        $coverage = CoverageCalculator::forKey(
            $capture,
            $capture->symbols[0],
            'cohesion.tcc.avg',
            AggregationScheme::current(),
        );

        self::assertEqualsWithDelta(2 / 3, $coverage->bySymbols() ?? -1.0, 1.0e-12);
        self::assertEqualsWithDelta(0.02, $coverage->byLoc() ?? -1.0, 1.0e-12);
    }

    /**
     * A class whose namespace the aggregation scheme excluded is uncovered,
     * not merely unmeasured. The current scheme admits `(global)` as a leaf
     * like any other, so exercising the exclusion needs the
     * `leaves-no-global` scheme explicitly — it is what the CodeIgniter case
     * used to mean before the tree guard defect was fixed, and it stays a
     * legitimate candidate scheme even though it is no longer the default.
     */
    #[Test]
    public function itCountsAClassUnderAnExcludedNamespaceAsUncovered(): void
    {
        $capture = new Capture('fixture', [
            new Subject('', SymbolLevel::Project, [], [], null),
            self::namespaceSubject('(global)', ['coupling.distance' => 0.94]),
            new Subject('Legacy', SymbolLevel::Class_, [], [], 100),
        ]);

        $excluded = CoverageCalculator::forKey(
            $capture,
            $capture->symbols[0],
            'coupling.distance.avg',
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES_NO_GLOBAL, AggregationScheme::WEIGHT_NONE),
        );
        $admitted = CoverageCalculator::forKey(
            $capture,
            $capture->symbols[0],
            'coupling.distance.avg',
            AggregationScheme::current(),
        );

        self::assertSame(0, $excluded->carriers);
        self::assertSame(1, $admitted->carriers);
        self::assertSame('ns=0/1', $excluded->note);
    }

    /**
     * The coverage of a namespace-collected key asks the same scheme the
     * aggregate does. A parent carrying the key is a contributor under the
     * current rule and not under the leaf rule, so the two answer differently
     * about the classes that sit in it — without this the calculator's own
     * membership call could stay on the superseded rule unnoticed.
     */
    #[Test]
    public function itCountsAClassUnderACarryingParentAsCoveredOnlyUnderTheCurrentScheme(): void
    {
        $capture = new Capture('parent-and-leaf-coverage', [
            new Subject('', SymbolLevel::Project, [], [], null),
            self::namespaceSubject('App', ['coupling.distance-own' => 0.2]),
            self::namespaceSubject('App\\Sub', ['coupling.distance-own' => 0.4]),
            new Subject('App\\InParent', SymbolLevel::Class_, [], [], 10),
            new Subject('App\\Sub\\InLeaf', SymbolLevel::Class_, [], [], 10),
        ]);

        $current = CoverageCalculator::forKey(
            $capture,
            $capture->symbols[0],
            'coupling.distance-own.avg',
            AggregationScheme::current(),
        );
        $leafOnly = CoverageCalculator::forKey(
            $capture,
            $capture->symbols[0],
            'coupling.distance-own.avg',
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_NONE),
        );

        self::assertSame(2, $current->carriers);
        self::assertSame('ns=2/2', $current->note);
        self::assertSame(1, $leafOnly->carriers);
        self::assertSame('ns=1/2', $leafOnly->note);
    }

    #[Test]
    public function itInterpolatesTheQuartilesItReports(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0];

        self::assertSame(25.0, Criteria::percentile($values, 0.5));
        self::assertSame(17.5, Criteria::percentile($values, 0.25));
        self::assertSame(32.5, Criteria::percentile($values, 0.75));
    }

    /**
     * @return list<ComputedMetricDefinition>
     */
    private static function defaultDefinitions(): array
    {
        return (new ComputedMetricsConfigResolver(
            new ComputedMetricFormulaValidator(),
            new HealthFormulaExcluder(),
        ))->resolve([]);
    }

    /**
     * A project with three leaf namespaces — one of them global — carrying
     * distances 0.2, 0.6 and 1.0, sizes 100/300/500 lines and 1/9/5 classes.
     */
    private static function distanceCapture(float $publishedAggregate): Capture
    {
        return new Capture('fixture', [
            new Subject(
                '',
                SymbolLevel::Project,
                ['coupling.distance-own.avg' => $publishedAggregate, 'coupling.cbo.avg' => 4.0],
                [],
                null,
            ),
            self::namespaceSubject('App\A', ['coupling.distance-own' => 0.2, 'size.class-count.sum' => 1.0], 100),
            self::namespaceSubject('App\B', ['coupling.distance-own' => 0.6, 'size.class-count.sum' => 9.0], 300),
            self::namespaceSubject('(global)', ['coupling.distance-own' => 1.0, 'size.class-count.sum' => 5.0], 500),
        ]);
    }

    private static function sparseCapture(int $classes, int $carriers): Capture
    {
        $symbols = [new Subject('', SymbolLevel::Project, ['cohesion.tcc.count' => (float) $carriers], [], null)];

        for ($index = 0; $index < $classes; $index++) {
            $symbols[] = new Subject(
                'App\C' . $index,
                SymbolLevel::Class_,
                $index < $carriers ? ['cohesion.tcc' => 0.5] : [],
                [],
                1,
            );
        }

        return new Capture('fixture', $symbols);
    }

    /**
     * @param array<string, float> $inputs
     */
    private static function namespaceSubject(string $name, array $inputs = [], ?int $loc = null): Subject
    {
        return new Subject($name, SymbolLevel::Namespace_, $inputs, [], $loc);
    }

    private static function classSubject(string $name): Subject
    {
        return new Subject($name, SymbolLevel::Class_, [], [], null);
    }

    /**
     * @param array<string, array<string, float>> $scores subject key => dimension => score
     */
    private static function evaluationOf(Capture $capture, array $scores): Evaluation
    {
        $outcomes = [];

        foreach ($scores as $subjectKey => $dimensions) {
            foreach ($dimensions as $dimension => $score) {
                $outcomes[$subjectKey][$dimension] = new DimensionOutcome($dimension, $score, [], [], []);
            }
        }

        return new Evaluation($capture, AggregationScheme::current(), $outcomes, []);
    }

    /**
     * @param list<Subject> $subjects
     *
     * @return list<string>
     */
    private static function namesOf(array $subjects): array
    {
        return array_map(static fn(Subject $subject): string => $subject->name, $subjects);
    }
}
