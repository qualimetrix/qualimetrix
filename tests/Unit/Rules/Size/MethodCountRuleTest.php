<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Size;

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
use Qualimetrix\Rules\Size\MethodCountOptions;
use Qualimetrix\Rules\Size\MethodCountRule;

#[CoversClass(MethodCountRule::class)]
#[CoversClass(MethodCountOptions::class)]
final class MethodCountRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame('size.method-count', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame('Checks number of methods per class', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame(RuleCategory::Size, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        self::assertSame(['methodCount'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(MethodCountOptions::class, MethodCountRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            ['method-count-warning' => 'warning', 'method-count-error' => 'error'],
            MethodCountRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MethodCountRule(new class implements \Qualimetrix\Core\Rule\RuleOptionsInterface {
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
        $rule = new MethodCountRule(new MethodCountOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions(warning: 10, error: 20));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Method count is 15, exceeds threshold of 10. Consider splitting into smaller focused classes', $violations[0]->message);
        self::assertSame(15, $violations[0]->metricValue);
        self::assertSame('size.method-count', $violations[0]->ruleName);
        self::assertSame('size.method-count', $violations[0]->violationCode);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new MethodCountRule(new MethodCountOptions(warning: 10, error: 20));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', 25);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Method count is 25, exceeds threshold of 20. Consider splitting into smaller focused classes', $violations[0]->message);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $methodCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new MethodCountRule(new MethodCountOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forClass('App\Test', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())->with('methodCount', $methodCount);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
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
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        yield 'below warning' => [14, 15, 25, null];
        yield 'at warning' => [15, 15, 25, Severity::Warning];
        yield 'above warning, below error' => [20, 15, 25, Severity::Warning];
        yield 'at error' => [25, 15, 25, Severity::Error];
        yield 'above error' => [30, 15, 25, Severity::Error];
    }

    public function testOptionsFromArrayDefaults(): void
    {
        $options = MethodCountOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(20, $options->warning);
        self::assertSame(30, $options->error);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = MethodCountOptions::fromArray([
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
        $options = MethodCountOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
