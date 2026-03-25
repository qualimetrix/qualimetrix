<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Maintainability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Maintainability\MaintainabilityOptions;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;

#[CoversClass(MaintainabilityRule::class)]
#[CoversClass(MaintainabilityOptions::class)]
final class MaintainabilityRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame('maintainability.index', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame(
            'Checks Maintainability Index (lower values indicate harder to maintain code)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame(RuleCategory::Maintainability, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        self::assertSame(['mi', 'methodLoc'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            MaintainabilityOptions::class,
            MaintainabilityRule::getOptionsClass(),
        );
    }

    public function testThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new MaintainabilityRule($wrongOptions);
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoMethods(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // MI of 30 is below warning threshold (40) but above error (20)
        $metricBag = (new MetricBag())
            ->with('mi', 30.0)
            ->with('methodLoc', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Maintainability Index is 30.0', $violations[0]->message);
        self::assertSame(30.0, $violations[0]->metricValue);
        self::assertSame('maintainability.index', $violations[0]->ruleName);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // MI of 15 is below error threshold (20) - very poor maintainability
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodLoc', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(15.0, $violations[0]->metricValue);
    }

    public function testAnalyzeNoViolationForHighMi(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'simple');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // MI of 90 is good (above warning threshold 65)
        $metricBag = (new MetricBag())
            ->with('mi', 90.0)
            ->with('methodLoc', 12);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testMetricValueIsFloatWithOneDecimal(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('mi', 25.67)
            ->with('methodLoc', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(25.7, $violations[0]->metricValue);
        self::assertIsFloat($violations[0]->metricValue);
    }

    public function testAnalyzeSkipsMethodWithoutMiMetric(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions());

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // No 'mi' metric
        $metricBag = new MetricBag();

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    // Options tests

    public function testOptionsFromArray(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'enabled' => false,
            'warning' => 70.0,
            'error' => 55.0,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(70.0, $options->warning);
        self::assertSame(55.0, $options->error);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = MaintainabilityOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testOptionsDefaults(): void
    {
        $options = new MaintainabilityOptions();

        self::assertTrue($options->enabled);
        self::assertSame(40.0, $options->warning);
        self::assertSame(20.0, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        float $mi,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new MaintainabilityRule(
            new MaintainabilityOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('mi', $mi)
            ->with('methodLoc', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
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
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        // Note: Lower MI is worse, so "above" warning means good, "below" means bad
        yield 'above warning threshold (good)' => [70.0, 65.0, 50.0, null];
        yield 'at warning threshold (good)' => [65.0, 65.0, 50.0, null];
        yield 'below warning, above error' => [60.0, 65.0, 50.0, Severity::Warning];
        yield 'at error threshold' => [50.0, 65.0, 50.0, Severity::Warning];
        yield 'below error threshold' => [40.0, 65.0, 50.0, Severity::Error];
    }

    public function testGetCliAliases(): void
    {
        $aliases = MaintainabilityRule::getCliAliases();

        self::assertArrayHasKey('mi-warning', $aliases);
        self::assertArrayHasKey('mi-error', $aliases);
        self::assertArrayHasKey('mi-exclude-tests', $aliases);
        self::assertArrayHasKey('mi-min-loc', $aliases);
        self::assertSame('warning', $aliases['mi-warning']);
        self::assertSame('error', $aliases['mi-error']);
        self::assertSame('excludeTests', $aliases['mi-exclude-tests']);
        self::assertSame('minLoc', $aliases['mi-min-loc']);
    }

    public function testOptionsFromArrayWithExcludeTests(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'exclude_tests' => false,
            'min_loc' => 20,
        ]);

        self::assertFalse($options->excludeTests);
        self::assertSame(20, $options->minLoc);
    }

    public function testOptionsFromArrayWithCamelCase(): void
    {
        $options = MaintainabilityOptions::fromArray([
            'excludeTests' => false,
            'minLoc' => 15,
        ]);

        self::assertFalse($options->excludeTests);
        self::assertSame(15, $options->minLoc);
    }

    public function testAnalyzeSkipsTestFiles(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(excludeTests: true));

        $symbolPath = SymbolPath::forMethod('App\Tests', 'UserServiceTest', 'testCalculate');
        $methodInfo = new SymbolInfo($symbolPath, 'tests/Service/UserServiceTest.php', 10);

        // Low MI that would normally trigger a violation
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodLoc', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should be skipped because it's a test file
        self::assertCount(0, $violations);
    }

    public function testAnalyzeIncludesTestFilesWhenNotExcluded(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(excludeTests: false));

        $symbolPath = SymbolPath::forMethod('App\Tests', 'UserServiceTest', 'testCalculate');
        $methodInfo = new SymbolInfo($symbolPath, 'tests/Service/UserServiceTest.php', 10);

        // Low MI that would trigger a violation
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodLoc', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should NOT be skipped
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testAnalyzeSkipsMethodsWithLowLoc(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(minLoc: 15));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // Low MI and low LOC (below minLoc threshold)
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodLoc', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should be skipped because methodLoc < minLoc
        self::assertCount(0, $violations);
    }

    public function testAnalyzeIncludesMethodsWithSufficientLoc(): void
    {
        $rule = new MaintainabilityRule(new MaintainabilityOptions(minLoc: 15));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // Low MI and sufficient LOC (above minLoc threshold)
        $metricBag = (new MetricBag())
            ->with('mi', 15.0)
            ->with('methodLoc', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$methodInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        // Should NOT be skipped
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }
}
