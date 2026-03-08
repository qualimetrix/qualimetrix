<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Structure;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Structure\WmcOptions;
use AiMessDetector\Rules\Structure\WmcRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WmcRule::class)]
#[CoversClass(WmcOptions::class)]
final class WmcRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame('complexity.wmc', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(
            'Checks Weighted Methods per Class (sum of method complexities)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(['wmc', 'isDataClass'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            WmcOptions::class,
            WmcRule::getOptionsClass(),
        );
    }

    public function testThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new WmcRule($wrongOptions);
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new WmcRule(new WmcOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoClasses(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testNoViolationBelowThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SimpleClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SimpleClass.php', 10);

        // WMC of 20 is below warning threshold (50)
        $metricBag = (new MetricBag())->with('wmc', 20);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testWarningAboveWarningThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MediumClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/MediumClass.php', 10);

        // WMC of 60 is above warning threshold (50) but below error (80)
        $metricBag = (new MetricBag())->with('wmc', 60);

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
        self::assertStringContainsString('WMC (Weighted Methods per Class) is 60', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 50', $violations[0]->message);
        self::assertStringContainsString('Simplify methods or split the class', $violations[0]->message);
        self::assertSame(60, $violations[0]->metricValue);
        self::assertSame('complexity.wmc', $violations[0]->ruleName);
    }

    public function testErrorAboveErrorThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ComplexClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/ComplexClass.php', 10);

        // WMC of 85 is above error threshold (80)
        $metricBag = (new MetricBag())->with('wmc', 85);

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
        self::assertStringContainsString('WMC (Weighted Methods per Class) is 85', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 80', $violations[0]->message);
        self::assertStringContainsString('Simplify methods or split the class', $violations[0]->message);
        self::assertSame(85, $violations[0]->metricValue);
    }

    public function testCustomThresholds(): void
    {
        $rule = new WmcRule(new WmcOptions(warning: 20, error: 40));

        $symbolPath = SymbolPath::forClass('App\Service', 'CustomClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/CustomClass.php', 10);

        // WMC of 25 is above custom warning threshold (20) but below custom error (40)
        $metricBag = (new MetricBag())->with('wmc', 25);

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
    }

    public function testClassWithoutMethods(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'EmptyClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/EmptyClass.php', 10);

        // WMC of 0 for class without methods
        $metricBag = (new MetricBag())->with('wmc', 0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testMultipleClasses(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath1 = SymbolPath::forClass('App', 'SimpleClass');
        $symbolPath2 = SymbolPath::forClass('App', 'ComplexClass');

        $classInfo1 = new SymbolInfo($symbolPath1, 'src/SimpleClass.php', 10);
        $classInfo2 = new SymbolInfo($symbolPath2, 'src/ComplexClass.php', 20);

        $metricBag1 = (new MetricBag())->with('wmc', 20); // No violation
        $metricBag2 = (new MetricBag())->with('wmc', 90); // Error

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo1, $classInfo2]);
        $repository->method('get')
            ->willReturnCallback(function ($path) use ($symbolPath1, $symbolPath2, $metricBag1, $metricBag2) {
                if ($path === $symbolPath1) {
                    return $metricBag1;
                }
                if ($path === $symbolPath2) {
                    return $metricBag2;
                }
                throw new RuntimeException('Unexpected path');
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame($symbolPath2, $violations[0]->symbolPath);
    }

    public function testAnalyzeSkipsClassWithoutWmcMetric(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SomeClass.php', 10);

        // No 'wmc' metric
        $metricBag = new MetricBag();

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    // Options tests

    public function testOptionsFromArray(): void
    {
        $options = WmcOptions::fromArray([
            'enabled' => false,
            'warning' => 20,
            'error' => 40,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(20, $options->warning);
        self::assertSame(40, $options->error);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = WmcOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testOptionsDefaults(): void
    {
        $options = new WmcOptions();

        self::assertTrue($options->enabled);
        self::assertSame(50, $options->warning);
        self::assertSame(80, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $wmc,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new WmcRule(
            new WmcOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('wmc', $wmc);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $violations);
        } else {
            self::assertCount(1, $violations);
            self::assertSame($expectedSeverity, $violations[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        // Higher WMC is worse
        yield 'below warning threshold' => [29, 30, 50, null];
        yield 'at warning threshold' => [30, 30, 50, Severity::Warning];
        yield 'just above warning threshold' => [31, 30, 50, Severity::Warning];
        yield 'above warning, below error' => [45, 30, 50, Severity::Warning];
        yield 'at error threshold' => [50, 30, 50, Severity::Error];
        yield 'just above error threshold' => [51, 30, 50, Severity::Error];
        yield 'far above error threshold' => [100, 30, 50, Severity::Error];
    }

    public function testGetCliAliases(): void
    {
        $aliases = WmcRule::getCliAliases();

        self::assertArrayHasKey('wmc-warning', $aliases);
        self::assertArrayHasKey('wmc-error', $aliases);
        self::assertArrayHasKey('wmc-exclude-data-classes', $aliases);
        self::assertSame('warning', $aliases['wmc-warning']);
        self::assertSame('error', $aliases['wmc-error']);
        self::assertSame('excludeDataClasses', $aliases['wmc-exclude-data-classes']);
    }

    public function testExcludeDataClassesOptionDefault(): void
    {
        $options = new WmcOptions();

        self::assertFalse($options->excludeDataClasses);
    }

    public function testExcludeDataClassesFromArray(): void
    {
        $options = WmcOptions::fromArray([
            'exclude_data_classes' => true,
        ]);

        self::assertTrue($options->excludeDataClasses);
    }

    public function testExcludeDataClassesFromArrayCamelCase(): void
    {
        $options = WmcOptions::fromArray([
            'excludeDataClasses' => true,
        ]);

        self::assertTrue($options->excludeDataClasses);
    }

    public function testExcludeDataClassesSkipsDataClasses(): void
    {
        $rule = new WmcRule(new WmcOptions(excludeDataClasses: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'UserDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        // WMC of 60 is above warning threshold (50), but isDataClass = 1
        $metricBag = (new MetricBag())
            ->with('wmc', 60)
            ->with('isDataClass', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should skip data class
        self::assertCount(0, $violations);
    }

    public function testExcludeDataClassesDisabledDoesNotSkip(): void
    {
        $rule = new WmcRule(new WmcOptions(excludeDataClasses: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'UserDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        // WMC of 90 is above error threshold (80), and isDataClass = 1
        $metricBag = (new MetricBag())
            ->with('wmc', 90)
            ->with('isDataClass', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should NOT skip when excludeDataClasses is false
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }
}
