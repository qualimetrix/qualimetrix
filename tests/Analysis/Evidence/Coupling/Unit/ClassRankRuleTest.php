<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankOptions;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRankRule;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
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
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use RuntimeException;

#[CoversClass(ClassRankRule::class)]
#[CoversClass(ClassRankOptions::class)]
final class ClassRankRuleTest extends TestCase
{
    #[Test]
    public function itDeclaresShareDefaultsAndTheExistingChannelAndCliAliases(): void
    {
        $options = ClassRankOptions::fromResolved(ResolvedOptionsFixture::values(ClassRankOptions::class, []));
        self::assertSame(5.0, $options->warning);
        self::assertSame(10.0, $options->error);
        self::assertSame('coupling.class-rank', (new ClassRankRule($options))->getName());
        self::assertSame(['class-rank-warning' => 'warning', 'class-rank-error' => 'error'], CliAliasReader::read(ClassRankRule::class));
        self::assertSame(ClassRankOptions::class, ClassRankRule::getOptionsClass());
    }

    #[Test]
    #[DataProvider('shares')]
    public function itJudgesRawShareAndRendersTruthfulBoundaryWords(float $share, ?Severity $severity, string $fragment): void
    {
        $findings = (new ClassRankRule(new ClassRankOptions()))->analyze($this->context([
            ['Hub', ClassType::Class_, (new MetricBag())->with('coupling.class-rank-share', $share)->with('coupling.ca', 1)],
        ]));
        if ($severity === null) {
            self::assertSame([], $findings);
            return;
        }
        self::assertCount(1, $findings);
        self::assertSame($share, $findings[0]->metricValue);
        self::assertSame($severity, $findings[0]->severity);
        self::assertStringContainsString($fragment, $findings[0]->message);
        self::assertStringContainsString('1 class depends', $findings[0]->recommendation ?? '');
    }

    /** @return iterable<string, array{float, ?Severity, string}> */
    public static function shares(): iterable
    {
        yield 'healthy' => [4.99, null, ''];
        yield 'warning equality' => [5.0, Severity::Warning, '5.00× uniform, reaches threshold of 5.00×'];
        yield 'warning' => [6.0, Severity::Warning, 'exceeds threshold of 5.00×'];
        yield 'error equality' => [10.0, Severity::Error, 'reaches threshold of 10.00×'];
        yield 'precision expands' => [5.000049, Severity::Warning, '5.00005× uniform, exceeds threshold of 5.00000×'];
        yield 'six digits collide' => [5.0000001, Severity::Warning, 'exceeds threshold of 5.000000× (display rounded)'];
    }

    #[Test]
    public function itJudgesOnlyExactPhpClassesEvenWhenKindsShareOneLogicalName(): void
    {
        $bag = (new MetricBag())->with('coupling.class-rank-share', 20)->with('coupling.ca', 1);
        $context = $this->context([
            ['Same', ClassType::Class_, $bag], ['Same', ClassType::Interface_, $bag],
            ['TraitType', ClassType::Trait_, $bag], ['EnumType', ClassType::Enum_, $bag],
        ]);
        $findings = (new ClassRankRule(new ClassRankOptions()))->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame('src/0.php', $findings[0]->location->pathString());
    }

    #[Test]
    public function itKeepsDuplicateDeclarationsAsIndependentJudgements(): void
    {
        $bag = (new MetricBag())->with('coupling.class-rank-share', 6)->with('coupling.ca', 1);
        $findings = (new ClassRankRule(new ClassRankOptions()))->analyze($this->context([
            ['Same', ClassType::Class_, $bag], ['Same', ClassType::Class_, $bag],
        ]));
        self::assertCount(2, $findings);
        self::assertNotSame($findings[0]->subject->toCanonical(), $findings[1]->subject->toCanonical());
    }

    #[Test]
    public function itPreservesHealthyZeroDependentsAndDoesNotSubstituteRawProbability(): void
    {
        $rule = new ClassRankRule(new ClassRankOptions());
        self::assertSame([], $rule->analyze($this->context([
            ['NoDependents', ClassType::Class_, (new MetricBag())->with('coupling.class-rank-share', 20)->with('coupling.ca', 0)],
            ['RawOnly', ClassType::Class_, (new MetricBag())->with('coupling.class-rank', 0.9)->with('coupling.ca', 1)],
        ])));
    }

    #[Test]
    public function itRefusesMissingDependentsAfterPublishedShare(): void
    {
        self::expectException(RuntimeException::class);
        (new ClassRankRule(new ClassRankOptions()))->analyze($this->context([
            ['Hub', ClassType::Class_, (new MetricBag())->with('coupling.class-rank-share', 20)],
        ]));
    }

    #[Test]
    public function itAppliesAnExactOverrideInShareUnits(): void
    {
        $base = $this->context([['Hub', ClassType::Class_, (new MetricBag())->with('coupling.class-rank-share', 6)->with('coupling.ca', 1)]]);
        $info = iterator_to_array($base->metrics->allClassDeclarations())[0];
        $subject = $info->subject ?? throw new LogicException('Fixture requires an exact subject.');
        $override = new ThresholdOverride('coupling.class-rank', 7, 12, 1, $subject, ControlScope::Class_, 100);
        $context = new AnalysisContext($base->metrics, $base->dependencyGraph, thresholdOverrides: ['src/0.php' => [$override]]);
        self::assertSame([], (new ClassRankRule(new ClassRankOptions()))->analyze($context));
    }

    #[Test]
    public function itRefusesAMeasuredDeclarationWithNoExactKind(): void
    {
        $base = $this->context([['Hub', ClassType::Class_, new MetricBag()]]);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn([]);
        self::expectException(LogicException::class);
        (new ClassRankRule(new ClassRankOptions()))->analyze(new AnalysisContext($base->metrics, $graph));
    }

    #[Test]
    public function itRefusesConflictingExactKinds(): void
    {
        $base = $this->context([['Hub', ClassType::Class_, new MetricBag()]]);
        $facts = $base->dependencyGraph?->getClassLikeDeclarations() ?? [];
        $facts[] = ClassLikeDeclaration::of($facts[0]->declaration, ClassType::Interface_, false, false);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn($facts);
        self::expectException(LogicException::class);
        (new ClassRankRule(new ClassRankOptions()))->analyze(new AnalysisContext($base->metrics, $graph));
    }

    #[Test]
    public function itAcceptsAKnownEmptyGraphAndDoesNotEnumerateWhenDisabled(): void
    {
        self::assertSame([], (new ClassRankRule(new ClassRankOptions()))->analyze($this->context([])));
        $metrics = self::createMock(MetricRepositoryInterface::class);
        $metrics->expects(self::never())->method('allClassDeclarations');
        self::assertSame([], (new ClassRankRule(new ClassRankOptions(enabled: false)))->analyze(new AnalysisContext($metrics)));
    }

    #[Test]
    public function itRecordsOneUnknownGraphBeforeEnumeratingAnyDeclaration(): void
    {
        foreach ([null, false, true] as $selected) {
            $metrics = self::createMock(MetricRepositoryInterface::class);
            $metrics->expects(self::never())->method('allClassDeclarations');
            $metrics->expects(self::never())->method('getSubject');
            $context = new AnalysisContext($metrics);
            $session = $selected === null ? null : new PopulationSession(($this->publication($selected))->publishes(...));
            if ($session !== null) {
                $context = $context->withPopulationTrace($session);
            }
            self::assertSame([], (new ClassRankRule(new ClassRankOptions()))->analyze($context));
            if ($session !== null) {
                $population = $session->freeze();
                self::assertSame(0, $population->judgedCount());
                self::assertSame($selected ? 1 : 0, $population->unjudgedCount());
                if ($selected) {
                    self::assertSame('invocation', $population->abstentions()[0]->unit);
                    self::assertSame('graph-available', $population->abstentions()[0]->gate);
                }
            }
        }
    }

    #[Test]
    public function itKeepsKnownEmptyGraphAtZeroAndDoesNotReadExcludedPhpKindMetrics(): void
    {
        $session = new PopulationSession(($this->publication())->publishes(...));
        self::assertSame([], (new ClassRankRule(new ClassRankOptions()))->analyze($this->context([])->withPopulationTrace($session)));
        self::assertTrue($session->freeze()->isEmpty());
        $base = $this->context([['OnlyInterface', ClassType::Interface_, new MetricBag()]]);
        $metrics = self::createMock(MetricRepositoryInterface::class);
        $metrics->method('allClassDeclarations')->willReturn(iterator_to_array($base->metrics->allClassDeclarations(), false));
        $metrics->expects(self::never())->method('getSubject');
        $session = new PopulationSession(($this->publication())->publishes(...));
        self::assertSame([], (new ClassRankRule(new ClassRankOptions()))->analyze((new AnalysisContext($metrics, $base->dependencyGraph))->withPopulationTrace($session)));
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertSame('php-class', $session->freeze()->abstentions()[0]->gate);
    }

    #[Test]
    public function itIncludesAbstractClassesAndRefusesExtraGraphDeclarations(): void
    {
        $base = $this->context([['AbstractHub', ClassType::Class_, MetricBag::fromArray(['coupling.class-rank-share' => 6, 'coupling.ca' => 1])]]);
        $facts = $base->dependencyGraph?->getClassLikeDeclarations() ?? [];
        $abstract = ClassLikeDeclaration::of($facts[0]->declaration, ClassType::Class_, true, false);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn([$abstract]);
        self::assertCount(1, (new ClassRankRule(new ClassRankOptions()))->analyze(new AnalysisContext($base->metrics, $graph)));
        $extra = ClassLikeDeclaration::of(DeclarationPath::of(SymbolPath::forClass('App', 'Extra'), RelativePath::fromString('src/Extra.php'), DeclarationOrdinal::fromRank(0)), ClassType::Class_, false, false);
        $extraGraph = self::createStub(DependencyGraphInterface::class);
        $extraGraph->method('getClassLikeDeclarations')->willReturn([$abstract, $extra]);
        self::expectException(LogicException::class);
        self::expectExceptionMessage('rosters disagree');
        (new ClassRankRule(new ClassRankOptions()))->analyze(new AnalysisContext($base->metrics, $extraGraph));
    }

    private function publication(bool $selected = true): ChannelPublication
    {
        $channel = new FindingChannel(ClassRankRule::NAME);
        return new ChannelPublication(new RuleEnablement([new EnablementDecision(
            new SelectionCellAddress(ClassRankRule::NAME, $channel, SymbolLevel::Class_, ChannelSelectionRole::Selectable),
            new AuthoredCellDecision($selected ? CellSwitch::On : CellSwitch::Off, CellAdmission::Direct),
        )], null));
    }

    /** @param list<array{string, ClassType, MetricBag}> $members */
    private function context(array $members): AnalysisContext
    {
        $infos = $facts = $bags = [];
        foreach ($members as $ordinal => [$name, $kind, $bag]) {
            $file = RelativePath::fromString('src/' . $ordinal . '.php');
            $path = DeclarationPath::of(SymbolPath::forClass('App', $name), $file, DeclarationOrdinal::fromRank(0));
            $subject = MetricSubject::declaration($path);
            $infos[] = new SymbolInfo($subject, $file, 10);
            $facts[] = ClassLikeDeclaration::of($path, $kind, false, false);
            $bags[$subject->toCanonical()] = $bag;
        }
        $metrics = self::createStub(MetricRepositoryInterface::class);
        $metrics->method('allClassDeclarations')->willReturn($infos);
        $metrics->method('getSubject')->willReturnCallback(static fn(MetricSubject $subject): MetricBag => $bags[$subject->toCanonical()]);
        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassLikeDeclarations')->willReturn($facts);
        return new AnalysisContext($metrics, $graph);
    }
}
