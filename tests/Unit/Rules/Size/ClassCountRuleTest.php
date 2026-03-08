<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Size;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\Size\ClassCountOptions;
use AiMessDetector\Rules\Size\ClassCountRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassCountRule::class)]
#[CoversClass(ClassCountOptions::class)]
final class ClassCountRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame('size.class-count', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame('Checks number of classes per namespace', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame(RuleCategory::Size, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame(['classCount'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(ClassCountOptions::class, ClassCountRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            ['class-count-warning' => 'warning', 'class-count-error' => 'error'],
            ClassCountRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ClassCountRule(new class implements \AiMessDetector\Core\Rule\RuleOptionsInterface {
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
        $rule = new ClassCountRule(new ClassCountOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        $metricBag = (new MetricBag())->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        // 18 classes is above warning (15) but below error (25)
        $metricBag = (new MetricBag())->with('classCount.sum', 18);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Class count is 18, exceeds threshold of 15. Consider splitting into sub-namespaces', $violations[0]->message);
        self::assertSame(18, $violations[0]->metricValue);
        self::assertSame('size.class-count', $violations[0]->ruleName);
        self::assertSame('size.class-count', $violations[0]->violationCode);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 0);

        // 30 classes is above error threshold (25)
        $metricBag = (new MetricBag())->with('classCount.sum', 30);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Class count is 30, exceeds threshold of 25. Consider splitting into sub-namespaces', $violations[0]->message);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $classCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ClassCountRule(new ClassCountOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forNamespace('App\Test');
        $nsInfo = new SymbolInfo($symbolPath, 'test.php', 0);

        $metricBag = (new MetricBag())->with('classCount.sum', $classCount);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
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
        yield 'below warning' => [9, 10, 15, null];
        yield 'at warning' => [10, 10, 15, Severity::Warning];
        yield 'above warning, below error' => [12, 10, 15, Severity::Warning];
        yield 'at error' => [15, 10, 15, Severity::Error];
        yield 'above error' => [20, 10, 15, Severity::Error];
    }

    public function testOptionsFromArrayDefaults(): void
    {
        $options = ClassCountOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(15, $options->warning);
        self::assertSame(25, $options->error);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = ClassCountOptions::fromArray([
            'enabled' => true,
            'warning' => 10,
            'error' => 20,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = ClassCountOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
