<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\NocOptions;
use Qualimetrix\Analysis\Evidence\Design\NocRule;
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

#[CoversClass(NocRule::class)]
#[CoversClass(NocOptions::class)]
final class NocRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame('design.noc', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(
            'Checks Number of Children (many direct subclasses indicate wide impact)',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetsCategory(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    #[Test]
    public function itRequiresNoc(): void
    {
        $rule = new NocRule(new NocOptions());

        self::assertSame(['noc'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            NocOptions::class,
            NocRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new NocRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new NocRule(new NocOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new NocRule(new NocOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itSkipsClassesWithZeroNoc(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/LeafClass.php'), 10);

        // NOC of 0 means no children (should be skipped)
        $metricBag = (new MetricBag())->with('noc', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'BaseService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/BaseService.php'), 10);

        // NOC of 12 is above warning threshold (10) but below error (15)
        $metricBag = (new MetricBag())->with('noc', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('NOC (Number of Children) is 12', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 10', $violations[0]->message);
        self::assertStringContainsString('Consider using interfaces instead of inheritance', $violations[0]->message);
        self::assertSame(12, $violations[0]->metricValue);
        self::assertSame('design.noc', $violations[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryPopularBase');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/VeryPopularBase.php'), 10);

        // NOC of 20 is above error threshold (15)
        $metricBag = (new MetricBag())->with('noc', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(20, $violations[0]->metricValue);
    }

    #[Test]
    public function itProducesNoViolationForFewChildren(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ReasonableBase');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/ReasonableBase.php'), 10);

        // NOC of 3 is normal (below warning threshold 7)
        $metricBag = (new MetricBag())->with('noc', 3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itSkipsClassWithoutNocMetric(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'noc' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itAppliesAnExactSubjectOverrideAtEquality(): void
    {
        $classInfo = self::subjectInfo(SymbolPath::forClass('App', 'ParentClass'), RelativePath::fromString('src/ParentClass.php'), 10);
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn((new MetricBag())->with('noc', 6));
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/ParentClass.php' => [new ThresholdOverride('design.noc', 5, 6, 1, $subject, ControlScope::Class_, 100)],
            ],
        );

        $violations = (new NocRule(new NocOptions(warning: 7, error: 15)))->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(6, $violations[0]->threshold);
        self::assertSame('NOC (Number of Children) is 6, exceeds threshold of 6. Consider using interfaces instead of inheritance', $violations[0]->message);
        self::assertSame($subject->toCanonical(), $violations[0]->subject->toCanonical());
    }

    // Options tests

    #[Test]
    public function itLoadsOptionsFromArray(): void
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

    #[Test]
    public function itDisablesOptionsWhenLoadedFromEmptyArray(): void
    {
        $options = NocOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    #[Test]
    public function itHasCorrectOptionDefaults(): void
    {
        $options = new NocOptions();

        self::assertTrue($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(15, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('noc', $noc);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
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
        // Higher NOC is worse
        yield 'below warning threshold' => [6, 7, 15, null];
        yield 'at warning threshold' => [7, 7, 15, Severity::Warning];
        yield 'above warning, below error' => [10, 7, 15, Severity::Warning];
        yield 'at error threshold' => [15, 7, 15, Severity::Error];
        yield 'above error threshold' => [25, 7, 15, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(NocRule::class);

        self::assertArrayHasKey('noc-warning', $aliases);
        self::assertArrayHasKey('noc-error', $aliases);
        self::assertSame('warning', $aliases['noc-warning']);
        self::assertSame('error', $aliases['noc-error']);
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
        $repository->method('get')->willReturn((new MetricBag())->with('noc', 12));

        $violations = (new NocRule(new NocOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);
        $subjects = array_map(static fn($violation): string => $violation->subject->toCanonical(), $violations);
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
