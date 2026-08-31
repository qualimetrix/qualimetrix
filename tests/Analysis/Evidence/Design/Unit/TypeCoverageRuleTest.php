<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\AbstractTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ParamTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\PropertyTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\ReturnTypeCoverageRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use ReflectionClass;

/**
 * The three type-coverage rules, swept as one set.
 *
 * Every case runs against all three: they share an emission helper, so a
 * regression in it would otherwise be caught on one dimension and missed on
 * the other two. What is deliberately NOT parameterised is
 * {@see itJudgesEachDimensionAgainstItsOwnConfiguration()} — the point of the
 * split is that the three no longer answer to one setting, and that can only
 * be shown by configuring them differently in one run.
 *
 * @phpstan-type Dimension array{class: class-string<AbstractTypeCoverageRule>, name: string, description: string, total: string, coverage: string, alias: string, label: string, hint: string}
 */
#[CoversClass(AbstractTypeCoverageRule::class)]
#[CoversClass(ParamTypeCoverageRule::class)]
#[CoversClass(ReturnTypeCoverageRule::class)]
#[CoversClass(PropertyTypeCoverageRule::class)]
final class TypeCoverageRuleTest extends TestCase
{
    /**
     * One argument, a struct, rather than seven positional ones: PHPUnit warns
     * when a data set carries more arguments than the case reads, and most of
     * these cases read two or three of the seven.
     *
     * The metric keys are written as **literals**, not read from
     * {@see MetricName}. Reading the constants would make this provider and the
     * rules under test one witness: a dimension swapped consistently in both —
     * `ParamTypeCoverageRule` returning the return-type metric and this table
     * agreeing — would pass. The literals are the second witness, and
     * {@see itNamesItselfAndItsSingleChannel()} pins them against the constants
     * so a rename of a constant is loud rather than silent drift.
     *
     * @return iterable<string, array{0: Dimension}>
     */
    public static function dimensions(): iterable
    {
        yield 'param' => [[
            'class' => ParamTypeCoverageRule::class,
            'name' => 'design.type-coverage.param',
            'description' => 'Checks type coverage of parameters per class',
            'total' => 'design.type-coverage.param.total',
            'coverage' => 'design.type-coverage.param',
            'alias' => 'param-type-coverage',
            'label' => 'Parameter',
            'hint' => 'Add type declarations to method parameters',
        ]];
        yield 'return' => [[
            'class' => ReturnTypeCoverageRule::class,
            'name' => 'design.type-coverage.return',
            'description' => 'Checks type coverage of return types per class',
            'total' => 'design.type-coverage.return.total',
            'coverage' => 'design.type-coverage.return',
            'alias' => 'return-type-coverage',
            'label' => 'Return',
            'hint' => 'Add return type declarations to methods',
        ]];
        yield 'property' => [[
            'class' => PropertyTypeCoverageRule::class,
            'name' => 'design.type-coverage.property',
            'description' => 'Checks type coverage of properties per class',
            'total' => 'design.type-coverage.property.total',
            'coverage' => 'design.type-coverage.property',
            'alias' => 'property-type-coverage',
            'label' => 'Property',
            'hint' => 'Add type declarations to properties',
        ]];
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itNamesItselfAndItsSingleChannel(array $dimension): void
    {
        $ruleClass = $dimension['class'];
        $rule = new $ruleClass(new TypeCoverageOptions());

        self::assertSame($dimension['name'], $rule->getName());
        self::assertSame($dimension['description'], $rule->getDescription());
        self::assertSame([$dimension['coverage']], $rule->requires());
        // The literals above are the second witness; this is where they are tied
        // back to the constants, so a renamed constant fails here rather than
        // leaving the table quietly measuring a key nothing produces.
        self::assertContains(
            $dimension['coverage'],
            (new ReflectionClass(MetricName::class))->getConstants(),
            'the metric key this table names is one MetricName declares',
        );
        self::assertContains(
            $dimension['total'],
            (new ReflectionClass(MetricName::class))->getConstants(),
            'and so is its total',
        );
        self::assertSame(TypeCoverageOptions::class, $ruleClass::getOptionsClass());
        self::assertSame(
            [$dimension['name']],
            array_keys($ruleClass::channelDeclarations()),
        );
    }

    /**
     * The option a user types is not derived from the producer's name, and has
     * not been since Ш5e3 renamed the producer without renaming the option:
     * `design.type-coverage.param` is addressed by `--param-type-coverage-warning`.
     * The alias is therefore a literal of this table rather than a substring of
     * the name — deriving it would assert the coupling the step removed.
     *
     * @param Dimension $dimension
     */
    public function itAliasesItsOwnTwoBoundariesOnly(array $dimension): void
    {
        $alias = $dimension['alias'];

        self::assertSame(
            [$alias . '-warning' => 'warning', $alias . '-error' => 'error'],
            CliAliasReader::read($dimension['class']),
        );
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itRejectsWrongOptionsTypeInConstructor(array $dimension): void
    {
        $ruleClass = $dimension['class'];

        self::expectException(InvalidArgumentException::class);

        new $ruleClass(new class implements RuleOptionsInterface {
            public static function fromArray(array $config): static
            {
                return new static();
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getSeverity(int|float $value): ?Severity
            {
                return null;
            }
        });
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itReadsNothingWhenDisabled(array $dimension): void
    {
        $ruleClass = $dimension['class'];
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $rule = new $ruleClass(new TypeCoverageOptions(enabled: false));

        self::assertSame([], $rule->analyze(new AnalysisContext($repository)));
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itIsSilentOnFullCoverage(array $dimension): void
    {
        self::assertSame([], self::analyze($dimension['class'], [
            $dimension['total'] => 5,
            $dimension['coverage'] => 100.0,
        ]));
    }

    /**
     * A class with nothing of this kind to type has no shortfall, which is a
     * different answer from "everything is typed" and reached by a different
     * branch.
     *
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itIsSilentWhenTheDimensionHasNoDeclarations(array $dimension): void
    {
        self::assertSame([], self::analyze($dimension['class'], [$dimension['total'] => 0]));
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itReportsItsOwnCodeSeverityAndMessage(array $dimension): void
    {
        $label = $dimension['label'];
        $hint = $dimension['hint'];
        $findings = self::analyze($dimension['class'], [$dimension['total'] => 10, $dimension['coverage'] => 20.0]);

        self::assertCount(1, $findings);
        self::assertSame($dimension['name'], $findings[0]->ruleName);
        self::assertSame($dimension['name'], $findings[0]->code);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(20.0, $findings[0]->metricValue);
        self::assertSame(50.0, $findings[0]->threshold);
        self::assertSame(
            \sprintf('%s type coverage is 20.0%% (minimum: 50.0%%). %s', $label, $hint),
            $findings[0]->message,
        );
        self::assertSame(
            \sprintf('%s type coverage: 20.0%% (threshold: 50.0%%) — missing type declarations', $label),
            $findings[0]->recommendation,
        );
    }

    /**
     * @param Dimension $dimension
     */
    #[Test]
    #[DataProvider('dimensions')]
    public function itTreatsMissingCoverageAsZeroWhenTheDimensionHasSubjects(array $dimension): void
    {
        $findings = self::analyze($dimension['class'], [$dimension['total'] => 2]);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(0.0, $findings[0]->metricValue);
    }

    /**
     * @return iterable<string, array{class-string<AbstractTypeCoverageRule>, string, string, float, ?Severity, ?float}>
     */
    public static function boundaries(): iterable
    {
        foreach (self::dimensions() as $label => [$dimension]) {
            $arguments = [$dimension['class'], $dimension['total'], $dimension['coverage']];

            yield $label . ' just below warning' => [...$arguments, 79.9, Severity::Warning, 80.0];
            yield $label . ' at warning' => [...$arguments, 80.0, null, null];
            yield $label . ' just below error' => [...$arguments, 49.9, Severity::Error, 50.0];
            yield $label . ' at error' => [...$arguments, 50.0, Severity::Warning, 80.0];
        }
    }

    /**
     * @param class-string<AbstractTypeCoverageRule> $ruleClass
     */
    #[Test]
    #[DataProvider('boundaries')]
    public function itPreservesStrictBoundaries(
        string $ruleClass,
        string $totalMetric,
        string $coverageMetric,
        float $coverage,
        ?Severity $expectedSeverity,
        ?float $expectedThreshold,
    ): void {
        $findings = self::analyze($ruleClass, [$totalMetric => 1, $coverageMetric => $coverage]);

        if ($expectedSeverity === null) {
            self::assertSame([], $findings);

            return;
        }

        self::assertCount(1, $findings);
        self::assertSame($expectedSeverity, $findings[0]->severity);
        self::assertSame($expectedThreshold, $findings[0]->threshold);
        self::assertSame(17, $findings[0]->location->line);
    }

    /**
     * What the split is for: three settings, three answers, in one run.
     */
    #[Test]
    public function itJudgesEachDimensionAgainstItsOwnConfiguration(): void
    {
        $metrics = [
            MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL => 10,
            MetricName::DESIGN_TYPE_COVERAGE_PARAM => 60.0,
            MetricName::DESIGN_TYPE_COVERAGE_RETURN_TOTAL => 10,
            MetricName::DESIGN_TYPE_COVERAGE_RETURN => 60.0,
            MetricName::DESIGN_TYPE_COVERAGE_PROPERTY_TOTAL => 10,
            MetricName::DESIGN_TYPE_COVERAGE_PROPERTY => 60.0,
        ];

        $param = self::analyze(ParamTypeCoverageRule::class, $metrics, new TypeCoverageOptions(warning: 90.0, error: 70.0));
        $return = self::analyze(ReturnTypeCoverageRule::class, $metrics, new TypeCoverageOptions(warning: 65.0, error: 50.0));
        $property = self::analyze(PropertyTypeCoverageRule::class, $metrics, new TypeCoverageOptions(enabled: false));

        self::assertCount(1, $param);
        self::assertSame(Severity::Error, $param[0]->severity);
        self::assertCount(1, $return);
        self::assertSame(Severity::Warning, $return[0]->severity);
        self::assertSame([], $property);
    }

    /**
     * Two declarations of one logical class are two subjects, and each gets
     * its own finding — the projection ADR 0026 settled.
     */
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn(MetricBag::fromArray([
            MetricName::DESIGN_TYPE_COVERAGE_PARAM_TOTAL => 4,
            MetricName::DESIGN_TYPE_COVERAGE_PARAM => 25.0,
        ]));

        $findings = (new ParamTypeCoverageRule(new TypeCoverageOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    /**
     * @param class-string<AbstractTypeCoverageRule> $ruleClass
     * @param array<string, int|float> $metrics
     *
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
     */
    private static function analyze(string $ruleClass, array $metrics, ?TypeCoverageOptions $options = null): array
    {
        $classInfo = self::subjectInfo(
            SymbolPath::forClass('App\\Service', 'TypedService'),
            RelativePath::fromString('src/TypedService.php'),
            17,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn(MetricBag::fromArray($metrics));

        return (new $ruleClass($options ?? new TypeCoverageOptions()))->analyze(new AnalysisContext($repository));
    }

    private static function subjectInfo(SymbolPath $symbolPath, RelativePath $file, int $line): SymbolInfo
    {
        $type = $symbolPath->getType();
        $kind = $type === SymbolType::Class_
            ? null
            : ($type === SymbolType::Function_ ? CallableKind::Function : CallableKind::Method);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($symbolPath, $file, DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
