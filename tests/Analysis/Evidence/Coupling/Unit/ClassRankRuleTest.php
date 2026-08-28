<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankOptions;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassRankRule::class)]
#[CoversClass(ClassRankOptions::class)]
final class ClassRankRuleTest extends TestCase
{
    #[Test]
    public function getName_returnsCorrectName(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertSame('coupling.class-rank', $rule->getName());
    }

    #[Test]
    public function getDescription_returnsNonEmptyString(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertNotEmpty($rule->getDescription());
    }

    #[Test]
    public function requires_returnsClassRank(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        self::assertSame(['coupling.class-rank'], $rule->requires());
    }

    #[Test]
    public function getOptionsClass_returnsClassRankOptions(): void
    {
        self::assertSame(ClassRankOptions::class, ClassRankRule::getOptionsClass());
    }

    #[Test]
    public function throwsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new ClassRankRule($wrongOptions);
    }

    #[Test]
    public function analyze_returnsEmptyWhenDisabled(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_returnsEmptyWhenNoClasses(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_skipsClassesWithoutClassRankMetric(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $classes = $this->createDummyClasses(100, 'src/SomeClass.php', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturn(new MetricBag());

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itAppliesAnExactSubjectOverrideBeforeProjectScale(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());
        $targetPath = SymbolPath::forClass('App', 'Hub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/Hub.php'), 100);
        $subject = $targetInfo->subject;
        self::assertNotNull($subject);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn($this->createDummyClasses(100));
        $repository->method('allDeclarations')->willReturn([$targetInfo]);
        $repository->method('get')->willReturn((new MetricBag())->with('coupling.class-rank', 0.03));

        self::assertCount(1, $rule->analyze(new AnalysisContext($repository)));

        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Hub.php' => [
                    new ThresholdOverride('coupling.class-rank', 0.04, 0.06, 1, $subject, ControlScope::Class_, 100),
                ],
            ],
        );

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function analyze_noFindingBelowThreshold(): void
    {
        // With 100 classes, scale factor = 1.0, so thresholds are unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $classes = $this->createDummyClasses(100);

        $metricBag = (new MetricBag())->with('coupling.class-rank', 0.01);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // All 100 classes have rank 0.01, below warning threshold 0.02
        self::assertCount(0, $findings);
    }

    #[Test]
    public function analyze_generatesWarning(): void
    {
        // With 100 classes, scale factor = 1.0, thresholds unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'ImportantClass');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/ImportantClass.php'), 10);

        // 0.03 is above warning (0.02) but below error (0.05)
        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.03);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.005);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Only the target class exceeds the warning threshold
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('ClassRank is 0.0300', $findings[0]->message);
        self::assertStringContainsString('scaled for 100 classes', $findings[0]->message);
        self::assertEqualsWithDelta(0.03, $findings[0]->metricValue, 0.001);
        self::assertSame('coupling.class-rank', $findings[0]->ruleName);
        self::assertSame('coupling.class-rank', $findings[0]->code);
    }

    #[Test]
    public function analyze_generatesError(): void
    {
        // With 100 classes, scale factor = 1.0, thresholds unchanged
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'CriticalHub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/CriticalHub.php'), 10);

        // 0.08 is above error threshold (0.05)
        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.08);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.005);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertEqualsWithDelta(0.08, $findings[0]->metricValue, 0.001);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        float $classRank,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ClassRankRule(new ClassRankOptions(
            warning: $warning,
            error: $error,
        ));

        $targetPath = SymbolPath::forClass('App', 'TestClass');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('test.php'), 1);

        $targetBag = (new MetricBag())->with('coupling.class-rank', $classRank);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.001);

        // Use 100 classes so scale factor = 1.0
        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        // Filter to just the target class findings
        $targetFindings = array_values(array_filter(
            $findings,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        if ($expectedSeverity === null) {
            self::assertCount(0, $targetFindings);
        } else {
            self::assertCount(1, $targetFindings);
            self::assertSame($expectedSeverity, $targetFindings[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        yield 'below warning' => [0.01, 0.02, 0.05, null];
        yield 'at warning' => [0.02, 0.02, 0.05, Severity::Warning];
        yield 'between warning and error' => [0.03, 0.02, 0.05, Severity::Warning];
        yield 'at error' => [0.05, 0.02, 0.05, Severity::Error];
        yield 'above error' => [0.10, 0.02, 0.05, Severity::Error];
    }

    // --- Threshold scaling tests ---

    #[Test]
    public function computeScaleFactor_at100Classes_returnsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ClassRankRule::computeScaleFactor(100), 0.001);
    }

    #[Test]
    public function computeScaleFactor_at1600Classes_returnsFour(): void
    {
        // sqrt(1600/100) = sqrt(16) = 4
        self::assertEqualsWithDelta(4.0, ClassRankRule::computeScaleFactor(1600), 0.001);
    }

    #[Test]
    public function computeScaleFactor_at25Classes_returnsHalf(): void
    {
        // sqrt(25/100) = sqrt(0.25) = 0.5
        self::assertEqualsWithDelta(0.5, ClassRankRule::computeScaleFactor(25), 0.001);
    }

    #[Test]
    public function computeScaleFactor_atZeroClasses_returnsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ClassRankRule::computeScaleFactor(0), 0.001);
    }

    #[Test]
    public function analyze_largeProject_lowersThresholds(): void
    {
        // With 400 classes: scale factor = sqrt(400/100) = 2.0
        // Effective warning = 0.02 / 2 = 0.01, effective error = 0.05 / 2 = 0.025
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'Hub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/Hub.php'), 10);

        // 0.015 would be below unscaled warning (0.02), but above scaled warning (0.01)
        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.015);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.001);

        $classes = $this->createDummyClasses(399);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        $targetFindings = array_values(array_filter(
            $findings,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetFindings);
        self::assertSame(Severity::Warning, $targetFindings[0]->severity);
    }

    #[Test]
    public function analyze_smallProject_raisesThresholds(): void
    {
        // With 25 classes: scale factor = sqrt(25/100) = 0.5
        // Effective warning = 0.02 / 0.5 = 0.04, effective error = 0.05 / 0.5 = 0.10
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'SmallHub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/SmallHub.php'), 10);

        // 0.03 would normally be a warning with default thresholds,
        // but with 25 classes, scaled warning = 0.04, so no finding
        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.03);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.001);

        $classes = $this->createDummyClasses(24);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        $targetFindings = array_values(array_filter(
            $findings,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(0, $targetFindings);
    }

    #[Test]
    public function analyze_largeProject_errorAtLowerRank(): void
    {
        // With 1600 classes: scale factor = 4.0
        // Effective error = 0.05 / 4 = 0.0125
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'MegaHub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/MegaHub.php'), 10);

        // 0.02 would normally just be a warning, but with 1600 classes it's an error
        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.02);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.0001);

        $classes = $this->createDummyClasses(1599);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        $targetFindings = array_values(array_filter(
            $findings,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetFindings);
        self::assertSame(Severity::Error, $targetFindings[0]->severity);
    }

    #[Test]
    public function analyze_messageIncludesClassCount(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());

        $targetPath = SymbolPath::forClass('App', 'Hub');
        $targetInfo = self::subjectInfo($targetPath, RelativePath::fromString('src/Hub.php'), 10);

        $targetBag = (new MetricBag())->with('coupling.class-rank', 0.03);
        $normalBag = (new MetricBag())->with('coupling.class-rank', 0.001);

        $classes = $this->createDummyClasses(99);
        $classes[] = $targetInfo;

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn($classes);
        $repository->method('allDeclarations')->willReturn($classes);
        $repository->method('get')
            ->willReturnCallback(static fn(SymbolPath $sp) => $sp === $targetPath ? $targetBag : $normalBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        $targetFindings = array_values(array_filter(
            $findings,
            static fn($v) => $v->symbolPath === $targetPath,
        ));

        self::assertCount(1, $targetFindings);
        self::assertStringContainsString('scaled for 100 classes', $targetFindings[0]->message);
    }

    // --- Options tests ---

    #[Test]
    public function options_defaults(): void
    {
        $options = new ClassRankOptions();

        self::assertTrue($options->isEnabled());
        self::assertEqualsWithDelta(0.02, $options->warning, 0.001);
        self::assertEqualsWithDelta(0.05, $options->error, 0.001);
    }

    #[Test]
    public function options_fromEmptyArray_disablesRule(): void
    {
        $options = ClassRankOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function options_fromArray_customValues(): void
    {
        $options = ClassRankOptions::fromArray([
            'enabled' => true,
            'warning' => 0.03,
            'error' => 0.08,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertEqualsWithDelta(0.03, $options->warning, 0.001);
        self::assertEqualsWithDelta(0.08, $options->error, 0.001);
    }

    #[Test]
    public function options_fromArray_disabledExplicitly(): void
    {
        $options = ClassRankOptions::fromArray([
            'enabled' => false,
        ]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function options_getSeverity_returnsNullBelowThreshold(): void
    {
        $options = new ClassRankOptions();

        self::assertNull($options->getSeverity(0.01));
    }

    #[Test]
    public function options_getSeverity_returnsWarning(): void
    {
        $options = new ClassRankOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(0.03));
    }

    #[Test]
    public function options_getSeverity_returnsError(): void
    {
        $options = new ClassRankOptions();

        self::assertSame(Severity::Error, $options->getSeverity(0.08));
    }

    #[Test]
    public function cliAliasAttributes_areDeclared(): void
    {
        $aliases = CliAliasReader::read(ClassRankRule::class);

        self::assertArrayHasKey('class-rank-warning', $aliases);
        self::assertArrayHasKey('class-rank-error', $aliases);
        self::assertSame('warning', $aliases['class-rank-warning']);
        self::assertSame('error', $aliases['class-rank-error']);
    }

    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $first = self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100);
        $second = self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn($this->createDummyClasses(100));
        $repository->method('allDeclarations')->willReturn([$first, $second]);
        $repository->method('get')->willReturn((new MetricBag())->with('coupling.class-rank', 0.03));

        $findings = (new ClassRankRule(new ClassRankOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    /**
     * Creates N dummy SymbolInfo instances for class symbols.
     *
     * @return list<SymbolInfo>
     */
    private function createDummyClasses(int $count, string $file = 'src/Dummy.php', int $line = 1): array
    {
        $relFile = RelativePath::fromString($file);
        $classes = [];
        for ($i = 0; $i < $count; $i++) {
            $path = SymbolPath::forClass('App\\Dummy', 'DummyClass' . $i);
            $classes[] = self::subjectInfo($path, $relFile, $line);
        }

        return $classes;
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
