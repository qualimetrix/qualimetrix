<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\WmcOptions;
use Qualimetrix\Analysis\Evidence\Complexity\WmcRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use RuntimeException;

#[CoversClass(WmcRule::class)]
#[CoversClass(WmcOptions::class)]
final class WmcRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame('complexity.wmc', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(
            'Checks Weighted Methods per Class (sum of method complexities)',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetsCategory(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(RuleCategory::Complexity, $rule->getCategory());
    }

    #[Test]
    public function itRequiresWmcIsDataClassAndMethodCount(): void
    {
        $rule = new WmcRule(new WmcOptions());

        self::assertSame(['wmc', 'isDataClass', 'methodCount'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            WmcOptions::class,
            WmcRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new WmcRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new WmcRule(new WmcOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itProducesNoFindingBelowThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SimpleClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SimpleClass.php'), 10);

        // WMC of 20 is below warning threshold (50)
        $metricBag = (new MetricBag())->with('wmc', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itGeneratesWarningAboveWarningThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MediumClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/MediumClass.php'), 10);

        // WMC of 60 is above warning threshold (50) but below error (80)
        $metricBag = (new MetricBag())->with('wmc', 60)->with('methodCount', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('WMC (Weighted Methods per Class) is 60', $findings[0]->message);
        self::assertStringContainsString('exceeds threshold of 50', $findings[0]->message);
        self::assertStringContainsString('Simplify methods or split the class', $findings[0]->message);
        self::assertSame(60, $findings[0]->metricValue);
        self::assertSame('complexity.wmc', $findings[0]->ruleName);
        // avg = 60/15 = 4.0, middle range
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('across 15 methods (avg 4.0)', $findings[0]->recommendation);
        self::assertStringContainsString('weighted method complexity is high', $findings[0]->recommendation);
    }

    #[Test]
    public function itGeneratesErrorAboveErrorThreshold(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ComplexClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/ComplexClass.php'), 10);

        // WMC of 85 is above error threshold (80)
        $metricBag = (new MetricBag())->with('wmc', 85)->with('methodCount', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('WMC (Weighted Methods per Class) is 85', $findings[0]->message);
        self::assertStringContainsString('exceeds threshold of 80', $findings[0]->message);
        self::assertStringContainsString('Simplify methods or split the class', $findings[0]->message);
        self::assertSame(85, $findings[0]->metricValue);
        // avg = 85/10 = 8.5, high complexity
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('across 10 methods (avg 8.5)', $findings[0]->recommendation);
        self::assertStringContainsString('some methods are very complex', $findings[0]->recommendation);
    }

    #[Test]
    public function itRecommendsWhenManySimpleMethods(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LargeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/LargeClass.php'), 10);

        // WMC of 93, 31 methods -> avg 3.0 -> "many methods, consider splitting"
        $metricBag = (new MetricBag())->with('wmc', 93)->with('methodCount', 31);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        // avg = 93/31 = 3.0 -> exactly 3.0, middle range (not < 3.0)
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('across 31 methods (avg 3.0)', $findings[0]->recommendation);
        self::assertStringContainsString('weighted method complexity is high', $findings[0]->recommendation);
    }

    #[Test]
    public function itRecommendsWhenManyVerySimpleMethods(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'HugeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/HugeClass.php'), 10);

        // WMC of 60, 30 methods -> avg 2.0 -> "many methods, consider splitting"
        $metricBag = (new MetricBag())->with('wmc', 60)->with('methodCount', 30);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        // avg = 60/30 = 2.0 -> < 3.0 -> "many methods"
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('across 30 methods (avg 2.0)', $findings[0]->recommendation);
        self::assertStringContainsString('many methods, consider splitting', $findings[0]->recommendation);
    }

    #[Test]
    public function itProvidesRecommendationWithoutMethodCount(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // WMC without methodCount metric
        $metricBag = (new MetricBag())->with('wmc', 60);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('WMC: 60 (threshold: 50)', $findings[0]->recommendation);
        self::assertStringContainsString('weighted method complexity is high', $findings[0]->recommendation);
        // Should NOT contain "across N methods" when methodCount is missing
        self::assertStringNotContainsString('across', $findings[0]->recommendation);
    }

    #[Test]
    public function itUsesTheZeroMethodRecommendationWithAnExactOverride(): void
    {
        $classInfo = self::subjectInfo(SymbolPath::forClass('App', 'GeneratedMethods'), RelativePath::fromString('src/GeneratedMethods.php'), 10);
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn(
            (new MetricBag())->with('wmc', 60)->with('methodCount', 0),
        );
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/GeneratedMethods.php' => [new ThresholdOverride('complexity.wmc', 55, 80, 1, $subject, ControlScope::Class_, 100)],
            ],
        );

        $findings = (new WmcRule(new WmcOptions()))->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(55, $findings[0]->threshold);
        self::assertSame('WMC: 60 (threshold: 55) — weighted method complexity is high', $findings[0]->recommendation);
        self::assertSame($subject->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itRespectsCustomThresholds(): void
    {
        $rule = new WmcRule(new WmcOptions(warning: 20, error: 40));

        $symbolPath = SymbolPath::forClass('App\Service', 'CustomClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/CustomClass.php'), 10);

        // WMC of 25 is above custom warning threshold (20) but below custom error (40)
        $metricBag = (new MetricBag())->with('wmc', 25);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itProducesNoFindingForClassWithoutMethods(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'EmptyClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/EmptyClass.php'), 10);

        // WMC of 0 for class without methods
        $metricBag = (new MetricBag())->with('wmc', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itAnalyzesMultipleClasses(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath1 = SymbolPath::forClass('App', 'SimpleClass');
        $symbolPath2 = SymbolPath::forClass('App', 'ComplexClass');

        $classInfo1 = self::subjectInfo($symbolPath1, RelativePath::fromString('src/SimpleClass.php'), 10);
        $classInfo2 = self::subjectInfo($symbolPath2, RelativePath::fromString('src/ComplexClass.php'), 20);

        $metricBag1 = (new MetricBag())->with('wmc', 20); // No finding
        $metricBag2 = (new MetricBag())->with('wmc', 90); // Error

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo1, $classInfo2]);
        $repository->method('get')
            ->willReturnCallback(function ($path) use ($symbolPath1, $symbolPath2, $metricBag1, $metricBag2) {
                if ($path === $symbolPath1) {
                    return $metricBag1;
                }
                if ($path === $symbolPath2) {
                    return $metricBag2;
                }
                throw new RuntimeException('Unexpected path');
            });

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame($symbolPath2, $findings[0]->symbolPath);
    }

    #[Test]
    public function itSkipsClassWithoutWmcMetric(): void
    {
        $rule = new WmcRule(new WmcOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'wmc' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    // Options tests

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = WmcOptions::fromArray([
            'enabled' => false,
            'warning' => 20,
            'error' => 40,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(20, $options->warning);
        self::assertSame(40, $options->error);
    }

    #[Test]
    public function itDisablesOptionsWhenLoadedFromEmptyArray(): void
    {
        $options = WmcOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    #[Test]
    public function itHasCorrectOptionDefaults(): void
    {
        $options = new WmcOptions();

        self::assertTrue($options->enabled);
        self::assertSame(50, $options->warning);
        self::assertSame(80, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        int $wmc,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new WmcRule(
            new WmcOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('wmc', $wmc);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
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
        // Higher WMC is worse
        yield 'below warning threshold' => [29, 30, 50, null];
        yield 'at warning threshold' => [30, 30, 50, Severity::Warning];
        yield 'just above warning threshold' => [31, 30, 50, Severity::Warning];
        yield 'above warning, below error' => [45, 30, 50, Severity::Warning];
        yield 'at error threshold' => [50, 30, 50, Severity::Error];
        yield 'just above error threshold' => [51, 30, 50, Severity::Error];
        yield 'far above error threshold' => [100, 30, 50, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(WmcRule::class);

        self::assertArrayHasKey('wmc-warning', $aliases);
        self::assertArrayHasKey('wmc-error', $aliases);
        self::assertArrayHasKey('wmc-exclude-data-classes', $aliases);
        self::assertSame('warning', $aliases['wmc-warning']);
        self::assertSame('error', $aliases['wmc-error']);
        self::assertSame('excludeDataClasses', $aliases['wmc-exclude-data-classes']);
    }

    #[Test]
    public function itHasExcludeDataClassesDisabledByDefault(): void
    {
        $options = new WmcOptions();

        self::assertFalse($options->excludeDataClasses);
    }

    #[Test]
    public function itLoadsExcludeDataClassesFromArray(): void
    {
        $options = WmcOptions::fromArray([
            'exclude_data_classes' => true,
        ]);

        self::assertTrue($options->excludeDataClasses);
    }

    #[Test]
    public function itLoadsExcludeDataClassesFromArrayCamelCase(): void
    {
        $options = WmcOptions::fromArray([
            'excludeDataClasses' => true,
        ]);

        self::assertTrue($options->excludeDataClasses);
    }

    #[Test]
    public function itSkipsDataClassesWhenExcludeDataClassesEnabled(): void
    {
        $rule = new WmcRule(new WmcOptions(excludeDataClasses: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'UserDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/UserDto.php'), 10);

        // WMC of 60 is above warning threshold (50), but isDataClass = 1
        $metricBag = (new MetricBag())
            ->with('wmc', 60)
            ->with('isDataClass', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should skip data class
        self::assertCount(0, $findings);
    }

    #[Test]
    public function itDoesNotSkipDataClassesWhenExcludeDataClassesDisabled(): void
    {
        $rule = new WmcRule(new WmcOptions(excludeDataClasses: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'UserDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/UserDto.php'), 10);

        // WMC of 90 is above error threshold (80), and isDataClass = 1
        $metricBag = (new MetricBag())
            ->with('wmc', 90)
            ->with('isDataClass', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Should NOT skip when excludeDataClasses is false
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn(
            (new MetricBag())->with('wmc', 60)->with('methodCount', 15),
        );

        $findings = (new WmcRule(new WmcOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
