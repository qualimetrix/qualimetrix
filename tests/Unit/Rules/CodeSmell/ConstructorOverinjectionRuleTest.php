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
use Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionOptions;
use Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionRule;

#[CoversClass(ConstructorOverinjectionRule::class)]
#[CoversClass(ConstructorOverinjectionOptions::class)]
final class ConstructorOverinjectionRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions());

        self::assertSame('code-smell.constructor-overinjection', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions());

        self::assertSame('Checks number of constructor parameters (dependencies)', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions());

        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions());

        self::assertSame(['parameterCount'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(ConstructorOverinjectionOptions::class, ConstructorOverinjectionRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            ['constructor-overinjection-warning' => 'warning', 'constructor-overinjection-error' => 'error'],
            ConstructorOverinjectionRule::getCliAliases(),
        );
    }

    public function testConstructorRejectsWrongOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);

        new ConstructorOverinjectionRule(new class implements \Qualimetrix\Core\Rule\RuleOptionsInterface {
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
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testNonConstructorMethodsSkipped(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 2, error: 4));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testGlobalFunctionConstructSkipped(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 2, error: 4));

        $symbolPath = SymbolPath::forGlobalFunction('App\Helpers', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Helpers/functions.php', 5);

        $metricBag = (new MetricBag())->with('parameterCount', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testBelowWarningThreshold(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 7);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAtWarningThreshold(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Constructor of UserService has 8 parameters (threshold 8). Consider using a parameter object or splitting responsibilities', $violations[0]->message);
        self::assertSame(8, $violations[0]->metricValue);
        self::assertSame('code-smell.constructor-overinjection', $violations[0]->ruleName);
        self::assertSame('code-smell.constructor-overinjection', $violations[0]->violationCode);
    }

    public function testAtErrorThreshold(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame('Constructor of UserService has 12 parameters (threshold 12). Consider using a parameter object or splitting responsibilities', $violations[0]->message);
    }

    public function testAboveErrorThreshold(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(15, $violations[0]->metricValue);
    }

    #[DataProvider('thresholdDataProvider')]
    public function testThresholdBoundaries(
        int $parameterCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forMethod('App\Test', 'TestClass', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', $parameterCount);

        $repository = self::createStub(MetricRepositoryInterface::class);
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
        yield 'below warning' => [7, 8, 12, null];
        yield 'at warning' => [8, 8, 12, Severity::Warning];
        yield 'above warning, below error' => [10, 8, 12, Severity::Warning];
        yield 'at error' => [12, 8, 12, Severity::Error];
        yield 'above error' => [15, 8, 12, Severity::Error];
    }

    public function testNullParameterCountSkipped(): void
    {
        $rule = new ConstructorOverinjectionRule(new ConstructorOverinjectionOptions(warning: 8, error: 12));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testOptionsFromArrayDefaults(): void
    {
        $options = ConstructorOverinjectionOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(8, $options->warning);
        self::assertSame(12, $options->error);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = ConstructorOverinjectionOptions::fromArray([
            'enabled' => true,
            'warning' => 6,
            'error' => 10,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(6, $options->warning);
        self::assertSame(10, $options->error);
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = ConstructorOverinjectionOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
