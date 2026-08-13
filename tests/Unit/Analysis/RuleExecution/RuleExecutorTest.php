<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\RuleExecution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Runtime\TransitionalRuntimeConfigurationHolder;
use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Configuration\RuleNamespaceExclusionProvider;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Configuration\RulePathExclusionProvider;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Rule\RuleSelector;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\RuleExclusionCaptureHolder;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;

#[CoversClass(RuleExecutor::class)]
final class RuleExecutorTest extends TestCase
{
    /**
     * Every existing test in this class predates the capture toggle and
     * asserts on `$stats->excludedViolations` directly, so it is enabled by
     * default here; the dedicated toggle tests below explicitly disable it.
     */
    protected function setUp(): void
    {
        RuleExclusionCaptureHolder::set(true);
    }

    protected function tearDown(): void
    {
        RuleExclusionCaptureHolder::reset();
    }

    #[Test]
    public function itExecutesWithNoRules(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([], $provider);

        $context = $this->createMinimalContext();

        self::assertSame([], $executor->execute($context));
        self::assertSame([], $executor->getActiveRules());
        self::assertSame(0, $executor->getTotalRulesCount());
    }

    #[Test]
    public function itExclusionStatsAreEmptyBeforeFirstExecute(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([], $provider);

        $stats = $executor->getRuleExclusionStats();

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
        $executor = new RuleExecutor([$rule], $provider);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

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
        $executor = new RuleExecutor([$rule1, $rule2], $provider, $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

        // Two consecutive execute() calls on the same executor: if the running
        // executor accumulated counts instead of resetting them, the second
        // call would report 2 instead of 1.
        $executor->execute($this->createMinimalContext());
        self::assertSame(1, $executor->getRuleExclusionStats()->totalNamespaceExclusions());

        $executor->execute($this->createMinimalContext());
        self::assertSame(1, $executor->getRuleExclusionStats()->totalNamespaceExclusions());
        self::assertCount(1, $executor->getRuleExclusionStats()->excludedViolations);
    }

    #[Test]
    public function itExecutesWithAllRulesEnabled(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertSame($violation1, $violations[0]);
        self::assertSame($violation2, $violations[1]);
        self::assertSame(2, $executor->getTotalRulesCount());
    }

    #[Test]
    public function itFiltersDisabledRulesDuringExecute(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $config = new TransitionalRuntimeConfiguration(disabledRules: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

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

        $config = new TransitionalRuntimeConfiguration(onlyRules: ['rule1', 'rule3']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

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

        $config = new TransitionalRuntimeConfiguration(disabledRules: ['disabled-rule']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertCount(1, $activeRules);
        self::assertSame('enabled-rule', $activeRules[0]->getName());
    }

    #[Test]
    public function itGetTotalRulesCountIncludesDisabled(): void
    {
        $rule1 = $this->createRule('rule1', []);
        $rule2 = $this->createRule('rule2', []);

        $config = new TransitionalRuntimeConfiguration(disabledRules: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        self::assertSame(2, $executor->getTotalRulesCount());
        self::assertCount(1, $executor->getActiveRules());
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
        $executor = new RuleExecutor($generator, $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame(1, $executor->getTotalRulesCount());
    }

    #[Test]
    public function itDisabledRulesTakePrecedenceOverOnlyRules(): void
    {
        $violation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$violation]);

        $config = new TransitionalRuntimeConfiguration(
            disabledRules: ['rule1'],
            onlyRules: ['rule1'],
        );
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertSame([], $violations);
        self::assertSame([], $executor->getActiveRules());
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
        $config = new TransitionalRuntimeConfiguration(disabledRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

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
        $config = new TransitionalRuntimeConfiguration(disabledRules: ['complexity.cyclomatic.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

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

        $config = new TransitionalRuntimeConfiguration(onlyRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertCount(2, $activeRules);
    }

    #[Test]
    public function itKeepsComputedFindingsWhenOnlyTheProducerRuleIsSelected(): void
    {
        $finding = $this->createViolation('computed.health', violationCode: 'health.complexity');
        $rule = $this->createRule('computed.health', [$finding]);
        $executor = new RuleExecutor(
            [$rule],
            $this->createConfiguredProvider(new TransitionalRuntimeConfiguration(onlyRules: ['computed.health'])),
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
        $executor = new RuleExecutor(
            [$rule],
            $this->createConfiguredProvider(new TransitionalRuntimeConfiguration(onlyRules: ['health.complexity'])),
            ruleSelector: $this->computedRuleSelector(),
        );

        self::assertSame([$complexity], $executor->execute($this->createMinimalContext()));
        self::assertSame([$rule], $executor->getActiveRules());
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
        $executor = new RuleExecutor([$rule], $provider);

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
        $config = new TransitionalRuntimeConfiguration(disabledRules: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

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
        $config = new TransitionalRuntimeConfiguration(disabledRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

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
        $config = new TransitionalRuntimeConfiguration(onlyRules: ['complexity.callable']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

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
        $executor = new RuleExecutor([$rule1, $rule2], $provider, $registry);

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
        $executor = new RuleExecutor(
            [$rule],
            $this->createConfiguredProvider(),
            $registry,
            $this->computedRuleSelector(),
        );

        $violations = $executor->execute($this->createMinimalContext());

        self::assertSame([$coupling], $violations);
        self::assertSame(
            [$cohesion],
            $executor->getRuleExclusionStats()->excludedViolations,
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
        $executor = new RuleExecutor(
            [$rule],
            $this->createConfiguredProvider(),
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
        $executor = new RuleExecutor(
            [$rule],
            $this->createConfiguredProvider(),
            $registry,
            $this->computedRuleSelector(),
        );

        self::assertSame([$projectCohesion], $executor->execute($this->createMinimalContext()));
        self::assertSame(
            [$namespaceCohesion],
            $executor->getRuleExclusionStats()->excludedViolations,
        );
    }

    // --- RuleExclusionCaptureHolder toggle tests ---

    #[Test]
    public function itDoesNotCaptureExcludedViolationsWhenCaptureHolderIsDisabled(): void
    {
        RuleExclusionCaptureHolder::set(false);

        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

        // Counts are collected regardless of the capture toggle.
        self::assertSame(['rule1' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame(1, $stats->totalNamespaceExclusions());
        // But the heavy Violation objects are not retained when disabled.
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itDoesNotCaptureExcludedPathViolationsWhenCaptureHolderIsDisabled(): void
    {
        RuleExclusionCaptureHolder::set(false);

        $excludedViolation = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

        self::assertSame(['rule1' => 1], $stats->pathExclusionsByRule);
        self::assertSame(1, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedViolations);
    }

    #[Test]
    public function itCapturesExcludedViolationsWhenCaptureHolderIsEnabled(): void
    {
        RuleExclusionCaptureHolder::set(true);

        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $executor->execute($this->createMinimalContext());
        $stats = $executor->getRuleExclusionStats();

        self::assertSame([$excludedViolation], $stats->excludedViolations);
    }

    private function createConfiguredProvider(?TransitionalRuntimeConfiguration $config = null): TransitionalRuntimeConfigurationHolder
    {
        $provider = new TransitionalRuntimeConfigurationHolder();
        $provider->setConfiguration($config ?? new TransitionalRuntimeConfiguration());

        return $provider;
    }

    /**
     * @param list<Violation> $violations
     */
    private function createRule(string $name, array $violations, RuleCategory $category = RuleCategory::Complexity): RuleInterface
    {
        $rule = self::createStub(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('analyze')->willReturn($violations);
        $rule->method('getCategory')->willReturn($category);

        return $rule;
    }

    private function createMinimalContext(): AnalysisContext
    {
        $repository = self::createStub(\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([]);

        return new AnalysisContext($repository, []);
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
        // RuleExecutor now calls analyze() for all rules uniformly.
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
        $executor = new RuleExecutor([$rule], $provider, $registry);

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
        $executor = new RuleExecutor([$rule1, $rule2], $provider, $registry);

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
        $executor = new RuleExecutor([$rule], $provider, $registry);

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
