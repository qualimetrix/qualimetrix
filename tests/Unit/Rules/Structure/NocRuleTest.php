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
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Structure\NocOptions;
use Qualimetrix\Rules\Structure\NocRule;

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
        $wrongOptions = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new NocRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new NocRule(new NocOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new NocRule(new NocOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itSkipsClassesWithZeroNoc(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/LeafClass.php'), 10);

        // NOC of 0 means no children (should be skipped)
        $metricBag = (new MetricBag())->with('noc', 0);

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
    public function itGeneratesWarning(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'BaseService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/BaseService.php'), 10);

        // NOC of 12 is above warning threshold (10) but below error (15)
        $metricBag = (new MetricBag())->with('noc', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
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
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/VeryPopularBase.php'), 10);

        // NOC of 20 is above error threshold (15)
        $metricBag = (new MetricBag())->with('noc', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
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
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/ReasonableBase.php'), 10);

        // NOC of 3 is normal (below warning threshold 7)
        $metricBag = (new MetricBag())->with('noc', 3);

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
    public function itSkipsClassWithoutNocMetric(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'noc' metric
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
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())->with('noc', $noc);

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
}
