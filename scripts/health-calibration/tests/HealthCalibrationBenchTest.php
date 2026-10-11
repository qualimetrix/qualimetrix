<?php

declare(strict_types=1);

namespace Qualimetrix\HealthCalibration\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricsSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ExcludeHealthSection;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricOutcome;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricSubjectEvaluation;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\MetricLookup;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\HealthCalibration\AggregationScheme;
use Qualimetrix\HealthCalibration\Bench;
use Qualimetrix\HealthCalibration\Capture;
use Qualimetrix\HealthCalibration\CaptureReader;
use Qualimetrix\HealthCalibration\Criteria;
use Qualimetrix\HealthCalibration\DimensionOutcome;
use Qualimetrix\HealthCalibration\EarnedTop;
use Qualimetrix\HealthCalibration\Evaluation;
use Qualimetrix\HealthCalibration\FormalRanking;
use Qualimetrix\HealthCalibration\Options;
use Qualimetrix\HealthCalibration\SelfTest;
use Qualimetrix\HealthCalibration\StatedCoverage;
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
    public function itMakesOwnTypesTheCurrentWeightAndExcludesBareEnums(): void
    {
        self::assertSame('declaring:types', AggregationScheme::parse('current')->toString());
        self::assertTrue(AggregationScheme::current()->isCurrent());
        $namespace = self::namespaceSubject('App', [
            'size.class-count' => 2.0,
            'size.trait-count' => 1.0,
            'size.interface-count' => 3.0,
            'size.implementing-enum-count' => 2.0,
            'size.enum-count' => 12.0,
            'size.class-count.sum' => 100.0,
        ]);
        self::assertSame(8.0, AggregationScheme::current()->weightOf($namespace));
    }

    #[Test]
    public function itExcludesZeroEdgeNamespacesFromProjectDistanceEvenIfCaptureCarriesOne(): void
    {
        $capture = new Capture('zero-edge', [
            new Subject('', SymbolLevel::Project, ['coupling.distance-own.avg' => 0.3], [], null),
            self::namespaceSubject('App\Isolated', [
                'coupling.distance-own' => 1.0, 'size.class-count' => 10.0,
                'coupling.ca-own' => 0.0, 'coupling.ce-own' => 0.0,
            ]),
            self::namespaceSubject('App\Connected', [
                'coupling.distance-own' => 0.3, 'size.class-count' => 1.0,
                'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0,
            ]),
        ]);
        $evaluation = (new Bench())->run($capture, self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::current());
        self::assertSame(['App\Connected'], $evaluation->derived[0]->contributors);
        self::assertSame(0.3, $evaluation->derived[0]->values['coupling.distance-own.avg']);
    }

    #[Test]
    public function itUsesTheProductOutcomeForApplicabilityAndRefusal(): void
    {
        $subject = new Subject('App\Empty', SymbolLevel::Class_, [], ['computed.probe' => 100.0], null);
        $definition = new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: [SymbolLevel::Class_->value => 'm["cohesion.tcc"] ?? 100'],
            description: 'applicability probe',
            levels: [SymbolLevel::Class_],
            applicability: [SymbolLevel::Class_->value => ComputedMetricApplicability::anyPresent(['cohesion.tcc'])],
        );
        $evaluation = (new Bench())->run(new Capture('probe', [$subject]), [$definition], [SymbolLevel::Class_], AggregationScheme::current());
        self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->outcome($subject, 'computed.probe')?->kind);
        self::assertNull($evaluation->scoreOf($subject, 'computed.probe'));
        self::assertCount(1, SelfTest::run([$evaluation], 0.1));
    }

    #[Test]
    public function itRetainsMissingAndFailedProductOutcomes(): void
    {
        $subject = self::classSubject('App\Probe');
        $definitions = [
            new ComputedMetricDefinition(
                name: 'computed.missing',
                formulas: [SymbolLevel::Class_->value => 'm["unknown.metric"]'],
                description: 'missing-key probe',
                levels: [SymbolLevel::Class_],
            ),
            new ComputedMetricDefinition(
                name: 'computed.failure',
                formulas: [SymbolLevel::Class_->value => '1 / 0'],
                description: 'failure probe',
                levels: [SymbolLevel::Class_],
            ),
        ];
        $evaluation = (new Bench())->run(new Capture('probe', [$subject]), $definitions, [SymbolLevel::Class_], AggregationScheme::current());
        self::assertSame(ComputedMetricOutcome::MISSING_KEYS, $evaluation->outcome($subject, 'computed.missing')?->kind);
        self::assertSame(ComputedMetricOutcome::FAILURE, $evaluation->outcome($subject, 'computed.failure')?->kind);
        self::assertNull($evaluation->scoreOf($subject, 'computed.failure'));
    }

    #[Test]
    public function itKeepsTwoDeclarationsWithTheSameLogicalNameSeparate(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-capture-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, json_encode(['symbols' => [
                ['type' => 'class', 'name' => 'App\Dup', 'subject' => 'declaration:class:App\\Dup@src/One.php', 'metrics' => ['size.method-count' => 1]],
                ['type' => 'class', 'name' => 'App\Dup', 'subject' => 'declaration:class:App\\Dup@src/Two.php', 'metrics' => ['size.method-count' => 2]],
            ]], \JSON_THROW_ON_ERROR));
            $capture = CaptureReader::read($path);
            $definition = new ComputedMetricDefinition(
                name: 'computed.probe',
                formulas: [SymbolLevel::Class_->value => 'm["size.method-count"]'],
                description: 'identity probe',
                levels: [SymbolLevel::Class_],
            );
            $evaluation = (new Bench())->run($capture, [$definition], [SymbolLevel::Class_], AggregationScheme::current());
            self::assertCount(2, $evaluation->outcomes);
            self::assertSame(1.0, $evaluation->scoreOf($capture->symbols[0], 'computed.probe'));
            self::assertSame(2.0, $evaluation->scoreOf($capture->symbols[1], 'computed.probe'));
        } finally {
            unlink($path);
        }
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
        // Own type counts 1, 9, 5; the published 0.999 is discarded.
        self::assertEqualsWithDelta(10.6 / 15.0, $derived->values['coupling.distance-own.avg'], 1.0e-12);
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
            self::namespaceSubject('App', ['coupling.distance-own' => 0.2, 'size.class-count' => 2.0], 100),
            self::namespaceSubject('App\Sub', ['coupling.distance-own' => 1.0, 'size.class-count' => 8.0], 100),
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
            new AggregationScheme(AggregationScheme::MEMBERS_LEAVES, AggregationScheme::WEIGHT_TYPES),
        );

        self::assertSame(['App', 'App\Sub'], $current->derived[0]->contributors);
        self::assertEqualsWithDelta(0.84, $current->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
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
            self::distanceCapture(publishedAggregate: 10.6 / 15.0),
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

    /** C4 names dimensions that cannot read the independently pooled D. */
    #[Test]
    public function itSaysNotPooledWhereNoDerivedAggregateReachesTheFormula(): void
    {
        $definitions = self::defaultDefinitions();
        $capture = self::distanceCapture(publishedAggregate: 0.4);
        $project = $capture->project();
        self::assertNotNull($project);
        $capture = new Capture($capture->id, [
            new Subject($project->name, $project->level, $project->inputs + [
                'size.symbol-method-count' => 1.0,
                'complexity.ccn.sum' => 1.0,
                'cohesion.tcc.avg' => 0.5,
                'cohesion.lcom.avg' => 1.0,
                'design.type-coverage.param.total.sum' => 1.0,
                'design.type-coverage.param.typed.sum' => 1.0,
                'maintainability.mi.avg' => 85.0,
            ], $project->published, $project->loc),
            ...\array_slice($capture->symbols, 1),
        ]);

        $bench = new Bench();
        $current = $bench->run($capture, $definitions, [SymbolLevel::Project], AggregationScheme::current(), keepPublishedProjectInputs: true);
        $weighted = $bench->run(
            $capture,
            $definitions,
            [SymbolLevel::Project],
            new AggregationScheme(AggregationScheme::MEMBERS_DECLARING, AggregationScheme::WEIGHT_TYPES),
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
    public function itJudgesPublishedProjectDistanceAgainstTheIndependentTypeWeightedPool(): void
    {
        $definitions = self::defaultDefinitions();
        $bench = new Bench();
        $correct = self::distanceCapture(publishedAggregate: 10.6 / 15.0);
        $unweighted = self::distanceCapture(publishedAggregate: 0.6);

        self::assertTrue(Criteria::hasDiscriminatingPool($correct));
        $weighted = $bench->run($correct, $definitions, [SymbolLevel::Project], AggregationScheme::current());
        $publishedCorrect = $bench->run($correct, $definitions, [SymbolLevel::Project], AggregationScheme::current(), keepPublishedProjectInputs: true);
        $publishedUnweighted = $bench->run($unweighted, $definitions, [SymbolLevel::Project], AggregationScheme::current(), keepPublishedProjectInputs: true);

        self::assertEqualsWithDelta(10.6 / 15.0, $weighted->derived[0]->values['coupling.distance-own.avg'], 1.0e-12);
        self::assertEqualsWithDelta(0.0, Criteria::aggregateDrift($publishedCorrect, $weighted)['health.coupling'], 1.0e-12);
        self::assertGreaterThan(1.0e-6, Criteria::aggregateDrift($publishedUnweighted, $weighted)['health.coupling']);

        ob_start();
        try {
            self::assertTrue(\Qualimetrix\HealthCalibration\printAggregateDrift([$publishedCorrect], [$weighted], 1.0e-6));
            self::assertFalse(\Qualimetrix\HealthCalibration\printAggregateDrift([$publishedUnweighted], [$weighted], 1.0e-6));
        } finally {
            ob_end_clean();
        }
    }

    #[Test]
    public function itDoesNotAcceptAWeightingVerdictFromAnEqualWeightPool(): void
    {
        $capture = new Capture('equal-weights', [
            new Subject('', SymbolLevel::Project, ['coupling.distance-own.avg' => 0.4, 'coupling.cbo.avg' => 4.0], [], null),
            self::namespaceSubject('App\A', ['coupling.distance-own' => 0.2, 'size.class-count' => 1.0, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0]),
            self::namespaceSubject('App\B', ['coupling.distance-own' => 0.6, 'size.class-count' => 1.0, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0]),
        ]);
        self::assertFalse(Criteria::hasDiscriminatingPool($capture));

        $bench = new Bench();
        $definitions = self::defaultDefinitions();
        $published = $bench->run($capture, $definitions, [SymbolLevel::Project], AggregationScheme::current(), keepPublishedProjectInputs: true);
        $weighted = $bench->run($capture, $definitions, [SymbolLevel::Project], AggregationScheme::current());
        ob_start();
        try {
            self::assertFalse(\Qualimetrix\HealthCalibration\printAggregateDrift([$published], [$weighted], 1.0e-6));
        } finally {
            ob_end_clean();
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
     * the current scheme `coupling.distance-own.avg` is 10.6/15 (all three
     * namespaces, global included) and the project formula uses that value;
     * the captured 0.999 would give a different result, and the
     * `leaves-no-global` unweighted scheme derives 0.4 — proof
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

        self::assertEqualsWithDelta(100.0 * 18.0 / (18.0 + (10.6 / 15.0) * 6.0), $current->scoreOf($project, 'health.coupling') ?? -1.0, 0.001);
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

    /** The upper and lower diagnostic populations are counted separately. */
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
    public function itKeepsLawfulComplementaryPoolingDiagnosticWithoutRejectingBindingCriteria(): void
    {
        $definitions = self::defaultDefinitions();
        $captures = array_map(self::complementaryCapture(...), [...FormalRanking::LOWER, ...FormalRanking::HIGHER]);
        $evaluations = array_map(static fn(Capture $capture): Evaluation => (new Bench())->run(
            $capture,
            $definitions,
            [SymbolLevel::Project, SymbolLevel::Namespace_, SymbolLevel::Class_],
            AggregationScheme::current(),
        ), $captures);
        $capture = $captures[0];
        $evaluation = $evaluations[0];
        $children = $capture->classesUnder($capture->symbols[1]);
        self::assertSame(50.0, $evaluation->scoreOf($children[0], 'health.cohesion'));
        self::assertSame(50.0, $evaluation->scoreOf($children[1], 'health.cohesion'));
        self::assertEqualsWithDelta(60.35533905932738, $evaluation->scoreOf($capture->symbols[1], 'health.cohesion') ?? -1.0, 1.0e-12);
        self::assertNotEmpty(Criteria::monotonicity([$evaluation]));
        $options = Options::parse(['--capture-dir=unused', '--levels=project,namespace,class']);
        ob_start();
        try {
            $exit = \Qualimetrix\HealthCalibration\reportCriteria($options, $evaluations, $definitions, $captures);
            $text = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertStringContainsString('C1 diagnostic', $text);
        self::assertStringContainsString('60.36', $text);
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

    #[Test]
    public function itJudgesAllPredeclaredSourceGroupsAndRejectsConstantHundred(): void
    {
        self::assertSame([10.0, 10.0], EarnedTop::values('cohesion.tcc-method-count', SymbolLevel::Class_));
        $defaults = self::defaultDefinitions();
        $rows = EarnedTop::judge($defaults);
        self::assertNotEmpty($rows);
        self::assertSame(['pass'], array_values(array_unique(array_column($rows, 'verdict'))));
        self::assertCount(18, array_unique(array_map(static fn(array $row): string => $row['level'] . ' ' . $row['dimension'], $rows)));
        $constant = array_map(static fn(ComputedMetricDefinition $definition): ComputedMetricDefinition => new ComputedMetricDefinition(
            name: $definition->name,
            formulas: array_fill_keys(array_keys($definition->formulas), '100'),
            description: 'constant mutation candidate',
            levels: $definition->levels,
            applicability: $definition->applicability,
        ), $defaults);
        $fakeRows = EarnedTop::judge($constant);
        self::assertCount(\count($rows), $fakeRows);
        self::assertSame(['fail'], array_values(array_unique(array_column($fakeRows, 'verdict'))));
    }

    #[Test]
    public function itFindsRealPerfectScoresMatchingFrozenAdverseProfiles(): void
    {
        $bad = new Subject('App\\Bad', SymbolLevel::Class_, ['complexity.ccn.max' => 11.0], [], 20, 'decl:bad');
        $empty = new Subject('App\\Empty', SymbolLevel::Class_, [], [], 1, 'decl:empty');
        $definition = new ComputedMetricDefinition('health.complexity', ['class' => '100'], 'constant candidate', [SymbolLevel::Class_]);
        $evaluation = (new Bench())->run(new Capture('fixture', [$bad, $empty], true), [$definition], [SymbolLevel::Class_], AggregationScheme::current());
        self::assertSame(['fixture class decl:bad health.complexity [complexity.ccn.max]'], EarnedTop::unearned([$evaluation]));

        $quotientDefinition = new ComputedMetricDefinition('health.complexity', ['project' => '100'], 'constant candidate', [SymbolLevel::Project]);
        $quotients = [];
        foreach (['legitimate' => 10000000.0, 'adverse' => 1000.0] as $id => $population) {
            $project = new Subject('', SymbolLevel::Project, ['complexity.ccn.sum' => 3000.0, 'size.symbol-method-count' => $population], [], null);
            $quotients[] = (new Bench())->run(new Capture($id, [$project], true), [$quotientDefinition], [SymbolLevel::Project], AggregationScheme::current());
        }
        self::assertSame(['adverse project  health.complexity [complexity.ccn.sum size.symbol-method-count]'], EarnedTop::unearned($quotients));

        $adjusted = new Subject('App\\Pure', SymbolLevel::Class_, ['cohesion.tcc' => 1.0, 'cohesion.lcom' => 6.0, 'cohesion.pure-method-count' => 10.0, 'size.method-count' => 10.0, 'cohesion.tcc-method-count' => 10.0], [], 20, 'decl:pure');
        $legitimate = (new Bench())->run(new Capture('pure', [$adjusted], true), self::defaultDefinitions(), [SymbolLevel::Class_], AggregationScheme::current());
        self::assertSame(100.0, $legitimate->scoreOf($adjusted, 'health.cohesion'));
        self::assertSame([], EarnedTop::unearned([$legitimate]));

    }

    #[Test]
    public function itSeparatesAbsentCohesionFromMeasuredZero(): void
    {
        $empty = new Subject('App\\Empty', SymbolLevel::Class_, [], [], 1, 'decl:empty');
        $zero = new Subject('App\\Zero', SymbolLevel::Class_, ['cohesion.tcc' => 0.0, 'cohesion.lcom' => 6.0], [], 1, 'decl:zero');
        $evaluation = (new Bench())->run(new Capture('fixture', [$empty, $zero], true), self::defaultDefinitions(), [SymbolLevel::Class_], AggregationScheme::current());
        self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->outcome($empty, 'health.cohesion')?->kind);
        self::assertNull($evaluation->scoreOf($empty, 'health.cohesion'));
        self::assertSame(0.0, $evaluation->scoreOf($zero, 'health.cohesion'));
        self::assertSame('measured', StatedCoverage::rows($evaluation, $zero, 'health.cohesion')[0]['state']);
    }

    #[Test]
    public function itStatesOneOfTenCallablesAndDoesNotBorrowClassLines(): void
    {
        $project = new Subject('', SymbolLevel::Project, [
            'size.symbol-method-count' => 10.0, 'size.symbol-class-count' => 1.0,
            'complexity.ccn.count' => 1.0, 'complexity.ccn.sum' => 1.0,
            'complexity.cognitive.count' => 1.0, 'complexity.cognitive.sum' => 0.0,
        ], [], 1000);
        $capture = new Capture('fixture', [$project,
            new Subject('App\\One', SymbolLevel::Class_, ['complexity.ccn.avg' => 1.0], [], 1000, 'decl:one'),
        ], true);
        $evaluation = (new Bench())->run($capture, self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::current());
        $rows = StatedCoverage::rows($evaluation, $project, 'health.complexity');
        self::assertSame('1/10 callables', $rows[0]['symbols']);
        self::assertSame('not-applicable: callable LOC is not measured', $rows[0]['loc']);
        self::assertSame('measured', $rows[0]['state']);
    }

    #[Test]
    public function itStatesMissingEmptyAndZeroCountWithoutAnApplicabilityCutoff(): void
    {
        $definition = new ComputedMetricDefinition('health.complexity', ['project' => 'm["complexity.ccn.sum"] ?? 0'], 'fixture', [SymbolLevel::Project]);
        foreach ([[10.0, 'not-measured'], [0.0, 'not-applicable']] as [$population, $expected]) {
            $project = new Subject('', SymbolLevel::Project, ['size.symbol-method-count' => $population], [], null);
            $evaluation = (new Bench())->run(new Capture('fixture', [$project], true), [$definition], [SymbolLevel::Project], AggregationScheme::current());
            self::assertSame($expected, StatedCoverage::rows($evaluation, $project, 'health.complexity')[0]['state']);
        }
        $project = new Subject('', SymbolLevel::Project, [], [], null);
        $evaluation = (new Bench())->run(new Capture('fixture', [$project], true), [$definition], [SymbolLevel::Project], AggregationScheme::current());
        self::assertSame('unjudged', StatedCoverage::rows($evaluation, $project, 'health.complexity')[0]['state']);
    }

    #[Test]
    public function itStatesClassLinesOnlyWithCompleteExactCarriers(): void
    {
        $project = new Subject('', SymbolLevel::Project, ['size.symbol-class-count' => 3.0, 'cohesion.tcc.count' => 2.0, 'cohesion.tcc.avg' => 0.5], [], null);
        $classes = [
            new Subject('App\\Small', SymbolLevel::Class_, ['cohesion.tcc' => 0.5], [], 10, 'decl:a'),
            new Subject('App\\AlsoSmall', SymbolLevel::Class_, ['cohesion.tcc' => 0.5], [], 10, 'decl:b'),
            new Subject('App\\Huge', SymbolLevel::Class_, [], [], 980, 'decl:c'),
        ];
        $capture = new Capture('fixture', [$project, ...$classes], true);
        $evaluation = (new Bench())->run($capture, self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::current());
        $row = StatedCoverage::rows($evaluation, $project, 'health.cohesion')[0];
        self::assertSame('2/3 classes', $row['symbols']);
        self::assertSame('20/1000 lines', $row['loc']);
        $classes[2] = new Subject('App\\Huge', SymbolLevel::Class_, [], [], null, 'decl:c');
        $incomplete = (new Bench())->run(new Capture('fixture', [$project, ...$classes], true), self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::current());
        self::assertStringStartsWith('unjudged:', StatedCoverage::rows($incomplete, $project, 'health.cohesion')[0]['loc']);
        self::assertSame('unjudged', StatedCoverage::rows($incomplete, $project, 'health.cohesion')[0]['state']);

        $namespaceProject = new Subject('', SymbolLevel::Project, ['size.symbol-declaring-namespace-count' => 2.0], [], null);
        $namespaceCapture = new Capture('namespace-lines', [$namespaceProject,
            new Subject('App', SymbolLevel::Namespace_, ['coupling.distance-own' => 0.2, 'size.class-count' => 1.0], [], 20, 'ns:App'),
            new Subject('(global)', SymbolLevel::Namespace_, ['coupling.distance-own' => 1.0, 'size.class-count' => 1.0], [], 80, 'ns:global'),
            new Subject('App\\A', SymbolLevel::Class_, [], [], 20, 'decl:a'),
            new Subject('Legacy', SymbolLevel::Class_, [], [], 80, 'decl:legacy'),
        ], true);
        $namespaceEvaluation = (new Bench())->run($namespaceCapture, self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::parse('leaves-no-global:none'));
        $namespaceRow = StatedCoverage::rows($namespaceEvaluation, $namespaceProject, 'health.coupling')[0];
        self::assertSame('1/2 namespaces declaring a type', $namespaceRow['symbols']);
        self::assertSame('20/100 lines', $namespaceRow['loc']);

    }

    #[Test]
    public function itStatesTypingInputCoverageFromExactClassesIncludingMeasuredZero(): void
    {
        $projectInputs = ['size.symbol-class-count' => 2.0];
        $classInputs = [];
        foreach (['param', 'return', 'property'] as $part) {
            $classInputs['design.type-coverage.' . $part . '.total'] = 1.0;
            $classInputs['design.type-coverage.' . $part . '.typed'] = 0.0;
            $projectInputs['design.type-coverage.' . $part . '.total.sum'] = 1.0;
            $projectInputs['design.type-coverage.' . $part . '.typed.sum'] = 0.0;
        }
        $project = new Subject('', SymbolLevel::Project, $projectInputs, [], null);
        $capture = new Capture('fixture', [$project,
            new Subject('App\\Measured', SymbolLevel::Class_, $classInputs, [], 10, 'decl:measured'),
            new Subject('App\\Missing', SymbolLevel::Class_, [], [], 90, 'decl:missing'),
        ], true);
        $evaluation = (new Bench())->run($capture, self::defaultDefinitions(), [SymbolLevel::Project], AggregationScheme::current());
        self::assertSame(0.0, $evaluation->scoreOf($project, 'health.typing'));
        $rows = StatedCoverage::rows($evaluation, $project, 'health.typing');
        self::assertCount(6, $rows);
        foreach ($rows as $row) {
            self::assertSame('1/2 classes', $row['symbols']);
            self::assertSame('10/100 lines', $row['loc']);
            self::assertSame('measured', $row['state']);
        }
    }

    #[Test]
    public function itRejectsDroppedMeasuredTccCarriersAsMachineCoverageFailure(): void
    {
        $options = Options::parse(['--capture-dir=unused', '--levels=namespace,class']);
        foreach (self::tccAggregateCases() as $case => [$aggregate, $hasTcc, $state, $expected]) {
            $class = new Subject('App\\B', SymbolLevel::Class_, [
                ...($hasTcc ? ['cohesion.tcc' => 0.0] : []), 'cohesion.lcom' => 1.0,
            ], [], 10, 'decl:B');
            $namespace = new Subject('App', SymbolLevel::Namespace_, [
                ...$aggregate, 'size.symbol-class-count' => 1.0, 'size.symbol-method-count' => $hasTcc ? 3.0 : 1.0,
                'cohesion.lcom.avg' => 1.0, 'cohesion.lcom.count' => 1.0,
            ], [], 10, 'ns:App');
            $evaluation = (new Bench())->run(new Capture('dropped-tcc', [$namespace, $class], true), self::defaultDefinitions(), [SymbolLevel::Namespace_, SymbolLevel::Class_], AggregationScheme::current());
            self::assertSame(isset($aggregate['cohesion.tcc.avg']) ? 50.0 : 100.0, $evaluation->scoreOf($namespace, 'health.cohesion'), $case);
            self::assertSame([], EarnedTop::unearned([$evaluation]), $case);
            $rows = StatedCoverage::rows($evaluation, $namespace, 'health.cohesion');
            self::assertSame($state, $rows[0]['state'], $case);
            ob_start();
            try {
                $agreed = \Qualimetrix\HealthCalibration\printCoverage([$evaluation], $options);
            } finally {
                ob_end_clean();
            }
            self::assertSame($expected, $agreed, $case);
        }
    }

    /** @return array<string, array{array<string, float>, bool, string, bool}> */
    private static function tccAggregateCases(): array
    {
        return [
            'measured zero' => [['cohesion.tcc.avg' => 0.0, 'cohesion.tcc.count' => 1.0], true, 'measured', true],
            'value dropped' => [['cohesion.tcc.count' => 1.0], true, 'unjudged', false],
            'value and count dropped' => [[], true, 'unjudged', false],
            'count dropped' => [['cohesion.tcc.avg' => 0.0], true, 'unjudged', false],
            'zero count with a value' => [['cohesion.tcc.avg' => 0.0, 'cohesion.tcc.count' => 0.0], true, 'unjudged', false],
            'valid absence' => [[], false, 'not-measured', true],
        ];
    }

    #[Test]
    public function itJudgesAggregateCoverageIndependentlyOfAnAbsentScore(): void
    {
        $options = Options::parse(['--capture-dir=unused', '--levels=namespace,class']);
        foreach ([false, true] as $nullable) {
            $definitions = self::defaultDefinitions();
            if ($nullable) {
                $definitions = array_map(static fn(ComputedMetricDefinition $definition): ComputedMetricDefinition => $definition->name !== 'health.cohesion' ? $definition : new ComputedMetricDefinition(
                    'health.cohesion',
                    ['class' => $definition->formulas['class'], 'namespace' => '(m["cohesion.tcc.avg"] ?? 0) >= 0 ? null : (m["cohesion.lcom.avg"] ?? 0)'],
                    'nullable coverage fixture',
                    $definition->levels,
                    applicability: ['class' => $definition->applicability['class']],
                ), $definitions);
            }
            foreach (self::absentScoreCoverageCases() as $case => [$aggregate, $carriers, $expectedState, $expectedAgreement]) {
                if (!$nullable && $aggregate !== []) {
                    continue;
                }
                $namespace = new Subject('App', SymbolLevel::Namespace_, ['size.symbol-class-count' => 1.0, 'size.symbol-method-count' => $carriers === [] ? 0.0 : 3.0, ...$aggregate], [], 10, 'ns:App');
                $class = new Subject('App\\B', SymbolLevel::Class_, $carriers, [], 10, 'decl:B');
                $evaluation = (new Bench())->run(new Capture($case, [$namespace, $class], true), $definitions, [SymbolLevel::Namespace_, SymbolLevel::Class_], AggregationScheme::current());
                self::assertNull($evaluation->scoreOf($namespace, 'health.cohesion'), $case);
                self::assertSame($nullable ? ComputedMetricOutcome::NO_VALUE : ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->outcome($namespace, 'health.cohesion')?->kind, $case);
                self::assertSame($expectedState, StatedCoverage::rows($evaluation, $namespace, 'health.cohesion')[0]['state'], $case);
                ob_start();
                try {
                    $agreed = \Qualimetrix\HealthCalibration\printCoverage([$evaluation], $options);
                } finally {
                    ob_end_clean();
                }
                self::assertSame($expectedAgreement, $agreed, $case);
            }
        }
    }

    /** @return array<string, array{array<string, float>, array<string, float>, string, bool}> */
    private static function absentScoreCoverageCases(): array
    {
        $carriers = ['cohesion.tcc' => 0.0, 'cohesion.lcom' => 1.0];
        $values = ['cohesion.tcc.avg' => 0.0, 'cohesion.lcom.avg' => 1.0];
        return [
            'lost all aggregates' => [[], $carriers, 'unjudged', false],
            'lawful absence' => [[], [], 'not-measured', true],
            'lost counts' => [$values, $carriers, 'unjudged', false],
            'coherent nullable inputs' => [[...$values, 'cohesion.tcc.count' => 1.0, 'cohesion.lcom.count' => 1.0], $carriers, 'measured', true],
        ];
    }

    #[Test]
    public function itRejectsTypingCoverageWhenClassLinesAreUnavailable(): void
    {
        $namespace = new Subject('App', SymbolLevel::Namespace_, [
            'size.symbol-class-count' => 1.0, 'design.type-coverage.param.total.sum' => 1.0,
            'design.type-coverage.param.typed.sum' => 1.0,
        ], [], null, 'ns:App');
        $class = new Subject('App\\A', SymbolLevel::Class_, [
            'design.type-coverage.param.total' => 1.0, 'design.type-coverage.param.typed' => 1.0,
        ], [], null, 'decl:A');
        $evaluation = (new Bench())->run(new Capture('missing-lines', [$namespace, $class], true), self::defaultDefinitions(), [SymbolLevel::Namespace_], AggregationScheme::current());
        $rows = StatedCoverage::rows($evaluation, $namespace, 'health.typing');
        self::assertSame('unjudged', $rows[0]['state']);
        $options = Options::parse(['--capture-dir=unused', '--levels=namespace']);
        ob_start();
        try {
            $agreed = \Qualimetrix\HealthCalibration\printCoverage([$evaluation], $options);
        } finally {
            ob_end_clean();
        }
        self::assertFalse($agreed);
    }

    #[Test]
    public function itRefusesCriteriaCoverageWithoutCompleteCaptureAndPrintsSelectedClass(): void
    {
        $subject = new Subject('App\\Empty', SymbolLevel::Class_, [], [], 7, 'decl:empty');
        $evaluation = (new Bench())->run(new Capture('legacy', [$subject]), self::defaultDefinitions(), [SymbolLevel::Class_], AggregationScheme::current());
        $options = Options::parse(['--capture-dir=unused', '--levels=class']);
        ob_start();
        $agreed = \Qualimetrix\HealthCalibration\printCoverage([$evaluation], $options);
        $text = (string) ob_get_clean();
        self::assertFalse($agreed);
        self::assertStringContainsString('coverage.complete=true', $text);
        self::assertStringContainsString('legacy class decl:empty health.cohesion', $text);
        self::assertStringContainsString('state=absent', $text);
        self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->outcome($subject, 'health.cohesion')?->kind);
    }

    #[Test]
    public function itJudgesExactlyTheTwentyFourFrozenPairsIncludingEquality(): void
    {
        $evaluations = [];
        foreach ([...FormalRanking::LOWER, ...FormalRanking::HIGHER, 'composer', 'laravel-framework', 'qmx'] as $id) {
            $project = new Subject('', SymbolLevel::Project, [], [], null);
            $evaluation = new Evaluation(new Capture($id, [$project], true), AggregationScheme::current(), [
                Evaluation::key($project) => ['health.overall' => new DimensionOutcome('health.overall', \in_array($id, ['composer', 'laravel-framework', 'qmx'], true) ? 0.0 : 50.0, [], [], [])],
            ], []);
            $evaluations[$id] = $evaluation;
        }
        $rows = FormalRanking::judge(array_values($evaluations));
        self::assertCount(24, $rows);
        self::assertSame(['pass'], array_values(array_unique(array_column($rows, 'verdict'))));
        unset($evaluations['flysystem']);
        self::assertCount(2, array_filter(FormalRanking::judge(array_values($evaluations)), static fn(array $row): bool => $row['verdict'] === 'unjudged'));
        $project = $evaluations['wordpress']->capture->project();
        self::assertNotNull($project);
        $evaluations['wordpress'] = new Evaluation($evaluations['wordpress']->capture, AggregationScheme::current(), [Evaluation::key($project) => ['health.overall' => new DimensionOutcome('health.overall', 51.0, [], [], [])]], []);
        self::assertCount(11, array_filter(FormalRanking::judge(array_values($evaluations)), static fn(array $row): bool => $row['verdict'] === 'fail'));
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
            new HealthFormulaExcluder(new ComputedMetricExpression()),
        ))->resolve(DocumentComposer::compose(new DocumentSchema([new ComputedMetricsSection(), new ExcludeHealthSection()]), []));
    }

    private static function complementaryCapture(string $id): Capture
    {
        $pooled = [
            'size.symbol-class-count' => 3.0, 'size.symbol-method-count' => 10.0, 'cohesion.tcc.avg' => 0.5, 'cohesion.tcc.count' => 2.0,
            'cohesion.lcom.avg' => 3.5, 'cohesion.lcom.count' => 2.0,
        ];
        return new Capture($id, [
            new Subject('', SymbolLevel::Project, [
                ...$pooled, 'size.symbol-declaring-namespace-count' => 2.0,
                'coupling.distance-own.avg' => 1.4 / 3.0, 'coupling.distance-own.count' => 2.0,
            ], [], 35, 'project:'),
            new Subject('App', SymbolLevel::Namespace_, [
                ...$pooled, 'size.class-count' => 1.0, 'coupling.distance-own' => 0.2,
                'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0,
            ], [], 35, 'ns:App'),
            new Subject('App\\Inner', SymbolLevel::Namespace_, [
                'size.symbol-class-count' => 2.0, 'size.symbol-method-count' => 3.0, 'size.class-count' => 2.0,
                'cohesion.tcc.avg' => 0.0, 'cohesion.tcc.count' => 1.0,
                'cohesion.lcom.avg' => 1.0, 'cohesion.lcom.count' => 1.0,
                'coupling.distance-own' => 0.6, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0,
            ], [], 25, 'ns:App\\Inner'),
            new Subject('App\\A', SymbolLevel::Class_, [
                'cohesion.tcc' => 1.0, 'cohesion.lcom' => 6.0, 'cohesion.pure-method-count' => 0.0,
            ], [], 10, 'decl:A'),
            new Subject('App\\Inner\\B', SymbolLevel::Class_, [
                'cohesion.tcc' => 0.0, 'cohesion.lcom' => 1.0, 'cohesion.pure-method-count' => 0.0,
            ], [], 20, 'decl:B'),
            new Subject('App\\Inner\\Empty', SymbolLevel::Class_, [], [], 5, 'decl:Empty'),
        ], true);
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
            self::namespaceSubject('App\A', ['coupling.distance-own' => 0.2, 'size.class-count' => 1.0, 'size.class-count.sum' => 1.0, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0], 100),
            self::namespaceSubject('App\B', ['coupling.distance-own' => 0.6, 'size.class-count' => 9.0, 'size.class-count.sum' => 9.0, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0], 300),
            self::namespaceSubject('(global)', ['coupling.distance-own' => 1.0, 'size.class-count' => 5.0, 'size.class-count.sum' => 5.0, 'coupling.ca-own' => 0.0, 'coupling.ce-own' => 1.0], 500),
        ]);
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
    #[Test]
    public function itPreservesUnchangedApplicableFormulasWithStrictFloatingPointEquality(): void
    {
        $before = json_decode(<<<'JSON'
{
    "health.complexity": {
        "class": "clamp(100 - max((m[\"complexity.ccn.avg\"] ?? 1) - 2, 0) * 2.0 - max((m[\"complexity.cognitive.avg\"] ?? 0) - 1, 0) * 2.0 - max((m[\"complexity.ccn.max\"] ?? 0) - 10, 0) ** 0.5 * 2.0 - max((m[\"complexity.cognitive.max\"] ?? 0) - 15, 0) ** 0.5 * 2.0, 0, 100)",
        "namespace": "clamp(100 - max((m[\"complexity.ccn.sum\"] ?? 0) / max(m[\"size.symbol-method-count\"], 1) - 2, 0) * 5.0 - max((m[\"complexity.cognitive.sum\"] ?? 0) / max(m[\"size.symbol-method-count\"], 1) - 1, 0) * 4.0 - max((m[\"complexity.ccn.p95\"] ?? 0) - 5, 0) ** 0.5 * 3.0 - max((m[\"complexity.cognitive.p95\"] ?? 0) - 6, 0) ** 0.5 * 3.0 - max((m[\"complexity.ccn.max\"] ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)",
        "project": "clamp(100 - max((m[\"complexity.ccn.sum\"] ?? 0) / max(m[\"size.symbol-method-count\"], 1) - 2, 0) * 5.0 - max((m[\"complexity.cognitive.sum\"] ?? 0) / max(m[\"size.symbol-method-count\"], 1) - 1, 0) * 4.0 - max((m[\"complexity.ccn.p95\"] ?? 0) - 5, 0) ** 0.5 * 3.0 - max((m[\"complexity.cognitive.p95\"] ?? 0) - 6, 0) ** 0.5 * 3.0 - max((m[\"complexity.ccn.max\"] ?? 0) - 20, 0) ** 0.5 * 0.8, 0, 100)"
    },
    "health.typing": {
        "class": "clamp(m[\"design.type-coverage.all\"] ?? 0, 0, 100)",
        "namespace": "(((m[\"design.type-coverage.param.total.sum\"] ?? 0) + (m[\"design.type-coverage.return.total.sum\"] ?? 0) + (m[\"design.type-coverage.property.total.sum\"] ?? 0)) == 0) ? 100 : clamp(((m[\"design.type-coverage.param.typed.sum\"] ?? 0) + (m[\"design.type-coverage.return.typed.sum\"] ?? 0) + (m[\"design.type-coverage.property.typed.sum\"] ?? 0)) / ((m[\"design.type-coverage.param.total.sum\"] ?? 0) + (m[\"design.type-coverage.return.total.sum\"] ?? 0) + (m[\"design.type-coverage.property.total.sum\"] ?? 0)) * 100, 0, 100)",
        "project": "(((m[\"design.type-coverage.param.total.sum\"] ?? 0) + (m[\"design.type-coverage.return.total.sum\"] ?? 0) + (m[\"design.type-coverage.property.total.sum\"] ?? 0)) == 0) ? 100 : clamp(((m[\"design.type-coverage.param.typed.sum\"] ?? 0) + (m[\"design.type-coverage.return.typed.sum\"] ?? 0) + (m[\"design.type-coverage.property.typed.sum\"] ?? 0)) / ((m[\"design.type-coverage.param.total.sum\"] ?? 0) + (m[\"design.type-coverage.return.total.sum\"] ?? 0) + (m[\"design.type-coverage.property.total.sum\"] ?? 0)) * 100, 0, 100)"
    },
    "health.maintainability": {
        "class": "clamp(100 - max(85 - (m[\"maintainability.mi.avg\"] ?? 75), 0) * 1.5 - max(65 - (m[\"maintainability.mi.min\"] ?? 65), 0) ** 0.5 * 3.0, 0, 100)",
        "namespace": "clamp(100 - max(85 - (m[\"maintainability.mi.avg\"] ?? 75), 0) * 2.5 - max(65 - (m[\"maintainability.mi.p5\"] ?? 65), 0) ** 0.5 * 4.5 - max(5 - (m[\"maintainability.mi.min\"] ?? 5), 0) ** 0.4 * 1.5, 0, 100)",
        "project": "clamp(100 - max(85 - (m[\"maintainability.mi.avg\"] ?? 75), 0) * 2.5 - max(65 - (m[\"maintainability.mi.p5\"] ?? 65), 0) ** 0.5 * 4.5 - max(5 - (m[\"maintainability.mi.min\"] ?? 5), 0) ** 0.4 * 1.5, 0, 100)"
    }
}
JSON, true, flags: \JSON_THROW_ON_ERROR);
        $expression = new ComputedMetricExpression();
        $evaluation = new ComputedMetricSubjectEvaluation($expression);
        $defaults = ComputedMetricDefaults::getDefaults();
        $measured = [];
        foreach ($before as $formulas) {
            foreach ($formulas as $formula) {
                foreach ($expression->keysOf($formula) as $key) {
                    if (!ComputedMetricExpression::isComputedReference($key)) {
                        $measured[$key] = 0;
                    }
                }
            }
        }
        foreach ([
            [
                'size.symbol-method-count' => 10, 'size.method-count' => 8,
                'cohesion.tcc' => 1, 'cohesion.lcom' => 1, 'cohesion.tcc.avg' => 1, 'cohesion.lcom.avg' => 1,
                'maintainability.mi.avg' => 100, 'maintainability.mi.min' => 100, 'maintainability.mi.p5' => 100,
                'design.type-coverage.param.total.sum' => 1, 'design.type-coverage.param.typed.sum' => 1, 'design.type-coverage.all' => 100,
            ],
            [
                'size.symbol-method-count' => 10, 'size.method-count' => 8, 'cohesion.pure-method-count' => 2,
                'cohesion.tcc' => 0.49, 'cohesion.lcom' => 3, 'cohesion.tcc.avg' => 0.49, 'cohesion.lcom.avg' => 2,
                'complexity.ccn.avg' => 3, 'complexity.cognitive.avg' => 2, 'complexity.ccn.max' => 8, 'complexity.cognitive.max' => 20,
                'complexity.ccn.sum' => 30, 'complexity.cognitive.sum' => 20, 'complexity.ccn.p95' => 5, 'complexity.cognitive.p95' => 6,
                'coupling.ce-packages' => 2, 'coupling.ce' => 10, 'coupling.distance' => 0.5, 'coupling.distance-own.avg' => 0.4,
                'coupling.ce-packages.avg' => 2, 'coupling.ce.avg' => 10, 'coupling.ce.max' => 40,
                'coupling.cbo.avg' => 10, 'coupling.cbo.p95' => 18, 'coupling.cbo.max' => 40,
                'maintainability.mi.avg' => 75, 'maintainability.mi.min' => 30, 'maintainability.mi.p5' => 60,
                'design.type-coverage.param.total.sum' => 3, 'design.type-coverage.param.typed.sum' => 2,
                'design.type-coverage.return.total.sum' => 2, 'design.type-coverage.return.typed.sum' => 1,
                'design.type-coverage.property.total.sum' => 1, 'design.type-coverage.property.typed.sum' => 1, 'design.type-coverage.all' => 80,
            ],
            [
                'size.symbol-method-count' => 10, 'size.method-count' => 2, 'cohesion.pure-method-count' => 1,
                'cohesion.tcc' => 0, 'cohesion.lcom' => 8, 'cohesion.tcc.avg' => 0, 'cohesion.lcom.avg' => 4,
                'complexity.ccn.avg' => 10, 'complexity.cognitive.avg' => 15, 'complexity.ccn.max' => 100, 'complexity.cognitive.max' => 200,
                'complexity.ccn.sum' => 100, 'complexity.cognitive.sum' => 150, 'complexity.ccn.p95' => 20, 'complexity.cognitive.p95' => 30,
                'coupling.ce-packages' => 10, 'coupling.ce' => 100, 'coupling.distance' => 1, 'coupling.distance-own.avg' => 1,
                'coupling.ce-packages.avg' => 10, 'coupling.ce.avg' => 100, 'coupling.ce.max' => 100,
                'coupling.cbo.avg' => 100, 'coupling.cbo.p95' => 200, 'coupling.cbo.max' => 300,
                'design.type-coverage.param.total.sum' => 1,
            ],
        ] as $point => $facts) {
            foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
                $values = [...$measured, ...$facts];
                foreach ($before as $name => $formulas) {
                    $expected = $expression->evaluate($formulas[$level->value], ['m' => new MetricLookup($values)]);
                    $actual = $evaluation->evaluate($defaults[$name], $level, $values);
                    $context = $name . ':' . $level->value . ':' . $point;
                    self::assertSame(ComputedMetricOutcome::VALUE, $actual->kind, $context);
                    self::assertSame($expected, $actual->value, $context);
                    $values[$name] = $actual->value;
                }
            }
        }
    }

}
