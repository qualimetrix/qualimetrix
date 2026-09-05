<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionAttribution;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;

/**
 * One test per mechanism (a) — a green test over a list would already pass
 * the moment the first mechanism worked, which is exactly the shape Ш6's
 * test plan calls out as insufficient for a corpus with all seven live at
 * once. Each case here isolates its mechanism and asserts the pair the
 * `suppressed` format actually publishes: {@see SuppressionMechanism} and
 * `suppressor`.
 */
#[CoversClass(SuppressionCompositionBuilder::class)]
final class SuppressionCompositionBuilderTest extends TestCase
{
    private SuppressionCompositionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SuppressionCompositionBuilder();
    }

    #[Test]
    public function itAttributesTheSuppressionMechanismToTheDirectiveFileAndLine(): void
    {
        $finding = $this->finding('code-smell.debug-code', 'src/Foo.php');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::Suppression->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::Suppression, $composition->all[0]->mechanism);
    }

    #[Test]
    public function itAttributesThePathExclusionMechanismToTheMatchedPattern(): void
    {
        $finding = $this->finding('code-smell.debug-code', 'src/Excluded/Foo.php');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::PathExclusion->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(suppressPaths: ['src/Excluded']),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::PathSuppression, $composition->all[0]->mechanism);
        self::assertSame('src/Excluded', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheNamespaceExclusionMechanismToTheMatchedPattern(): void
    {
        $finding = $this->finding('code-smell.debug-code', 'src/Foo.php', 'App\\Excluded');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::NamespaceExclusion->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(suppressNamespaces: ['App\\Excluded']),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::NamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame('App\\Excluded', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheBaselineMechanismToTheSubjectAndChannel(): void
    {
        $finding = $this->finding('complexity.ccn', 'src/Foo.php');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::Baseline->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::Baseline, $composition->all[0]->mechanism);
        self::assertStringContainsString('complexity.ccn', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheGitScopeMechanismToTheReference(): void
    {
        $finding = $this->finding('code-smell.debug-code', 'src/Foo.php');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::GitScope->value => [$finding]],
        );

        $options = new FindingProjectionOptions(gitScope: new GitScopeRequest(
            reference: 'main..HEAD',
            projectRoot: \Qualimetrix\Core\Path\AbsolutePath::fromString(sys_get_temp_dir()),
            includeParentNamespaces: true,
        ));

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            $options,
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::GitScope, $composition->all[0]->mechanism);
        self::assertSame('main..HEAD', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheRuleNamespaceExclusionLedgerHalfToTheProducer(): void
    {
        $finding = $this->finding('coupling.cbo', 'src/Foo.php', 'App\\Excluded', ruleName: 'coupling.cbo');
        $ruleExecution = new RuleExecutionResult([$finding], [], new RuleExclusionStats(
            namespaceExclusionsByRule: ['coupling.cbo' => 1],
            excludedFindings: [$finding],
            attributions: [new RuleExclusionAttribution('coupling.cbo', isPathExclusion: false, matchedPatterns: ['App\\Excluded'])],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['coupling.cbo' => ['suppress_namespaces' => ['App\\Excluded']]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame('coupling.cbo', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheRulePathExclusionLedgerHalfToTheProducer(): void
    {
        $finding = $this->finding('code-smell.long-parameter-list', 'src/Excluded/Foo.php', ruleName: 'code-smell.long-parameter-list');
        $ruleExecution = new RuleExecutionResult([$finding], [], new RuleExclusionStats(
            pathExclusionsByRule: ['code-smell.long-parameter-list' => 1],
            excludedFindings: [$finding],
            attributions: [new RuleExclusionAttribution('code-smell.long-parameter-list', isPathExclusion: true, matchedPatterns: ['src/Excluded'])],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['code-smell.long-parameter-list' => ['suppress_paths' => ['src/Excluded']]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::RulePathSuppression, $composition->all[0]->mechanism);
        self::assertSame('code-smell.long-parameter-list', $composition->all[0]->suppressor);
    }

    #[Test]
    public function itReportsAGlobalExcludePathPatternThatMatchedNothingAsInert(): void
    {
        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(suppressPaths: ['src/NeverMatched.php']),
            suppressions: [],
        );

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::PathSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('src/NeverMatched.php', $composition->neverMatched[0]->suppressor);
    }

    /**
     * Reproduces Ш6's own motivating example measured against this project's
     * `qmx.yaml`: a per-rule `suppress_paths` entry naming a file that does
     * not exist excludes nothing, and the composition keyed by what fired
     * cannot tell that apart from a pattern that was never written at all.
     */
    #[Test]
    public function itReportsAPerRuleExcludePathPatternThatMatchedNothingAsInert(): void
    {
        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $this->ruleExecution(),
            $this->ruleConfiguration(['coupling.cbo' => ['suppress_paths' => ['src/DoesNotExist.php']]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::RulePathSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('coupling.cbo: src/DoesNotExist.php', $composition->neverMatched[0]->suppressor);
    }

    /**
     * Reproduces the computed-metric family, where one rule instance
     * publishes findings under a `$ruleName` distinct from the producer whose
     * `suppress_namespaces` actually excluded them ({@see \Qualimetrix\Analysis\Finding\RuleExecution::producerOf()}).
     * The composition must publish the ledger's recorded producer, not the
     * finding's own `ruleName` — the bug this guards against dropped the
     * finding from the composition entirely wherever the two names diverged.
     */
    #[Test]
    public function itAttributesALedgerExclusionToItsRecordedProducerEvenWhenTheFindingsOwnRuleNameDiffers(): void
    {
        $channel = 'health.cohesion';
        $finding = $this->finding($channel, 'src/Foo.php', 'App\\Excluded', ruleName: 'computed.health');
        $ruleExecution = new RuleExecutionResult([$finding], [], new RuleExclusionStats(
            namespaceExclusionsByRule: [$channel => 1],
            excludedFindings: [$finding],
            attributions: [new RuleExclusionAttribution($channel, isPathExclusion: false, matchedPatterns: ['App\\Excluded'])],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration([$channel => ['suppress_namespaces' => ['App\\Excluded']]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame($channel, $composition->all[0]->suppressor);
        self::assertNotSame($finding->ruleName, $composition->all[0]->suppressor);
    }

    #[Test]
    public function itAttributesTheRuleNamespaceChannelExclusionToTheProducerAndReportsAnUnfiredChannelPatternAsInert(): void
    {
        $channel = 'health.cohesion';
        $siblingChannel = 'health.coupling';
        $finding = $this->finding($channel, 'src/Foo.php', 'App\\Excluded', ruleName: 'computed.health');
        $ruleExecution = new RuleExecutionResult([$finding], [], new RuleExclusionStats(
            namespaceExclusionsByRule: ['computed.health' => 1],
            excludedFindings: [$finding],
            attributions: [new RuleExclusionAttribution(
                'computed.health',
                isPathExclusion: false,
                matchedChannelPatterns: [['selector' => $channel, 'pattern' => 'App\\Excluded']],
            )],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['computed.health' => ['suppress_namespace_channels' => [
                $channel => ['App\\Excluded'],
                $siblingChannel => ['App\\NeverMatched'],
            ]]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame('computed.health', $composition->all[0]->suppressor);

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('computed.health: ' . $siblingChannel . ' App\\NeverMatched', $composition->neverMatched[0]->suppressor);
    }

    /**
     * Two overlapping global `--suppress-path` patterns both independently
     * match the same removed file. Crediting only the first-matched pattern
     * (the shape {@see \Qualimetrix\Core\Util\PathMatcher::matches()} returns)
     * would report the second as inert even though it excludes findings of
     * its own.
     */
    #[Test]
    public function itDoesNotReportAnOverlappingGlobalExcludePathPatternAsInertWhenItIndependentlyMatches(): void
    {
        $finding = $this->finding('code-smell.debug-code', 'src/Reporting/Foo.php');
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::PathExclusion->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(suppressPaths: ['src', 'src/Reporting']),
            suppressions: [],
        );

        self::assertSame([], $composition->neverMatched);
    }

    #[Test]
    public function itAttributesTheBaselineMechanismToTheOccurrenceKeyAndDependencyEdgeWhenPresent(): void
    {
        $namespace = 'App\\Foo';
        $symbolPath = SymbolPath::forNamespace($namespace);
        $target = SymbolPath::forClass('App\\Bar', 'Baz');
        $occurrenceKey = OccurrenceKey::semantic('test', ['edge' => 'App\\Bar\\Baz']);
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: 'circular-dependency',
            code: 'circular-dependency.class-cycle',
            message: 'test',
            severity: Severity::Warning,
            dependencyTarget: $target,
            dependencyType: DependencyType::New_,
            occurrenceKey: $occurrenceKey,
        );
        $filterResult = new FindingProjectionResult(
            findings: [],
            removedByStage: [FindingFilterStage::Baseline->value => [$finding]],
        );

        $composition = $this->builder->build(
            $filterResult,
            $this->ruleExecution(),
            $this->ruleConfiguration([]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(
            $finding->subject->toCanonical() . ' ' . $finding->code
                . ' [' . $occurrenceKey->value . '] -> ' . $target->toCanonical() . ' (new)',
            $composition->all[0]->suppressor,
        );
    }

    private function finding(
        string $code,
        string $file,
        ?string $namespace = null,
        string $ruleName = 'test-rule',
    ): Finding {
        $symbolPath = $namespace === null
            ? SymbolPath::forFile(RelativePath::fromString($file))
            : SymbolPath::forNamespace($namespace);

        return new Finding(
            location: new Location(RelativePath::fromString($file), 10),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            code: $code,
            message: 'test',
            severity: Severity::Warning,
        );
    }

    private function ruleExecution(): RuleExecutionResult
    {
        return new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty());
    }

    /**
     * @param array<string, array<string, mixed>> $rulesConfig
     */
    private function ruleConfiguration(array $rulesConfig): RuleConfigurationInterface
    {
        return new class ($rulesConfig) implements RuleConfigurationInterface {
            /** @param array<string, array<string, mixed>> $rulesConfig */
            public function __construct(private array $rulesConfig) {}

            public function replace(FindingConfiguration $configuration): void {}

            public function configureCli(string $ruleName, array $options): void {}

            public function configFileOptions(): array
            {
                return $this->rulesConfig;
            }

            public function cliOptions(): array
            {
                return [];
            }

            public function all(): array
            {
                return $this->rulesConfig;
            }

            public function configureSelection(RuleSelection $selection): void {}

            public function selection(): RuleSelection
            {
                return new RuleSelection();
            }

            public function captureExcludedFindings(): void {}

            public function capturesExcludedFindings(): bool
            {
                return true;
            }

            public function configureNamespaceExclusions(string $ruleName, array $patterns): void {}

            public function configureNamespaceChannelExclusions(string $ruleName, array $patterns): void {}

            public function configurePathExclusions(string $ruleName, array $patterns): void {}

            public function isNamespaceExcluded(string $ruleName, string $namespace): bool
            {
                /** @var list<string> $patterns */
                $patterns = \is_array($this->rulesConfig[$ruleName]['suppress_namespaces'] ?? null)
                    ? $this->rulesConfig[$ruleName]['suppress_namespaces']
                    : [];

                return (new NamespaceMatcher($patterns))->matches($namespace) !== null;
            }

            public function isNamespaceChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool
            {
                return false;
            }

            public function isPathExcluded(string $ruleName, RelativePath $path): bool
            {
                /** @var list<string> $patterns */
                $patterns = \is_array($this->rulesConfig[$ruleName]['suppress_paths'] ?? null)
                    ? $this->rulesConfig[$ruleName]['suppress_paths']
                    : [];

                return (new PathMatcher($patterns))->matches($path) !== null;
            }

            public function resetRuntimeState(): void {}
        };
    }
}
