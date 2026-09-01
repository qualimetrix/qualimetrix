<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionTarget;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveVerdict;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;

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
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL), [self::finding()]);

        self::assertSame(DirectiveEffect::Effective, self::single($verdicts)->effect);
        self::assertNull(self::single($verdicts)->reason);
    }

    #[Test]
    public function itCallsASuppressionInertWhenNothingItCoversWasProduced(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL), []);

        self::assertSame(DirectiveEffect::Inert, self::single($verdicts)->effect);
    }

    /**
     * The authored spelling is `*` for the symbol and next-line forms; a bare
     * `@qmx-ignore-file` is desugared to the same token by the extractor.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveWithoutARuleFilter(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(SuppressionTarget::NO_RULE_FILTER), []);

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::AddressesEveryChannel, self::single($verdicts)->reason);
    }

    /**
     * `coupling.cbo:project` names a level the channel never reports at, so no
     * finding could ever be silenced by it. `annotation.unresolved-directive`
     * already says so, and calling it inert on top would judge one mistake twice.
     */
    #[Test]
    public function itRefusesToJudgeAChannelLevelPairAddressabilityAlreadyRefused(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective(self::CHANNEL . ':project'), []);

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::AlreadyRefused, self::single($verdicts)->reason);
    }

    #[Test]
    public function itRefusesToJudgeASelectorThatNamesNoChannelAtAll(): void
    {
        $verdicts = self::usage()->verdicts(self::fileDirective('coupling.instabilty'), []);

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::AlreadyRefused, self::single($verdicts)->reason);
    }

    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerASelectorSwitchedOff(): void
    {
        $registry = new RuleOptionsRegistry();
        $registry->configureSelection(new RuleSelection(disabled: [self::CHANNEL]));

        $verdicts = self::usage($registry)->verdicts(self::fileDirective(self::CHANNEL), []);

        self::assertSame(DirectiveEffect::Unmeasured, self::single($verdicts)->effect);
        self::assertSame(DirectiveUnmeasurableReason::ProducerDisabled, self::single($verdicts)->reason);
    }

    /**
     * The other way of switching a rule off: it runs and returns nothing.
     * Reading only the selector is what made this path report every annotation
     * of the switched-off rule as a leftover.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerOptionsSwitchedOff(): void
    {
        $registry = new RuleOptionsRegistry();
        $registry->configureCli(self::CHANNEL, ['enabled' => false]);

        $verdicts = self::usage($registry)->verdicts(self::fileDirective(self::CHANNEL), []);

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
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::File),
            new Suppression(self::CHANNEL, 'reason', 7, SuppressionType::NextLine),
        ]], []);

        self::assertCount(2, $verdicts);
        self::assertSame(
            [SuppressionType::File->value, SuppressionType::NextLine->value],
            array_map(static fn(DirectiveVerdict $verdict): string => $verdict->form, $verdicts),
        );
    }

    /**
     * The projection claim: one computation, two answers. A `stale()` that
     * reported anything else would mean the report and the channel disagree
     * about the same directive.
     */
    #[Test]
    public function itProjectsExactlyTheInertVerdictsIntoStaleFindings(): void
    {
        $directives = [self::FILE => [
            new Suppression(self::CHANNEL, 'reason', 3, SuppressionType::File),
            new Suppression(SuppressionTarget::NO_RULE_FILTER, 'reason', 4, SuppressionType::File),
            new Suppression('complexity.cyclomatic', 'reason', 5, SuppressionType::File),
        ]];
        $usage = self::usage();

        $inert = array_values(array_filter(
            $usage->verdicts($directives, [self::finding()]),
            static fn(DirectiveVerdict $verdict): bool => $verdict->effect === DirectiveEffect::Inert,
        ));
        $stale = $usage->stale($directives, [self::finding()], Severity::Warning);

        self::assertCount(1, $inert);
        self::assertSame('complexity.cyclomatic', $inert[0]->target);
        self::assertCount(1, $stale);
        self::assertSame($inert[0]->line, $stale[0]->location->line);
    }

    /** @param list<DirectiveVerdict> $verdicts */
    private static function single(array $verdicts): DirectiveVerdict
    {
        self::assertCount(1, $verdicts);

        return $verdicts[0];
    }

    /** @return array<string, list<Suppression>> */
    private static function fileDirective(string $authored): array
    {
        return [self::FILE => [new Suppression($authored, 'reason', 3, SuppressionType::File)]];
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
                subject: $subject,
                controlScope: ControlScope::Class_,
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

    private static function usage(?RuleOptionsRegistry $registry = null): DirectiveUsage
    {
        return new DirectiveUsage(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry ?? new RuleOptionsRegistry(),
        );
    }

    private static ?RuleChannelSnapshotFactoryInterface $snapshotFactory = null;

    private static function productionUniverse(): ChannelUniverseInterface
    {
        if (self::$snapshotFactory === null) {
            $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($universe instanceof RuleChannelSnapshotFactoryInterface);
            self::$snapshotFactory = $universe;
        }

        return self::$snapshotFactory->snapshot(new ResolvedComputedMetricDefinitions([]));
    }
}
