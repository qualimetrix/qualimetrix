<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * The cases the command cannot stage: a run with no namespace tree, and the
 * exact matcher semantics the suppression itself uses.
 *
 * Everything about wiring, selection and exit codes is proved by
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\SuppressionBinding\UnboundSuppressionIntegrationTest}
 * on a real run instead; a passing unit test here would say nothing about any
 * of it.
 */
#[CoversClass(UnboundSuppressionAudit::class)]
final class UnboundSuppressionAuditTest extends TestCase
{
    /**
     * A run that built no namespace tree has no universe to judge namespaces
     * against, and "no universe" is not "nothing bound": reporting every
     * configured namespace there would turn a missing measurement into an
     * accusation. The path half is unaffected — its universe is the coverage,
     * which a run always has.
     */
    #[Test]
    public function itJudgesNoNamespaceWhenTheRunBuiltNoNamespaceTree(): void
    {
        $channels = $this->channelsOf($this->audit()->findings(
            [$this->path(SelectorKind::Subtree, 'src/Gone')],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')],
            [RelativePath::fromString('src/Service.php')],
            null,
            $this->scope(),
        ));

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_PATH], $channels);
    }

    /**
     * An empty tree is a measured answer, unlike `null`: the run declared no
     * namespace, so a configured namespace really did bind to nothing.
     */
    #[Test]
    public function itJudgesNamespacesAgainstAnEmptyTree(): void
    {
        $channels = $this->channelsOf($this->audit()->findings(
            [],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')],
            [],
            [],
            $this->scope(),
        ));

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_NAMESPACE], $channels);
    }

    /**
     * Binding is decided by the same two matchers the suppression will use, so
     * a regex and subtree selector that the filter would honour is a hit here too. Were the two to
     * part, the channel would accuse a value that goes on suppressing findings
     * every run.
     */
    #[Test]
    public function itHonoursTheRegexAndSubtreeModesTheSuppressionFiltersUse(): void
    {
        $bound = $this->audit()->findings(
            [
                $this->path(SelectorKind::Regex, 'src/.*Service\\.php'),
                $this->path(SelectorKind::Subtree, 'src'),
            ],
            [
                $this->namespace(SelectorKind::Regex, 'Sample\\\\.*'),
                $this->namespace(SelectorKind::Subtree, 'Sample'),
            ],
            [RelativePath::fromString('src/UserService.php')],
            ['Sample\\Deep'],
            $this->scope([$this->tempDir . '/src', $this->tempDir . '/tests']),
        );

        self::assertSame([], $this->channelsOf($bound));
    }

    /** A prefix stops at a path boundary, exactly as the filter's does. */
    #[Test]
    public function itDoesNotTreatAPrefixOfANameAsABinding(): void
    {
        $findings = $this->audit()->findings(
            [$this->path(SelectorKind::Subtree, 'src/Serv')],
            [],
            [RelativePath::fromString('src/Service/User.php')],
            [],
            $this->scope(),
        );

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_PATH], $this->channelsOf($findings));
    }

    /** Nothing is judged with the producer switched off. */
    #[Test]
    public function itJudgesNothingWhenTheProducerIsDisabled(): void
    {
        $audit = $this->audit(enabled: false);

        self::assertSame([], $audit->findings(
            [$this->path(SelectorKind::Subtree, 'src/Gone')],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')],
            [],
            [],
            $this->scope(),
        ));
    }

    /**
     * The ledger is read by both option spellings, because
     * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} applies both:
     * a channel reading only one of them would call half the configured
     * patterns unbound while the run was busy applying them.
     */
    #[Test]
    public function itReadsBothSpellingsOfThePerRuleLedgerOptions(): void
    {
        $audit = $this->audit(
            pathLedger: [
                'complexity.ccn' => [$this->path(SelectorKind::Subtree, 'src/Gone')],
                'code-smell.goto' => [$this->path(SelectorKind::Exact, 'src/Service.php')],
            ],
            namespaceLedger: [
                'design.dit' => [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')],
            ],
        );

        $findings = $audit->findings(
            [],
            [],
            [RelativePath::fromString('src/Service.php')],
            [],
            $this->scope(),
        );
        $messages = array_map(static fn(Finding $finding): string => $finding->message, $findings);

        self::assertCount(2, $findings, implode(' | ', $messages));
        self::assertStringContainsString('complexity.ccn', $messages[0]);
        self::assertStringContainsString('design.dit', $messages[1]);
    }

    /**
     * The third option the ledger applies.
     *
     * `suppress_namespace_channels` was applied by
     * {@see \Qualimetrix\Analysis\Finding\FindingExclusionLedger} and judged by
     * nobody while each side enumerated the options for itself, so a pattern
     * under it kept its silence. Both sides now read
     * {@see \Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression},
     * and the report names the selector because that is the line to find.
     */
    #[Test]
    public function itJudgesAChannelSuppressionPatternTheLedgerApplies(): void
    {
        $audit = $this->audit(channelLedger: [
            'coupling.cbo' => ['coupling.cbo:namespace' => [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')]],
        ]);

        $findings = $audit->findings([], [], [], ['Sample'], $this->scope());

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER], $this->channelsOf($findings));
        self::assertStringContainsString(
            'suppress_namespace_channels.coupling.cbo:namespace',
            $findings[0]->message . ' ' . ($findings[0]->recommendation ?? ''),
        );
    }

    /**
     * The same option, bound: the channel speaks about a pattern that names
     * nothing, never about one that names a namespace the run declared.
     */
    #[Test]
    public function itStaysSilentOnAChannelSuppressionPatternThatBinds(): void
    {
        $audit = $this->audit(channelLedger: [
            'coupling.cbo' => ['coupling.cbo:namespace' => [$this->namespace(SelectorKind::Subtree, 'Sample')]],
        ]);

        self::assertSame([], $this->channelsOf($audit->findings([], [], [], ['Sample'], $this->scope())));
    }

    /**
     * The defect this gate was added for: a legitimate entry written for
     * `qmx check .` reported as unbound by `qmx check src/`, which never
     * looked where the entry points. Both halves fire at once, so both are
     * asserted here.
     */
    #[Test]
    public function itJudgesNoValueWhoseSubjectTheRunDidNotAnalyse(): void
    {
        $findings = $this->audit()->findings(
            [$this->path(SelectorKind::Subtree, 'tests/Gone')],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Tests\\Gone')],
            [RelativePath::fromString('src/Service.php')],
            ['Sample'],
            $this->scope(),
        );

        self::assertSame([], $this->channelsOf($findings));
    }

    /**
     * The same two entries on a run that does reach them: unjudgeable is a
     * fact about the pair, not a permanent exemption, and a stale entry is
     * still named the moment a run can tell.
     */
    #[Test]
    public function itJudgesTheSameValuesOnARunThatReachesTheirSubject(): void
    {
        $findings = $this->audit()->findings(
            [$this->path(SelectorKind::Subtree, 'tests/Gone')],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Tests\\Gone')],
            [RelativePath::fromString('src/Service.php'), RelativePath::fromString('tests/ServiceTest.php')],
            ['Sample', 'Sample\\Tests'],
            $this->scope([$this->tempDir]),
        );

        self::assertSame(
            [UnboundSuppressionOptions::UNMATCHED_PATH, UnboundSuppressionOptions::UNMATCHED_NAMESPACE],
            $this->channelsOf($findings),
        );
    }

    /**
     * A regex is only judged when the run covers the complete project
     * universe. On a partial run, an unmatched expression remains silent.
     */
    #[Test]
    public function itLeavesAnUnmatchedRegexSilentOnAPartialRun(): void
    {
        $findings = $this->audit()->findings(
            [$this->path(SelectorKind::Regex, '.*Gone\\.php')],
            [],
            [RelativePath::fromString('src/Service.php')],
            [],
            $this->scope(),
        );

        self::assertSame([], $this->channelsOf($findings));
    }

    /**
     * The namespace twin is judged on a run that covers all autoload roots:
     * an unmatched regex is then an actionable stale selector.
     */
    #[Test]
    public function itJudgesAnUnmatchedNamespaceRegexOnAWholeProjectRun(): void
    {
        $findings = $this->audit()->findings(
            [],
            [$this->namespace(SelectorKind::Regex, '.*\\\\Gone')],
            [RelativePath::fromString('src/Service.php'), RelativePath::fromString('tests/ServiceTest.php')],
            ['Sample', 'Sample\\Tests'],
            $this->scope([$this->tempDir]),
        );

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_NAMESPACE], $this->channelsOf($findings));
    }

    /**
     * And the same whole-project run still names an anchored namespace value
     * that bound to nothing — the report the case above must not have cost.
     */
    #[Test]
    public function itStillNamesAnAnchoredNamespaceValueOnThatSameRun(): void
    {
        $findings = $this->audit()->findings(
            [],
            [$this->namespace(SelectorKind::Subtree, 'Sample\\Gone')],
            [RelativePath::fromString('src/Service.php'), RelativePath::fromString('tests/ServiceTest.php')],
            ['Sample', 'Sample\\Tests'],
            $this->scope([$this->tempDir . '/src', $this->tempDir . '/tests']),
        );

        self::assertSame([UnboundSuppressionOptions::UNMATCHED_NAMESPACE], $this->channelsOf($findings));
    }

    /** Recommendations describe the explicit authored kind, never the removed implicit-glob grammar. */
    #[Test]
    public function itExplainsTheExplicitSelectorKindInUnmatchedRecommendations(): void
    {
        $findings = $this->audit()->findings(
            [
                $this->path(SelectorKind::Exact, 'src/Gone.php'),
                $this->path(SelectorKind::Subtree, 'src/Gone'),
                $this->path(SelectorKind::Regex, 'src/.*Gone\\.php'),
            ],
            [],
            [RelativePath::fromString('src/Service.php'), RelativePath::fromString('tests/ServiceTest.php')],
            [],
            $this->scope([$this->tempDir]),
        );
        $recommendations = array_map(
            static fn(Finding $finding): string => $finding->recommendation ?? '',
            $findings,
        );

        self::assertCount(3, $recommendations);
        self::assertStringContainsString('"exact" selector matches only', $recommendations[0]);
        self::assertStringContainsString('"subtree" selector also includes descendants', $recommendations[1]);
        self::assertStringContainsString('"regex" selector is a full-subject PCRE fragment', $recommendations[2]);
        self::assertStringNotContainsString('glob character', implode(' ', $recommendations));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function channelsOf(array $findings): array
    {
        return array_map(static fn(Finding $finding): string => $finding->ruleName, $findings);
    }

    /**
     * @param array<string, list<PathPattern>> $pathLedger
     * @param array<string, list<NamespacePattern>> $namespaceLedger
     * @param array<string, array<string, list<NamespacePattern>>> $channelLedger
     */
    private function audit(
        bool $enabled = true,
        array $pathLedger = [],
        array $namespaceLedger = [],
        array $channelLedger = [],
    ): UnboundSuppressionAudit {
        $execution = self::createStub(RuleExecutionInterface::class);
        // Selection is proved on a real run; here the audit's own arithmetic is
        // the subject, so `publishable()` passes everything through.
        $execution->method('publishable')->willReturnArgument(0);

        $configuration = self::createStub(RuleConfigurationInterface::class);
        $rules = array_values(array_unique([
            ...array_keys($pathLedger),
            ...array_keys($namespaceLedger),
            ...array_keys($channelLedger),
        ]));
        $configuration->method('all')->willReturn(array_fill_keys($rules, []));
        $configuration->method('pathExclusions')->willReturnCallback(
            static fn(string $ruleName): array => $pathLedger[$ruleName] ?? [],
        );
        $configuration->method('namespaceExclusions')->willReturnCallback(
            static fn(string $ruleName): array => $namespaceLedger[$ruleName] ?? [],
        );
        $configuration->method('namespaceChannelExclusions')->willReturnCallback(
            static fn(string $ruleName): array => $channelLedger[$ruleName] ?? [],
        );

        return new UnboundSuppressionAudit(
            new UnboundSuppressionOptions($enabled),
            $execution,
            $configuration,
        );
    }

    /**
     * A real tree, because the per-value gate answers by looking at one: the
     * project autoloads `Sample\` from `src/` for production and
     * `Sample\Tests\` from `tests/` for development, and the run below
     * analyses `src/` alone — the exact shape that reported three channels at
     * once before this gate existed.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-unbound-audit-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/src', 0777, true);
        mkdir($this->tempDir . '/tests', 0777, true);
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Sample\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Sample\\Tests\\' => 'tests/']],
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    private string $tempDir = '';

    /**
     * @param ?list<string> $analyzedPaths
     */
    private function scope(?array $analyzedPaths = null): ValueScopeJudgement
    {
        return new ValueScopeJudgement(
            $this->tempDir,
            (new ComposerReader())->extractPsr4Roots($this->tempDir . '/composer.json'),
            $analyzedPaths ?? [$this->tempDir . '/src'],
            projectDeclared: true,
        );
    }

    private function path(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(SelectorDefinition::fromKindAndValue($kind->value, $value));
    }

    private function namespace(SelectorKind $kind, string $value): NamespacePattern
    {
        return new NamespacePattern(SelectorDefinition::fromKindAndValue($kind->value, $value));
    }
}
