<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(LongParameterListRule::class)]
#[CoversClass(LongParameterListOptions::class)]
final class LongParameterListRuleTest extends TestCase
{
    #[Test]
    public function itGetName(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame('code-smell.long-parameter-list', $rule->getName());
    }

    #[Test]
    public function itGetDescription(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame('Checks number of parameters per method', $rule->getDescription());
    }

    #[Test]
    public function itGetCategory(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    #[Test]
    public function itRequires(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertSame(['parameterCount', 'isVoConstructor'], $rule->requires());
    }

    #[Test]
    public function itGetOptionsClass(): void
    {
        self::assertSame(LongParameterListOptions::class, LongParameterListRule::getOptionsClass());
    }

    #[Test]
    public function itGetCliAliases(): void
    {
        self::assertSame(
            [
                'long-parameter-list-warning' => 'warning',
                'long-parameter-list-error' => 'error',
                'long-parameter-list-vo-warning' => 'vo-warning',
                'long-parameter-list-vo-error' => 'vo-error',
            ],
            CliAliasReader::read(LongParameterListRule::class),
        );
    }

    #[Test]
    public function itConstructorRejectsWrongOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);

        new LongParameterListRule(new class implements \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface {
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

    #[Test]
    public function itAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allCallables');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itBelowWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itAtWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 4);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('Method has 4 parameters, exceeds threshold of 4. Consider introducing a parameter object', $findings[0]->message);
        self::assertSame(4, $findings[0]->metricValue);
        self::assertSame('code-smell.long-parameter-list', $findings[0]->ruleName);
        self::assertSame('code-smell.long-parameter-list', $findings[0]->code);
    }

    #[Test]
    public function itAtErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 6);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('Method has 6 parameters, exceeds threshold of 6. Consider introducing a parameter object', $findings[0]->message);
    }

    #[Test]
    public function itAboveErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: 4, error: 6));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'create');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(8, $findings[0]->metricValue);
    }

    #[Test]
    public function itCustomThresholds(): void
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
    #[Test]
    public function itThresholdBoundaries(
        int $parameterCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LongParameterListRule(new LongParameterListOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forMethod('App\Test', 'TestClass', 'testMethod');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())->with('parameterCount', $parameterCount);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
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

    #[Test]
    public function itOptionsFromArrayDefaults(): void
    {
        $options = LongParameterListOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    #[Test]
    public function itOptionsFromArrayCustomValues(): void
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

    #[Test]
    public function itOptionsFromEmptyArrayDisabled(): void
    {
        $options = LongParameterListOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    // -- VO Constructor Tests ------------------------------------------------

    #[Test]
    public function itOptionsDefaultVoThresholds(): void
    {
        $options = new LongParameterListOptions();

        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    #[Test]
    public function itOptionsFromArrayVoThresholds(): void
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

    #[Test]
    public function itOptionsFromArrayVoDefaultsWhenNotSpecified(): void
    {
        $options = LongParameterListOptions::fromArray([
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertSame(8, $options->voWarning);
        self::assertSame(12, $options->voError);
    }

    #[Test]
    public function itGetVoSeverityBelowWarning(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertNull($options->getVoSeverity(7));
    }

    #[Test]
    public function itGetVoSeverityAtWarning(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Warning, $options->getVoSeverity(8));
    }

    #[Test]
    public function itGetVoSeverityBetweenWarningAndError(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Warning, $options->getVoSeverity(10));
    }

    #[Test]
    public function itGetVoSeverityAtError(): void
    {
        $options = new LongParameterListOptions(voWarning: 8, voError: 12);

        self::assertSame(Severity::Error, $options->getVoSeverity(12));
    }

    #[Test]
    public function itVoConstructorBelowVoThresholdNoFinding(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        // 7 params in VO constructor — below vo-warning=8, but above regular warning=4
        $metricBag = (new MetricBag())
            ->with('parameterCount', 7)
            ->with('isVoConstructor', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itVoConstructorAtVoWarningThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', 8)
            ->with('isVoConstructor', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('VO constructor', $findings[0]->message);
        self::assertStringContainsString('promoted parameters', $findings[0]->message);
        self::assertSame(8, $findings[0]->metricValue);
        self::assertSame(8, $findings[0]->threshold);
    }

    #[Test]
    public function itVoConstructorAtVoErrorThreshold(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Dto', 'UserDto', '__construct');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Dto/UserDto.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', 13)
            ->with('isVoConstructor', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('VO constructor', $findings[0]->message);
        self::assertSame(13, $findings[0]->metricValue);
        self::assertSame(12, $findings[0]->threshold);
    }

    #[Test]
    public function itNonVoConstructorStillUsesStandardThresholds(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions(
            warning: 4,
            error: 6,
            voWarning: 8,
            voError: 12,
        ));

        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', '__construct');
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 5 params in non-VO constructor — above warning=4, below error=6
        $metricBag = (new MetricBag())
            ->with('parameterCount', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Method has 5 parameters', $findings[0]->message);
        self::assertSame(4, $findings[0]->threshold);
    }

    #[Test]
    public function itRequiresIncludesVoConstructorMetric(): void
    {
        $rule = new LongParameterListRule(new LongParameterListOptions());

        self::assertContains('isVoConstructor', $rule->requires());
    }

    #[Test]
    public function itGetCliAliasesIncludesVoOptions(): void
    {
        $aliases = CliAliasReader::read(LongParameterListRule::class);

        self::assertArrayHasKey('long-parameter-list-vo-warning', $aliases);
        self::assertArrayHasKey('long-parameter-list-vo-error', $aliases);
        self::assertSame('vo-warning', $aliases['long-parameter-list-vo-warning']);
        self::assertSame('vo-error', $aliases['long-parameter-list-vo-error']);
    }

    #[DataProvider('voThresholdDataProvider')]
    #[Test]
    public function itVoThresholdBoundaries(
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
        $methodInfo = $this->exactDeclarationInfo($symbolPath, 'test.php', 10);

        $metricBag = (new MetricBag())
            ->with('parameterCount', $parameterCount)
            ->with('isVoConstructor', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$methodInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
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

    #[Test]
    public function itThresholdShorthandKeepsVoDefaults(): void
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

    #[Test]
    public function itThresholdShorthandWithExplicitVoWarning(): void
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

    #[Test]
    public function itVoThresholdShorthand(): void
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

    #[Test]
    public function itThresholdShorthandCannotMixWithVoWarningAndVoThreshold(): void
    {
        self::expectException(InvalidArgumentException::class);

        LongParameterListOptions::fromArray([
            'vo-threshold' => 10,
            'vo-warning' => 8,
        ]);
    }

    /**
     * Regression: `RuleOptionsFactory` (config-file keys) and
     * `RuleOptionsParser` (`--rule-opt` keys) both normalize kebab-case
     * option names to camelCase before they reach fromArray(). The
     * `vo-warning`/`vo-error`/`vo-threshold` options must therefore also be
     * recognized in their camelCase form, not just the kebab-case primary
     * spelling already covered by {@see itOptionsFromArrayVoThresholds()} and
     * {@see itVoThresholdShorthand()}.
     */
    #[Test]
    public function itAcceptsCamelCaseVoWarningAndVoError(): void
    {
        $options = LongParameterListOptions::fromArray([
            'warning' => 4,
            'error' => 6,
            'voWarning' => 10,
            'voError' => 15,
        ]);

        self::assertSame(10, $options->voWarning);
        self::assertSame(15, $options->voError);
    }

    #[Test]
    public function itAcceptsCamelCaseVoThresholdShorthand(): void
    {
        $options = LongParameterListOptions::fromArray([
            'threshold' => 5,
            'voThreshold' => 10,
        ]);

        self::assertSame(5, $options->warning);
        self::assertSame(5, $options->error);
        self::assertSame(10, $options->voWarning);
        self::assertSame(10, $options->voError);
    }

    #[DataProvider('voLocalThresholdOverrideCases')]
    #[Test]
    public function itAppliesLocalThresholdOverridesToVoConstructorThresholds(
        int $parameterCount,
        ?Severity $expectedSeverity,
        ?int $expectedThreshold,
    ): void {
        $file = RelativePath::fromString('src/Dto/UserDto.php');
        $symbolInfo = $this->exactDeclarationInfo(SymbolPath::forMethod('App\\Dto', 'UserDto', '__construct'), $file->value(), 10);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$symbolInfo]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())
                ->with('parameterCount', $parameterCount)
                ->with('isVoConstructor', 1),
        );
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                $file->value() => [
                    new ThresholdOverride(
                        'code-smell.long-parameter-list',
                        5,
                        7,
                        1,
                        $symbolInfo->subject ?? throw new LogicException('Exact subject is required'),
                        ControlScope::Callable,
                        20,
                    ),
                ],
            ],
        );

        $findings = (new LongParameterListRule(
            new LongParameterListOptions(warning: 3, error: 4, voWarning: 8, voError: 12),
        ))->analyze($context);

        if ($expectedSeverity === null) {
            self::assertSame([], $findings);

            return;
        }

        self::assertCount(1, $findings);
        self::assertSame($expectedSeverity, $findings[0]->severity);
        self::assertSame($expectedThreshold, $findings[0]->threshold);
    }

    /**
     * @return iterable<string, array{int, ?Severity, ?int}>
     */
    public static function voLocalThresholdOverrideCases(): iterable
    {
        yield 'below local warning' => [4, null, null];
        yield 'at local warning' => [5, Severity::Warning, 5];
        yield 'at local error' => [7, Severity::Error, 7];
    }

    #[Test]
    public function itKeepsRegularThresholdOverridesOnTheRegularBranch(): void
    {
        $file = RelativePath::fromString('src/Service/UserService.php');
        $symbolInfo = $this->exactDeclarationInfo(SymbolPath::forMethod('App\\Service', 'UserService', 'create'), $file->value(), 10);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$symbolInfo]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('parameterCount', 5));
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                $file->value() => [
                    new ThresholdOverride(
                        'code-smell.long-parameter-list',
                        5,
                        7,
                        1,
                        $symbolInfo->subject ?? throw new LogicException('Exact subject is required'),
                        ControlScope::Callable,
                        20,
                    ),
                ],
            ],
        );

        $findings = (new LongParameterListRule(
            new LongParameterListOptions(warning: 3, error: 4, voWarning: 8, voError: 12),
        ))->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(5, $findings[0]->threshold);
        self::assertStringStartsWith('Method has 5 parameters', $findings[0]->message);
    }

    #[Test]
    public function itBindsTheFindingAndThresholdControlToTheExactCallableDeclaration(): void
    {
        $file = RelativePath::fromString('src/Service/UserService.php');
        $logical = SymbolPath::forMethod('App\\Service', 'UserService', 'create');
        $controlled = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(0))),
            $file,
            10,
        );
        $uncontrolled = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($logical, $file, DeclarationOrdinal::fromRank(1))),
            $file,
            20,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$controlled, $uncontrolled]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('parameterCount', 5));
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                $file->value() => [
                    new ThresholdOverride(
                        'code-smell.long-parameter-list',
                        6,
                        7,
                        1,
                        $controlled->subject ?? throw new LogicException('Exact subject is required'),
                        ControlScope::Callable,
                        20,
                    ),
                ],
            ],
        );

        $findings = (new LongParameterListRule(
            new LongParameterListOptions(warning: 4, error: 6),
        ))->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(
            $uncontrolled->subject?->toCanonical(),
            $findings[0]->subject->toCanonical(),
        );
    }

    #[Test]
    public function itPreservesCallableOrderAcrossRegularAndVoProjections(): void
    {
        $regular = $this->exactDeclarationInfo(
            SymbolPath::forMethod('App\\Service', 'UserService', 'create'),
            'src/Service/UserService.php',
            10,
        );
        $vo = $this->exactDeclarationInfo(
            SymbolPath::forMethod('App\\Dto', 'UserDto', '__construct'),
            'src/Dto/UserDto.php',
            20,
        );
        $regularSubject = $regular->subject ?? throw new LogicException('Exact regular subject is required');
        $voSubject = $vo->subject ?? throw new LogicException('Exact VO subject is required');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$regular, $vo]);
        $repository->method('getSubject')->willReturnCallback(
            static fn(MetricSubject $subject): MetricBag => $subject->toCanonical() === $regularSubject->toCanonical()
                ? (new MetricBag())->with('parameterCount', 4)
                : (new MetricBag())->with('parameterCount', 8)->with('isVoConstructor', 1),
        );

        $findings = (new LongParameterListRule(new LongParameterListOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        self::assertSame($regularSubject->toCanonical(), $findings[0]->subject->toCanonical());
        self::assertSame('Method has 4 parameters, exceeds threshold of 4. Consider introducing a parameter object', $findings[0]->message);
        self::assertSame(4, $findings[0]->threshold);
        self::assertSame($voSubject->toCanonical(), $findings[1]->subject->toCanonical());
        self::assertSame('VO constructor has 8 promoted parameters, exceeds threshold of 8. Consider splitting the value object', $findings[1]->message);
        self::assertSame(8, $findings[1]->threshold);
    }

    private function exactDeclarationInfo(SymbolPath $symbolPath, string $file, int $line): SymbolInfo
    {
        $relativePath = RelativePath::fromString($file);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($symbolPath, $relativePath, DeclarationOrdinal::fromRank(0))),
            $relativePath,
            $line,
        );
    }
}
