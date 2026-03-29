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

        self::assertSame(['parameterCount', 'isVoConstructor'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(LongParameterListOptions::class, LongParameterListRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            [
                'long-parameter-list-warning' => 'warning',
                'long-parameter-list-error' => 'error',
                'long-parameter-list-vo-warning' => 'vo-warning',
                'long-parameter-list-vo-error' => 'vo-error',
            ],
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

    // -- VO Constructor Tests ------------------------------------------------

    public function testOptionsDefaultVoThresholds(): void
    {
        $options = new LongParameterListOptions();

        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    public function testOptionsFromArrayVoThresholds(): void
    {
        $options = LongParameterListOptions::fromArray([
            'warning' => 4,
            'error' => 6,
            'vo-warning' => 10,
            'vo-error' => 15,
        ]);

        self::assertSame(10, $options->voWarning);
        self::assertSame(15, $options->voError);
    }

    public function testOptionsFromArrayVoDefaultsWhenNotSpecified(): void
    {
        $options = LongParameterListOptions::fromArray([
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    public function testGetVoSeverityBelowWarning(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertNull($options->getVoSeverity(7));
    }

    public function testGetVoSeverityAtWarning(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Warning, $options->getVoSeverity(8));
    }

    public function testGetVoSeverityBetweenWarningAndError(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Warning, $options->getVoSeverity(10));
    }

    public function testGetVoSeverityAtError(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Error, $options->getVoSeverity(12));
    }

    public function testVoConstructorBelowVoThresholdNoViolation(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        // 7 params in VO constructor — below vo-warning=8, but above regular warning=4
        $metricBag = (new MetricBag())
            ->with('parameterCount', 7)
            ->with('isVoConstructor', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testVoConstructorAtVoWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', 8)
            ->with('isVoConstructor', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('VO constructor', $violations[0]->message);
        self::assertStringContainsString('promoted parameters', $violations[0]->message);
        self::assertSame(8, $violations[0]->metricValue);
        self::assertSame(8, $violations[0]->threshold);
    }

    public function testVoConstructorAtVoErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', 13)
            ->with('isVoConstructor', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertStringContainsString('VO constructor', $violations[0]->message);
        self::assertSame(13, $violations[0]->metricValue);
        self::assertSame(12, $violations[0]->threshold);
    }

    public function testNonVoConstructorStillUsesStandardThresholds(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 5 params in non-VO constructor — above warning=4, below error=6
        $metricBag = (new MetricBag())
            ->with('parameterCount', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Method ? [$methodInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Method has 5 parameters', $violations[0]->message);
        self::assertSame(4, $violations[0]->threshold);
    }

    public function testRequiresIncludesVoConstructorMetric(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertContains('isVoConstructor', $rule->requires());
    }

    public function testGetCliAliasesIncludesVoOptions(): void
    {
        $aliases = LongParameterListRule::getCliAliases();

        self::assertArrayHasKey('long-parameter-list-vo-warning', $aliases);
        self::assertArrayHasKey('long-parameter-list-vo-error', $aliases);
        self::assertSame('vo-warning', $aliases['long-parameter-list-vo-warning']);
        self::assertSame('vo-error', $aliases['long-parameter-list-vo-error']);
    }

    #[DataProvider('voThresholdDataProvider')]
    public function testVoThresholdBoundaries(
        int $parameterCount,
        int $voWarning,
        int $voError,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: $voWarning,
            voError: $voError,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'TestDto', '__construct');
        $methodInfo = new SymbolInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', $parameterCount)
            ->with('isVoConstructor', 1);

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
    public static function voThresholdDataProvider(): iterable
    {
        yield 'vo below warning' => [7, 8, 12, null];
        yield 'vo at warning' => [8, 8, 12, Severity::Warning];
        yield 'vo above warning, below error' => [10, 8, 12, Severity::Warning];
        yield 'vo at error' => [12, 8, 12, Severity::Error];
        yield 'vo above error' => [15, 8, 12, Severity::Error];
    }

    // -- Threshold shorthand tests (regression for VO reuse bug) ----------------

    public function testThresholdShorthandKeepsVoDefaults(): void
    {
        $options = LongParameterListOptions::fromArray([
            'threshold' => 5,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(5, $options->warning);
        self::assertSame(5, $options->error);
        // VO thresholds must remain at defaults, not be overwritten by 'threshold'
        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    public function testThresholdShorthandWithExplicitVoWarning(): void
    {
        $options = LongParameterListOptions::fromArray([
            'threshold' => 5,
            'vo-warning' => 10,
        ]);

        self::assertSame(5, $options->warning);
        self::assertSame(5, $options->error);
        self::assertSame(10, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    public function testVoThresholdShorthand(): void
    {
        $options = LongParameterListOptions::fromArray([
            'threshold' => 5,
            'vo-threshold' => 10,
        ]);

        self::assertSame(5, $options->warning);
        self::assertSame(5, $options->error);
        self::assertSame(10, $options->voWarning);
        self::assertSame(10, $options->voError);
    }

    public function testThresholdShorthandCannotMixWithVoWarningAndVoThreshold(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LongParameterListOptions::fromArray([
            'vo-threshold' => 10,
            'vo-warning' => 8,
        ]);
    }
}
