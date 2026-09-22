<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\FindingProjection;

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
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;

/**
 * One test per mechanism — a green test over a list would already pass
 * when only the first mechanism worked. Each case here isolates its mechanism
 * and asserts the pair the
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
            new FindingProjectionOptions(suppressPaths: $this->paths(['src/Excluded'])),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::PathSuppression, $composition->all[0]->mechanism);
        self::assertSame('subtree:src/Excluded', $composition->all[0]->suppressor);
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
            new FindingProjectionOptions(suppressNamespaces: $this->namespaces(['App\\Excluded'])),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::NamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame('subtree:App\\Excluded', $composition->all[0]->suppressor);
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
            attributions: [new RuleExclusionAttribution('coupling.cbo', isPathExclusion: false, matchedPatterns: $this->definitions(['App\\Excluded']))],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['coupling.cbo' => ['suppress_namespaces' => $this->namespaces(['App\\Excluded'])]]),
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
            attributions: [new RuleExclusionAttribution('code-smell.long-parameter-list', isPathExclusion: true, matchedPatterns: $this->pathDefinitions(['src/Excluded']))],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['code-smell.long-parameter-list' => ['suppress_paths' => $this->paths(['src/Excluded'])]]),
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
            new FindingProjectionOptions(suppressPaths: $this->exactPaths(['src/NeverMatched.php'])),
            suppressions: [],
        );

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::PathSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('exact:src/NeverMatched.php', $composition->neverMatched[0]->suppressor);
    }

    /**
     * A per-rule `suppress_paths` entry naming a file that does
     * not exist excludes nothing, and the composition keyed by what fired
     * cannot tell that apart from a pattern that was never written at all.
     */
    #[Test]
    public function itReportsAPerRuleExcludePathPatternThatMatchedNothingAsInert(): void
    {
        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $this->ruleExecution(),
            $this->ruleConfiguration(['coupling.cbo' => ['suppress_paths' => $this->exactPaths(['src/DoesNotExist.php'])]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::RulePathSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('coupling.cbo: exact:src/DoesNotExist.php', $composition->neverMatched[0]->suppressor);
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
            attributions: [new RuleExclusionAttribution($channel, isPathExclusion: false, matchedPatterns: $this->definitions(['App\\Excluded']))],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration([$channel => ['suppress_namespaces' => $this->namespaces(['App\\Excluded'])]]),
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
                matchedChannelPatterns: [['selector' => $channel, 'pattern' => $this->definitions(['App\\Excluded'])[0]]],
            )],
        ), LevelActivity::empty());

        $composition = $this->builder->build(
            new FindingProjectionResult(findings: []),
            $ruleExecution,
            $this->ruleConfiguration(['computed.health' => ['suppress_namespace_channels' => [
                $channel => $this->namespaces(['App\\Excluded']),
                $siblingChannel => $this->namespaces(['App\\NeverMatched']),
            ]]]),
            new FindingProjectionOptions(),
            suppressions: [],
        );

        self::assertCount(1, $composition->all);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->all[0]->mechanism);
        self::assertSame('computed.health', $composition->all[0]->suppressor);

        self::assertCount(1, $composition->neverMatched);
        self::assertSame(SuppressionMechanism::RuleNamespaceSuppression, $composition->neverMatched[0]->mechanism);
        self::assertSame('computed.health: ' . $siblingChannel . ' subtree:App\\NeverMatched', $composition->neverMatched[0]->suppressor);
    }

    /**
     * Two overlapping global `--suppress-path` patterns both independently
     * match the same removed file. Crediting only the first-matched pattern
     * (the shape {@see \Qualimetrix\Core\Pattern\PathMatcher::matches()} returns)
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
            new FindingProjectionOptions(suppressPaths: $this->paths(['src', 'src/Reporting'])),
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

            public function namespaceExclusions(string $ruleName): array
            {
                return $this->rulesConfig[$ruleName]['suppress_namespaces'] ?? [];
            }

            public function namespaceChannelExclusions(string $ruleName): array
            {
                return $this->rulesConfig[$ruleName]['suppress_namespace_channels'] ?? [];
            }

            public function pathExclusions(string $ruleName): array
            {
                return $this->rulesConfig[$ruleName]['suppress_paths'] ?? [];
            }

            public function isNamespaceExcluded(string $ruleName, string $namespace): bool
            {
                return false;
            }

            public function isNamespaceChannelExcluded(string $ruleName, FindingChannel $channel, string $namespace): bool
            {
                return false;
            }

            public function isPathExcluded(string $ruleName, RelativePath $path): bool
            {
                return false;
            }

            public function resetRuntimeState(): void {}
        };
    }

    /**
     * @param list<string> $values
     *
     * @return list<PathPattern>
     */
    private function paths(array $values): array
    {
        return array_map(static fn(string $value): PathPattern => new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value)), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<PathPattern>
     */
    private function exactPaths(array $values): array
    {
        return array_map(static fn(string $value): PathPattern => new PathPattern(new SelectorDefinition(SelectorKind::Exact, $value)), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<NamespacePattern>
     */
    private function namespaces(array $values): array
    {
        return array_map(static fn(string $value): NamespacePattern => new NamespacePattern(new SelectorDefinition(SelectorKind::Subtree, $value)), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<SelectorDefinition>
     */
    private function definitions(array $values): array
    {
        return array_map(static fn(string $value): SelectorDefinition => new SelectorDefinition(SelectorKind::Subtree, $value), $values);
    }

    /**
     * @param list<string> $values
     *
     * @return list<SelectorDefinition>
     */
    private function pathDefinitions(array $values): array
    {
        return array_map(static fn(string $value): SelectorDefinition => new SelectorDefinition(SelectorKind::Subtree, $value), $values);
    }
}
