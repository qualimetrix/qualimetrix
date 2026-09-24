<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricBranchTrace;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Stringable;

#[CoversClass(ComputedMetricEvaluator::class)]
#[CoversClass(ComputedMetricBranchTrace::class)]
final class ComputedMetricEvaluatorTest extends TestCase
{
    #[Test]
    public function itLeavesTheRepositoryUntouchedWhenGivenNoDefinitions(): void
    {
        $repo = new InMemoryMetricRepository();
        $this->evaluate($repo, []);

        self::assertSame([], $repo->get(SymbolPath::forProject())->all());
    }

    #[Test]
    public function itEvaluatesASimpleClassLevelFormula(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'complexity.ccn.avg' => 3.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'm["complexity.ccn.avg"] * 10'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        $result = $repo->get($classPath)->get('health.test');
        self::assertSame(30.0, $result);
    }

    #[Test]
    public function itEvaluatesDependentMetricsInTopologicalOrderRegardlessOfInputOrder(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'complexity.ccn.avg' => 5.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        // Define B first, which depends on A — evaluator should sort them
        $defB = new ComputedMetricDefinition(
            name: 'health.b',
            formulas: ['class' => 'm["health.a"] * 2'],
            description: 'Depends on A',
            levels: [SymbolLevel::Class_],
        );
        $defA = new ComputedMetricDefinition(
            name: 'health.a',
            formulas: ['class' => 'm["complexity.ccn.avg"] + 1'],
            description: 'Base metric',
            levels: [SymbolLevel::Class_],
        );

        // Pass B before A — topological sort should fix the order
        $this->evaluate($repo, [$defB, $defA]);

        $bag = $repo->get($classPath);
        self::assertSame(6.0, $bag->get('health.a'));
        self::assertSame(12.0, $bag->get('health.b'));
    }

    #[Test]
    public function itRefusesAFormulaReferencingAnUnknownMetricWithoutAFallback(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray(['known' => 1.0]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'm["missing_var"] * 10'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        // A ConfigurationRefusal, not a plain RuntimeException: this is a
        // mistake in `qmx.yaml`, and only that kind carries exit code 3.
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "missing_var" at level "class", where no symbol carries it');

        $this->evaluate($repo, [$definition]);
    }

    #[Test]
    public function itListsAllUnknownMetricsReferencedByAFormula(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray(['known' => 1.0]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'm["known"] + m["foo"] + m["bar"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "foo", "bar" at level "class", where no symbol carries them');

        $this->evaluate($repo, [$definition]);
    }

    /**
     * A fallback chain that starts at another computed metric the level does
     * not carry and ends at a measured key the level does not carry reads
     * nothing any symbol has. Configuration cannot see it — which levels carry
     * a measured key is a fact of the run — so it is refused here, naming both
     * keys, rather than skipping every symbol with a warning.
     */
    #[Test]
    public function itRefusesAFallbackChainWhereNoSymbolAtTheLevelCarriesAnyLink(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App', 'Svc');
        $repo->add($classPath, MetricBag::fromArray(['size.method-count' => 2]), RelativePath::fromString('src/Svc.php'), 1);
        $repo->add(SymbolPath::forProject(), MetricBag::fromArray(['size.loc' => 10]), null, null);

        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('reads "computed.cls-only", "size.method-count" at level "project", where no symbol carries them');

        $this->evaluate($repo, [
            new ComputedMetricDefinition(
                name: 'computed.cls-only',
                formulas: ['class' => 'm["size.method-count"] + 1'],
                description: 'Class only',
                levels: [SymbolLevel::Class_],
            ),
            new ComputedMetricDefinition(
                name: 'computed.reader',
                formulas: ['project' => 'm["computed.cls-only"] ?? m["size.method-count"]'],
                description: 'Reads a chain the project level carries no link of',
                levels: [SymbolLevel::Project],
            ),
        ]);
    }

    #[Test]
    public function itAcceptsAMetricPresentOnlyOnSomeSymbolsAsKnown(): void
    {
        $repo = new InMemoryMetricRepository();

        // Class A has 'complexity.ccn', class B does not — but union includes 'complexity.ccn', so formula is valid
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $repo->add($classA, MetricBag::fromArray(['complexity.ccn' => 5.0]), RelativePath::fromString('src/ClassA.php'), 1);
        $repo->add($classB, MetricBag::fromArray([]), RelativePath::fromString('src/ClassB.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => '(m["complexity.ccn"] ?? 0) * 10'],
            description: 'Test with partial data',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(50.0, $repo->get($classA)->get('health.test'));
        self::assertSame(0.0, $repo->get($classB)->get('health.test'));
    }

    /**
     * An unguarded read of a key this symbol does not carry used to reach the
     * arithmetic as `null`, which PHP coerces to 0. The zero was published as a
     * measurement and scored the symbol; with `inverted: true` it raised a
     * finding of severity error. A formula that asked without `??` gets no
     * value, and the run says which symbol and which key.
     */
    #[Test]
    public function itPublishesNoValueForASymbolMissingAnUnguardedMetric(): void
    {
        $repo = new InMemoryMetricRepository();
        $rich = SymbolPath::forClass('App', 'Rich');
        $bare = SymbolPath::forClass('App', 'Bare');
        $repo->add($rich, MetricBag::fromArray(['cohesion.tcc' => 1.0]), RelativePath::fromString('src/Rich.php'), 1);
        $repo->add($bare, MetricBag::fromArray([]), RelativePath::fromString('src/Bare.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] * 100'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([$definition]);
        (new ComputedMetricEvaluator($catalog, self::createStub(ProfilerInterface::class), $logger))
            ->evaluate($repo, 1);

        self::assertSame(100.0, $repo->get($rich)->get('computed.probe'));
        self::assertNull($repo->get($bare)->get('computed.probe'));
        self::assertCount(1, $logger->contexts);
        self::assertSame(1, $logger->contexts[0]['skipped']);
        self::assertSame('App\\Bare', $logger->contexts[0]['symbols']);
        self::assertSame('cohesion.tcc', $logger->contexts[0]['missing']);
    }

    /**
     * The right side of `??` is read only where the left is absent. Treating it
     * as required skipped a symbol that carried the left side, and the value
     * the formula computes there was lost.
     */
    #[Test]
    public function itPublishesTheLeftSideOfAFallbackBetweenMetricsWhereOnlyTheLeftIsPresent(): void
    {
        $repo = new InMemoryMetricRepository();
        $tccOnly = SymbolPath::forClass('App', 'TccOnly');
        $lccOnly = SymbolPath::forClass('App', 'LccOnly');
        $repo->add($tccOnly, MetricBag::fromArray(['cohesion.tcc' => 0.5]), RelativePath::fromString('src/TccOnly.php'), 1);
        $repo->add($lccOnly, MetricBag::fromArray(['cohesion.lcc' => 0.7]), RelativePath::fromString('src/LccOnly.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] ?? m["cohesion.lcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.5, $repo->get($tccOnly)->get('computed.probe'));
        self::assertSame(0.7, $repo->get($lccOnly)->get('computed.probe'));
        self::assertSame([], $logger->records);
    }

    /**
     * Where neither side of the fallback is present, the formula's value is
     * `null`; that is a symbol with no value, and the run names both keys the
     * formula looked for.
     */
    #[Test]
    public function itSkipsASymbolCarryingNeitherSideOfAFallbackBetweenMetrics(): void
    {
        $repo = new InMemoryMetricRepository();
        $tccOnly = SymbolPath::forClass('App', 'TccOnly');
        $bare = SymbolPath::forClass('App', 'Bare');
        $repo->add($tccOnly, MetricBag::fromArray(['cohesion.tcc' => 0.5, 'cohesion.lcc' => 0.7]), RelativePath::fromString('src/TccOnly.php'), 1);
        $repo->add($bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] ?? m["cohesion.lcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.5, $repo->get($tccOnly)->get('computed.probe'));
        self::assertNull($repo->get($bare)->get('computed.probe'));
        self::assertCount(1, $logger->records);
        self::assertSame('App\\Bare', $logger->records[0]['context']['symbols']);
        self::assertSame('cohesion.tcc, cohesion.lcc', $logger->records[0]['context']['missing']);
    }

    /**
     * One line per metric and level, not per symbol: a formula that misses on
     * most of a large project would otherwise flood the log. The line says
     * how many symbols got no value, names some, and does not claim they
     * carry none of the formula's metrics — one missing key is enough.
     */
    #[Test]
    public function itReportsSkippedSymbolsOnceForTheMetricAndLevel(): void
    {
        $repo = new InMemoryMetricRepository();
        $repo->add(SymbolPath::forClass('App', 'Rich'), MetricBag::fromArray(['cohesion.tcc' => 1.0, 'size.loc' => 1]), RelativePath::fromString('src/Rich.php'), 1);
        foreach (range(1, 7) as $i) {
            $repo->add(SymbolPath::forClass('App', 'Bare' . $i), MetricBag::fromArray(['size.loc' => 1]), RelativePath::fromString('src/Bare' . $i . '.php'), 1);
        }

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] * m["size.loc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertCount(1, $logger->records);
        self::assertSame(
            'Computed metric published no value for symbols lacking a metric its formula reads without a "??" fallback',
            $logger->records[0]['message'],
        );
        self::assertSame(7, $logger->records[0]['context']['skipped']);
        self::assertSame('App\\Bare1, App\\Bare2, App\\Bare3, App\\Bare4, App\\Bare5 (and 2 more)', $logger->records[0]['context']['symbols']);
        self::assertSame('cohesion.tcc', $logger->records[0]['context']['missing']);
    }

    /**
     * A null the inner `??` hands on is caught by the outer one: nothing in
     * `(a ?? b) ?? 0` is ever read as null by the arithmetic.
     */
    #[Test]
    public function itTreatsAParenthesisedFallbackChainAsGuardedToItsLastLink(): void
    {
        $repo = new InMemoryMetricRepository();
        $bare = SymbolPath::forClass('App', 'Bare');
        $rich = SymbolPath::forClass('App', 'Rich');
        $repo->add($bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);
        $repo->add($rich, MetricBag::fromArray(['cohesion.lcc' => 0.7]), RelativePath::fromString('src/Rich.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '((m["cohesion.tcc"] ?? m["cohesion.lcc"]) ?? 0) + 1'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(1.0, $repo->get($bare)->get('computed.probe'));
        self::assertSame(1.7, $repo->get($rich)->get('computed.probe'));
        self::assertSame([], $logger->records);
    }

    /**
     * A key only the branch not taken reads is never read. Requiring it
     * skipped a class with one method, which publishes no TCC, although the
     * formula takes the literal branch there — and where no class at the
     * level carried the key, the whole run was refused.
     */
    #[Test]
    public function itPublishesTheBranchATernaryTakesWithoutTheKeyOnlyTheOtherBranchReads(): void
    {
        $repo = new InMemoryMetricRepository();
        $single = SymbolPath::forClass('App', 'Single');
        $empty = SymbolPath::forClass('App', 'Empty');
        $repo->add($single, MetricBag::fromArray(['size.method-count' => 1]), RelativePath::fromString('src/Single.php'), 1);
        $repo->add($empty, MetricBag::fromArray(['size.method-count' => 0, 'cohesion.tcc' => 0.25]), RelativePath::fromString('src/Empty.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? 7 : m["cohesion.tcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(7.0, $repo->get($single)->get('computed.probe'));
        self::assertSame(0.25, $repo->get($empty)->get('computed.probe'));
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function itDoesNotRefuseAKeyNoSymbolCarriesWhereOnlyABranchReadsIt(): void
    {
        $repo = new InMemoryMetricRepository();
        $single = SymbolPath::forClass('App', 'Single');
        $repo->add($single, MetricBag::fromArray(['size.method-count' => 1]), RelativePath::fromString('src/Single.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? 7 : m["cohesion.tcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(7.0, $repo->get($single)->get('computed.probe'));
        self::assertSame([], $logger->records);
    }

    /**
     * The branch the evaluation took reads an absent key: its `null` reached
     * the arithmetic and the result is not a measurement. Judged by the branch
     * the evaluation actually ran, so the level carrying the key nowhere is a
     * per-symbol skip with its warning, not a refusal.
     */
    #[Test]
    public function itPublishesNoValueWhereTheTakenBranchReadsAnAbsentKey(): void
    {
        $repo = new InMemoryMetricRepository();
        $guarded = SymbolPath::forClass('App', 'Guarded');
        $reached = SymbolPath::forClass('App', 'Reached');
        $repo->add($guarded, MetricBag::fromArray(['size.method-count' => 0]), RelativePath::fromString('src/Guarded.php'), 1);
        $repo->add($reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? m["cohesion.tcc"] / m["size.method-count"] : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.0, $repo->get($guarded)->get('computed.probe'));
        self::assertNull($repo->get($reached)->get('computed.probe'));
        self::assertCount(1, $logger->records);
        self::assertSame(1, $logger->records[0]['context']['skipped']);
        self::assertSame('App\\Reached', $logger->records[0]['context']['symbols']);
        self::assertSame('cohesion.tcc', $logger->records[0]['context']['missing']);
    }

    /**
     * The same judgement for the right side of `and`: it runs only where the
     * left is true.
     */
    #[Test]
    public function itJudgesTheRightSideOfAndByWhetherTheEvaluationReachedIt(): void
    {
        $repo = new InMemoryMetricRepository();
        $shortCircuited = SymbolPath::forClass('App', 'ShortCircuited');
        $reached = SymbolPath::forClass('App', 'Reached');
        $repo->add($shortCircuited, MetricBag::fromArray(['size.method-count' => 0]), RelativePath::fromString('src/ShortCircuited.php'), 1);
        $repo->add($reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '(m["size.method-count"] > 0 and m["cohesion.tcc"] < 0.5) ? 1 : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.0, $repo->get($shortCircuited)->get('computed.probe'));
        self::assertNull($repo->get($reached)->get('computed.probe'));
        self::assertCount(1, $logger->records);
        self::assertSame('App\\Reached', $logger->records[0]['context']['symbols']);
    }

    /**
     * The condition always runs: `null > 0` is false, so an absent key there
     * would choose a branch on nothing.
     */
    #[Test]
    public function itPublishesNoValueWhereTheConditionReadsAnAbsentKey(): void
    {
        $repo = new InMemoryMetricRepository();
        $rich = SymbolPath::forClass('App', 'Rich');
        $bare = SymbolPath::forClass('App', 'Bare');
        $repo->add($rich, MetricBag::fromArray(['cohesion.tcc' => 0.75]), RelativePath::fromString('src/Rich.php'), 1);
        $repo->add($bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] > 0.5 ? 1 : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(1.0, $repo->get($rich)->get('computed.probe'));
        self::assertNull($repo->get($bare)->get('computed.probe'));
        self::assertSame('cohesion.tcc', $logger->records[0]['context']['missing']);
    }

    /**
     * A `null` the taken branch hands to an enclosing `??` is caught there,
     * the same as a read on the left of `??`.
     */
    #[Test]
    public function itLetsAnEnclosingFallbackCatchTheNullOfTheTakenBranch(): void
    {
        $repo = new InMemoryMetricRepository();
        $bare = SymbolPath::forClass('App', 'Bare');
        $repo->add($bare, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Bare.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '(m["size.method-count"] > 0 ? m["cohesion.tcc"] : 1) ?? 5'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(5.0, $repo->get($bare)->get('computed.probe'));
        self::assertSame([], $logger->records);
    }

    /**
     * The branch is judged when the evaluation enters it, before it runs: a
     * `null` handed to a PHP function there is a deprecation printed into the
     * report's own output, and a result nobody may publish.
     */
    #[Test]
    public function itNeverRunsTheBranchItEntersWithAnAbsentKey(): void
    {
        $repo = new InMemoryMetricRepository();
        $reached = SymbolPath::forClass('App', 'Reached');
        $repo->add($reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
                name: 'computed.probe',
                formulas: ['class' => 'm["size.method-count"] > 0 ? sqrt(m["cohesion.tcc"]) : 0'],
                description: 'Test metric',
                levels: [SymbolLevel::Class_],
            )]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised);
        self::assertNull($repo->get($reached)->get('computed.probe'));
        self::assertSame('cohesion.tcc', $logger->records[0]['context']['missing']);
    }

    /**
     * A ternary inside the entered branch whose every branch reads the absent
     * key stops the evaluation at the outer entry; the key is still named.
     */
    #[Test]
    public function itNamesTheKeyANestedTernaryReadsOnEveryPath(): void
    {
        $repo = new InMemoryMetricRepository();
        $reached = SymbolPath::forClass('App', 'Reached');
        $repo->add($reached, MetricBag::fromArray(['size.method-count' => 4, 'size.loc' => 10]), RelativePath::fromString('src/Reached.php'), 1);

        $logger = $this->evaluateLogging($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? (m["size.loc"] > 5 ? m["cohesion.tcc"] * 2 : m["cohesion.tcc"] * 3) : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertNull($repo->get($reached)->get('computed.probe'));
        self::assertCount(1, $logger->records);
        self::assertSame('cohesion.tcc', $logger->records[0]['context']['missing']);
    }

    #[Test]
    public function itUsesTheNullCoalescingFallbackForAMissingMetric(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => '(m["missing_var"] ?? 42) * 2'],
            description: 'Test metric with fallback',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(84.0, $repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function itDoesNotStoreANanFormulaResult(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'value' => -1.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'sqrt(m["value"])'],
            description: 'NaN test',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertNull($repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function itDoesNotStoreAnInfiniteFormulaResult(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'value' => 0.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'log(m["value"])'],
            description: 'Infinity test',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertNull($repo->get($classPath)->get('health.test'));
    }

    #[Test]
    public function itComputesTheDefaultHealthScoresAtClassLevel(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([
            'complexity.ccn.avg' => 4.0,
            'complexity.cognitive.avg' => 6.0,
            'complexity.npath.avg' => 10.0,
            'cohesion.tcc' => 0.6,
            'cohesion.lcom' => 2.0,
            'coupling.cbo' => 8.0,
            'coupling.ce' => 6.0,
            'design.type-coverage.all' => 80.0,
            'design.dit' => 2.0,
            'maintainability.mi.avg' => 65.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        $bag = $repo->get($classPath);

        // health.complexity = clamp(100 - max(4-2,0)*2.0 - max(6-1,0)*2.0 - max(0-10,0)^0.5*2.0 - max(0-15,0)^0.5*2.0, 0, 100)
        //                   = 100 - 4.0 - 10.0 - 0 - 0 = 86.0
        self::assertEqualsWithDelta(86.0, $bag->get('health.complexity'), 0.01);

        // health.cohesion = clamp(sqrt(0.6)*50 + (1 - clamp((2-1)/5, 0, 1)) * 50, 0, 100)
        //                 = 0.7746*50 + 0.8*50 = 38.73 + 40 = 78.73
        self::assertEqualsWithDelta(78.73, $bag->get('health.cohesion'), 0.01);

        // health.coupling = clamp(100 * 15 / (15 + max(0*3.0 + 6^0.5*0.5 - 5, 0)), 0, 100)
        //                 = 100 * 15 / (15 + max(-3.78, 0)) = 100.0 (no ce_packages in test data)
        self::assertEqualsWithDelta(100.0, $bag->get('health.coupling'), 0.01);

        // health.typing = clamp(80, 0, 100) = 80
        self::assertEqualsWithDelta(80.0, $bag->get('health.typing'), 0.01);

        // health.maintainability = clamp(100 - max(85-65,0)*1.5 - max(50-50,0)^0.5*3.0, 0, 100)
        //                       = 100 - 30 - 0 = 70.0
        self::assertEqualsWithDelta(70.0, $bag->get('health.maintainability'), 0.01);

        // health.overall = clamp(86.0*0.35 + 78.73*0.25 + 100.0*0.25 + 80*0.15, 0, 100)
        //                = 30.1 + 19.6825 + 25.0 + 12.0 = 86.78
        self::assertEqualsWithDelta(86.78, $bag->get('health.overall'), 0.01);
    }

    #[Test]
    public function itBoostsCohesionHealthForClassesWithPureMethods(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Rules', 'DistanceRule');
        $repo->add($classPath, MetricBag::fromArray([
            'cohesion.tcc' => 0.0,
            'cohesion.lcom' => 5.0,
            'size.method-count' => 5,
            'cohesion.pure-method-count' => 4,
            'coupling.ce' => 3.0,
            'design.type-coverage.all' => 100.0,
        ]), RelativePath::fromString('src/DistanceRule.php'), 10);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        $bag = $repo->get($classPath);

        // tcc_adj = 0.0 + (1 - 0.0) * (4/5) * 0.4 = 0.32
        // lcom_adj = max(5 - 4*0.7, 1) = max(2.2, 1) = 2.2
        // cohesion = clamp(sqrt(0.32)*50 + (1 - clamp((2.2-1)/5, 0, 1))*50, 0, 100)
        //          = 0.5657*50 + (1 - 0.24)*50 = 28.28 + 38.0 = 66.28
        self::assertEqualsWithDelta(66.28, $bag->get('health.cohesion'), 0.5);

        // Without pureMethodCount boost, cohesion would be:
        // sqrt(0.0)*50 + (1 - clamp(4/5,0,1))*50 = 0 + 10 = 10.0
        // The boost raises it from 10 to ~66 — significant improvement
        self::assertGreaterThan(50.0, $bag->get('health.cohesion'));
    }

    #[Test]
    public function itComputesTheDefaultHealthScoresAtNamespaceLevel(): void
    {
        $repo = new InMemoryMetricRepository();

        // Add a class so the namespace is registered
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/UserService.php'), 10);

        // Add namespace-level metrics
        $nsPath = SymbolPath::forNamespace('App\\Service');
        $repo->add($nsPath, MetricBag::fromArray([
            'complexity.ccn.avg' => 3.0,
            'complexity.ccn.sum' => 30.0,
            'complexity.cognitive.avg' => 4.0,
            'complexity.cognitive.sum' => 40.0,
            MetricName::SIZE_SYMBOL_METHOD_COUNT => 10,
            'complexity.npath.avg' => 5.0,
            'cohesion.tcc.avg' => 0.5,
            'cohesion.lcom.avg' => 3.0,
            'coupling.ce' => 6,
            'coupling.ce.avg' => 4.0,
            'coupling.ce.max' => 8.0,
            'coupling.ce-packages.avg' => 0.2,
            'coupling.distance' => 0.3,
            'design.type-coverage.param.typed.sum' => 40.0,
            'design.type-coverage.return.typed.sum' => 35.0,
            'design.type-coverage.property.typed.sum' => 20.0,
            'design.type-coverage.param.total.sum' => 50.0,
            'design.type-coverage.return.total.sum' => 50.0,
            'design.type-coverage.property.total.sum' => 25.0,
            'design.dit.avg' => 1.5,
            'coupling.abstractness' => 0.2,
            'maintainability.mi.avg' => 70.0,
            'maintainability.mi.p5' => 50.0,
            'maintainability.mi.min' => 25.0,
        ]), null, null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        $bag = $repo->get($nsPath);

        // health.complexity = clamp(100 - max(30/10-2,0)*5.0 - max(40/10-1,0)*4.0 - 0 - 0 - 0, 0, 100)
        //                   = 100 - 5.0 - 12.0 = 83.0 (no p95/max metrics present → ?? 0)
        self::assertEqualsWithDelta(83.0, $bag->get('health.complexity'), 0.01);

        // health.cohesion = clamp(sqrt(0.5)*50 + (1 - clamp((3-1)/2, 0, 1))*50, 0, 100)
        //                 = 0.7071*50 + 0*50 = 35.36 (LCOM4 3.0 saturates the structural half)
        self::assertEqualsWithDelta(35.36, $bag->get('health.cohesion'), 0.01);

        // health.coupling = 100 * 18 / (18 + dist*6
        //                              + max(ce_packages_avg*3 + sqrt(ce_avg)*0.5 - 4, 0)*4
        //                              + max(ce_max - 30, 0)^0.5 * 0.8
        //                              + max(ce - 50, 0)^0.5 * 0.6)
        //                 = 1800 / (18 + 1.8 + max(0.2*3 + 2*0.5 - 4, 0)*4 + 0 + 0)
        //                 = 1800 / 19.8 ≈ 90.91
        self::assertEqualsWithDelta(90.91, $bag->get('health.coupling'), 0.01);

        // health.typing = (40+35+20) / max(50+50+25, 1) * 100 = 95/125 * 100 = 76
        self::assertEqualsWithDelta(76.0, $bag->get('health.typing'), 0.01);

        // health.maintainability = clamp(100 - max(85-70,0)*2.5 - max(65-50,0)^0.5*4.5 - max(5-25,0)^0.4*1.5, 0, 100)
        //                       = 100 - 37.5 - sqrt(15)*4.5 - 0 = 100 - 37.5 - 17.43 = 45.07
        self::assertEqualsWithDelta(45.07, $bag->get('health.maintainability'), 0.5);

        // health.overall = clamp(83*0.30 + 35.36*0.20 + 90.91*0.20 + 76*0.10 + 45.07*0.20, 0, 100)
        //                = 24.9 + 7.071 + 18.182 + 7.6 + 9.014 = 66.77
        self::assertEqualsWithDelta(66.77, $bag->get('health.overall'), 0.5);
    }

    #[Test]
    public function itScoresTypingHealthAsFullForANamespaceWithNoTypeablePositions(): void
    {
        // A namespace containing only marker interfaces (no methods, no properties)
        // has zero typeable positions. The metric must return 100 (vacuous truth)
        // rather than 0 — there is literally nothing untyped.
        $repo = new InMemoryMetricRepository();

        // Register an empty interface-like class so the namespace appears.
        $classPath = SymbolPath::forClass('App\\Shared\\Messaging', 'AsyncMessageInterface');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/AsyncMessageInterface.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Shared\\Messaging');
        $repo->add($nsPath, MetricBag::fromArray([
            // All typeCoverage sums explicitly zero (denotes 0 typeable positions).
            'design.type-coverage.param.typed.sum' => 0.0,
            'design.type-coverage.return.typed.sum' => 0.0,
            'design.type-coverage.property.typed.sum' => 0.0,
            'design.type-coverage.param.total.sum' => 0.0,
            'design.type-coverage.return.total.sum' => 0.0,
            'design.type-coverage.property.total.sum' => 0.0,
        ]), null, null);

        // Evaluate the typing definition in isolation — sibling formulas would require
        // additional metrics (symbolMethodCount, mi.*, etc.) we do not stub out here.
        $typing = ComputedMetricDefaults::getDefaults()['health.typing'];
        $this->evaluate($repo, [$typing]);

        self::assertSame(100.0, $repo->get($nsPath)->get('health.typing'));
    }

    #[Test]
    public function itScoresTypingHealthAsFullForANamespaceMissingAllTypeCoverageMetrics(): void
    {
        // Edge case: namespace bag has NO typeCoverage.* keys at all (rather than explicit 0s).
        // The `?? 0` fallbacks must still resolve the ternary condition to true → 100.
        $repo = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App\\Empty', 'Marker');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/Marker.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Empty');
        $repo->add($nsPath, MetricBag::fromArray([]), null, null);

        $typing = ComputedMetricDefaults::getDefaults()['health.typing'];
        $this->evaluate($repo, [$typing]);

        self::assertSame(100.0, $repo->get($nsPath)->get('health.typing'));
    }

    #[Test]
    public function itScoresTypingHealthAsFullAtProjectLevelWhenThereIsNoTypeSurface(): void
    {
        // The project-level formula inherits from namespace via getFormulaForLevel,
        // so an entirely type-surface-free project should also yield 100.
        $repo = new InMemoryMetricRepository();

        $classPath = SymbolPath::forClass('App\\Shared\\Messaging', 'AsyncMessageInterface');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/AsyncMessageInterface.php'), 1);

        $projectPath = SymbolPath::forProject();
        $repo->add($projectPath, MetricBag::fromArray([
            'design.type-coverage.param.typed.sum' => 0.0,
            'design.type-coverage.return.typed.sum' => 0.0,
            'design.type-coverage.property.typed.sum' => 0.0,
            'design.type-coverage.param.total.sum' => 0.0,
            'design.type-coverage.return.total.sum' => 0.0,
            'design.type-coverage.property.total.sum' => 0.0,
        ]), null, null);

        $typing = ComputedMetricDefaults::getDefaults()['health.typing'];
        $this->evaluate($repo, [$typing]);

        self::assertSame(100.0, $repo->get($projectPath)->get('health.typing'));
    }

    #[Test]
    public function itPenalizesNamespaceCouplingHealthForHighEfferentBreadth(): void
    {
        $repo = new InMemoryMetricRepository();

        // Register a class so the namespace appears in the repository.
        $repo->add(SymbolPath::forClass('App\\Big', 'X'), MetricBag::fromArray([]), RelativePath::fromString('src/X.php'), 1);

        // Namespace with high efferent coupling: ce.avg=10 (per-class), ce.max=60 (outlier),
        // ce_packages.avg=2 (touches 2 vendor packages on avg per class), ns-level ce=80.
        $nsPath = SymbolPath::forNamespace('App\\Big');
        $repo->add($nsPath, MetricBag::fromArray([
            'coupling.ce' => 80,
            'coupling.ce.avg' => 10.0,
            'coupling.ce.max' => 60,
            'coupling.ce-packages.avg' => 2.0,
            'coupling.distance' => 0.2,
            MetricName::SIZE_SYMBOL_METHOD_COUNT => 1,
        ]), null, null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        // distance*6              = 0.2 * 6                              = 1.2
        // per-class breadth term  = max(2*3 + sqrt(10)*0.5 - 4, 0)*4
        //                         = max(6 + 1.5811 - 4, 0)*4 = 3.5811*4  = 14.3246
        // ce.max outlier          = max(60-30, 0)^0.5 * 0.8 = sqrt(30)*0.8 = 4.3818
        // ns breadth              = max(80-50, 0)^0.5 * 0.6 = sqrt(30)*0.6 = 3.2863
        // denom                   = 18 + 1.2 + 14.3246 + 4.3818 + 3.2863 = 41.1927
        // score                   = 100 * 18 / 41.1927                    ≈ 43.70
        self::assertEqualsWithDelta(43.70, $repo->get($nsPath)->get('health.coupling'), 0.1);
    }

    #[Test]
    public function itRewardsNamespaceCouplingHealthForLowOutgoingCoupling(): void
    {
        $repo = new InMemoryMetricRepository();

        // Register a class so the namespace appears in the repository.
        $repo->add(SymbolPath::forClass('App\\Contracts', 'I'), MetricBag::fromArray([]), RelativePath::fromString('src/I.php'), 1);

        // Stable-contracts namespace: low outgoing coupling (ce=5, ce.avg=1.3, ce.max=4),
        // moderate distance from main sequence. Bidirectional CBO would be high here
        // because of high afferent (every consumer depends on these contracts), but the
        // formula uses efferent metrics only.
        $nsPath = SymbolPath::forNamespace('App\\Contracts');
        $repo->add($nsPath, MetricBag::fromArray([
            'coupling.ce' => 5,
            'coupling.ce.avg' => 1.3,
            'coupling.ce.max' => 4,
            'coupling.ce-packages.avg' => 0.0,
            'coupling.distance' => 0.4,
            MetricName::SIZE_SYMBOL_METHOD_COUNT => 1,
        ]), null, null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        // distance*6 = 2.4; all other terms clamp to 0.
        // denom = 18 + 2.4 = 20.4 -> 100*18/20.4 ≈ 88.24
        self::assertEqualsWithDelta(88.24, $repo->get($nsPath)->get('health.coupling'), 0.1);
    }

    #[Test]
    public function itEvaluatesTheBuiltInMathFunctionsInFormulas(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App\\Service', 'Svc');
        $repo->add($classPath, MetricBag::fromArray([
            'a' => 16.0,
            'b' => -5.0,
            'c' => 3.0,
            'd' => 7.0,
            'e' => 100.0,
            'f' => 1000.0,
        ]), RelativePath::fromString('src/Svc.php'), 1);

        $tests = [
            ['health.sqrt-test', 'sqrt(m["a"])', 4.0],
            ['health.abs-test', 'abs(m["b"])', 5.0],
            ['health.min-test', 'min(m["c"], m["d"])', 3.0],
            ['health.max-test', 'max(m["c"], m["d"])', 7.0],
            ['health.log-test', 'log(m["e"])', log(100.0)],
            ['health.log10-test', 'log10(m["f"])', 3.0],
            ['health.clamp-test', 'clamp(150, 0, 100)', 100.0],
            ['health.clamp-low-test', 'clamp(m["b"], 0, 100)', 0.0],
        ];

        $definitions = [];
        foreach ($tests as [$name, $formula]) {
            $definitions[] = new ComputedMetricDefinition(
                name: $name,
                formulas: ['class' => $formula],
                description: 'Math test',
                levels: [SymbolLevel::Class_],
            );
        }

        $this->evaluate($repo, $definitions);

        $bag = $repo->get($classPath);
        foreach ($tests as [$name, , $expected]) {
            self::assertEqualsWithDelta($expected, $bag->get($name), 0.001, "Failed for {$name}");
        }
    }

    #[Test]
    public function itComputesAnIndependentMetricValuePerClass(): void
    {
        $repo = new InMemoryMetricRepository();

        $class1 = SymbolPath::forClass('App', 'ClassA');
        $class2 = SymbolPath::forClass('App', 'ClassB');

        $repo->add($class1, MetricBag::fromArray(['complexity.ccn' => 2.0]), RelativePath::fromString('src/ClassA.php'), 1);
        $repo->add($class2, MetricBag::fromArray(['complexity.ccn' => 8.0]), RelativePath::fromString('src/ClassB.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'health.simple',
            formulas: ['class' => 'm["complexity.ccn"] * 10'],
            description: 'Simple test',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(20.0, $repo->get($class1)->get('health.simple'));
        self::assertSame(80.0, $repo->get($class2)->get('health.simple'));
    }

    #[Test]
    public function itFallsBackToTheNamespaceFormulaAtProjectLevel(): void
    {
        $repo = new InMemoryMetricRepository();

        // Need a class to register the namespace
        $classPath = SymbolPath::forClass('App', 'Svc');
        $repo->add($classPath, MetricBag::fromArray([]), RelativePath::fromString('src/Svc.php'), 1);

        $nsPath = SymbolPath::forNamespace('App');
        $repo->add($nsPath, MetricBag::fromArray(['value' => 42.0]), null, null);

        $projectPath = SymbolPath::forProject();
        $repo->add($projectPath, MetricBag::fromArray(['value' => 99.0]), null, null);

        $definition = new ComputedMetricDefinition(
            name: 'health.inherited',
            formulas: ['namespace' => 'm["value"] + 1'],
            description: 'Inherits namespace formula for project',
            levels: [SymbolLevel::Namespace_, SymbolLevel::Project],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(43.0, $repo->get($nsPath)->get('health.inherited'));
        // Project should use the namespace formula with project-level metrics
        self::assertSame(100.0, $repo->get($projectPath)->get('health.inherited'));
    }

    #[Test]
    public function itFallsBackToInputOrderWithoutCrashingOnACircularDependency(): void
    {
        $repo = new InMemoryMetricRepository();
        $classPath = SymbolPath::forClass('App', 'Svc');
        $repo->add($classPath, MetricBag::fromArray(['x' => 1.0]), RelativePath::fromString('src/Svc.php'), 1);

        $defA = new ComputedMetricDefinition(
            name: 'health.a',
            formulas: ['class' => '(m["health.b"] ?? 0) + m["x"]'],
            description: 'Circular A',
            levels: [SymbolLevel::Class_],
        );
        $defB = new ComputedMetricDefinition(
            name: 'health.b',
            formulas: ['class' => '(m["health.a"] ?? 0) + m["x"]'],
            description: 'Circular B',
            levels: [SymbolLevel::Class_],
        );

        // Should not throw — falls back to original order with warning
        $this->evaluate($repo, [$defA, $defB]);

        // Both should compute using the fallback ?? 0
        $bag = $repo->get($classPath);
        self::assertNotNull($bag->get('health.a'));
        self::assertNotNull($bag->get('health.b'));
    }

    #[Test]
    public function itAveragesComplexityHealthPerMethodRatherThanPerClassAtNamespaceLevel(): void
    {
        $repo = new InMemoryMetricRepository();

        // Namespace with 2 classes: one has 5 methods (all CCN=2), another has 1 method (CCN=30)
        // WMC class A = 10 (ccn.sum), WMC class B = 30 (ccn.sum)
        // Per-class CCN avg (old formula) = avg(10, 30) = 20 → terrible score
        // Per-method CCN avg (new formula) = (10+30)/6 = 6.67 → moderate

        $classA = SymbolPath::forClass('App\\Service', 'ClassA');
        $repo->add($classA, MetricBag::fromArray([]), RelativePath::fromString('src/ClassA.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Service');
        $repo->add($nsPath, MetricBag::fromArray([
            'complexity.ccn.sum' => 40.0,          // total CCN across all methods
            'complexity.ccn.avg' => 20.0,          // average WMC (per-class) - NOT per-method
            'complexity.cognitive.sum' => 30.0,
            'complexity.cognitive.avg' => 15.0,    // average per-class cognitive sum
            MetricName::SIZE_SYMBOL_METHOD_COUNT => 6,    // total method count
            'complexity.npath.avg' => 10.0,
        ]), null, null);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());

        // Only evaluate health.complexity
        $complexityDef = array_filter($defaults, static fn($d) => $d->name === 'health.complexity');
        $this->evaluate($repo, array_values($complexityDef));

        $bag = $repo->get($nsPath);
        $score = $bag->get('health.complexity');
        self::assertNotNull($score);

        // Per-method averages: CCN = 40/6 ≈ 6.67, Cognitive = 30/6 = 5.0
        // penalty = max(6.67-2, 0)*5.0 + max(5.0-1, 0)*4.0 + 0 + 0 + 0
        //         = 4.67*5.0 + 4.0*4.0 = 23.33 + 16.0 = 39.33
        // score = 100 - 39.33 = 60.67
        self::assertEqualsWithDelta(60.67, $score, 0.01);

        // Verify: reading the per-class averages instead (ccn.avg=20, cognitive.avg=15) would
        // penalise 18*5.0 + 14*4.0 = 146, clamping the score to 0.
        self::assertGreaterThan(50.0, $score, 'Per-method averaging should give a much better score than per-class WMC averaging');
    }

    #[Test]
    public function itReadsOneImmutableDefinitionSnapshotPerEvaluation(): void
    {
        $catalog = $this->createMock(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->expects(self::once())->method('all')->willReturn([]);

        (new ComputedMetricEvaluator($catalog, self::createStub(ProfilerInterface::class)))
            ->evaluate(new InMemoryMetricRepository(), 1);
    }

    #[Test]
    public function itDoesNothingForZeroAnalyzedFiles(): void
    {
        $repository = new InMemoryMetricRepository();
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn(array_values(ComputedMetricDefaults::getDefaults()));

        (new ComputedMetricEvaluator($catalog, self::createStub(ProfilerInterface::class)))->evaluate($repository, 0);

        self::assertSame([], $repository->get(SymbolPath::forProject())->all());
    }

    #[Test]
    public function itOwnsTheComputedProfilerSpan(): void
    {
        $profiler = self::createStub(ProfilerInterface::class);
        $starts = [];
        $stops = [];
        $profiler->method('start')->willReturnCallback(static function (string $name, ?string $category) use (&$starts): void {
            $starts[] = [$name, $category];
        });
        $profiler->method('stop')->willReturnCallback(static function (string $name) use (&$stops): void {
            $stops[] = $name;
        });
        $definition = new ComputedMetricDefinition(
            name: 'computed.test',
            formulas: ['project' => '1'],
            description: 'Test',
            levels: [SymbolLevel::Project],
        );
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([$definition]);

        (new ComputedMetricEvaluator($catalog, $profiler))->evaluate(new InMemoryMetricRepository(), 1);
        self::assertSame(['computed', 'pipeline'], $starts[0]);
        self::assertSame(['computed.computed.test', 'computed'], $stops);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return object{records: list<array{message: string, context: array<string, mixed>}>}
     */
    private function evaluateLogging(MetricRepositoryInterface $repository, array $definitions): object
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn($definitions);
        (new ComputedMetricEvaluator($catalog, self::createStub(ProfilerInterface::class), $logger))
            ->evaluate($repository, 1);

        return $logger;
    }

    /** @param list<ComputedMetricDefinition> $definitions */
    private function evaluate(MetricRepositoryInterface $repository, array $definitions): void
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn($definitions);

        (new ComputedMetricEvaluator($catalog, self::createStub(ProfilerInterface::class)))->evaluate($repository, 1);
    }
}
