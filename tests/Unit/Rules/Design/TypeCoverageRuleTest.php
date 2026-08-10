<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Design;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Design\TypeCoverageOptions;
use Qualimetrix\Rules\Design\TypeCoverageRule;

#[CoversClass(TypeCoverageRule::class)]
#[CoversClass(TypeCoverageOptions::class)]
final class TypeCoverageRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame('design.type-coverage', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(
            'Checks type coverage of parameters, return types, and properties per class',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetsCategory(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(['typeCoverage.param'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(TypeCoverageOptions::class, TypeCoverageRule::getOptionsClass());
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        self::assertSame(
            [
                'type-coverage-param-warning' => 'param_warning',
                'type-coverage-param-error' => 'param_error',
                'type-coverage-return-warning' => 'return_warning',
                'type-coverage-return-error' => 'return_error',
                'type-coverage-property-warning' => 'property_warning',
                'type-coverage-property-error' => 'property_error',
            ],
            CliAliasReader::read(TypeCoverageRule::class),
        );
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        new TypeCoverageRule(new class implements \Qualimetrix\Core\Rule\RuleOptionsInterface {
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

    #[Test]
    public function itAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itProducesNoViolationsWithFullCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 5)
            ->with('typeCoverage.paramTyped', 5)
            ->with('typeCoverage.param', 100.0)
            ->with('typeCoverage.returnTotal', 3)
            ->with('typeCoverage.returnTyped', 3)
            ->with('typeCoverage.return', 100.0)
            ->with('typeCoverage.propertyTotal', 2)
            ->with('typeCoverage.propertyTyped', 2)
            ->with('typeCoverage.property', 100.0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itWarnsOnLowParamCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 7)
            ->with('typeCoverage.param', 70.0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
        self::assertStringContainsString('70.0%', $violations[0]->message);
        self::assertStringContainsString('80.0%', $violations[0]->message);
    }

    #[Test]
    public function itErrorsOnLowParamCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 3)
            ->with('typeCoverage.param', 30.0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
    }

    #[Test]
    public function itFlagsLowReturnCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            returnWarning: 80.0,
            returnError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 4)
            ->with('typeCoverage.returnTyped', 1)
            ->with('typeCoverage.return', 25.0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.return', $violations[0]->violationCode);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itFlagsLowPropertyCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            propertyWarning: 80.0,
            propertyError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 5)
            ->with('typeCoverage.propertyTyped', 3)
            ->with('typeCoverage.property', 60.0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.property', $violations[0]->violationCode);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function itProducesMultipleViolationsPerClass(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
            returnWarning: 80.0,
            returnError: 50.0,
            propertyWarning: 80.0,
            propertyError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'BadClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 2)
            ->with('typeCoverage.param', 20.0)
            ->with('typeCoverage.returnTotal', 5)
            ->with('typeCoverage.returnTyped', 1)
            ->with('typeCoverage.return', 20.0)
            ->with('typeCoverage.propertyTotal', 4)
            ->with('typeCoverage.propertyTyped', 0)
            ->with('typeCoverage.property', 0.0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
        self::assertSame('design.type-coverage.return', $violations[1]->violationCode);
        self::assertSame('design.type-coverage.property', $violations[2]->violationCode);
    }

    #[Test]
    public function itProducesNoViolationForClassWithNoMethods(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        $symbolPath = SymbolPath::forClass('App', 'EmptyClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itLoadsCustomThresholds(): void
    {
        $options = TypeCoverageOptions::fromArray([
            'enabled' => true,
            'param_warning' => 90.0,
            'param_error' => 70.0,
            'return_warning' => 95.0,
            'return_error' => 80.0,
            'property_warning' => 85.0,
            'property_error' => 60.0,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(90.0, $options->paramWarning);
        self::assertSame(70.0, $options->paramError);
        self::assertSame(95.0, $options->returnWarning);
        self::assertSame(80.0, $options->returnError);
        self::assertSame(85.0, $options->propertyWarning);
        self::assertSame(60.0, $options->propertyError);
    }

    #[Test]
    public function itDisablesWhenLoadedFromEmptyArray(): void
    {
        $options = TypeCoverageOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itHasOptionsDefaults(): void
    {
        $options = TypeCoverageOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(80.0, $options->paramWarning);
        self::assertSame(50.0, $options->paramError);
        self::assertSame(80.0, $options->returnWarning);
        self::assertSame(50.0, $options->returnError);
        self::assertSame(80.0, $options->propertyWarning);
        self::assertSame(50.0, $options->propertyError);
    }

    #[Test]
    public function itComputesSeverityViaOptionsMethods(): void
    {
        $options = new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
            returnWarning: 80.0,
            returnError: 50.0,
            propertyWarning: 80.0,
            propertyError: 50.0,
        );

        // Below error threshold
        self::assertSame(Severity::Error, $options->getParamSeverity(30.0));
        self::assertSame(Severity::Error, $options->getReturnSeverity(30.0));
        self::assertSame(Severity::Error, $options->getPropertySeverity(30.0));

        // Between warning and error
        self::assertSame(Severity::Warning, $options->getParamSeverity(60.0));
        self::assertSame(Severity::Warning, $options->getReturnSeverity(60.0));
        self::assertSame(Severity::Warning, $options->getPropertySeverity(60.0));

        // Above warning threshold
        self::assertNull($options->getParamSeverity(90.0));
        self::assertNull($options->getReturnSeverity(90.0));
        self::assertNull($options->getPropertySeverity(90.0));

        // Generic getSeverity always returns null
        self::assertNull($options->getSeverity(30.0));
    }

    #[Test]
    #[DataProvider('coverageBoundaryProvider')]
    public function itPreservesStrictBoundariesForEveryCoverageDimension(
        string $totalMetric,
        string $coverageMetric,
        string $code,
        float $coverage,
        ?Severity $expectedSeverity,
        ?float $expectedThreshold,
    ): void {
        $symbolPath = SymbolPath::forClass('App\\Service', 'TypedService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/TypedService.php'), 17);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([$totalMetric => 1, $coverageMetric => $coverage]),
        );

        $violations = (new TypeCoverageRule(new TypeCoverageOptions()))
            ->analyze(new AnalysisContext($repository));

        if ($expectedSeverity === null) {
            self::assertSame([], $violations);
            return;
        }

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.' . $code, $violations[0]->violationCode);
        self::assertSame($expectedSeverity, $violations[0]->severity);
        self::assertSame($expectedThreshold, $violations[0]->threshold);
        self::assertSame($classInfo->subject?->toCanonical(), $violations[0]->subject->toCanonical());
        self::assertSame(17, $violations[0]->location->line);
    }

    /**
     * @return iterable<string, array{string, string, string, float, ?Severity, ?float}>
     */
    public static function coverageBoundaryProvider(): iterable
    {
        $dimensions = [
            'param' => [MetricName::TYPE_COVERAGE_PARAM_TOTAL, MetricName::TYPE_COVERAGE_PARAM],
            'return' => [MetricName::TYPE_COVERAGE_RETURN_TOTAL, MetricName::TYPE_COVERAGE_RETURN],
            'property' => [MetricName::TYPE_COVERAGE_PROPERTY_TOTAL, MetricName::TYPE_COVERAGE_PROPERTY],
        ];

        foreach ($dimensions as $code => [$totalMetric, $coverageMetric]) {
            yield $code . ' just below warning' => [$totalMetric, $coverageMetric, $code, 79.9, Severity::Warning, 80.0];
            yield $code . ' at warning' => [$totalMetric, $coverageMetric, $code, 80.0, null, null];
            yield $code . ' just below error' => [$totalMetric, $coverageMetric, $code, 49.9, Severity::Error, 50.0];
            yield $code . ' at error' => [$totalMetric, $coverageMetric, $code, 50.0, Severity::Warning, 80.0];
        }
    }

    #[Test]
    public function itTreatsMissingCoverageAsZeroWhenTheDimensionHasSubjects(): void
    {
        $symbolPath = SymbolPath::forClass('App\\Service', 'UntypedService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/UntypedService.php'), 19);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::TYPE_COVERAGE_PARAM_TOTAL => 2]),
        );

        $violations = (new TypeCoverageRule(new TypeCoverageOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.0, $violations[0]->metricValue);
    }

    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn(
            (new MetricBag())
                ->with('typeCoverage.paramTotal', 4)
                ->with('typeCoverage.param', 25.0)
                ->with('typeCoverage.returnTotal', 0)
                ->with('typeCoverage.propertyTotal', 0),
        );

        $violations = (new TypeCoverageRule(new TypeCoverageOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);
        $subjects = array_map(static fn($violation): string => $violation->subject->toCanonical(), $violations);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php:100',
            'declaration:class:App\\Service\\Twin@src/B.php:200',
        ], $subjects);
    }

    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath($symbolPath, $file, $line ?? 0)),
            $file,
            $line,
            $kind,
        );
    }
}
