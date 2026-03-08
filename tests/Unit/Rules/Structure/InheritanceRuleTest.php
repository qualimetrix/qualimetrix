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
use AiMessDetector\Rules\Structure\InheritanceOptions;
use AiMessDetector\Rules\Structure\InheritanceRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InheritanceRule::class)]
#[CoversClass(InheritanceOptions::class)]
final class InheritanceRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame('design.inheritance', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(
            'Checks Depth of Inheritance Tree (deep hierarchies increase complexity)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(['dit'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            InheritanceOptions::class,
            InheritanceRule::getOptionsClass(),
        );
    }

    public function testThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        new InheritanceRule($wrongOptions);
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoClasses(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'DeepClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/DeepClass.php', 10);

        // DIT of 5 is at warning threshold (5) but below error (7)
        $metricBag = (new MetricBag())->with('dit', 5);

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
        self::assertStringContainsString('DIT (Depth of Inheritance) is 5', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 4', $violations[0]->message);
        self::assertStringContainsString('Prefer composition over deep inheritance', $violations[0]->message);
        self::assertSame(5, $violations[0]->metricValue);
        self::assertSame('design.inheritance', $violations[0]->ruleName);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryDeepClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/VeryDeepClass.php', 10);

        // DIT of 8 is above error threshold (7)
        $metricBag = (new MetricBag())->with('dit', 8);

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
        self::assertSame(8, $violations[0]->metricValue);
    }

    public function testAnalyzeNoViolationForShallowDit(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ShallowClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/ShallowClass.php', 10);

        // DIT of 2 is normal (below warning threshold 5)
        $metricBag = (new MetricBag())->with('dit', 2);

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

    public function testAnalyzeSkipsClassWithoutDitMetric(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SomeClass.php', 10);

        // No 'dit' metric
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
        $options = InheritanceOptions::fromArray([
            'enabled' => false,
            'warning' => 4,
            'error' => 6,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = InheritanceOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    public function testOptionsDefaults(): void
    {
        $options = new InheritanceOptions();

        self::assertTrue($options->enabled);
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $dit,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new InheritanceRule(
            new InheritanceOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('dit', $dit);

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
        // Higher DIT is worse
        yield 'below warning threshold' => [4, 5, 7, null];
        yield 'at warning threshold' => [5, 5, 7, Severity::Warning];
        yield 'above warning, below error' => [6, 5, 7, Severity::Warning];
        yield 'at error threshold' => [7, 5, 7, Severity::Error];
        yield 'above error threshold' => [10, 5, 7, Severity::Error];
    }

    public function testGetCliAliases(): void
    {
        $aliases = InheritanceRule::getCliAliases();

        self::assertArrayHasKey('dit-warning', $aliases);
        self::assertArrayHasKey('dit-error', $aliases);
        self::assertSame('warning', $aliases['dit-warning']);
        self::assertSame('error', $aliases['dit-error']);
    }
}
