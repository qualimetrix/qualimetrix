<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\Audit\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Policy\Inline\Directive\RefusedDirectives;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

/**
 * What each authored suppression did, against the real channel universe.
 *
 * The verdict is the computation; the `annotation.unused-directive` finding is
 * one projection of it. Every case here separates an answer from the absence of
 * one: three of the four `unmeasurable` paths used to be a single boolean, and
 * collapsing them again would report "this annotation does nothing" about a
 * directive nobody could ask about.
 */
#[CoversClass(DirectiveUsage::class)]
final class DirectiveUsageTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    private const string CHANNEL = 'coupling.cbo';

    #[Test]
    public function itCallsASuppressionEffectiveWhenSomethingItCoversWasProduced(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL), [self::finding()], LevelActivity::empty(), self::coverage());

        self::assertSame(DirectiveEffect::Effective, self::single($verdicts)->effect);
        self::assertNull(self::single($verdicts)->reason);
    }

    #[Test]
    public function itCallsASuppressionInertWhenNothingItCoversWasProduced(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL), [], LevelActivity::empty(), self::coverage());

        self::assertSame(DirectiveEffect::Inert, self::single($verdicts)->effect);
    }

    #[Test]
    public function itDoesNotCallARunWideProducerInertOnAPartialSelection(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective('design.noc'), [], LevelActivity::empty(), self::partialCoverage());

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
    }

    /**
     * The authored spelling is `*` for the symbol and next-line forms; a bare
     * `@qmx-ignore-file` is desugared to the same token by the extractor.
     */
    #[Test]
    public function itJudgesADirectiveWithoutARuleFilterByWhatItSilenced(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(SuppressionTarget::NO_RULE_FILTER), [], LevelActivity::empty(), self::coverage());

        self::assertSame(DirectiveEffect::Inert, self::single($verdicts)->effect);
        $effective = self::usage()->verdicts(self::fileDirective(SuppressionTarget::NO_RULE_FILTER), [self::finding()], LevelActivity::empty(), self::coverage());
        self::assertSame(DirectiveEffect::Effective, self::single($effective)->effect);
    }

    /**
     * `coupling.cbo:project` names a level the channel never reports at, so no
     * finding could ever be silenced by it. `annotation.unresolved-directive`
     * already says so, and calling it inert on top would judge one mistake twice.
     */
    #[Test]
    public function itRefusesToJudgeAChannelLevelPairAddressabilityAlreadyRefused(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL . ':project'), [], LevelActivity::empty(), self::coverage());

        self::assertSame([], $verdicts);
    }

    /**
     * The directive was refused where it was written, so the audit answers
     * about it with the same refusal rather than judging it a second time —
     * which would print a staleness complaint on the line the refusal already
     * occupies.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveThatReachesTheBannedChannel(): void
    {
        foreach ([InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME, 'annotation.*'] as $target) {
            $verdicts = self::usage()->verdicts(self::fileDirective($target), [], LevelActivity::empty(), self::coverage());

            self::assertSame([], $verdicts, $target);
        }
    }

    #[Test]
    public function itRefusesToJudgeASelectorThatNamesNoChannelAtAll(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective('coupling.instabilty'), [], LevelActivity::empty(), self::coverage());

        self::assertSame([], $verdicts);
    }

    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerASelectorSwitchedOff(): void
    {
        $registry = new RuleOptionsRegistry();

        $verdicts = self::usage($registry, disabled: [self::CHANNEL])->verdicts(self::fileDirective(self::CHANNEL), [], LevelActivity::empty(), self::coverage());

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::ProducerDisabled, self::single($verdicts)->reason);
    }

    /**
     * The other way of switching a rule off: it runs and returns nothing.
     * Reading only the selector is what made this path report every annotation
     * of the switched-off rule as a leftover.
     *
     * The switch is read off what the run recorded, not re-derived from the
     * registry here — the spellings that put it there are covered on the rule
     * that reads them.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerOptionsSwitchedOff(): void
    {
        $registry = new RuleOptionsRegistry();

        $verdicts = self::usage($registry)->verdicts(
            self::fileDirective(self::CHANNEL),
            [],
            LevelActivity::fromMap([self::CHANNEL => [SymbolLevel::Class_->value => false]]),
            self::coverage(),
        );

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::ProducerDisabled, self::single($verdicts)->reason);
    }

    /**
     * A class docblock is materialised on the class and on every method it
     * governs. Those are bindings of one annotation: the author wrote one
     * directive, and it did something as soon as any one of them was silenced.
     */
    #[Test]
    public function itReportsOneVerdictForAClassDocblockThatBoundSixDeclarations(): void
    {
        $verdicts = self::usage()->verdicts(
            [self::FILE => self::classDocblockBindings(self::CHANNEL)],
            [self::finding(MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forMethod('Demo', 'Big', 'c'),
                RelativePath::fromString(self::FILE),
                DeclarationOrdinal::fromRank(0),
            )))],
            LevelActivity::empty(),
            self::coverage(),
        );

        self::assertSame(DirectiveEffect::Effective, self::single($verdicts)->effect);
    }

    /**
     * Two forms on one line addressing one channel are two directives, not one:
     * the authored-site key carries the form, and a report that merged them
     * could not say which tag to remove.
     */
    #[Test]
    public function itKeepsTwoDirectiveFormsWrittenOnOneLineApart(): void
    {
        $verdicts = self::usage()->verdicts([self::FILE => [
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::File, position: 0),
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::NextLine, position: 0, silencedLine: 7 + 1),
        ]], [], LevelActivity::empty(), self::coverage());

        self::assertCount(2, $verdicts);
        self::assertSame(
            [SuppressionType::File->value, SuppressionType::NextLine->value],
            array_map(static fn(DirectiveVerdict $verdict): string => $verdict->site->form, $verdicts),
        );
    }

    /**
     * Where the directive was written, not where the finding was reported. The
     * file and line are what a reader opens to delete the annotation, and they
     * are also what the stale finding is keyed by, so nothing else in the suite
     * would notice if the verdict carried the wrong site.
     */
    #[Test]
    public function itCarriesTheSiteTheDirectiveWasWrittenAt(): void
    {
        $verdicts = self::usage()->verdicts(
            ['src/Other.php' => [new Suppression(self::CHANNEL, 'reason', 42, SuppressionType::File, position: 0)]],
            [],
            LevelActivity::empty(),
            self::coverage(),
        );

        self::assertSame('src/Other.php', self::single($verdicts)->site->file->value());
        self::assertSame(42, self::single($verdicts)->site->line);
        self::assertSame(self::CHANNEL, self::single($verdicts)->site->target);
    }

    /**
     * Three keys now describe one authored site — the two in the code and the
     * verdict's own — and nothing forces them to agree. The fixture carries
     * both shapes that could split them: a class docblock materialised on six
     * declarations, and two forms written on one line.
     */
    #[Test]
    public function itGroupsAuthoredSitesTheSameWayThePolicyDoes(): void
    {
        $directives = [self::FILE => [
            ...self::classDocblockBindings(self::CHANNEL),
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::File, position: 0),
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::NextLine, position: 0, silencedLine: 7 + 1),
        ]];

        $policy = new InlineDirectivePolicy(self::usage(), new RefusedDirectives(self::productionUniverse()));
        $policy->prepare($directives, [], []);
        $authored = array_map(
            static fn(Suppression $suppression): string => $suppression->line . '/' . $suppression->type->value,
            $policy->authoredSuppressions()[self::FILE],
        );
        $judged = array_map(
            static fn(DirectiveVerdict $verdict): string => $verdict->site->line . '/' . $verdict->site->form,
            self::usage()->verdicts($directives, [], LevelActivity::empty(), self::coverage()),
        );

        sort($authored);
        sort($judged);

        self::assertSame($authored, $judged);
        self::assertCount(3, $judged);
    }

    /**
     * Findings and verdicts must agree about the same directive under the
     * captured publication used by the invocation.
     */
    #[Test]
    public function itProjectsExactlyTheInertVerdictsIntoStaleFindings(): void
    {
        $directives = [self::FILE => [
            new Suppression(self::CHANNEL, 'reason', 3, SuppressionType::File, position: 0),
            new Suppression(SuppressionTarget::NO_RULE_FILTER, 'reason', 4, SuppressionType::File, position: 0),
            new Suppression('complexity.ccn', 'reason', 5, SuppressionType::File, position: 0),
        ]];
        $registry = new RuleOptionsRegistry();
        $usage = self::usage($registry);
        $publication = new ChannelPublication($registry->enablement() ?? throw new LogicException('Fixture requires resolved publication.'));

        $inert = array_values(array_filter(
            $usage->verdicts($directives, [self::finding()], LevelActivity::empty(), self::coverage()),
            static fn(DirectiveVerdict $verdict): bool => $verdict->effect === DirectiveEffect::Inert,
        ));
        $stale = $usage->usageResult($directives, [self::finding()], Severity::Warning, LevelActivity::empty(), self::coverage(), $publication)['findings'];

        self::assertCount(1, $inert);
        self::assertSame('complexity.ccn', $inert[0]->site->target);
        self::assertCount(1, $stale);
        self::assertSame($inert[0]->site->line, $stale[0]->location->line);
    }

    /** @param list<DirectiveVerdict> $verdicts */
    private static function single(array $verdicts): DirectiveVerdict
    {
        self::assertCount(1, $verdicts);

        return $verdicts[0];
    }

    #[Test]
    public function itKeepsIdenticalSuppressionsFromTwoPositionsOnOneLineApart(): void
    {
        $directives = [self::FILE => [
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::File, position: 20),
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::File, position: 80),
        ]];
        $usage = self::usage();
        $verdicts = $usage->verdicts($directives, [], LevelActivity::empty(), self::coverage());
        self::assertCount(2, $verdicts);
        self::assertSame([20, 80], array_map(static fn(DirectiveVerdict $v): ?int => $v->site->position, $verdicts));

        $policy = new InlineDirectivePolicy($usage, new RefusedDirectives(self::productionUniverse()));
        $policy->prepare($directives, [], []);
        $authored = $policy->authoredSuppressions();
        self::assertArrayHasKey(self::FILE, $authored);
        self::assertCount(2, $authored[self::FILE]);
    }

    /** @return array<string, list<Suppression>> */
    /**
     * A directive the extractor refused is carried to the store so that it can
     * be reported at all. The audit must not then judge it: `check` has already
     * named the line, and a second answer would either credit a dead tag or
     * demand the author delete an annotation whose question nobody could ask.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveTheExtractorRefused(): void
    {
        $refused = [self::FILE => [new Suppression(
            self::CHANNEL,
            null,
            3,
            SuppressionType::Symbol,
            position: 0,
            refusal: DirectiveRefusal::noDeclarationToBind(),
        )]];

        $verdicts = self::usage()->verdicts($refused, [self::finding()], LevelActivity::empty(), self::coverage());

        self::assertSame([], $verdicts);
    }

    /** A report that named an unreadable tag as one of the four real ones would send its author to the wrong line. */
    #[Test]
    public function itReportsAnUnreadableTagUnderTheFormItWasWrittenAs(): void
    {
        $refused = [self::FILE => [new Suppression(
            self::CHANNEL,
            null,
            3,
            SuppressionType::Symbol,
            position: 0,
            refusal: DirectiveRefusal::formNotRecognised('ignore-lines'),
        )]];

        $policy = new InlineDirectivePolicy(self::usage(), new RefusedDirectives(self::productionUniverse()));
        $policy->prepare($refused, [], []);
        $verdicts = $policy->directiveVerdicts([], LevelActivity::empty(), self::coverage());

        self::assertSame('ignore-lines', self::single($verdicts)->site->form);
        self::assertSame(DirectiveEffect::Refused, self::single($verdicts)->effect);
    }

    /**
     * Two refusals of different tags naming one channel on one line are two
     * mistakes, and the author has to fix both. The authored-site key named
     * the type, which every refusal shares, instead of the form it was
     * written as — so the store kept one and the report judged one.
     */
    #[Test]
    public function itKeepsTwoRefusalsOfDifferentFormsOnOneLineApart(): void
    {
        $directives = [self::FILE => [
            new Suppression(self::CHANNEL, null, 3, SuppressionType::Symbol, position: 0, refusal: DirectiveRefusal::formNotRecognised('ignore-lines')),
            new Suppression(self::CHANNEL, null, 3, SuppressionType::Symbol, position: 0, refusal: DirectiveRefusal::formNotRecognised('ignore-lins')),
            new Suppression(self::CHANNEL, null, 3, SuppressionType::Symbol, position: 0, refusal: DirectiveRefusal::noDeclarationToBind()),
        ]];

        $policy = new InlineDirectivePolicy(self::usage(), new RefusedDirectives(self::productionUniverse()));
        $policy->prepare($directives, [], []);
        $judged = array_map(
            static fn(DirectiveVerdict $verdict): string => $verdict->site->form,
            $policy->directiveVerdicts([], LevelActivity::empty(), self::coverage()),
        );
        sort($judged);

        self::assertCount(3, $policy->authoredSuppressions()[self::FILE]);
        self::assertSame(['ignore-lines', 'ignore-lins', 'symbol'], $judged);
    }

    /** @return array<string, list<Suppression>> */
    private static function fileDirective(string $authored): array
    {
        return [self::FILE => [new Suppression($authored, 'reason', 3, SuppressionType::File, position: 0)]];
    }

    /**
     * What the extractor produces for one class docblock: the class plus every
     * method it governs, all carrying the same authored line and text.
     *
     * @return list<Suppression>
     */
    private static function classDocblockBindings(string $authored): array
    {
        $file = RelativePath::fromString(self::FILE);
        $subjects = [MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('Demo', 'Big'),
            $file,
            DeclarationOrdinal::fromRank(0),
        ))];

        foreach (['a', 'b', 'c', 'd', 'e'] as $member) {
            $subjects[] = MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forMethod('Demo', 'Big', $member),
                $file,
                DeclarationOrdinal::fromRank(0),
            ));
        }

        return array_map(
            static fn(MetricSubject $subject): Suppression => new Suppression(
                $authored,
                'reason',
                4,
                SuppressionType::Symbol,
                position: 0,
                binding: new DeclarationBinding($subject, ControlScope::Class_, DeclarationReach::whole(null, 'test')),
            ),
            $subjects,
        );
    }

    private static function finding(?MetricSubject $subject = null): Finding
    {
        $subject ??= MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FILE)));

        return new Finding(
            location: new Location(RelativePath::fromString(self::FILE), 10),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::CHANNEL,
            code: self::CHANNEL,
            message: 'CBO: 30 (threshold: 25)',
            severity: Severity::Warning,
        );
    }

    /** @param list<string> $disabled */
    private static function usage(?RuleOptionsRegistry $registry = null, array $disabled = []): DirectiveUsage
    {
        $universe = self::productionUniverse();
        $registry ??= new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready(
            FindingConfiguration::none(),
            self::$metadata,
            channels: $universe,
            disabled: $disabled,
        ));

        return new DirectiveUsage($universe, $registry, $universe, new RefusedDirectives($universe));
    }

    /** @var list<RuleMetadata> */
    private static array $metadata = [];

    private static ?RuleChannelSnapshotFactoryInterface $snapshotFactory = null;

    private static function coverage(): \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts
    {
        return \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(
            new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(),
            [RelativePath::fromString(self::FILE)],
            [],
        );
    }

    private static function partialCoverage(): \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts
    {
        return \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(
            new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(
                [\Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor::Paths],
            ),
            [RelativePath::fromString(self::FILE)],
            [],
        );
    }

    private static function productionUniverse(): ChannelUniverseInterface
    {
        if (self::$snapshotFactory === null) {
            $container = (new ContainerFactory())->create();
            $execution = $container->get(RuleExecutionInterface::class);
            \assert($execution instanceof RuleExecutionInterface);
            self::$metadata = $execution->allRules();
            $universe = $container->get(ChannelUniverseInterface::class);
            \assert($universe instanceof RuleChannelSnapshotFactoryInterface);
            self::$snapshotFactory = $universe;
        }

        return self::$snapshotFactory->snapshot(new ResolvedComputedMetricDefinitions([]));
    }
}
