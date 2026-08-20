<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
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
        $wrongOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        new LcomRule($wrongOptions);
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new LcomRule(new LcomOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/GodClass.php'), 10);

        // LCOM of 4 is above warning threshold (3) but below error (5)
        $metricBag = (new MetricBag())
            ->with('lcom', 4)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/VeryLargeClass.php'), 10);

        // LCOM of 5 is above error threshold (4)
        $metricBag = (new MetricBag())
            ->with('lcom', 5)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/CohesiveClass.php'), 10);

        // LCOM of 1 means perfectly cohesive (below warning threshold 2)
        $metricBag = (new MetricBag())->with('lcom', 1);

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
    public function itSkipsClassWithoutLcomMetric(): void
    {
        $rule = new LcomRule(new LcomOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'lcom' metric
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
    public function itPreservesReadonlyMinMethodAndExactOverrideEligibility(): void
    {
        $classInfo = self::subjectInfo(SymbolPath::forClass('App', 'Candidate'), RelativePath::fromString('src/Candidate.php'), 10);
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $contextFor = static function (MetricBag $bag, array $overrides = []) use ($classInfo): AnalysisContext {
            $repository = self::createStub(MetricRepositoryInterface::class);
            $repository->method('allDeclarations')->willReturn([$classInfo]);
            $repository->method('get')->willReturn($bag);

            return new AnalysisContext($repository, thresholdOverrides: $overrides);
        };
        $rule = new LcomRule(new LcomOptions(warning: 3, error: 5, excludeReadonly: true, minMethods: 3));

        self::assertSame([], $rule->analyze($contextFor(
            (new MetricBag())->with('lcom', 4)->with('methodCount', 3)->with('isReadonly', 1),
        )));
        self::assertSame([], $rule->analyze($contextFor(
            (new MetricBag())->with('lcom', 4)->with('methodCount', 2)->with('isReadonly', 0),
        )));

        $eligible = (new MetricBag())->with('lcom', 3)->with('methodCount', 3)->with('isReadonly', 0);
        $violations = $rule->analyze($contextFor($eligible));
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame($subject->toCanonical(), $violations[0]->subject->toCanonical());

        self::assertSame([], $rule->analyze($contextFor($eligible, [
            'src/Candidate.php' => [new ThresholdOverride('design.lcom', 4, 6, 1, $subject, ControlScope::Class_, 100)],
        ])));
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('lcom', $lcom)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

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
            (new MetricBag())->with('lcom', 4)->with('methodCount', 5)->with('isReadonly', 0),
        );

        $violations = (new LcomRule(new LcomOptions()))
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
