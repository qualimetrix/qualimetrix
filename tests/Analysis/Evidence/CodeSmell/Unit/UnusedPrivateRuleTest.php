<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use LogicException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\UnusedPrivateRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(UnusedPrivateRule::class)]
#[CoversClass(UnusedPrivateOptions::class)]
final class UnusedPrivateRuleTest extends TestCase
{
    #[Test]
    public function itCountsMeasuredZeroAndDistinguishesMissingPublication(): void
    {
        $infos = [];
        foreach (['Healthy', 'Missing'] as $name) {
            $file = \Qualimetrix\Core\Path\RelativePath::fromString('src/' . $name . '.php');
            $subject = \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(\Qualimetrix\Core\Symbol\SymbolPath::forClass('Population', $name), $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0)));
            $infos[] = new \Qualimetrix\Core\Symbol\SymbolInfo($subject, $file, 1);
        }
        $repository = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn($infos);
        $repository->method('getSubject')->willReturnCallback(static fn(\Qualimetrix\Core\Symbol\MetricSubject $subject): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag => $subject->toSymbolPath()->type === 'Healthy' ? (new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag())->with(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName::CODE_SMELL_UNUSED_PRIVATE_TOTAL, 0) : new \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag());
        $decisions = [];
        foreach (UnusedPrivateRule::channelDeclarations() as $channel => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(UnusedPrivateRule::NAME, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($channel), $level, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable), new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct));
            }
        }
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession((new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null)))->publishes(...));
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());
        self::assertSame([], $rule->analyze((new \Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(1, $session->freeze()->judgedCount());
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertSame('declaration', $session->freeze()->abstentions()[0]->unit);
        self::assertStringContainsString('Missing', $session->freeze()->abstentions()[0]->examples[0]);
    }

    #[Test]
    public function itExposesItsRuleNameAndDescription(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        self::assertSame('code-smell.unused-private', $rule->getName());
        self::assertSame('Detects unused private methods, properties, and constants', $rule::getDescription());
    }

    #[Test]
    public function itDeclaresUnusedPrivateOptionsAsItsOptionsClass(): void
    {
        self::assertSame(UnusedPrivateOptions::class, UnusedPrivateRule::getOptionsClass());
    }

    #[Test]
    public function itSkipsDeclarationLookupAndReturnsNoFindingsWhenDisabled(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allClassDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsNoFindingsWhenNoPrivateMemberIsUnused(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Clean');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/Clean.php', 5);

        $metricBag = (new MetricBag())
            ->with('code-smell.unused-private.total', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReportsAWarningFindingForAnUnusedPrivateMethod(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'Smelly');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/Smelly.php', 5);

        $metricBag = (new MetricBag())
            ->with('code-smell.unused-private.total', 1)
            ->withEntry('code-smell.unused-private.method', ['line' => 15, 'name' => 'doLoadMappingFile']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(15, $findings[0]->location->line);
        self::assertSame('Unused private method `doLoadMappingFile`', $findings[0]->message);
        self::assertSame('code-smell.unused-private', $findings[0]->ruleName);
        self::assertSame(1, $findings[0]->metricValue);
    }

    #[Test]
    public function itReportsAWarningFindingForAnUnusedPrivateProperty(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'PropClass');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/PropClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('code-smell.unused-private.total', 1)
            ->withEntry('code-smell.unused-private.property', ['line' => 10, 'name' => 'cache']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame('Unused private property `cache`', $findings[0]->message);
    }

    #[Test]
    public function itReportsAWarningFindingForAnUnusedPrivateConstant(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ConstClass');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/ConstClass.php', 5);

        $metricBag = (new MetricBag())
            ->with('code-smell.unused-private.total', 1)
            ->withEntry('code-smell.unused-private.constant', ['line' => 8, 'name' => 'MAX_RETRIES']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame('Unused private constant `MAX_RETRIES`', $findings[0]->message);
        self::assertSame(8, $findings[0]->location->line);
    }

    #[Test]
    public function itReportsOneFindingPerUnusedMemberInDeclarationOrder(): void
    {
        $rule = new UnusedPrivateRule(new UnusedPrivateOptions());

        $symbolPath = SymbolPath::forClass('App', 'ManyUnused');
        $classInfo = $this->exactClassInfo($symbolPath, 'src/ManyUnused.php', 5);

        $metricBag = (new MetricBag())
            ->with('code-smell.unused-private.total', 4)
            ->withEntry('code-smell.unused-private.method', ['line' => 10, 'name' => 'foo'])
            ->withEntry('code-smell.unused-private.method', ['line' => 15, 'name' => 'bar'])
            ->withEntry('code-smell.unused-private.property', ['line' => 7, 'name' => 'baz'])
            ->withEntry('code-smell.unused-private.constant', ['line' => 8, 'name' => 'QUX']);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(4, $findings);

        self::assertSame([
            'Unused private method `foo`',
            'Unused private method `bar`',
            'Unused private property `baz`',
            'Unused private constant `QUX`',
        ], array_map(static fn($finding): string => $finding->message, $findings));
        self::assertSame([10, 15, 7, 8], array_map(static fn($finding): ?int => $finding->location->line, $findings));
        self::assertSame([4, 4, 4, 4], array_map(static fn($finding): int|float|null => $finding->metricValue, $findings));
        self::assertSame([true, true, true, true], array_map(static fn($finding): bool => $finding->location->precise, $findings));
    }

    #[Test]
    public function itPreservesTheUnnamedEntryWordingAndRecommendation(): void
    {
        $classInfo = $this->exactClassInfo(SymbolPath::forClass('App', 'Unnamed'), 'src/Unnamed.php', 5);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())
                ->with('code-smell.unused-private.total', 1)
                ->withEntry('code-smell.unused-private.property', ['line' => 9]),
        );

        $findings = (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame('Unused private property', $findings[0]->message);
        self::assertSame('Remove the unused symbol to reduce dead code.', $findings[0]->recommendation);
        self::assertSame($classInfo->subject?->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itRejectsMissingExactAndDeclarationSubjectsBeforeReadingMetrics(): void
    {
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('getSubject');
        $repository->method('allClassDeclarations')->willReturn([
            new SymbolInfo(SymbolPath::forClass('App', 'Legacy'), RelativePath::fromString('src/Legacy.php'), 1),
        ]);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Unused private findings require an exact class subject');

        (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));
    }

    #[Test]
    public function itSkipsTypedNonClassDeclarationsBeforeReadingMetrics(): void
    {
        $path = RelativePath::fromString('src/helper.php');
        $function = new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forGlobalFunction('App', 'helper'), $path, DeclarationOrdinal::fromRank(0))),
            $path,
            1,
        );
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$function]);
        $repository->expects(self::never())->method('getSubject');

        self::assertSame([], (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository)));
    }

    #[Test]
    public function itProducesSeparateExactSubjectFindingsForDuplicateLogicalClassDeclarations(): void
    {
        $logical = SymbolPath::forClass('App', 'Duplicated');
        $first = $this->exactClassInfo($logical, 'src/First.php', 5);
        $second = $this->exactClassInfo($logical, 'src/Second.php', 8);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$first, $second]);
        $repository->method('getSubject')->willReturnCallback(
            static fn() => (new MetricBag())
                ->with('code-smell.unused-private.total', 1)
                ->withEntry('code-smell.unused-private.method', ['line' => 15, 'name' => 'stale']),
        );

        $findings = (new UnusedPrivateRule(new UnusedPrivateOptions()))->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        self::assertSame($first->subject?->toCanonical(), $findings[0]->subject->toCanonical());
        self::assertSame($second->subject?->toCanonical(), $findings[1]->subject->toCanonical());
    }

    #[Test]
    public function itReadsTheEnabledFlagFromTheOptionsArray(): void
    {
        $options = UnusedPrivateOptions::fromResolved(ResolvedOptionsFixture::values(UnusedPrivateOptions::class, ['enabled' => false]));
        self::assertFalse($options->isEnabled());

        $options = UnusedPrivateOptions::fromResolved(ResolvedOptionsFixture::values(UnusedPrivateOptions::class, []));
        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itReportsWarningSeverityForAnyPositiveCountAndNoneForZero(): void
    {
        $options = new UnusedPrivateOptions();

        self::assertSame(Severity::Warning, $options->getSeverity(1));
        self::assertSame(Severity::Warning, $options->getSeverity(5));
        self::assertNull($options->getSeverity(0));
    }

    private function exactClassInfo(SymbolPath $symbolPath, string $file, int $line): SymbolInfo
    {
        $relativePath = RelativePath::fromString($file);

        return new SymbolInfo(
            MetricSubject::declaration(DeclarationPath::of($symbolPath, $relativePath, DeclarationOrdinal::fromRank(0))),
            $relativePath,
            $line,
        );
    }
}
