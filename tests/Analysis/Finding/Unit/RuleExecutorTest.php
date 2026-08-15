<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\Exclusion\RulePathExclusionProvider;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(RuleExecution::class)]
final class RuleExecutorTest extends TestCase
{
    private bool $captureExcludedViolations = true;

    /**
     * Every existing test in this class predates the capture toggle and
     * asserts on `$stats->excludedViolations` directly, so it is enabled by
     * default here; the dedicated toggle tests below explicitly disable it.
     */
    protected function setUp(): void
    {
        $this->captureExcludedViolations = true;
    }

    #[Test]
    public function itExecutesWithNoRules(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([], $provider);

        $context = $this->createMinimalContext();

        self::assertSame([], $executor->execute($context));
        self::assertSame([], $executor->activeRules($provider->selection()));
        self::assertSame(0, $executor->totalRuleCount());
    }

    #[Test]
    public function itPublishesRuleMetadataWithExactAliasMappingWithoutConcreteRuleInstances(): void
    {
        $execution = $this->createExecution([new RuleMetadataFixtureRule()]);

        self::assertEquals([
            new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata(
                name: 'fixture.metadata',
                optionsClass: RuleExecutionFixtureOptions::class,
                category: RuleCategory::Complexity,
                description: 'Metadata fixture',
                aliases: ['fixture-threshold' => 'warning'],
                active: true,
            ),
        ], $execution->allRules());
    }

    #[Test]
    public function itExclusionStatsAreEmptyBeforeFirstExecute(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([], $provider);

        $stats = $executor->exclusionStats();

        self::assertTrue($stats->isEmpty());
        self::assertSame(0, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itExclusionStatsAreZeroWhenNoExclusionsConfigured(): void
    {
        $violation = $this->createViolationWithNamespace('rule1', 'App\\Core');
        $rule = $this->createRule('rule1', [$violation]);

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $provider);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertCount(1, $violations);
        self::assertTrue($stats->isEmpty());
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itNamespaceExclusionIncrementsStatsPerRule(): void
    {
        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $includedViolation = $this->createViolationWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertCount(1, $violations);
        self::assertFalse($stats->isEmpty());
        self::assertSame(['rule1' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame([], $stats->pathExclusionsByRule);
        self::assertSame(1, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
        self::assertSame([$excludedViolation], $stats->excludedViolations);
    }

    #[Test]
    public function itPathExclusionIncrementsStatsPerRule(): void
    {
        $excludedViolation = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $includedViolation = $this->createViolationWithFile('rule1', 'src/Core/Service.php');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertCount(1, $violations);
        self::assertSame(['rule1' => 1], $stats->pathExclusionsByRule);
        self::assertSame([], $stats->namespaceExclusionsByRule);
        self::assertSame(1, $stats->totalPathExclusions());
        self::assertSame([$excludedViolation], $stats->excludedViolations);
    }

    #[Test]
    public function itExclusionStatsBreakDownByRuleNameSeparately(): void
    {
        $v1 = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $v2 = $this->createViolationWithNamespace('rule2', 'App\\Tests');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);
        $exclusionProvider->setExclusions('rule2', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertSame(['rule1' => 1, 'rule2' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame(2, $stats->totalNamespaceExclusions());
    }

    #[Test]
    public function itExclusionStatsAreResetOnEachExecuteCall(): void
    {
        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        // Two consecutive execute() calls on the same executor: if the running
        // executor accumulated counts instead of resetting them, the second
        // call would report 2 instead of 1.
        $executor->execute($this->createMinimalContext());
        self::assertSame(1, $executor->exclusionStats()->totalNamespaceExclusions());

        $executor->execute($this->createMinimalContext());
        self::assertSame(1, $executor->exclusionStats()->totalNamespaceExclusions());
        self::assertCount(1, $executor->exclusionStats()->excludedViolations);
    }

    #[Test]
    public function itExecutesWithAllRulesEnabled(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertSame($violation1, $violations[0]);
        self::assertSame($violation2, $violations[1]);
        self::assertSame(2, $executor->totalRuleCount());
    }

    #[Test]
    public function itFiltersDisabledRulesDuringExecute(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $config = new RuleSelection(disabled: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($violation2, $violations[0]);
    }

    #[Test]
    public function itExecutesWithOnlyRulesFilter(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');
        $violation3 = $this->createViolation('rule3');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);
        $rule3 = $this->createRule('rule3', [$violation3]);

        $config = new RuleSelection(only: ['rule1', 'rule3']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertSame($violation1, $violations[0]);
        self::assertSame($violation3, $violations[1]);
    }

    #[Test]
    public function itGetActiveRulesReturnsOnlyEnabled(): void
    {
        $rule1 = $this->createRule('enabled-rule', []);
        $rule2 = $this->createRule('disabled-rule', []);

        $config = new RuleSelection(disabled: ['disabled-rule']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $activeRules = $executor->activeRules($provider->selection());

        self::assertCount(1, $activeRules);
        self::assertSame('enabled-rule', $activeRules[0]->name);
    }

    #[Test]
    public function itGetTotalRulesCountIncludesDisabled(): void
    {
        $rule1 = $this->createRule('rule1', []);
        $rule2 = $this->createRule('rule2', []);

        $config = new RuleSelection(disabled: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        self::assertSame(2, $executor->totalRuleCount());
        self::assertCount(1, $executor->activeRules($provider->selection()));
    }

    #[Test]
    public function itExecutesWithIterableRules(): void
    {
        $violation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$violation]);

        $generator = (function () use ($rule) {
            yield $rule;
        })();

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution($generator, $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame(1, $executor->totalRuleCount());
    }

    #[Test]
    public function itDisabledRulesTakePrecedenceOverOnlyRules(): void
    {
        $violation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$violation]);

        $config = new RuleSelection(
            disabled: ['rule1'],
            only: ['rule1'],
        );
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertSame([], $violations);
        self::assertSame([], $executor->activeRules($provider->selection()));
    }

    // --- Prefix matching tests ---

    #[Test]
    public function itExecutesWithPrefixDisable(): void
    {
        $v1 = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic');
        $v2 = $this->createViolation('complexity.cognitive', violationCode: 'complexity.cognitive');
        $v3 = $this->createViolation('size.method-count', violationCode: 'size.method-count');

        $rule1 = $this->createRule('complexity.cyclomatic', [$v1]);
        $rule2 = $this->createRule('complexity.cognitive', [$v2]);
        $rule3 = $this->createRule('size.method-count', [$v3]);

        // Disable entire complexity group
        $config = new RuleSelection(disabled: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame('size.method-count', $violations[0]->ruleName);
    }

    #[Test]
    public function itFiltersViolationsByViolationCodeDuringExecute(): void
    {
        $methodViolation = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic.callable');
        $classViolation = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic.class');

        $rule = $this->createHierarchicalRule(
            'complexity.cyclomatic',
            [RuleLevel::Callable, RuleLevel::Class_],
            [
                RuleLevel::Callable->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Disable only class-level violations
        $config = new RuleSelection(disabled: ['complexity.cyclomatic.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    #[Test]
    public function itGetActiveRulesWithPrefixOnlyRules(): void
    {
        $rule1 = $this->createRule('complexity.cyclomatic', []);
        $rule2 = $this->createRule('complexity.cognitive', []);
        $rule3 = $this->createRule('size.method-count', []);

        $config = new RuleSelection(only: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $activeRules = $executor->activeRules($provider->selection());

        self::assertCount(2, $activeRules);
    }

    #[Test]
    public function itKeepsComputedFindingsWhenOnlyTheProducerRuleIsSelected(): void
    {
        $finding = $this->createViolation('computed.health', violationCode: 'health.complexity');
        $rule = $this->createRule('computed.health', [$finding]);
        $executor = $this->createExecution(
            [$rule],
            $this->createConfiguredProvider(new RuleSelection(only: ['computed.health'])),
            ruleSelector: $this->computedRuleSelector(),
        );

        self::assertSame([$finding], $executor->execute($this->createMinimalContext()));
    }

    #[Test]
    public function itRunsTheComputedProducerWhenOnlyItsViolationCodeIsSelected(): void
    {
        $complexity = $this->createViolation('computed.health', violationCode: 'health.complexity');
        $cohesion = $this->createViolation('computed.health', violationCode: 'health.cohesion');
        $rule = $this->createRule('computed.health', [$complexity, $cohesion]);
        $provider = $this->createConfiguredProvider(new RuleSelection(only: ['health.complexity']));
        $executor = $this->createExecution(
            [$rule],
            $provider,
            ruleSelector: $this->computedRuleSelector(),
        );

        self::assertSame([$complexity], $executor->execute($this->createMinimalContext()));
        self::assertSame(['computed.health'], array_map(
            static fn($metadata): string => $metadata->name,
            $executor->activeRules($provider->selection()),
        ));
    }

    // --- Hierarchical rules tests ---

    #[Test]
    public function itExecutesHierarchicalRuleWithAllLevelsEnabled(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.callable', level: RuleLevel::Callable);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Callable, RuleLevel::Class_],
            [
                RuleLevel::Callable->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertContains($methodViolation, $violations);
        self::assertContains($classViolation, $violations);
    }

    #[Test]
    public function itExecutesHierarchicalRuleWithSpecificViolationCodeDisabled(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.callable', level: RuleLevel::Callable);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Callable, RuleLevel::Class_],
            [
                RuleLevel::Callable->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Disable class-level violations via violationCode filtering
        $config = new RuleSelection(disabled: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        // Only method level should pass through
        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    #[Test]
    public function itExecutesHierarchicalRuleWithEntireRuleDisabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Callable, RuleLevel::Class_],
            [
                RuleLevel::Callable->value => [$this->createViolation('complexity', violationCode: 'complexity.callable')],
                RuleLevel::Class_->value => [$this->createViolation('complexity', violationCode: 'complexity.class')],
            ],
        );

        // Disable entire rule
        $config = new RuleSelection(disabled: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itAppliesOnlyRulesFilterToHierarchicalRule(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.callable', level: RuleLevel::Callable);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Callable, RuleLevel::Class_],
            [
                RuleLevel::Callable->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Only enable callable-level violations
        $config = new RuleSelection(only: ['complexity.callable']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    // --- Namespace exclusion tests ---

    #[Test]
    public function itFiltersViolationsByNamespaceExclusion(): void
    {
        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $includedViolation = $this->createViolationWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($includedViolation, $violations[0]);
    }

    #[Test]
    public function itNamespaceExclusionPassesThroughNullNamespace(): void
    {
        $fileViolation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$fileViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
    }

    #[Test]
    public function itNamespaceExclusionPassesThroughEmptyNamespace(): void
    {
        $globalViolation = $this->createViolationWithNamespace('rule1', '');
        $rule = $this->createRule('rule1', [$globalViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
    }

    #[Test]
    public function itNamespaceExclusionDoesNotAffectOtherRules(): void
    {
        $v1 = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $v2 = $this->createViolationWithNamespace('rule2', 'App\\Tests');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($v2, $violations[0]);
    }

    #[Test]
    public function itChannelNamespaceExclusionLeavesSiblingViolationCodesActive(): void
    {
        $cohesion = $this->createViolationWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $coupling = $this->createViolationWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.coupling',
        );
        $rule = $this->createRule('computed.health', [$cohesion, $coupling]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setChannelExclusions(
            'computed.health',
            'health.cohesion',
            ['App\\Metrics'],
        );

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $executor = $this->createExecution(
            [$rule],
            $registry,
            $this->computedRuleSelector(),
        );

        $violations = $executor->execute($this->createMinimalContext());

        self::assertSame([$coupling], $violations);
        self::assertSame(
            [$cohesion],
            $executor->exclusionStats()->excludedViolations,
        );
    }

    #[Test]
    public function itChannelNamespaceExclusionDoesNotHideClassFindingsInThatNamespace(): void
    {
        $namespaceCohesion = $this->createViolationWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $classCohesion = new Violation(
            location: new Location(RelativePath::fromString('src/Metrics/Collector.php'), 10),
            symbolPath: SymbolPath::forClass('App\\Metrics', 'Collector'),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass('App\\Metrics', 'Collector'),
                RelativePath::fromString('src/Metrics/Collector.php'),
                10,
            )),
            ruleName: 'computed.health',
            violationCode: 'health.cohesion',
            message: 'Class cohesion health is low',
            severity: Severity::Warning,
        );
        $rule = $this->createRule('computed.health', [$namespaceCohesion, $classCohesion]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setChannelExclusions(
            'computed.health',
            'health.cohesion',
            ['App\\Metrics'],
        );

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $executor = $this->createExecution(
            [$rule],
            $registry,
            $this->computedRuleSelector(),
        );

        self::assertSame([$classCohesion], $executor->execute($this->createMinimalContext()));
    }

    #[Test]
    public function itChannelNamespaceWildcardDoesNotHideProjectFindings(): void
    {
        $namespaceCohesion = $this->createViolationWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $projectCohesion = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            ruleName: 'computed.health',
            violationCode: 'health.cohesion',
            message: 'Project cohesion health is low',
            severity: Severity::Warning,
        );
        $rule = $this->createRule('computed.health', [$namespaceCohesion, $projectCohesion]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setChannelExclusions('computed.health', 'health', ['*']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $executor = $this->createExecution(
            [$rule],
            $registry,
            $this->computedRuleSelector(),
        );

        self::assertSame([$projectCohesion], $executor->execute($this->createMinimalContext()));
        self::assertSame(
            [$namespaceCohesion],
            $executor->exclusionStats()->excludedViolations,
        );
    }

    // --- Finding-owned excluded-violation capture policy tests ---

    #[Test]
    public function itDoesNotCaptureExcludedViolationsWhenCaptureHolderIsDisabled(): void
    {
        $this->captureExcludedViolations = false;

        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        // Counts are collected regardless of the capture toggle.
        self::assertSame(['rule1' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame(1, $stats->totalNamespaceExclusions());
        // But the heavy Violation objects are not retained when disabled.
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itDoesNotCaptureExcludedPathViolationsWhenCaptureHolderIsDisabled(): void
    {
        $this->captureExcludedViolations = false;

        $excludedViolation = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertSame(['rule1' => 1], $stats->pathExclusionsByRule);
        self::assertSame(1, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itCapturesExcludedViolationsWhenCaptureHolderIsEnabled(): void
    {
        $this->captureExcludedViolations = true;

        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->exclusionStats();

        self::assertSame([$excludedViolation], $stats->excludedViolations);
    }

    private function createConfiguredProvider(?RuleSelection $selection = null): RuleOptionsRegistry
    {
        $selection ??= new RuleSelection();
        $provider = new RuleOptionsRegistry();
        $provider->configureSelection($selection);

        return $provider;
    }

    /**
     * @param iterable<RuleInterface> $rules
     */
    private function createExecution(
        iterable $rules,
        ?RuleOptionsRegistry $registry = null,
        ?RuleSelector $ruleSelector = null,
    ): RuleExecution {
        $registry ??= new RuleOptionsRegistry();
        if ($this->captureExcludedViolations) {
            $registry->captureExcludedViolations();
        }

        return new RuleExecution(
            $rules,
            self::createStub(ProfilerInterface::class),
            $registry,
            $ruleSelector,
        );
    }

    /**
     * @param list<Violation> $violations
     */
    private function createRule(string $name, array $violations, RuleCategory $category = RuleCategory::Complexity): RuleInterface
    {
        return new class ($name, $violations, $category) implements RuleInterface {
            /** @param list<Violation> $violations */
            public function __construct(
                private readonly string $name,
                private readonly array $violations,
                private readonly RuleCategory $category,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }
            public function getDescription(): string
            {
                return $this->name;
            }
            public function getCategory(): RuleCategory
            {
                return $this->category;
            }
            public function requires(): array
            {
                return [];
            }
            public function analyze(AnalysisContext $context): array
            {
                return $this->violations;
            }
            public static function getOptionsClass(): string
            {
                return RuleExecutionFixtureOptions::class;
            }
        };
    }

    private function createMinimalContext(): AnalysisContext
    {
        $repository = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([]);

        return new AnalysisContext($repository);
    }

    /**
     * @param list<RuleLevel> $supportedLevels
     * @param array<string, list<Violation>> $violationsByLevel
     */
    private function createHierarchicalRule(
        string $name,
        array $supportedLevels,
        array $violationsByLevel,
        RuleCategory $category = RuleCategory::Complexity,
    ): RuleInterface {
        // RuleExecution now calls analyze() for all rules uniformly.
        // Flatten all level violations into a single list for analyze().
        $allViolations = array_merge(...array_values($violationsByLevel));

        return $this->createRule($name, $allViolations, $category);
    }

    private function createViolation(string $ruleName, ?string $violationCode = null, ?RuleLevel $level = null): Violation
    {
        return new Violation(
            location: new Location(
                file: RelativePath::fromString('test/file.php'),
                line: 1,
            ),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test/file.php')),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test/file.php'))),
            ruleName: $ruleName,
            violationCode: $violationCode ?? $ruleName,
            message: "Violation from $ruleName",
            severity: Severity::Warning,
            level: $level,
        );
    }

    private function createViolationWithNamespace(
        string $ruleName,
        string $namespace,
        ?string $violationCode = null,
    ): Violation {
        return new Violation(
            location: new Location(
                file: RelativePath::fromString('test/file.php'),
                line: 1,
            ),
            symbolPath: SymbolPath::forNamespace($namespace),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace($namespace)),
            ruleName: $ruleName,
            violationCode: $violationCode ?? $ruleName,
            message: "Violation from $ruleName in $namespace",
            severity: Severity::Warning,
        );
    }

    // --- Path exclusion tests ---

    #[Test]
    public function itFiltersViolationsByExcludePaths(): void
    {
        $excludedViolation = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $includedViolation = $this->createViolationWithFile('rule1', 'src/Core/Service.php');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($includedViolation, $violations[0]);
    }

    #[Test]
    public function itExcludePathsAreIsolatedPerRule(): void
    {
        $v1 = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $v2 = $this->createViolationWithFile('rule2', 'src/Generated/Model.php');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($v2, $violations[0]);
    }

    #[Test]
    public function itExcludePathsWithEmptyFilePassesThrough(): void
    {
        $violation = $this->createViolationWithFile('rule1', '');

        $rule = $this->createRule('rule1', [$violation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($violation, $violations[0]);
    }

    private function createViolationWithFile(string $ruleName, string $file): Violation
    {
        $symbolPathFile = RelativePath::fromString($file !== '' ? $file : 'unknown');

        return new Violation(
            location: new Location(
                file: $file !== '' ? RelativePath::fromString($file) : null,
                line: 1,
            ),
            symbolPath: SymbolPath::forFile($symbolPathFile),
            subject: MetricSubject::aggregate(SymbolPath::forFile($symbolPathFile)),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: "Violation from $ruleName in $file",
            severity: Severity::Warning,
        );
    }

    private function computedRuleSelector(): RuleSelector
    {
        return new RuleSelector(new class implements RuleChannelRegistryInterface {
            public function channelsProducedBy(string $producerRuleName): array
            {
                if ($producerRuleName !== 'computed.health') {
                    return [];
                }

                return [
                    new ViolationChannel('computed.health', 'health.complexity'),
                    new ViolationChannel('computed.health', 'health.cohesion'),
                ];
            }
        });
    }
}

final readonly class RuleExecutionFixtureOptions implements RuleOptionsInterface
{
    public static function fromArray(array $config): self
    {
        return new self();
    }
    public function isEnabled(): bool
    {
        return true;
    }
    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}

#[CliAlias('fixture-threshold', 'warning')]
final readonly class RuleMetadataFixtureRule implements RuleInterface
{
    public function getName(): string
    {
        return 'fixture.metadata';
    }
    public function getDescription(): string
    {
        return 'Metadata fixture';
    }
    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
    }
    public function requires(): array
    {
        return [];
    }
    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
    public static function getOptionsClass(): string
    {
        return RuleExecutionFixtureOptions::class;
    }
}
