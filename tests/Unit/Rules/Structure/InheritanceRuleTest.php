<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Structure;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Structure\InheritanceOptions;
use Qualimetrix\Rules\Structure\InheritanceRule;

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
    public function itGetsCategory(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
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
        $wrongOptions = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new InheritanceRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'DeepClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/DeepClass.php', 10);

        // DIT of 5 is at warning threshold (5) but below error (7)
        $metricBag = (new MetricBag())->with('dit', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('DIT (Depth of Inheritance) is 5', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 4', $violations[0]->message);
        self::assertStringContainsString('Prefer composition over deep inheritance', $violations[0]->message);
        self::assertSame(5, $violations[0]->metricValue);
        self::assertSame('design.inheritance', $violations[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryDeepClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/VeryDeepClass.php', 10);

        // DIT of 8 is above error threshold (7)
        $metricBag = (new MetricBag())->with('dit', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(8, $violations[0]->metricValue);
    }

    #[Test]
    public function itProducesNoViolationForShallowDit(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ShallowClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/ShallowClass.php', 10);

        // DIT of 2 is normal (below warning threshold 5)
        $metricBag = (new MetricBag())->with('dit', 2);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itSkipsClassWithoutDitMetric(): void
    {
        $rule = new InheritanceRule(new InheritanceOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SomeClass.php', 10);

        // No 'dit' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
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
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())->with('dit', $dit);

        $repository = self::createStub(MetricRepositoryInterface::class);
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
}
