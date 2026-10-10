<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricBranchTrace;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(ComputedMetricEvaluator::class)]
#[CoversClass(ComputedMetricBranchTrace::class)]
final class ComputedMetricEvaluatorTest extends TestCase
{
    #[Test]
    public function itKeepsCustomMissingAndNullAbsenceSeparateFromAuthoredHealthConstant(): void
    {
        $repo = $this->repository();
        $path = SymbolPath::forClass('App', 'Empty');
        $this->addFixture($repo, $path, new MetricBag(), RelativePath::fromString('Empty.php'), 1);
        $summary = $this->evaluate($repo, [
            new ComputedMetricDefinition('computed.missing', ['class' => 'm["cohesion.tcc"] * 100'], '', [SymbolLevel::Class_]),
            new ComputedMetricDefinition('computed.null', ['class' => 'null'], '', [SymbolLevel::Class_]),
            new ComputedMetricDefinition('health.typing', ['class' => '80'], '', [SymbolLevel::Class_]),
        ]);
        self::assertSame(80.0, $this->readFixture($repo, $path)->get('health.typing'));
        self::assertCount(2, $summary->absences);
        self::assertSame('computed.missing', $summary->absences[0]->metricName);
        self::assertSame(1, $summary->absences[0]->missingKeysCount);
        self::assertSame(0, $summary->absences[0]->noValueCount);
        self::assertSame(['cohesion.tcc'], $summary->absences[0]->missingKeys);
        self::assertSame('computed.null', $summary->absences[1]->metricName);
        self::assertSame(0, $summary->absences[1]->missingKeysCount);
        self::assertSame(1, $summary->absences[1]->noValueCount);
        self::assertSame([], $summary->absences[1]->missingKeys);
    }

    #[Test]
    public function itKeepsCopiedBuiltinNullAsAuthoredAbsenceWhileTheBuiltinIsQuiet(): void
    {
        $repo = $this->repository();
        $builtin = ComputedMetricDefaults::getDefaults()['health.cohesion'];
        $summary = $this->evaluate($repo, [$builtin]);
        self::assertSame([], $summary->absences);
        $copy = new ComputedMetricDefinition('health.cohesion', $builtin->formulas, '', $builtin->levels);
        $summary = $this->evaluate($repo, [$copy]);
        self::assertCount(1, $summary->absences);
        self::assertSame(SymbolLevel::Project, $summary->absences[0]->level);
        self::assertSame(1, $summary->absences[0]->noValueCount);
        self::assertNull($repo->getSubject(MetricSubject::aggregate(SymbolPath::forProject()))->get('health.cohesion'));
    }

    #[Test]
    public function itCountsDuplicateLogicalClassesAsExactDeclarations(): void
    {
        $repo = $this->repository();
        $path = SymbolPath::forClass('App', 'Duplicate');
        foreach (['z.php', 'a.php', 'b.php', 'c.php', 'd.php'] as $file) {
            $this->addFixture($repo, $path, new MetricBag(), RelativePath::fromString($file), 1);
        }
        $summary = $this->evaluate($repo, [new ComputedMetricDefinition('computed.null', ['class' => 'null'], '', [SymbolLevel::Class_])]);
        $absence = $summary->absences[0];
        self::assertSame(5, $absence->noValueCount);
        self::assertSame([
            'declaration:class:App\Duplicate@a.php',
            'declaration:class:App\Duplicate@b.php',
            'declaration:class:App\Duplicate@c.php',
        ], array_map(static fn(MetricSubject $subject): string => $subject->toCanonical(), $absence->subjects));
    }

    #[Test]
    public function itRefusesApplicableBuiltinMissingOrNullInsteadOfSummarizingIt(): void
    {
        foreach (['m["cohesion.tcc"]', 'null'] as $formula) {
            $repo = $this->repository();
            $definition = new ComputedMetricDefinition(
                'health.cohesion',
                ['project' => $formula],
                '',
                [SymbolLevel::Project],
                applicability: ['project' => \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability::always()],
            );
            try {
                $this->evaluate($repo, [$definition]);
                self::fail('An applicable builtin must produce a measured value.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('Applicable builtin formula produced', $refusal->summary());
                self::assertSame('the merged configuration', $refusal->sources()[0]->describe());
            }
            self::assertNull($repo->getSubject(MetricSubject::aggregate(SymbolPath::forProject()))->get('health.cohesion'));
        }
    }

    #[Test]
    public function itPassesInvalidRawPolicyFactsAtAllThreeLevelsToThePureEvaluation(): void
    {
        foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
            $repo = $this->repository();
            $class = SymbolPath::forClass('App', 'Invalid');
            $this->addFixture($repo, $class, new MetricBag(), RelativePath::fromString('Invalid.php'), 1);
            $path = match ($level) {
                SymbolLevel::Class_ => $class,
                SymbolLevel::Namespace_ => SymbolPath::forNamespace('App'),
                default => SymbolPath::forProject(),
            };
            if ($level === SymbolLevel::Class_) {
                $repo->addSubjectScalar(MetricSubject::declaration(DeclarationPath::of($class, RelativePath::fromString('Invalid.php'), DeclarationOrdinal::fromRank(0))), 'cohesion.tcc', \INF);
            } else {
                $this->addFixture($repo, $path, MetricBag::fromArray(['cohesion.tcc' => \INF]), null, null);
            }
            $definition = new ComputedMetricDefinition(
                'health.cohesion',
                [$level->value => '80'],
                '',
                [$level],
                applicability: [$level->value => \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricApplicability::anyPresent(['cohesion.tcc'])],
            );
            try {
                $this->evaluate($repo, [$definition]);
                self::fail('The raw policy input must be validated before the constant formula.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('Applicability input "cohesion.tcc" must be a finite measured number', $refusal->summary());
                self::assertStringContainsString('level "' . $level->value . '"', $refusal->summary());
            }
        }
    }

    #[Test]
    public function itRefusesInvalidFormulaResultsAndReadOperands(): void
    {
        foreach ([
            [new ComputedMetricDefinition('computed.test', ['project' => 'true'], '', [SymbolLevel::Project]), []],
            [new ComputedMetricDefinition('computed.test', ['project' => '"80"'], '', [SymbolLevel::Project]), []],
            [new ComputedMetricDefinition('computed.test', ['project' => '1 / 0'], '', [SymbolLevel::Project]), []],
            [ComputedMetricDefaults::getDefaults()['health.complexity'], [
                'complexity.ccn.sum' => 10,
                'size.symbol-method-count' => \NAN,
            ]],
        ] as [$definition, $values]) {
            $repo = $this->repository();
            if ($values !== []) {
                $this->addFixture($repo, SymbolPath::forProject(), MetricBag::fromArray($values), null, null);
            }
            try {
                $this->evaluate($repo, [$definition]);
                self::fail('A failed formula must refuse the run.');
            } catch (ConfigurationRefusal $refusal) {
                self::assertStringContainsString('Computed metric "' . $definition->name . '" failed at level "project"', $refusal->summary());
                self::assertSame('the merged configuration', $refusal->sources()[0]->describe());
                self::assertNull($repo->getSubject(MetricSubject::aggregate(SymbolPath::forProject()))->get($definition->name));
            }
        }

        $repo = $this->repository();
        $invalid = SymbolPath::forClass('App', 'Invalid');
        $carrier = SymbolPath::forClass('App', 'Carrier');
        $this->addFixture(
            $repo,
            $invalid,
            MetricBag::fromArray(['complexity.ccn' => \NAN]),
            RelativePath::fromString('Invalid.php'),
            1,
        );
        $this->addFixture(
            $repo,
            $carrier,
            MetricBag::fromArray(['complexity.ccn' => 1, 'size.loc' => 1]),
            RelativePath::fromString('Carrier.php'),
            1,
        );
        $definition = new ComputedMetricDefinition(
            'computed.test',
            ['class' => 'm["complexity.ccn"] + m["size.loc"]'],
            '',
            [SymbolLevel::Class_],
        );
        try {
            $this->evaluate($repo, [$definition]);
            self::fail('A reached invalid class input must not become an authored missing-key summary.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Computed metric "computed.test" failed at level "class"', $refusal->summary());
            self::assertSame('the merged configuration', $refusal->sources()[0]->describe());
            self::assertNull($this->readFixture($repo, $invalid)->get('computed.test'));
        }
    }

    #[Test]
    public function itLeavesTheRepositoryUntouchedWhenGivenNoDefinitions(): void
    {
        $repo = $this->repository();
        $this->evaluate($repo, []);

        self::assertSame([], $this->readFixture($repo, SymbolPath::forProject())->all());
    }

    #[Test]
    public function itEvaluatesASimpleClassLevelFormula(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
            'complexity.ccn.avg' => 3.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'm["complexity.ccn.avg"] * 10'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        $result = $this->readFixture($repo, $classPath)->get('health.test');
        self::assertSame(30.0, $result);
    }

    #[Test]
    public function itEvaluatesDependentMetricsInTopologicalOrderRegardlessOfInputOrder(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
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

        $bag = $this->readFixture($repo, $classPath);
        self::assertSame(6.0, $bag->get('health.a'));
        self::assertSame(12.0, $bag->get('health.b'));
    }

    #[Test]
    public function itRefusesAFormulaReferencingAnUnknownMetricWithoutAFallback(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray(['known' => 1.0]), RelativePath::fromString('src/UserService.php'), 10);

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
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray(['known' => 1.0]), RelativePath::fromString('src/UserService.php'), 10);

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
     * keys, rather than publishing an ordinary absence summary for the whole level.
     */
    #[Test]
    public function itRefusesAFallbackChainWhereNoSymbolAtTheLevelCarriesAnyLink(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App', 'Svc');
        $this->addFixture($repo, $classPath, MetricBag::fromArray(['size.method-count' => 2]), RelativePath::fromString('src/Svc.php'), 1);
        $this->addFixture($repo, SymbolPath::forProject(), MetricBag::fromArray(['size.loc' => 10]), null, null);

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
        $repo = $this->repository();

        // Class A has 'complexity.ccn', class B does not — but union includes 'complexity.ccn', so formula is valid
        $classA = SymbolPath::forClass('App', 'ClassA');
        $classB = SymbolPath::forClass('App', 'ClassB');
        $this->addFixture($repo, $classA, MetricBag::fromArray(['complexity.ccn' => 5.0]), RelativePath::fromString('src/ClassA.php'), 1);
        $this->addFixture($repo, $classB, MetricBag::fromArray([]), RelativePath::fromString('src/ClassB.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => '(m["complexity.ccn"] ?? 0) * 10'],
            description: 'Test with partial data',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(50.0, $this->readFixture($repo, $classA)->get('health.test'));
        self::assertSame(0.0, $this->readFixture($repo, $classB)->get('health.test'));
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
        $repo = $this->repository();
        $rich = SymbolPath::forClass('App', 'Rich');
        $bare = SymbolPath::forClass('App', 'Bare');
        $this->addFixture($repo, $rich, MetricBag::fromArray(['cohesion.tcc' => 1.0]), RelativePath::fromString('src/Rich.php'), 1);
        $this->addFixture($repo, $bare, MetricBag::fromArray([]), RelativePath::fromString('src/Bare.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] * 100'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        );

        $summary = $this->evaluate($repo, [$definition]);

        self::assertSame(100.0, $this->readFixture($repo, $rich)->get('computed.probe'));
        self::assertNull($this->readFixture($repo, $bare)->get('computed.probe'));
        self::assertCount(1, $summary->absences);
        self::assertSame(1, $summary->absences[0]->missingKeysCount);
        self::assertSame('declaration:class:App\\Bare@src/Bare.php', $summary->absences[0]->subjects[0]->toCanonical());
        self::assertSame(['cohesion.tcc'], $summary->absences[0]->missingKeys);
    }

    /**
     * The right side of `??` is read only where the left is absent. Treating it
     * as required skipped a symbol that carried the left side, and the value
     * the formula computes there was lost.
     */
    #[Test]
    public function itPublishesTheLeftSideOfAFallbackBetweenMetricsWhereOnlyTheLeftIsPresent(): void
    {
        $repo = $this->repository();
        $tccOnly = SymbolPath::forClass('App', 'TccOnly');
        $lccOnly = SymbolPath::forClass('App', 'LccOnly');
        $this->addFixture($repo, $tccOnly, MetricBag::fromArray(['cohesion.tcc' => 0.5]), RelativePath::fromString('src/TccOnly.php'), 1);
        $this->addFixture($repo, $lccOnly, MetricBag::fromArray(['cohesion.lcc' => 0.7]), RelativePath::fromString('src/LccOnly.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] ?? m["cohesion.lcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.5, $this->readFixture($repo, $tccOnly)->get('computed.probe'));
        self::assertSame(0.7, $this->readFixture($repo, $lccOnly)->get('computed.probe'));
        self::assertSame([], $summary->absences);
    }

    /**
     * Where neither side of the fallback is present, the formula's value is
     * `null`; that is a symbol with no value, and the run names both keys the
     * formula looked for.
     */
    #[Test]
    public function itSkipsASymbolCarryingNeitherSideOfAFallbackBetweenMetrics(): void
    {
        $repo = $this->repository();
        $tccOnly = SymbolPath::forClass('App', 'TccOnly');
        $bare = SymbolPath::forClass('App', 'Bare');
        $this->addFixture($repo, $tccOnly, MetricBag::fromArray(['cohesion.tcc' => 0.5, 'cohesion.lcc' => 0.7]), RelativePath::fromString('src/TccOnly.php'), 1);
        $this->addFixture($repo, $bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] ?? m["cohesion.lcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.5, $this->readFixture($repo, $tccOnly)->get('computed.probe'));
        self::assertNull($this->readFixture($repo, $bare)->get('computed.probe'));
        self::assertCount(1, $summary->absences);
        self::assertSame('declaration:class:App\\Bare@src/Bare.php', $summary->absences[0]->subjects[0]->toCanonical());
        self::assertSame('cohesion.lcc, cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    #[Test]
    public function itSummarizesAbsencesWithBoundedExactSamplesForTheMetricAndLevel(): void
    {
        $repo = $this->repository();
        $this->addFixture($repo, SymbolPath::forClass('App', 'Rich'), MetricBag::fromArray(['cohesion.tcc' => 1.0, 'size.loc' => 1]), RelativePath::fromString('src/Rich.php'), 1);
        foreach (range(1, 7) as $i) {
            $this->addFixture($repo, SymbolPath::forClass('App', 'Bare' . $i), MetricBag::fromArray(['size.loc' => 1]), RelativePath::fromString('src/Bare' . $i . '.php'), 1);
        }

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] * m["size.loc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertCount(1, $summary->absences);
        self::assertSame('computed.probe', $summary->absences[0]->metricName);
        self::assertSame(SymbolLevel::Class_, $summary->absences[0]->level);
        self::assertSame(7, $summary->absences[0]->missingKeysCount);
        self::assertSame(['declaration:class:App\\Bare1@src/Bare1.php', 'declaration:class:App\\Bare2@src/Bare2.php', 'declaration:class:App\\Bare3@src/Bare3.php'], array_map(static fn(MetricSubject $subject): string => $subject->toCanonical(), $summary->absences[0]->subjects));
        self::assertSame('cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    /**
     * A null the inner `??` hands on is caught by the outer one: nothing in
     * `(a ?? b) ?? 0` is ever read as null by the arithmetic.
     */
    #[Test]
    public function itTreatsAParenthesisedFallbackChainAsGuardedToItsLastLink(): void
    {
        $repo = $this->repository();
        $bare = SymbolPath::forClass('App', 'Bare');
        $rich = SymbolPath::forClass('App', 'Rich');
        $this->addFixture($repo, $bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);
        $this->addFixture($repo, $rich, MetricBag::fromArray(['cohesion.lcc' => 0.7]), RelativePath::fromString('src/Rich.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '((m["cohesion.tcc"] ?? m["cohesion.lcc"]) ?? 0) + 1'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(1.0, $this->readFixture($repo, $bare)->get('computed.probe'));
        self::assertSame(1.7, $this->readFixture($repo, $rich)->get('computed.probe'));
        self::assertSame([], $summary->absences);
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
        $repo = $this->repository();
        $single = SymbolPath::forClass('App', 'Single');
        $empty = SymbolPath::forClass('App', 'Empty');
        $this->addFixture($repo, $single, MetricBag::fromArray(['size.method-count' => 1]), RelativePath::fromString('src/Single.php'), 1);
        $this->addFixture($repo, $empty, MetricBag::fromArray(['size.method-count' => 0, 'cohesion.tcc' => 0.25]), RelativePath::fromString('src/Empty.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? 7 : m["cohesion.tcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(7.0, $this->readFixture($repo, $single)->get('computed.probe'));
        self::assertSame(0.25, $this->readFixture($repo, $empty)->get('computed.probe'));
        self::assertSame([], $summary->absences);
    }

    #[Test]
    public function itDoesNotRefuseAKeyNoSymbolCarriesWhereOnlyABranchReadsIt(): void
    {
        $repo = $this->repository();
        $single = SymbolPath::forClass('App', 'Single');
        $this->addFixture($repo, $single, MetricBag::fromArray(['size.method-count' => 1]), RelativePath::fromString('src/Single.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? 7 : m["cohesion.tcc"]'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(7.0, $this->readFixture($repo, $single)->get('computed.probe'));
        self::assertSame([], $summary->absences);
    }

    /**
     * The branch the evaluation took reads an absent key: its `null` reached
     * the arithmetic and the result is not a measurement. Judged by the branch
     * the evaluation actually ran, so the level carrying the key nowhere is a
     * per-symbol nonfailure absence, not a refusal.
     */
    #[Test]
    public function itPublishesNoValueWhereTheTakenBranchReadsAnAbsentKey(): void
    {
        $repo = $this->repository();
        $guarded = SymbolPath::forClass('App', 'Guarded');
        $reached = SymbolPath::forClass('App', 'Reached');
        $this->addFixture($repo, $guarded, MetricBag::fromArray(['size.method-count' => 0]), RelativePath::fromString('src/Guarded.php'), 1);
        $this->addFixture($repo, $reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? m["cohesion.tcc"] / m["size.method-count"] : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.0, $this->readFixture($repo, $guarded)->get('computed.probe'));
        self::assertNull($this->readFixture($repo, $reached)->get('computed.probe'));
        self::assertCount(1, $summary->absences);
        self::assertSame(1, $summary->absences[0]->missingKeysCount);
        self::assertSame('declaration:class:App\\Reached@src/Reached.php', $summary->absences[0]->subjects[0]->toCanonical());
        self::assertSame('cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    /**
     * The same judgement for the right side of `and`: it runs only where the
     * left is true.
     */
    #[Test]
    public function itJudgesTheRightSideOfAndByWhetherTheEvaluationReachedIt(): void
    {
        $repo = $this->repository();
        $shortCircuited = SymbolPath::forClass('App', 'ShortCircuited');
        $reached = SymbolPath::forClass('App', 'Reached');
        $this->addFixture($repo, $shortCircuited, MetricBag::fromArray(['size.method-count' => 0]), RelativePath::fromString('src/ShortCircuited.php'), 1);
        $this->addFixture($repo, $reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '(m["size.method-count"] > 0 and m["cohesion.tcc"] < 0.5) ? 1 : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(0.0, $this->readFixture($repo, $shortCircuited)->get('computed.probe'));
        self::assertNull($this->readFixture($repo, $reached)->get('computed.probe'));
        self::assertCount(1, $summary->absences);
        self::assertSame('declaration:class:App\\Reached@src/Reached.php', $summary->absences[0]->subjects[0]->toCanonical());
    }

    /**
     * The condition always runs: `null > 0` is false, so an absent key there
     * would choose a branch on nothing.
     */
    #[Test]
    public function itPublishesNoValueWhereTheConditionReadsAnAbsentKey(): void
    {
        $repo = $this->repository();
        $rich = SymbolPath::forClass('App', 'Rich');
        $bare = SymbolPath::forClass('App', 'Bare');
        $this->addFixture($repo, $rich, MetricBag::fromArray(['cohesion.tcc' => 0.75]), RelativePath::fromString('src/Rich.php'), 1);
        $this->addFixture($repo, $bare, MetricBag::fromArray(['size.loc' => 3]), RelativePath::fromString('src/Bare.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["cohesion.tcc"] > 0.5 ? 1 : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(1.0, $this->readFixture($repo, $rich)->get('computed.probe'));
        self::assertNull($this->readFixture($repo, $bare)->get('computed.probe'));
        self::assertSame('cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    /**
     * A `null` the taken branch hands to an enclosing `??` is caught there,
     * the same as a read on the left of `??`.
     */
    #[Test]
    public function itLetsAnEnclosingFallbackCatchTheNullOfTheTakenBranch(): void
    {
        $repo = $this->repository();
        $bare = SymbolPath::forClass('App', 'Bare');
        $this->addFixture($repo, $bare, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Bare.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => '(m["size.method-count"] > 0 ? m["cohesion.tcc"] : 1) ?? 5'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertSame(5.0, $this->readFixture($repo, $bare)->get('computed.probe'));
        self::assertSame([], $summary->absences);
    }

    /**
     * The branch is judged when the evaluation enters it, before it runs: a
     * `null` handed to a PHP function there is a deprecation printed into the
     * report's own output, and a result nobody may publish.
     */
    #[Test]
    public function itNeverRunsTheBranchItEntersWithAnAbsentKey(): void
    {
        $repo = $this->repository();
        $reached = SymbolPath::forClass('App', 'Reached');
        $this->addFixture($repo, $reached, MetricBag::fromArray(['size.method-count' => 4]), RelativePath::fromString('src/Reached.php'), 1);

        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
                name: 'computed.probe',
                formulas: ['class' => 'm["size.method-count"] > 0 ? sqrt(m["cohesion.tcc"]) : 0'],
                description: 'Test metric',
                levels: [SymbolLevel::Class_],
            )]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised);
        self::assertNull($this->readFixture($repo, $reached)->get('computed.probe'));
        self::assertSame('cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    /**
     * A ternary inside the entered branch whose every branch reads the absent
     * key stops the evaluation at the outer entry; the key is still named.
     */
    #[Test]
    public function itNamesTheKeyANestedTernaryReadsOnEveryPath(): void
    {
        $repo = $this->repository();
        $reached = SymbolPath::forClass('App', 'Reached');
        $this->addFixture($repo, $reached, MetricBag::fromArray(['size.method-count' => 4, 'size.loc' => 10]), RelativePath::fromString('src/Reached.php'), 1);

        $summary = $this->evaluate($repo, [new ComputedMetricDefinition(
            name: 'computed.probe',
            formulas: ['class' => 'm["size.method-count"] > 0 ? (m["size.loc"] > 5 ? m["cohesion.tcc"] * 2 : m["cohesion.tcc"] * 3) : 0'],
            description: 'Test metric',
            levels: [SymbolLevel::Class_],
        )]);

        self::assertNull($this->readFixture($repo, $reached)->get('computed.probe'));
        self::assertCount(1, $summary->absences);
        self::assertSame('cohesion.tcc', implode(', ', $summary->absences[0]->missingKeys));
    }

    #[Test]
    public function itUsesTheNullCoalescingFallbackForAMissingMetric(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => '(m["missing_var"] ?? 42) * 2'],
            description: 'Test metric with fallback',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(84.0, $this->readFixture($repo, $classPath)->get('health.test'));
    }

    #[Test]
    public function itDoesNotStoreANanFormulaResult(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
            'value' => -1.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'sqrt(m["value"])'],
            description: 'NaN test',
            levels: [SymbolLevel::Class_],
        );

        try {
            $this->evaluate($repo, [$definition]);
            self::fail('A nonfinite formula must refuse the run.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('must be finite', $refusal->summary());
            self::assertSame('the merged configuration', $refusal->sources()[0]->describe());
        }

        self::assertNull($this->readFixture($repo, $classPath)->get('health.test'));
    }

    #[Test]
    public function itDoesNotStoreAnInfiniteFormulaResult(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
            'value' => 0.0,
        ]), RelativePath::fromString('src/UserService.php'), 10);

        $definition = new ComputedMetricDefinition(
            name: 'health.test',
            formulas: ['class' => 'log(m["value"])'],
            description: 'Infinity test',
            levels: [SymbolLevel::Class_],
        );

        try {
            $this->evaluate($repo, [$definition]);
            self::fail('A nonfinite formula must refuse the run.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('must be finite', $refusal->summary());
            self::assertSame('the merged configuration', $refusal->sources()[0]->describe());
        }

        self::assertNull($this->readFixture($repo, $classPath)->get('health.test'));
    }

    #[Test]
    public function itComputesTheDefaultHealthScoresAtClassLevel(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
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

        $bag = $this->readFixture($repo, $classPath);

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
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Rules', 'DistanceRule');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
            'cohesion.tcc' => 0.0,
            'cohesion.lcom' => 5.0,
            'size.method-count' => 5,
            'cohesion.pure-method-count' => 4,
            'coupling.ce' => 3.0,
            'design.type-coverage.all' => 100.0,
        ]), RelativePath::fromString('src/DistanceRule.php'), 10);

        $defaults = array_values(ComputedMetricDefaults::getDefaults());
        $this->evaluate($repo, $defaults);

        $bag = $this->readFixture($repo, $classPath);

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
        $repo = $this->repository();

        // Add a class so the namespace is registered
        $classPath = SymbolPath::forClass('App\\Service', 'UserService');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/UserService.php'), 10);

        // Add namespace-level metrics
        $nsPath = SymbolPath::forNamespace('App\\Service');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([
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

        $bag = $this->readFixture($repo, $nsPath);

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
    public function itOmitsTypingHealthForANamespaceWithNoTypeablePositions(): void
    {
        // A namespace containing only marker interfaces (no methods, no properties)
        // has no measured percentage: zero denominator is not a health score.
        $repo = $this->repository();

        // Register an empty interface-like class so the namespace appears.
        $classPath = SymbolPath::forClass('App\\Shared\\Messaging', 'AsyncMessageInterface');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/AsyncMessageInterface.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Shared\\Messaging');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([
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

        self::assertNull($this->readFixture($repo, $nsPath)->get('health.typing'));
    }

    #[Test]
    public function itOmitsTypingHealthForANamespaceMissingAllTypeCoverageMetrics(): void
    {
        // Edge case: namespace bag has NO typeCoverage.* keys at all (rather than explicit 0s).
        // Applicability leaves that missing measurement absent.
        $repo = $this->repository();

        $classPath = SymbolPath::forClass('App\\Empty', 'Marker');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/Marker.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Empty');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([]), null, null);

        $typing = ComputedMetricDefaults::getDefaults()['health.typing'];
        $this->evaluate($repo, [$typing]);

        self::assertNull($this->readFixture($repo, $nsPath)->get('health.typing'));
    }

    #[Test]
    public function itOmitsTypingHealthAtProjectLevelWhenThereIsNoTypeSurface(): void
    {
        // The project-level formula inherits from namespace via getFormulaForLevel,
        // so a project without a type surface also has no measured typing score.
        $repo = $this->repository();

        $classPath = SymbolPath::forClass('App\\Shared\\Messaging', 'AsyncMessageInterface');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/AsyncMessageInterface.php'), 1);

        $projectPath = SymbolPath::forProject();
        $this->addFixture($repo, $projectPath, MetricBag::fromArray([
            'design.type-coverage.param.typed.sum' => 0.0,
            'design.type-coverage.return.typed.sum' => 0.0,
            'design.type-coverage.property.typed.sum' => 0.0,
            'design.type-coverage.param.total.sum' => 0.0,
            'design.type-coverage.return.total.sum' => 0.0,
            'design.type-coverage.property.total.sum' => 0.0,
        ]), null, null);

        $typing = ComputedMetricDefaults::getDefaults()['health.typing'];
        $this->evaluate($repo, [$typing]);

        self::assertNull($this->readFixture($repo, $projectPath)->get('health.typing'));
    }

    #[Test]
    public function itPenalizesNamespaceCouplingHealthForHighEfferentBreadth(): void
    {
        $repo = $this->repository();

        // Register a class so the namespace appears in the repository.
        $this->addFixture($repo, SymbolPath::forClass('App\\Big', 'X'), MetricBag::fromArray([]), RelativePath::fromString('src/X.php'), 1);

        // Namespace with high efferent coupling: ce.avg=10 (per-class), ce.max=60 (outlier),
        // ce_packages.avg=2 (touches 2 vendor packages on avg per class), ns-level ce=80.
        $nsPath = SymbolPath::forNamespace('App\\Big');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([
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
        self::assertEqualsWithDelta(43.70, $this->readFixture($repo, $nsPath)->get('health.coupling'), 0.1);
    }

    #[Test]
    public function itRewardsNamespaceCouplingHealthForLowOutgoingCoupling(): void
    {
        $repo = $this->repository();

        // Register a class so the namespace appears in the repository.
        $this->addFixture($repo, SymbolPath::forClass('App\\Contracts', 'I'), MetricBag::fromArray([]), RelativePath::fromString('src/I.php'), 1);

        // Stable-contracts namespace: low outgoing coupling (ce=5, ce.avg=1.3, ce.max=4),
        // moderate distance from main sequence. Bidirectional CBO would be high here
        // because of high afferent (every consumer depends on these contracts), but the
        // formula uses efferent metrics only.
        $nsPath = SymbolPath::forNamespace('App\\Contracts');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([
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
        self::assertEqualsWithDelta(88.24, $this->readFixture($repo, $nsPath)->get('health.coupling'), 0.1);
    }

    #[Test]
    public function itEvaluatesTheBuiltInMathFunctionsInFormulas(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App\\Service', 'Svc');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([
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

        $bag = $this->readFixture($repo, $classPath);
        foreach ($tests as [$name, , $expected]) {
            self::assertEqualsWithDelta($expected, $bag->get($name), 0.001, "Failed for {$name}");
        }
    }

    #[Test]
    public function itComputesAnIndependentMetricValuePerClass(): void
    {
        $repo = $this->repository();

        $class1 = SymbolPath::forClass('App', 'ClassA');
        $class2 = SymbolPath::forClass('App', 'ClassB');

        $this->addFixture($repo, $class1, MetricBag::fromArray(['complexity.ccn' => 2.0]), RelativePath::fromString('src/ClassA.php'), 1);
        $this->addFixture($repo, $class2, MetricBag::fromArray(['complexity.ccn' => 8.0]), RelativePath::fromString('src/ClassB.php'), 1);

        $definition = new ComputedMetricDefinition(
            name: 'health.simple',
            formulas: ['class' => 'm["complexity.ccn"] * 10'],
            description: 'Simple test',
            levels: [SymbolLevel::Class_],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(20.0, $this->readFixture($repo, $class1)->get('health.simple'));
        self::assertSame(80.0, $this->readFixture($repo, $class2)->get('health.simple'));
    }

    #[Test]
    public function itFallsBackToTheNamespaceFormulaAtProjectLevel(): void
    {
        $repo = $this->repository();

        // Need a class to register the namespace
        $classPath = SymbolPath::forClass('App', 'Svc');
        $this->addFixture($repo, $classPath, MetricBag::fromArray([]), RelativePath::fromString('src/Svc.php'), 1);

        $nsPath = SymbolPath::forNamespace('App');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray(['value' => 42.0]), null, null);

        $projectPath = SymbolPath::forProject();
        $this->addFixture($repo, $projectPath, MetricBag::fromArray(['value' => 99.0]), null, null);

        $definition = new ComputedMetricDefinition(
            name: 'health.inherited',
            formulas: ['namespace' => 'm["value"] + 1'],
            description: 'Inherits namespace formula for project',
            levels: [SymbolLevel::Namespace_, SymbolLevel::Project],
        );

        $this->evaluate($repo, [$definition]);

        self::assertSame(43.0, $this->readFixture($repo, $nsPath)->get('health.inherited'));
        // Project should use the namespace formula with project-level metrics
        self::assertSame(100.0, $this->readFixture($repo, $projectPath)->get('health.inherited'));
    }

    #[Test]
    public function itFallsBackToInputOrderWithoutCrashingOnACircularDependency(): void
    {
        $repo = $this->repository();
        $classPath = SymbolPath::forClass('App', 'Svc');
        $this->addFixture($repo, $classPath, MetricBag::fromArray(['x' => 1.0]), RelativePath::fromString('src/Svc.php'), 1);

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
        $bag = $this->readFixture($repo, $classPath);
        self::assertNotNull($bag->get('health.a'));
        self::assertNotNull($bag->get('health.b'));
    }

    #[Test]
    public function itAveragesComplexityHealthPerMethodRatherThanPerClassAtNamespaceLevel(): void
    {
        $repo = $this->repository();

        // Namespace with 2 classes: one has 5 methods (all CCN=2), another has 1 method (CCN=30)
        // WMC class A = 10 (ccn.sum), WMC class B = 30 (ccn.sum)
        // Per-class CCN avg (old formula) = avg(10, 30) = 20 → terrible score
        // Per-method CCN avg (new formula) = (10+30)/6 = 6.67 → moderate

        $classA = SymbolPath::forClass('App\\Service', 'ClassA');
        $this->addFixture($repo, $classA, MetricBag::fromArray([]), RelativePath::fromString('src/ClassA.php'), 1);

        $nsPath = SymbolPath::forNamespace('App\\Service');
        $this->addFixture($repo, $nsPath, MetricBag::fromArray([
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

        $bag = $this->readFixture($repo, $nsPath);
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
    public function itReturnsAnEmptySummaryForTheInstalledEmptySnapshot(): void
    {
        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())));
        $snapshot = new ResolvedComputedMetricDefinitions([]);
        $analysis->replace($snapshot);
        $summary = (new ComputedMetricEvaluator($analysis, self::createStub(ProfilerInterface::class)))
            ->evaluate($this->repository(), 1);
        self::assertSame($snapshot->all(), $analysis->all());
        self::assertSame([], $summary->absences);
    }

    #[Test]
    public function itDoesNothingForZeroAnalyzedFiles(): void
    {
        $repository = $this->repository();
        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())));
        $analysis->replace(new ResolvedComputedMetricDefinitions(array_values(ComputedMetricDefaults::getDefaults())));

        (new ComputedMetricEvaluator($analysis, self::createStub(ProfilerInterface::class)))->evaluate($repository, 0);

        self::assertSame([], $this->readFixture($repository, SymbolPath::forProject())->all());
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
        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())));
        $analysis->replace(new ResolvedComputedMetricDefinitions([$definition]));

        (new ComputedMetricEvaluator($analysis, $profiler))->evaluate($this->repository(), 1);
        self::assertSame(['computed', 'pipeline'], $starts[0]);
        self::assertSame(['computed.computed.test', 'computed'], $stops);
    }

    /** @param list<ComputedMetricDefinition> $definitions */
    private function evaluate(MetricRepositoryInterface $repository, array $definitions): ComputedMetricEvaluationSummary
    {
        $analysis = new ComputedMetricAnalysis(new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder(new \Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression())));
        $analysis->replace(new ResolvedComputedMetricDefinitions($definitions));

        return (new ComputedMetricEvaluator($analysis, self::createStub(ProfilerInterface::class)))->evaluate($repository, 1);
    }
    /** @var list<string> */
    private const array CLASS_FIXTURE_KEYS = [
        'a',
        'b',
        'c',
        'd',
        'e',
        'f',
        'cohesion.lcc',
        'cohesion.lcom',
        'cohesion.lcom.avg',
        'cohesion.pure-method-count',
        'cohesion.tcc',
        'cohesion.tcc.avg',
        'complexity.ccn',
        'complexity.ccn.avg',
        'complexity.ccn.sum',
        'complexity.cognitive.avg',
        'complexity.cognitive.sum',
        'complexity.npath.avg',
        'computed.cls-only',
        'computed.probe',
        'computed.reader',
        'computed.test',
        'coupling.abstractness',
        'coupling.cbo',
        'coupling.ce',
        'coupling.ce-packages.avg',
        'coupling.ce.avg',
        'coupling.ce.max',
        'coupling.distance',
        'design.dit',
        'design.dit.avg',
        'design.type-coverage.all',
        'design.type-coverage.param.total.sum',
        'design.type-coverage.param.typed.sum',
        'design.type-coverage.property.total.sum',
        'design.type-coverage.property.typed.sum',
        'design.type-coverage.return.total.sum',
        'design.type-coverage.return.typed.sum',
        'health.a',
        'health.b',
        'health.cohesion',
        'health.complexity',
        'health.coupling',
        'health.design',
        'health.inherited',
        'health.maintainability',
        'health.overall',
        'health.simple',
        'health.sqrt-test',
        'health.abs-test',
        'health.min-test',
        'health.max-test',
        'health.log-test',
        'health.log10-test',
        'health.clamp-test',
        'health.clamp-low-test',
        'health.test',
        'health.typing',
        'known',
        'maintainability.mi.avg',
        'maintainability.mi.min',
        'maintainability.mi.p5',
        'size.loc',
        'size.method-count',
        'value',
        'x',
    ];

    private function repository(): InMemoryMetricRepository
    {
        return new InMemoryMetricRepository(array_map(
            static fn(string $key): MetricDefinition => new MetricDefinition($key, SymbolLevel::Class_),
            self::CLASS_FIXTURE_KEYS,
        ));
    }

    private function addFixture(InMemoryMetricRepository $repository, SymbolPath $path, MetricBag $bag, ?RelativePath $file, ?int $line): void
    {
        if ($path->getType() === SymbolType::Class_) {
            self::assertNotNull($file);
            $repository->addSubject(MetricSubject::declaration(DeclarationPath::of(
                $path,
                $file,
                DeclarationOrdinal::fromRank(0),
            )), $bag, $file, $line);
            return;
        }
        $repository->add($path, $bag, $file, $line);
    }

    private function readFixture(InMemoryMetricRepository $repository, SymbolPath $path): MetricBag
    {
        if ($path->getType() !== SymbolType::Class_) {
            return $repository->get($path);
        }
        $subjects = [];
        foreach ($repository->allClassDeclarations() as $info) {
            if ($info->symbolPath->toCanonical() === $path->toCanonical()) {
                $subjects[] = $info->subject;
            }
        }
        self::assertCount(1, $subjects, 'Class fixture must identify one exact declaration');
        self::assertNotNull($subjects[0]);

        return $repository->getSubject($subjects[0]);
    }

}
