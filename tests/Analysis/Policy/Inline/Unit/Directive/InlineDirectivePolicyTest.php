<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

/**
 * Usage accounting for the three suppression forms.
 *
 * The three forms are covered separately because each binds to the finding
 * differently — a symbol directive by declaration subject, a file directive by
 * file, a next-line directive by the line after itself — and a single shared
 * case would prove only whichever binding it happened to exercise.
 */
#[CoversClass(InlineDirectivePolicy::class)]
final class InlineDirectivePolicyTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    #[Test]
    public function itReportsASymbolDirectiveThatMatchedNothing(): void
    {
        $policy = self::policy();
        $policy->prepare([self::FILE => [self::symbolDirective()]], [], []);
        $policy->enableUsageReporting(Severity::Info);

        $findings = $policy->auditDirectiveUsage([]);

        self::assertCount(1, $findings);
        self::assertSame(InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME, $findings[0]->code);
        self::assertSame(Severity::Info, $findings[0]->severity);
    }

    #[Test]
    public function itStaysSilentWhenTheSymbolDirectiveActuallySuppressedSomething(): void
    {
        $policy = self::policy();
        $policy->prepare([self::FILE => [self::symbolDirective()]], [], []);
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([self::finding(self::declarationSubject(), 42)]));
    }

    #[Test]
    public function itAccountsForTheFileForm(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertCount(1, $policy->auditDirectiveUsage([]));
        self::assertSame([], $policy->auditDirectiveUsage([self::finding(self::fileSubject(), 99)]));
    }

    #[Test]
    public function itAccountsForTheNextLineForm(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 10, SuppressionType::NextLine)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertCount(
            1,
            $policy->auditDirectiveUsage([self::finding(self::fileSubject(), 12)]),
            'A finding two lines down is not the next line, so the directive did nothing.',
        );
        self::assertSame([], $policy->auditDirectiveUsage([self::finding(self::fileSubject(), 11)]));
    }

    /**
     * The first of the two scope limits: disabling a family of rules must not
     * turn every annotation belonging to it into a finding. The shipped
     * `legacy` preset does exactly that disabling.
     */
    #[Test]
    public function itIgnoresDirectivesAddressingARuleThisRunDisabled(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->configureSelection(new RuleSelection([], ['code-smell.goto']));

        $policy = self::policy($configuration);
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /**
     * The same limit reached the other way. `disabled_rules` stops the rule
     * from running; `rules: { X: { enabled: false } }` lets it run and makes
     * it return nothing. The user made one decision either way, so reading
     * only the selection reported every annotation of a rule switched off by
     * its own options as a leftover.
     */
    #[Test]
    public function itIgnoresDirectivesAddressingARuleSwitchedOffByItsOwnOptions(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->setConfigFileOptions(['code-smell.goto' => ['enabled' => false]]);

        $policy = self::policy($configuration);
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /** The scalar spelling of the same thing. */
    #[Test]
    public function itIgnoresDirectivesAddressingARuleSwitchedOffByTheScalarSpelling(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->setConfigFileOptions(['code-smell.goto' => false]);

        $policy = self::policy($configuration);
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /** A live rule is still accounted for — the guard above is not a blanket. */
    #[Test]
    public function itStillAccountsForARuleLeftEnabledByItsOptions(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->setConfigFileOptions(['code-smell.goto' => ['enabled' => true]]);

        $policy = self::policy($configuration);
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertCount(1, $policy->auditDirectiveUsage([]));
    }

    /**
     * The explicit `ruleName#violationCode` spelling is accounted for exactly
     * like the one-part one — it names one channel and needs no expansion.
     */
    #[Test]
    public function itAccountsForADirectiveWrittenAsAnExplicitChannelPair(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertCount(1, $policy->auditDirectiveUsage([]));
        self::assertSame([], $policy->auditDirectiveUsage([self::finding(self::fileSubject(), 99)]));
    }

    /**
     * A target addressing no channel is already reported as a configuration
     * error, so counting it here too would say the same directive is both
     * broken and merely stale. The retired `rule#code` spelling is the sharpest
     * case: it is a target that no longer parses at all.
     */
    #[Test]
    public function itDoesNotAlsoCallAnUnaddressableTargetStale(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto#code-smell.goto', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /**
     * The second limit needs no code and is asserted anyway: the directive map
     * is keyed by the files collection analysed, so an annotation outside the
     * analysed set is never in the map to begin with.
     */
    #[Test]
    public function itOnlySeesDirectivesFromTheAnalysedFileSet(): void
    {
        $policy = self::policy();
        $policy->prepare([], [], []);
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /**
     * A directive whose pair is impossible — the channel is real but never
     * reports at the named level — must never be counted stale. Before this
     * fix, `isAccountable()` read only the channel half of the target and let
     * the impossible pair through: the same directive would then earn both
     * `annotation.unresolved-directive` from {@see \Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability}
     * and a spurious `annotation.unused-directive` from here — one mistake,
     * reported twice.
     */
    #[Test]
    public function itNeverCallsAnImpossiblePairStale(): void
    {
        $policy = self::policy();
        $policy->prepare(
            [self::FILE => [new Suppression('code-smell.goto:project', null, 1, SuppressionType::File)]],
            [],
            [],
        );
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /** "Everything here" has no channel to check, so it is never stale. */
    #[Test]
    public function itNeverReportsTheNoRuleFilterForm(): void
    {
        $policy = self::policy();
        $policy->prepare([self::FILE => [new Suppression('*', null, 1, SuppressionType::File)]], [], []);
        $policy->enableUsageReporting(Severity::Info);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    /** Without the owning rule having run, the post-execution half says nothing. */
    #[Test]
    public function itReportsNothingUntilTheOwningRuleEnablesIt(): void
    {
        $policy = self::policy();
        $policy->prepare([self::FILE => [self::symbolDirective()]], [], []);

        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    #[Test]
    public function itForgetsTheReportingGateOnReset(): void
    {
        $policy = self::policy();
        $policy->prepare([self::FILE => [self::symbolDirective()]], [], []);
        $policy->enableUsageReporting(Severity::Info);
        self::assertCount(1, $policy->auditDirectiveUsage([]));

        $policy->reset();
        self::assertSame([], $policy->auditDirectiveUsage([]));
    }

    private static function policy(?RuleOptionsRegistry $configuration = null): InlineDirectivePolicy
    {
        $channel = new FindingChannel('code-smell.goto');

        return new InlineDirectivePolicy(
            new ChannelUniverse(
                [$channel->code => ChannelDeclaration::occurrence(SymbolLevel::Class_)],
                ['code-smell.goto' => [$channel->code]],
                ['code-smell.goto' => false],
                new ResolvedComputedMetricDefinitions([]),
            ),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $configuration ?? new RuleOptionsRegistry(),
        );
    }

    private static function symbolDirective(): Suppression
    {
        return new Suppression(
            'code-smell.goto',
            'reason',
            10,
            SuppressionType::Symbol,
            subject: self::declarationSubject(),
            controlScope: ControlScope::Class_,
        );
    }

    private static function declarationSubject(): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'Foo'), RelativePath::fromString(self::FILE), DeclarationOrdinal::fromRank(0)));
    }

    private static function fileSubject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FILE)));
    }

    private static function finding(MetricSubject $subject, int $line): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString(self::FILE), $line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: 'code-smell.goto',
            code: 'code-smell.goto',
            message: 'goto',
            severity: Severity::Warning,
        );
    }
}
