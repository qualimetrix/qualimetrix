<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\InheritanceOptions;
use Qualimetrix\Analysis\Evidence\Design\InheritanceRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(InheritanceRule::class)]
#[CoversClass(InheritanceOptions::class)]
final class InheritanceRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame('design.inheritance', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(
            'Checks Depth of Inheritance Tree (deep hierarchies increase complexity)',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itRequiresDit(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(['dit'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            InheritanceOptions::class,
            InheritanceRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new InheritanceRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'DeepClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/DeepClass.php'), 10);

        // DIT of 5 is at warning threshold (5) but below error (7)
        $metricBag = (new MetricBag())->with('dit', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('DIT (Depth of Inheritance) is 5', $findings[0]->message);
        self::assertStringContainsString('exceeds threshold of 4', $findings[0]->message);
        self::assertStringContainsString('Prefer composition over deep inheritance', $findings[0]->message);
        self::assertSame(5, $findings[0]->metricValue);
        self::assertSame('design.inheritance', $findings[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryDeepClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/VeryDeepClass.php'), 10);

        // DIT of 8 is above error threshold (7)
        $metricBag = (new MetricBag())->with('dit', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(8, $findings[0]->metricValue);
    }

    #[Test]
    public function itProducesNoFindingForShallowDit(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ShallowClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/ShallowClass.php'), 10);

        // DIT of 2 is normal (below warning threshold 5)
        $metricBag = (new MetricBag())->with('dit', 2);

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
    public function itSkipsClassWithoutDitMetric(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'dit' metric
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
        $options = InheritanceOptions::fromArray([
            'enabled' => false,
            'warning' => 4,
            'error' => 6,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    #[Test]
    public function itDisablesOptionsWhenLoadedFromEmptyArray(): void
    {
        $options = InheritanceOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    #[Test]
    public function itHasCorrectOptionDefaults(): void
    {
        $options = new InheritanceOptions();

        self::assertTrue($options->enabled);
        self::assertSame(4, $options->warning);
        self::assertSame(6, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('dit', $dit);

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
        // Higher DIT is worse
        yield 'below warning threshold' => [4, 5, 7, null];
        yield 'at warning threshold' => [5, 5, 7, Severity::Warning];
        yield 'above warning, below error' => [6, 5, 7, Severity::Warning];
        yield 'at error threshold' => [7, 5, 7, Severity::Error];
        yield 'above error threshold' => [10, 5, 7, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(InheritanceRule::class);

        self::assertArrayHasKey('dit-warning', $aliases);
        self::assertArrayHasKey('dit-error', $aliases);
        self::assertSame('warning', $aliases['dit-warning']);
        self::assertSame('error', $aliases['dit-error']);
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
        $repository->method('get')->willReturn((new MetricBag())->with('dit', 5));

        $findings = (new InheritanceRule(new InheritanceOptions()))
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
