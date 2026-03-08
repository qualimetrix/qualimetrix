<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Structure;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Structure\LcomOptions;
use AiMessDetector\Rules\Structure\LcomRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LcomRule::class)]
#[CoversClass(LcomOptions::class)]
final class LcomRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame('design.lcom', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(
            'Checks Lack of Cohesion of Methods (high values indicate class should be split)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(['lcom', 'methodCount', 'isReadonly'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            LcomOptions::class,
            LcomRule::getOptionsClass(),
        );
    }

    public function testThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new LcomRule($wrongOptions);
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new LcomRule(new LcomOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoClasses(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GodClass.php', 10);

        // LCOM of 4 is above warning threshold (3) but below error (5)
        $metricBag = (new MetricBag())
            ->with('lcom', 4)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

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
        self::assertStringContainsString('LCOM (Lack of Cohesion) is 4', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 3', $violations[0]->message);
        self::assertStringContainsString('Class could be split into 4 cohesive parts', $violations[0]->message);
        self::assertSame(4, $violations[0]->metricValue);
        self::assertSame('design.lcom', $violations[0]->ruleName);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryLargeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/VeryLargeClass.php', 10);

        // LCOM of 5 is above error threshold (4)
        $metricBag = (new MetricBag())
            ->with('lcom', 5)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

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
        self::assertSame(5, $violations[0]->metricValue);
    }

    public function testAnalyzeNoViolationForCohesiveClass(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'CohesiveClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/CohesiveClass.php', 10);

        // LCOM of 1 means perfectly cohesive (below warning threshold 2)
        $metricBag = (new MetricBag())->with('lcom', 1);

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

    public function testAnalyzeSkipsClassWithoutLcomMetric(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SomeClass.php', 10);

        // No 'lcom' metric
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
        $options = LcomOptions::fromArray([
            'enabled' => false,
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = LcomOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testOptionsDefaults(): void
    {
        $options = new LcomOptions();

        self::assertTrue($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $lcom,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LcomRule(
            new LcomOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('lcom', $lcom)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

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
        // Higher LCOM is worse
        yield 'below warning threshold' => [1, 2, 4, null];
        yield 'at warning threshold' => [2, 2, 4, Severity::Warning];
        yield 'above warning, below error' => [3, 2, 4, Severity::Warning];
        yield 'at error threshold' => [4, 2, 4, Severity::Error];
        yield 'above error threshold' => [6, 2, 4, Severity::Error];
    }

    public function testGetCliAliases(): void
    {
        $aliases = LcomRule::getCliAliases();

        self::assertArrayHasKey('lcom-warning', $aliases);
        self::assertArrayHasKey('lcom-error', $aliases);
        self::assertSame('warning', $aliases['lcom-warning']);
        self::assertSame('error', $aliases['lcom-error']);
    }
}
