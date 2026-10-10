<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use InvalidArgumentException;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\NocOptions;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\NocRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(NocRule::class)]
#[CoversClass(NocOptions::class)]
final class NocRuleTest extends TestCase
{
    #[Test]
    public function itAccountsForWrongLogicalKindsBeforeReadingClassMetrics(): void
    {
        $info = self::subjectInfo(SymbolPath::forMethod('App', 'Service', 'run'), RelativePath::fromString('service.php'), 1);
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::once())->method('allClassDeclarations')->willReturn([$info]);
        $repository->expects(self::never())->method('getSubject');
        $session = self::populationSession(NocRule::NAME);
        self::assertSame([], (new NocRule(new NocOptions()))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(0, $session->freeze()->judgedCount());
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertSame('logical-class-kind', $session->freeze()->abstentions()[0]->gate);
    }

    #[Test]
    public function itSeparatesMissingZeroAndHealthyChildCounts(): void
    {
        $info = self::subjectInfo(SymbolPath::fromClassFqn('App\\ParentClass'), RelativePath::fromString('parent.php'), 1);
        foreach ([[null, 'noc-present'], [0, 'noc-positive'], [1, null]] as [$count, $gate]) {
            $repository = self::createStub(MetricRepositoryInterface::class);
            $repository->method('allClassDeclarations')->willReturn([$info]);
            $repository->method('getSubject')->willReturn($count === null ? new MetricBag() : MetricBag::fromArray(['design.noc' => $count]));
            $session = self::populationSession(NocRule::NAME);
            self::assertSame([], (new NocRule(new NocOptions()))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
            self::assertSame($gate === null ? 1 : 0, $session->freeze()->judgedCount());
            self::assertSame($gate === null ? 0 : 1, $session->freeze()->unjudgedCount());
            if ($gate !== null) {
                self::assertSame($gate, $session->freeze()->abstentions()[0]->gate);
            }
        }
    }

    #[Test]
    public function itRefusesNegativeChildCountsEvenWithoutAccounting(): void
    {
        $info = self::subjectInfo(SymbolPath::fromClassFqn('App\\InvalidParent'), RelativePath::fromString('invalid.php'), 1);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $repository->method('getSubject')->willReturn(MetricBag::fromArray(['design.noc' => -1]));
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Invalid measured population count.');
        (new NocRule(new NocOptions()))->analyze(new AnalysisContext($repository));
    }

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
            $rule::getDescription(),
        );
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
        $repository->expects(self::never())->method('allClassDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new NocRule(new NocOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
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
        $metricBag = (new MetricBag())->with('design.noc', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'BaseService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/BaseService.php'), 10);

        // NOC of 12 is above warning threshold (10) but below error (15)
        $metricBag = (new MetricBag())->with('design.noc', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('NOC (Number of Children) is 12', $findings[0]->message);
        self::assertStringContainsString('exceeds threshold of 10', $findings[0]->message);
        self::assertStringContainsString('Consider using interfaces instead of inheritance', $findings[0]->message);
        self::assertSame(12, $findings[0]->metricValue);
        self::assertSame('design.noc', $findings[0]->ruleName);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'VeryPopularBase');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/VeryPopularBase.php'), 10);

        // NOC of 20 is above error threshold (15)
        $metricBag = (new MetricBag())->with('design.noc', 20);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(20, $findings[0]->metricValue);
    }

    #[Test]
    public function itProducesNoFindingForFewChildren(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ReasonableBase');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/ReasonableBase.php'), 10);

        // NOC of 3 is normal (below warning threshold 7)
        $metricBag = (new MetricBag())->with('design.noc', 3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itSkipsClassWithoutNocMetric(): void
    {
        $rule = new NocRule(new NocOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'SomeClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SomeClass.php'), 10);

        // No 'design.noc' metric
        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itAppliesAnExactSubjectOverrideAtEquality(): void
    {
        $classInfo = self::subjectInfo(SymbolPath::forClass('App', 'ParentClass'), RelativePath::fromString('src/ParentClass.php'), 10);
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('design.noc', 6));
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/ParentClass.php' => [new ThresholdOverride('design.noc', 5, 6, 1, $subject, ControlScope::Class_, 100)],
            ],
        );

        $findings = (new NocRule(new NocOptions(warning: 7, error: 15)))->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(6, $findings[0]->threshold);
        self::assertSame('NOC (Number of Children) is 6, reaches threshold of 6. Consider using interfaces instead of inheritance', $findings[0]->message);
        self::assertSame($subject->toCanonical(), $findings[0]->subject->toCanonical());
    }

    // Options tests

    #[Test]
    public function itLoadsOptionsFromArray(): void
    {
        $options = NocOptions::fromResolved(ResolvedOptionsFixture::values(NocOptions::class, [
            'enabled' => false,
            'warning' => 10,
            'error' => 20,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itUsesConstructorDefaultsForAnEmptyBodyAndHonoursExplicitDisablement(): void
    {
        self::assertEquals(new NocOptions(), NocOptions::fromResolved(ResolvedOptionsFixture::values(NocOptions::class, [])));
        self::assertFalse(NocOptions::fromResolved(ResolvedOptionsFixture::values(NocOptions::class, ['enabled' => false]))->isEnabled());
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

        $metricBag = (new MetricBag())->with('design.noc', $noc);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
            $selectedThreshold = $expectedSeverity === Severity::Error ? $error : $warning;
            self::assertStringContainsString(($noc === $selectedThreshold ? 'reaches' : 'exceeds') . ' threshold of', $findings[0]->message);
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
        $repository->method('allClassDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('getSubject')->willReturn((new MetricBag())->with('design.noc', 12));

        $findings = (new NocRule(new NocOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private static function populationSession(string $producer, bool $selected = true): PopulationSession
    {
        return new PopulationSession((new ChannelPublication(new RuleEnablement([new EnablementDecision(
            new SelectionCellAddress($producer, new FindingChannel($producer), SymbolLevel::Class_, ChannelSelectionRole::Selectable),
            new AuthoredCellDecision($selected ? CellSwitch::On : CellSwitch::Off, CellAdmission::Direct),
        )], null)))->publishes(...));
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
            $kind === \Qualimetrix\Core\Symbol\CallableKind::Method ? \Qualimetrix\Core\Symbol\DeclarationPath::of(\Qualimetrix\Core\Symbol\SymbolPath::forClass($symbolPath->namespace ?? '', $symbolPath->type ?? ''), $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0)) : null,
        );
    }
}
