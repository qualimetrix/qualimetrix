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
use Qualimetrix\Rules\Structure\LcomOptions;
use Qualimetrix\Rules\Structure\LcomRule;

#[CoversClass(LcomRule::class)]
#[CoversClass(LcomOptions::class)]
final class LcomRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame('design.lcom', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(
            'Checks Lack of Cohesion of Methods (high values indicate class should be split)',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itGetsCategory(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    #[Test]
    public function itRequiresLcomMethodCountAndIsReadonly(): void
    {
        $rule = new LcomRule(new LcomOptions());

        self::assertSame(['lcom', 'methodCount', 'isReadonly'], $rule->requires());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            LcomOptions::class,
            LcomRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itThrowsExceptionForWrongOptionsType(): void
    {
        $wrongOptions = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new LcomRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new LcomRule(new LcomOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/GodClass.php'), 10);

        // LCOM of 4 is above warning threshold (3) but below error (5)
        $metricBag = (new MetricBag())
            ->with('lcom', 4)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('LCOM (Lack of Cohesion) is 4', $violations[0]->message);
        self::assertStringContainsString('exceeds threshold of 3', $violations[0]->message);
        self::assertStringContainsString('Class could be split into 4 cohesive parts', $violations[0]->message);
        self::assertSame(4, $violations[0]->metricValue);
        self::assertSame('design.lcom', $violations[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryLargeClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/VeryLargeClass.php'), 10);

        // LCOM of 5 is above error threshold (4)
        $metricBag = (new MetricBag())
            ->with('lcom', 5)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(5, $violations[0]->metricValue);
    }

    #[Test]
    public function itProducesNoViolationForCohesiveClass(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'CohesiveClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/CohesiveClass.php'), 10);

        // LCOM of 1 means perfectly cohesive (below warning threshold 2)
        $metricBag = (new MetricBag())->with('lcom', 1);

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
    public function itSkipsClassWithoutLcomMetric(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'lcom' metric
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
        $options = LcomOptions::fromArray([
            'enabled' => false,
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[Test]
    public function itDisablesOptionsWhenLoadedFromEmptyArray(): void
    {
        $options = LcomOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }

    #[Test]
    public function itHasCorrectOptionDefaults(): void
    {
        $options = new LcomOptions();

        self::assertTrue($options->enabled);
        self::assertSame(3, $options->warning);
        self::assertSame(5, $options->error);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        int $lcom,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new LcomRule(
            new LcomOptions(
                warning: $warning,
                error: $error,
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'TestClass');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('lcom', $lcom)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

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
        // Higher LCOM is worse
        yield 'below warning threshold' => [1, 2, 4, null];
        yield 'at warning threshold' => [2, 2, 4, Severity::Warning];
        yield 'above warning, below error' => [3, 2, 4, Severity::Warning];
        yield 'at error threshold' => [4, 2, 4, Severity::Error];
        yield 'above error threshold' => [6, 2, 4, Severity::Error];
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(LcomRule::class);

        self::assertArrayHasKey('lcom-warning', $aliases);
        self::assertArrayHasKey('lcom-error', $aliases);
        self::assertArrayHasKey('lcom-exclude-methods', $aliases);
        self::assertSame('warning', $aliases['lcom-warning']);
        self::assertSame('error', $aliases['lcom-error']);
        self::assertSame('excludeMethods', $aliases['lcom-exclude-methods']);
    }

    #[Test]
    public function itLoadsExcludeMethodsFromArray(): void
    {
        $options = LcomOptions::fromArray([
            'exclude_methods' => ['getName', 'getDescription'],
        ]);

        self::assertSame(['getName', 'getDescription'], $options->excludeMethods);
    }

    #[Test]
    public function itLoadsExcludeMethodsFromArraySnakeCase(): void
    {
        $options = LcomOptions::fromArray([
            'excludeMethods' => ['getName', 'getDescription'],
        ]);

        self::assertSame(['getName', 'getDescription'], $options->excludeMethods);
    }

    #[Test]
    public function itLoadsExcludeMethodsFromArrayAsString(): void
    {
        $options = LcomOptions::fromArray([
            'exclude_methods' => 'getName',
        ]);

        self::assertSame(['getName'], $options->excludeMethods);
    }

    #[Test]
    public function itSetsExcludeMethodsToNullWhenNotProvided(): void
    {
        $options = LcomOptions::fromArray([
            'warning' => 3,
            'error' => 5,
        ]);

        self::assertNull($options->excludeMethods);
    }

    #[Test]
    public function itPreservesExcludeMethodsOnOverride(): void
    {
        $options = LcomOptions::fromArray([
            'exclude_methods' => ['getName', 'getDescription'],
        ]);

        $overridden = $options->withOverride(warning: 4, error: 6);

        self::assertSame(4, $overridden->warning);
        self::assertSame(6, $overridden->error);
        self::assertSame(['getName', 'getDescription'], $overridden->excludeMethods);
    }
}
