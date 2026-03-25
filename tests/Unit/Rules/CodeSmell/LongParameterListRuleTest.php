<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\CodeSmell;

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
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\LongParameterListOptions;
use Qualimetrix\Rules\CodeSmell\LongParameterListRule;

#[CoversClass(LongParameterListRule::class)]
#[CoversClass(LongParameterListOptions::class)]
final class LongParameterListRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame('code-smell.long-parameter-list', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame('Checks number of parameters per method', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame(['parameterCount'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(LongParameterListOptions::class, LongParameterListRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            ['long-parameter-list-warning' => 'warning', 'long-parameter-list-error' => 'error'],
            LongParameterListRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LongParameterListRule(new class implements \Qualimetrix\Core\Rule\RuleOptionsInterface {
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
        $rule = new LongParameterListRule(new LongParameterListOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testBelowWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 3);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAtWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 4);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Method has 4 parameters, exceeds threshold of 4. Consider introducing a parameter object', $violations[0]->message);
        self::assertSame(4, $violations[0]->metricValue);
        self::assertSame('code-smell.long-parameter-list', $violations[0]->ruleName);
        self::assertSame('code-smell.long-parameter-list', $violations[0]->violationCode);
    }

    public function testAtErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 6);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Method has 6 parameters, exceeds threshold of 6. Consider introducing a parameter object', $violations[0]->message);
    }

    public function testAboveErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 8);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(8, $violations[0]->metricValue);
    }

    public function testCustomThresholds(): void
    {
        $options = LongParameterListOptions::fromArray([
            'enabled' => true,
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $parameterCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forMethod('App\Test', 'TestClass', 'testMethod');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', $parameterCount);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
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
        yield 'below warning' => [3, 4, 6, null];
        yield 'at warning' => [4, 4, 6, Severity::Warning];
        yield 'above warning, below error' => [5, 4, 6, Severity::Warning];
        yield 'at error' => [6, 4, 6, Severity::Error];
        yield 'above error' => [8, 4, 6, Severity::Error];
    }

    public function testOptionsFromArrayDefaults(): void
    {
        $options = LongParameterListOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = LongParameterListOptions::fromArray([
            'enabled' => true,
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = LongParameterListOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
