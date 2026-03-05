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
use AiMessDetector\Rules\Structure\NocOptions;
use AiMessDetector\Rules\Structure\NocRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NocRule::class)]
#[CoversClass(NocOptions::class)]
final class NocRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame('noc', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(
            'Checks Number of Children (many direct subclasses indicate wide impact)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(['noc'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            NocOptions::class,
            NocRule::getOptionsClass(),
        );
    }

    public function testThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new NocRule($wrongOptions);
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new NocRule(new NocOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoClasses(): void
    {
        $rule = new NocRule(new NocOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeSkipsClassesWithZeroNoc(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/LeafClass.php', 10);

        // NOC of 0 means no children (should be skipped)
        $metricBag = (new MetricBag())->with('noc', 0);

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

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'BaseService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/BaseService.php', 10);

        // NOC of 12 is above warning threshold (10) but below error (15)
        $metricBag = (new MetricBag())->with('noc', 12);

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
        self::assertStringContainsString('NOC (Number of Children) is 12', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 10', $violations[0]->message);
        self::assertStringContainsString('Consider using interfaces instead of inheritance', $violations[0]->message);
        self::assertSame(12, $violations[0]->metricValue);
        self::assertSame('noc', $violations[0]->ruleName);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryPopularBase');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/VeryPopularBase.php', 10);

        // NOC of 20 is above error threshold (15)
        $metricBag = (new MetricBag())->with('noc', 20);

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
        self::assertSame(20, $violations[0]->metricValue);
    }

    public function testAnalyzeNoViolationForFewChildren(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ReasonableBase');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/ReasonableBase.php', 10);

        // NOC of 3 is normal (below warning threshold 7)
        $metricBag = (new MetricBag())->with('noc', 3);

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

    public function testAnalyzeSkipsClassWithoutNocMetric(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SomeClass.php', 10);

        // No 'noc' metric
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
        $options = NocOptions::fromArray([
            'enabled' => false,
            'warning' => 10,
            'error' => 20,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = NocOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testOptionsDefaults(): void
    {
        $options = new NocOptions();

        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(15, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $noc,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new NocRule(
            new NocOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('noc', $noc);

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
        // Higher NOC is worse
        yield 'below warning threshold' => [6, 7, 15, null];
        yield 'at warning threshold' => [7, 7, 15, Severity::Warning];
        yield 'above warning, below error' => [10, 7, 15, Severity::Warning];
        yield 'at error threshold' => [15, 7, 15, Severity::Error];
        yield 'above error threshold' => [25, 7, 15, Severity::Error];
    }

    public function testGetCliAliases(): void
    {
        $aliases = NocRule::getCliAliases();

        self::assertArrayHasKey('noc-warning', $aliases);
        self::assertArrayHasKey('noc-error', $aliases);
        self::assertSame('warning', $aliases['noc-warning']);
        self::assertSame('error', $aliases['noc-error']);
    }
}
