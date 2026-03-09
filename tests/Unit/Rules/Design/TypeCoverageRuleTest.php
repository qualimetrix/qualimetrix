<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Design;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Design\TypeCoverageOptions;
use AiMessDetector\Rules\Design\TypeCoverageRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeCoverageRule::class)]
#[CoversClass(TypeCoverageOptions::class)]
final class TypeCoverageRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame('design.type-coverage', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(
            'Checks type coverage of parameters, return types, and properties per class',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        self::assertSame(['typeCoverage.param'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(TypeCoverageOptions::class, TypeCoverageRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
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
            TypeCoverageRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TypeCoverageRule(new class implements \AiMessDetector\Core\Rule\RuleOptionsInterface {
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

    public function testAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testFullCoverageNoViolations(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

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

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testLowParamCoverageWarning(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 7)
            ->with('typeCoverage.param', 70.0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
        self::assertStringContainsString('70.0%', $violations[0]->message);
        self::assertStringContainsString('80.0%', $violations[0]->message);
    }

    public function testLowParamCoverageError(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            paramWarning: 80.0,
            paramError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 10)
            ->with('typeCoverage.paramTyped', 3)
            ->with('typeCoverage.param', 30.0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
    }

    public function testLowReturnCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            returnWarning: 80.0,
            returnError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 4)
            ->with('typeCoverage.returnTyped', 1)
            ->with('typeCoverage.return', 25.0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.return', $violations[0]->violationCode);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testLowPropertyCoverage(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions(
            propertyWarning: 80.0,
            propertyError: 50.0,
        ));

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 5);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 5)
            ->with('typeCoverage.propertyTyped', 3)
            ->with('typeCoverage.property', 60.0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame('design.type-coverage.property', $violations[0]->violationCode);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testMultipleViolationsPerClass(): void
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
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

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

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(3, $violations);
        self::assertSame('design.type-coverage.param', $violations[0]->violationCode);
        self::assertSame('design.type-coverage.return', $violations[1]->violationCode);
        self::assertSame('design.type-coverage.property', $violations[2]->violationCode);
    }

    public function testClassWithNoMethodsNoViolation(): void
    {
        $rule = new TypeCoverageRule(new TypeCoverageOptions());

        $symbolPath = SymbolPath::forClass('App', 'EmptyClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('typeCoverage.paramTotal', 0)
            ->with('typeCoverage.returnTotal', 0)
            ->with('typeCoverage.propertyTotal', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testCustomThresholds(): void
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

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = TypeCoverageOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    public function testOptionsFromArrayDefaults(): void
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

    public function testOptionsSeverityMethods(): void
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
}
