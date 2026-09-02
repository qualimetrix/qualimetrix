<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\ProducerDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\Exclusion\RulePathExclusionProvider;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Finding\RuleExecution;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(RuleExecution::class)]
final class RuleExecutorTest extends TestCase
{
    private bool $captureExcludedFindings = true;

    /**
     * Every existing test in this class predates the capture toggle and
     * asserts on `$stats->excludedFindings` directly, so it is enabled by
     * default here; the dedicated toggle tests below explicitly disable it.
     */
    protected function setUp(): void
    {
        $this->captureExcludedFindings = true;
    }

    #[Test]
    public function itExecutesWithNoRules(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([], $provider);

        $context = $this->createMinimalContext();

        $result = $executor->execute($context);

        self::assertSame([], $result->published);
        self::assertSame([], $result->produced);
        self::assertTrue($result->exclusions->isEmpty());
        self::assertSame([], self::activeRules($executor));
        self::assertCount(0, $executor->allRules());
    }

    #[Test]
    public function itPublishesRuleMetadataWithExactAliasMappingWithoutConcreteRuleInstances(): void
    {
        $execution = $this->createExecution([new RuleMetadataFixtureRule()]);

        self::assertEquals([
            new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata(
                name: 'fixture.metadata',
                optionsClass: RuleExecutionFixtureOptions::class,
                description: 'Metadata fixture',
                aliases: ['fixture-threshold' => 'warning'],
                active: true,
            ),
        ], $execution->allRules());
    }

    #[Test]
    public function itExclusionStatsAreEmptyWhenNoRulesRan(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([], $provider);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertTrue($stats->isEmpty());
        self::assertSame(0, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedFindings);
    }

    #[Test]
    public function itExclusionStatsAreZeroWhenNoExclusionsConfigured(): void
    {
        $finding = $this->createFindingWithNamespace('rule1', 'App\\Core');
        $rule = $this->createRule('rule1', [$finding]);

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $provider);

        $result = $executor->execute($this->createMinimalContext());
        $findings = $result->published;
        $stats = $result->exclusions;

        self::assertCount(1, $findings);
        self::assertTrue($stats->isEmpty());
        self::assertSame([], $stats->excludedFindings);
    }

    #[Test]
    public function itNamespaceExclusionIncrementsStatsPerRule(): void
    {
        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $includedFinding = $this->createFindingWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $result = $executor->execute($this->createMinimalContext());
        $findings = $result->published;
        $stats = $result->exclusions;

        self::assertCount(1, $findings);
        self::assertFalse($stats->isEmpty());
        self::assertSame(['rule1' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame([], $stats->pathExclusionsByRule);
        self::assertSame(1, $stats->totalNamespaceExclusions());
        self::assertSame(0, $stats->totalPathExclusions());
        self::assertSame([$excludedFinding], $stats->excludedFindings);
    }

    #[Test]
    public function itPathExclusionIncrementsStatsPerRule(): void
    {
        $excludedFinding = $this->createFindingWithFile('rule1', 'src/Generated/Model.php');
        $includedFinding = $this->createFindingWithFile('rule1', 'src/Core/Service.php');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $result = $executor->execute($this->createMinimalContext());
        $findings = $result->published;
        $stats = $result->exclusions;

        self::assertCount(1, $findings);
        self::assertSame(['rule1' => 1], $stats->pathExclusionsByRule);
        self::assertSame([], $stats->namespaceExclusionsByRule);
        self::assertSame(1, $stats->totalPathExclusions());
        self::assertSame([$excludedFinding], $stats->excludedFindings);
    }

    #[Test]
    public function itExclusionStatsBreakDownByRuleNameSeparately(): void
    {
        $v1 = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $v2 = $this->createFindingWithNamespace('rule2', 'App\\Tests');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);
        $exclusionProvider->setExclusions('rule2', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertSame(['rule1' => 1, 'rule2' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame(2, $stats->totalNamespaceExclusions());
    }

    #[Test]
    public function itExclusionStatsAreResetOnEachExecuteCall(): void
    {
        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        // Two consecutive execute() calls on the same executor: if the running
        // executor accumulated counts instead of resetting them, the second
        // call would report 2 instead of 1.
        $first = $executor->execute($this->createMinimalContext());
        self::assertSame(1, $first->exclusions->totalNamespaceExclusions());

        $second = $executor->execute($this->createMinimalContext());
        self::assertSame(1, $second->exclusions->totalNamespaceExclusions());
        self::assertCount(1, $second->exclusions->excludedFindings);
    }

    #[Test]
    public function itExecutesWithAllRulesEnabled(): void
    {
        $finding1 = $this->createFinding('rule1');
        $finding2 = $this->createFinding('rule2');

        $rule1 = $this->createRule('rule1', [$finding1]);
        $rule2 = $this->createRule('rule2', [$finding2]);

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(2, $findings);
        self::assertSame($finding1, $findings[0]);
        self::assertSame($finding2, $findings[1]);
        self::assertCount(2, $executor->allRules());
    }

    #[Test]
    public function itFiltersDisabledRulesDuringExecute(): void
    {
        $finding1 = $this->createFinding('rule1');
        $finding2 = $this->createFinding('rule2');

        $rule1 = $this->createRule('rule1', [$finding1]);
        $rule2 = $this->createRule('rule2', [$finding2]);

        $config = new RuleSelection(disabled: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(1, $findings);
        self::assertSame($finding2, $findings[0]);
    }

    #[Test]
    public function itExecutesWithOnlyRulesFilter(): void
    {
        $finding1 = $this->createFinding('rule1');
        $finding2 = $this->createFinding('rule2');
        $finding3 = $this->createFinding('rule3');

        $rule1 = $this->createRule('rule1', [$finding1]);
        $rule2 = $this->createRule('rule2', [$finding2]);
        $rule3 = $this->createRule('rule3', [$finding3]);

        $config = new RuleSelection(only: ['rule1', 'rule3']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(2, $findings);
        self::assertSame($finding1, $findings[0]);
        self::assertSame($finding3, $findings[1]);
    }

    #[Test]
    public function itGetActiveRulesReturnsOnlyEnabled(): void
    {
        $rule1 = $this->createRule('enabled-rule', []);
        $rule2 = $this->createRule('disabled-rule', []);

        $config = new RuleSelection(disabled: ['disabled-rule']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        $activeRules = self::activeRules($executor);

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

        self::assertCount(2, $executor->allRules());
        self::assertCount(1, self::activeRules($executor));
    }

    #[Test]
    public function itExecutesWithIterableRules(): void
    {
        $finding = $this->createFinding('rule1');
        $rule = $this->createRule('rule1', [$finding]);

        $generator = (function () use ($rule) {
            yield $rule;
        })();

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution($generator, $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(1, $findings);
        self::assertCount(1, $executor->allRules());
    }

    #[Test]
    public function itDisabledRulesTakePrecedenceOverOnlyRules(): void
    {
        $finding = $this->createFinding('rule1');
        $rule = $this->createRule('rule1', [$finding]);

        $config = new RuleSelection(
            disabled: ['rule1'],
            only: ['rule1'],
        );
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertSame([], $findings);
        self::assertSame([], self::activeRules($executor));
    }

    // --- Narrowed execution tests ($restrictToProducer) ---

    /**
     * `$restrictToProducer` intersects the run's own selection; it never
     * overrides it. A producer the configuration disabled must stay silent
     * even when the narrowing names it by its exact name — otherwise a
     * threshold audit's counterfactual could re-enable a rule the run itself
     * turned off.
     */
    #[Test]
    public function itDoesNotExecuteAProducerDisabledByConfigurationEvenWhenNarrowingNamesIt(): void
    {
        $finding = $this->createFinding('rule1');
        $rule = $this->createRule('rule1', [$finding]);

        $provider = $this->createConfiguredProvider(new RuleSelection(disabled: ['rule1']));
        $executor = $this->createExecution([$rule], $provider);

        $result = $executor->execute($this->createMinimalContext(), 'rule1');

        self::assertSame([], $result->produced);
        self::assertSame([], $result->published);
    }

    /**
     * Narrowing also filters `published()`, not only which rule instances
     * run: a rule sharing one class across several classless producers keeps
     * running because one of them is the restricted producer, but only that
     * producer's findings may come out the other end.
     *
     * Modelled with two declared classless producers hosted by the same
     * instance — the computed-metric family's actual shape — rather than
     * through a `RuleChannelRegistryInterface` that maps the instance's own
     * name to those channels: {@see RuleExecution::isEnabled()} narrows by
     * exact producer-name equality, so an instance runs under narrowing only
     * through its own name or {@see RuleExecution::hostsAnEnabledProducer()}.
     *
     * A `$channelIdentity` is wired for the same reason `FindingConfigurator`
     * wires one in production: {@see RuleExecution::published()} narrows by
     * comparing `$producer` — resolved through
     * {@see RuleExecution::producerOf()} — against the restricted name, and
     * without an identity view `producerOf()` falls back to the *instance's*
     * name (`computed.health`), which would never equal either classless
     * producer and silently empty `published` regardless of which one was
     * asked for.
     */
    #[Test]
    public function itRestrictsPublishedFindingsToTheNarrowedProducerNotJustWhichRulesRun(): void
    {
        $complexity = $this->createFinding('computed.health', code: 'health.complexity');
        $cohesion = $this->createFinding('computed.health', code: 'health.cohesion');
        $rule = $this->createRule('computed.health', [$complexity, $cohesion]);

        // A classless producer's channel code is its own name, so resolving
        // "the producer of this code" is the identity function here — exactly
        // as the container's real ChannelIdentity resolves it for this family.
        $channelIdentity = self::createStub(ChannelIdentityInterface::class);
        $channelIdentity->method('producerOf')->willReturnArgument(0);

        $executor = $this->createExecution(
            [$rule],
            $this->createConfiguredProvider(),
            classlessProducers: [
                new ProducerDeclaration(
                    name: 'health.complexity',
                    hostRuleName: 'computed.health',
                    optionsClass: RuleExecutionFixtureOptions::class,
                    description: 'Complexity health, hosted by computed.health',
                ),
                new ProducerDeclaration(
                    name: 'health.cohesion',
                    hostRuleName: 'computed.health',
                    optionsClass: RuleExecutionFixtureOptions::class,
                    description: 'Cohesion health, hosted by computed.health',
                ),
            ],
            channelIdentity: $channelIdentity,
        );

        $result = $executor->execute($this->createMinimalContext(), 'health.complexity');

        // produced is untouched by narrowing — it is what the rule instance
        // that ran actually returned, both channels included.
        self::assertSame([$complexity, $cohesion], $result->produced);
        self::assertSame([$complexity], $result->published);
    }

    /**
     * The `published()` half of the same guarantee {@see RuleExecution::isEnabled()}
     * gives {@see itDoesNotExecuteAProducerWhoseChannelCodeCollidesWithTheNarrowedName()}:
     * a configuration validator can legitimately publish a finding whose true
     * producer differs from the rule instance it ran inside
     * ({@see RuleExecution::published()}'s own docblock), so `$producer` here
     * is not always `$ruleName`. Narrowing to `ruleA` must not keep a finding
     * whose real producer is `ruleB`, even when `ruleB`'s channel happens to
     * be coded `ruleA`.
     */
    #[Test]
    public function itDoesNotPublishAFindingWhoseProducerCollidesWithTheNarrowedNameByChannelCodeAlone(): void
    {
        $finding = $this->createFinding('ruleB', code: 'ruleA');
        $rule = $this->createRule('ruleA', [$finding]);

        $channelIdentity = self::createStub(ChannelIdentityInterface::class);
        $channelIdentity->method('producerOf')->willReturn('ruleB');

        $executor = $this->createExecution(
            [$rule],
            $this->createConfiguredProvider(),
            channelIdentity: $channelIdentity,
        );

        $result = $executor->execute($this->createMinimalContext(), 'ruleA');

        self::assertSame(
            [],
            $result->published,
            'a finding whose true producer is "ruleB" must not publish under a sweep narrowed to "ruleA",'
            . ' even though the finding\'s own channel is coded "ruleA"',
        );
    }

    /**
     * `--only-rule health.typing` names a producer with no rule class of its
     * own; the instance that hosts it must still run, and an unrelated
     * instance must not, which is the pair of facts a single-instance fixture
     * cannot show.
     */
    #[Test]
    public function itExecutesTheHostOfAClasslessProducerWhenNarrowingNamesThatProducer(): void
    {
        $typingFinding = $this->createFinding('typing.health', code: 'health.typing');
        $host = $this->createRule('typing.health', [$typingFinding]);

        $otherFinding = $this->createFinding('other.rule');
        $other = $this->createRule('other.rule', [$otherFinding]);

        $classlessProducer = new ProducerDeclaration(
            name: 'health.typing',
            hostRuleName: 'typing.health',
            optionsClass: RuleExecutionFixtureOptions::class,
            description: 'Typing health, hosted by typing.health',
        );

        $executor = $this->createExecution(
            [$host, $other],
            $this->createConfiguredProvider(),
            classlessProducers: [$classlessProducer],
        );

        $result = $executor->execute($this->createMinimalContext(), 'health.typing');

        self::assertSame([$typingFinding], $result->produced);
    }

    /**
     * The narrowing addresses one producer by exact name, not by the broader
     * "selector" grammar `--only-rule` accepts: a selector also matches a
     * producer by any channel it publishes, and `ruleB` here publishes a
     * channel coded exactly `ruleA`. Narrowing to `ruleA` must run only
     * `ruleA`, never `ruleB` on account of that channel-code coincidence.
     */
    #[Test]
    public function itDoesNotExecuteAProducerWhoseChannelCodeCollidesWithTheNarrowedName(): void
    {
        $findingA = $this->createFinding('ruleA');
        $ruleA = $this->createRule('ruleA', [$findingA]);

        $findingB = $this->createFinding('ruleB', code: 'ruleA');
        $ruleB = $this->createRule('ruleB', [$findingB]);

        $selector = new RuleSelector(new class implements RuleChannelRegistryInterface {
            public function channelsProducedBy(string $producerRuleName): array
            {
                return $producerRuleName === 'ruleB' ? [new FindingChannel('ruleA')] : [];
            }
        });

        $executor = $this->createExecution([$ruleA, $ruleB], $this->createConfiguredProvider(), $selector);

        $result = $executor->execute($this->createMinimalContext(), 'ruleA');

        self::assertSame(
            [$findingA],
            $result->produced,
            'narrowing to "ruleA" must not run "ruleB" merely because it publishes a channel coded "ruleA"',
        );
    }

    // --- Group selector tests ---

    #[Test]
    public function itExecutesWithGroupDisable(): void
    {
        $v1 = $this->createFinding('complexity.cyclomatic', code: 'complexity.cyclomatic');
        $v2 = $this->createFinding('complexity.cognitive', code: 'complexity.cognitive');
        $v3 = $this->createFinding('size.method-count', code: 'size.method-count');

        $rule1 = $this->createRule('complexity.cyclomatic', [$v1]);
        $rule2 = $this->createRule('complexity.cognitive', [$v2]);
        $rule3 = $this->createRule('size.method-count', [$v3]);

        // Disable the whole complexity group — the group form is explicit now
        $config = new RuleSelection(disabled: ['complexity.*']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(1, $findings);
        self::assertSame('size.method-count', $findings[0]->ruleName);
    }

    #[Test]
    public function itFiltersFindingsByCodeDuringExecute(): void
    {
        $methodFinding = $this->createFinding('complexity.cyclomatic', code: 'complexity.cyclomatic.callable');
        $classFinding = $this->createFinding('complexity.cyclomatic', code: 'complexity.cyclomatic.class');

        $rule = $this->createHierarchicalRule(
            'complexity.cyclomatic',
            [SymbolLevel::Callable, SymbolLevel::Class_],
            [
                SymbolLevel::Callable->value => [$methodFinding],
                SymbolLevel::Class_->value => [$classFinding],
            ],
        );

        // Disable only class-level findings
        $config = new RuleSelection(disabled: ['complexity.cyclomatic.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(1, $findings);
        self::assertSame($methodFinding, $findings[0]);
    }

    #[Test]
    public function itGetActiveRulesWithAGroupOnlySelector(): void
    {
        $rule1 = $this->createRule('complexity.cyclomatic', []);
        $rule2 = $this->createRule('complexity.cognitive', []);
        $rule3 = $this->createRule('size.method-count', []);

        $config = new RuleSelection(only: ['complexity.*']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule1, $rule2, $rule3], $provider);

        $activeRules = self::activeRules($executor);

        self::assertCount(2, $activeRules);
    }

    #[Test]
    public function itDoesNotTreatABarePrefixAsAGroup(): void
    {
        $rule1 = $this->createRule('complexity.cyclomatic', []);
        $rule2 = $this->createRule('complexity.cognitive', []);

        $provider = $this->createConfiguredProvider(new RuleSelection(only: ['complexity']));
        $executor = $this->createExecution([$rule1, $rule2], $provider);

        self::assertSame([], self::activeRules($executor));
    }

    #[Test]
    public function itKeepsComputedFindingsWhenOnlyTheProducerRuleIsSelected(): void
    {
        $finding = $this->createFinding('computed.health', code: 'health.complexity');
        $rule = $this->createRule('computed.health', [$finding]);
        $executor = $this->createExecution(
            [$rule],
            $this->createConfiguredProvider(new RuleSelection(only: ['computed.health'])),
            ruleSelector: $this->computedRuleSelector(),
        );

        self::assertSame([$finding], $executor->execute($this->createMinimalContext())->published);
    }

    #[Test]
    public function itRunsTheComputedProducerWhenOnlyItsCodeIsSelected(): void
    {
        $complexity = $this->createFinding('computed.health', code: 'health.complexity');
        $cohesion = $this->createFinding('computed.health', code: 'health.cohesion');
        $rule = $this->createRule('computed.health', [$complexity, $cohesion]);
        $provider = $this->createConfiguredProvider(new RuleSelection(only: ['health.complexity']));
        $executor = $this->createExecution(
            [$rule],
            $provider,
            ruleSelector: $this->computedRuleSelector(),
        );

        self::assertSame([$complexity], $executor->execute($this->createMinimalContext())->published);
        self::assertSame(['computed.health'], array_map(
            static fn($metadata): string => $metadata->name,
            self::activeRules($executor),
        ));
    }

    // --- Hierarchical rules tests ---

    #[Test]
    public function itExecutesHierarchicalRuleWithAllLevelsEnabled(): void
    {
        $methodFinding = $this->createFinding('complexity', code: 'complexity.callable');
        $classFinding = $this->createFinding('complexity', code: 'complexity.class');

        $rule = $this->createHierarchicalRule(
            'complexity',
            [SymbolLevel::Callable, SymbolLevel::Class_],
            [
                SymbolLevel::Callable->value => [$methodFinding],
                SymbolLevel::Class_->value => [$classFinding],
            ],
        );

        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(2, $findings);
        self::assertContains($methodFinding, $findings);
        self::assertContains($classFinding, $findings);
    }

    #[Test]
    public function itExecutesHierarchicalRuleWithSpecificCodeDisabled(): void
    {
        $methodFinding = $this->createFinding('complexity', code: 'complexity.callable');
        $classFinding = $this->createFinding('complexity', code: 'complexity.class');

        $rule = $this->createHierarchicalRule(
            'complexity',
            [SymbolLevel::Callable, SymbolLevel::Class_],
            [
                SymbolLevel::Callable->value => [$methodFinding],
                SymbolLevel::Class_->value => [$classFinding],
            ],
        );

        // Disable class-level findings via code filtering
        $config = new RuleSelection(disabled: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        // Only method level should pass through
        self::assertCount(1, $findings);
        self::assertSame($methodFinding, $findings[0]);
    }

    #[Test]
    public function itExecutesHierarchicalRuleWithEntireRuleDisabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [SymbolLevel::Callable, SymbolLevel::Class_],
            [
                SymbolLevel::Callable->value => [$this->createFinding('complexity', code: 'complexity.callable')],
                SymbolLevel::Class_->value => [$this->createFinding('complexity', code: 'complexity.class')],
            ],
        );

        // Disable entire rule
        $config = new RuleSelection(disabled: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution([$rule], $provider);

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertSame([], $findings);
    }

    #[Test]
    public function itAppliesOnlyRulesFilterToHierarchicalRule(): void
    {
        $methodFinding = $this->createFinding('complexity', code: 'complexity.callable');
        $classFinding = $this->createFinding('complexity', code: 'complexity.class');

        $rule = $this->createHierarchicalRule(
            'complexity',
            [SymbolLevel::Callable, SymbolLevel::Class_],
            [
                SymbolLevel::Callable->value => [$methodFinding],
                SymbolLevel::Class_->value => [$classFinding],
            ],
        );

        // Only enable callable-level findings, addressed by their exact
        // channel. A channel selector reaches its producer through the channel
        // registry — never by the producer name happening to be a prefix of
        // the selector, which is the reverse match this substrate removes.
        $config = new RuleSelection(only: ['complexity.callable']);
        $provider = $this->createConfiguredProvider($config);
        $executor = $this->createExecution(
            [$rule],
            $provider,
            new RuleSelector(new InMemoryRuleChannelRegistry([
                'complexity' => [
                    new FindingChannel('complexity.callable'),
                    new FindingChannel('complexity.class'),
                ],
            ])),
        );

        $context = $this->createMinimalContext();
        $findings = $executor->execute($context)->published;

        self::assertCount(1, $findings);
        self::assertSame($methodFinding, $findings[0]);
    }

    // --- Namespace exclusion tests ---

    #[Test]
    public function itFiltersFindingsByNamespaceExclusion(): void
    {
        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $includedFinding = $this->createFindingWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($includedFinding, $findings[0]);
    }

    /**
     * `produced()` must hold a per-rule `exclude_namespaces` casualty that
     * `published()` drops — the Ш5e2b precedent (`AUDIT.md`) where an audit
     * comparing `execute()`'s return value called four such directives
     * "not deciding anything" because nothing recorded what the rule found
     * before the ledger ran.
     *
     * Killed by collecting `produced` from {@see RuleExecution::published()}'s
     * `$kept` accumulator instead of from the pre-ledger `$ruleFindings`: the
     * excluded finding then vanishes from `produced()` too and this assertion
     * goes red.
     */
    #[Test]
    public function itKeepsAPerRuleNamespaceExclusionCasualtyInProducedButNotInPublished(): void
    {
        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $includedFinding = $this->createFindingWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $executor = $this->createExecution([$rule], $registry);

        $result = $executor->execute($this->createMinimalContext());

        self::assertSame([$excludedFinding, $includedFinding], $result->produced);
        self::assertSame([$includedFinding], $result->published);
    }

    #[Test]
    public function itFiltersFileSymbolFindingsBySubjectNamespaceExclusion(): void
    {
        $excludedFinding = $this->createFileSymbolFindingWithSubjectNamespace('rule1', 'App\\Tests');
        $includedFinding = $this->createFileSymbolFindingWithSubjectNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($includedFinding, $findings[0]);
    }

    #[Test]
    public function itNamespaceExclusionPassesThroughNullNamespace(): void
    {
        $fileFinding = $this->createFinding('rule1');
        $rule = $this->createRule('rule1', [$fileFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
    }

    #[Test]
    public function itNamespaceExclusionPassesThroughEmptyNamespace(): void
    {
        $globalFinding = $this->createFindingWithNamespace('rule1', '');
        $rule = $this->createRule('rule1', [$globalFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
    }

    #[Test]
    public function itNamespaceExclusionDoesNotAffectOtherRules(): void
    {
        $v1 = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $v2 = $this->createFindingWithNamespace('rule2', 'App\\Tests');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($v2, $findings[0]);
    }

    #[Test]
    public function itChannelNamespaceExclusionLeavesSiblingCodesActive(): void
    {
        $cohesion = $this->createFindingWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $coupling = $this->createFindingWithNamespace(
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

        $result = $executor->execute($this->createMinimalContext());

        self::assertSame([$coupling], $result->published);
        self::assertSame(
            [$cohesion],
            $result->exclusions->excludedFindings,
        );
    }

    #[Test]
    public function itChannelNamespaceExclusionDoesNotHideClassFindingsInThatNamespace(): void
    {
        $namespaceCohesion = $this->createFindingWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $classCohesion = new Finding(
            location: new Location(RelativePath::fromString('src/Metrics/Collector.php'), 10),
            symbolPath: SymbolPath::forClass('App\\Metrics', 'Collector'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Metrics', 'Collector'), RelativePath::fromString('src/Metrics/Collector.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: 'computed.health',
            code: 'health.cohesion',
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

        self::assertSame([$classCohesion], $executor->execute($this->createMinimalContext())->published);
    }

    #[Test]
    public function itChannelNamespaceWildcardDoesNotHideProjectFindings(): void
    {
        $namespaceCohesion = $this->createFindingWithNamespace(
            'computed.health',
            'App\\Metrics',
            'health.cohesion',
        );
        $projectCohesion = new Finding(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            ruleName: 'computed.health',
            code: 'health.cohesion',
            message: 'Project cohesion health is low',
            severity: Severity::Warning,
        );
        $rule = $this->createRule('computed.health', [$namespaceCohesion, $projectCohesion]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setChannelExclusions('computed.health', 'health.*', ['*']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $executor = $this->createExecution(
            [$rule],
            $registry,
            $this->computedRuleSelector(),
        );

        $result = $executor->execute($this->createMinimalContext());

        self::assertSame([$projectCohesion], $result->published);
        self::assertSame(
            [$namespaceCohesion],
            $result->exclusions->excludedFindings,
        );
    }

    #[Test]
    public function itAttributesALedgerExclusionToTheChannelIdentityProducerNotTheFindingsRuleName(): void
    {
        // Mirrors the computed-metric family {@see RuleExecution::producerOf()}
        // describes: one rule instance publishes under $ruleName
        // 'computed.health', but the channel below has its own exclusion
        // configuration. A consumer that re-derives "who excluded this" from
        // Finding::$ruleName instead of reading the recorded attribution
        // would name the wrong producer.
        $channel = 'health.cohesion';
        $excludedFinding = $this->createFindingWithNamespace('computed.health', 'App\\Metrics', $channel);
        $rule = $this->createRule('computed.health', [$excludedFinding]);

        $registry = new RuleOptionsRegistry();
        $registry->setConfigFileOptions([$channel => ['exclude_namespaces' => ['App\\Metrics']]]);
        $registry->configureNamespaceExclusions($channel, ['App\\Metrics']);

        $channelIdentity = self::createStub(ChannelIdentityInterface::class);
        $channelIdentity->method('producerOf')->willReturn($channel);

        $executor = $this->createExecution([$rule], $registry, channelIdentity: $channelIdentity);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertCount(1, $stats->attributions);
        self::assertSame($channel, $stats->attributions[0]->producerRuleName);
        self::assertNotSame($excludedFinding->ruleName, $stats->attributions[0]->producerRuleName);
        self::assertSame(['App\\Metrics'], $stats->attributions[0]->matchedPatterns);
    }

    #[Test]
    public function itRecordsTheFiringNamespaceChannelSelectorAndPatternInTheAttribution(): void
    {
        $channel = 'health.cohesion';
        $cohesion = $this->createFindingWithNamespace('computed.health', 'App\\Metrics', $channel);
        $rule = $this->createRule('computed.health', [$cohesion]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setChannelExclusions('computed.health', $channel, ['App\\Metrics']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $registry->setConfigFileOptions(['computed.health' => [
            'exclude_namespace_channels' => [$channel => ['App\\Metrics']],
        ]]);

        $executor = $this->createExecution([$rule], $registry, $this->computedRuleSelector());

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertCount(1, $stats->attributions);
        self::assertSame([], $stats->attributions[0]->matchedPatterns);
        self::assertSame(
            [['selector' => $channel, 'pattern' => 'App\\Metrics']],
            $stats->attributions[0]->matchedChannelPatterns,
        );
    }

    /**
     * Two overlapping `exclude_paths` entries both independently match the
     * same file. The ledger must record both as fired, not only the one its
     * own short-circuiting lookup happens to reach first — a consumer
     * computing "which configured pattern fired nothing" needs every pattern
     * that could have fired, or it misreports the second as dead.
     */
    #[Test]
    public function itRecordsEveryConfiguredPathPatternThatIndependentlyMatchesTheExcludedFinding(): void
    {
        $excludedFinding = $this->createFindingWithFile('rule1', 'src/Excluded/Deep/Foo.php');
        $rule = $this->createRule('rule1', [$excludedFinding]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Excluded', 'src/Excluded/Deep']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $registry->setConfigFileOptions(['rule1' => ['exclude_paths' => ['src/Excluded', 'src/Excluded/Deep']]]);

        $executor = $this->createExecution([$rule], $registry);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertCount(1, $stats->attributions);
        self::assertSame(['src/Excluded', 'src/Excluded/Deep'], $stats->attributions[0]->matchedPatterns);
    }

    // --- Finding-owned excluded-finding capture policy tests ---

    #[Test]
    public function itDoesNotCaptureExcludedFindingsWhenCaptureHolderIsDisabled(): void
    {
        $this->captureExcludedFindings = false;

        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        // Counts are collected regardless of the capture toggle.
        self::assertSame(['rule1' => 1], $stats->namespaceExclusionsByRule);
        self::assertSame(1, $stats->totalNamespaceExclusions());
        // But the heavy Finding objects are not retained when disabled.
        self::assertSame([], $stats->excludedFindings);
    }

    #[Test]
    public function itDoesNotCaptureExcludedPathFindingsWhenCaptureHolderIsDisabled(): void
    {
        $this->captureExcludedFindings = false;

        $excludedFinding = $this->createFindingWithFile('rule1', 'src/Generated/Model.php');
        $rule = $this->createRule('rule1', [$excludedFinding]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertSame(['rule1' => 1], $stats->pathExclusionsByRule);
        self::assertSame(1, $stats->totalPathExclusions());
        self::assertSame([], $stats->excludedFindings);
    }

    #[Test]
    public function itCapturesExcludedFindingsWhenCaptureHolderIsEnabled(): void
    {
        $this->captureExcludedFindings = true;

        $excludedFinding = $this->createFindingWithNamespace('rule1', 'App\\Tests');
        $rule = $this->createRule('rule1', [$excludedFinding]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $stats = $executor->execute($this->createMinimalContext())->exclusions;

        self::assertSame([$excludedFinding], $stats->excludedFindings);
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
     * @param iterable<ProducerDeclaration> $classlessProducers
     */
    private function createExecution(
        iterable $rules,
        ?RuleOptionsRegistry $registry = null,
        ?RuleSelector $ruleSelector = null,
        ?ChannelIdentityInterface $channelIdentity = null,
        iterable $classlessProducers = [],
    ): RuleExecution {
        $registry ??= new RuleOptionsRegistry();
        if ($this->captureExcludedFindings) {
            $registry->captureExcludedFindings();
        }

        return new RuleExecution(
            $rules,
            self::createStub(ProfilerInterface::class),
            $registry,
            $ruleSelector,
            classlessProducers: $classlessProducers,
            channelIdentity: $channelIdentity,
        );
    }

    /**
     * @param list<Finding> $findings
     */
    private function createRule(string $name, array $findings): RuleInterface
    {
        return new class ($name, $findings) implements RuleInterface {
            /** @param list<Finding> $findings */
            public function __construct(
                private readonly string $name,
                private readonly array $findings,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }
            public function getDescription(): string
            {
                return $this->name;
            }
            public static function shape(): ChannelShape
            {
                return ChannelShape::Occurrence;
            }
            public function requires(): array
            {
                return [];
            }
            /**
     * A double with no producers of its own: an empty activity declares
     * nothing, and absence is not disablement.
     *
     * @return array<string, array<string, bool>>
     */
            public function levelActivity(): array
            {
                return [];
            }

            public function analyze(AnalysisContext $context): array
            {
                return $this->findings;
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
     * @param list<SymbolLevel> $supportedLevels
     * @param array<string, list<Finding>> $findingsByLevel
     */
    private function createHierarchicalRule(
        string $name,
        array $supportedLevels,
        array $findingsByLevel,
    ): RuleInterface {
        // RuleExecution now calls analyze() for all rules uniformly.
        // Flatten all level findings into a single list for analyze().
        $allFindings = array_merge(...array_values($findingsByLevel));

        return $this->createRule($name, $allFindings);
    }

    private function createFinding(string $ruleName, ?string $code = null): Finding
    {
        return new Finding(
            location: new Location(
                file: RelativePath::fromString('test/file.php'),
                line: 1,
            ),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('test/file.php')),
            subject: MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('test/file.php'))),
            ruleName: $ruleName,
            code: $code ?? $ruleName,
            message: "Violation from $ruleName",
            severity: Severity::Warning,
        );
    }

    private function createFindingWithNamespace(
        string $ruleName,
        string $namespace,
        ?string $code = null,
    ): Finding {
        return new Finding(
            location: new Location(
                file: RelativePath::fromString('test/file.php'),
                line: 1,
            ),
            symbolPath: SymbolPath::forNamespace($namespace),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace($namespace)),
            ruleName: $ruleName,
            code: $code ?? $ruleName,
            message: "Violation from $ruleName in $namespace",
            severity: Severity::Warning,
        );
    }

    private function createFileSymbolFindingWithSubjectNamespace(string $ruleName, string $namespace): Finding
    {
        $file = RelativePath::fromString('test/file.php');

        return new Finding(
            location: new Location(
                file: $file,
                line: 1,
            ),
            symbolPath: SymbolPath::forFile($file),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($namespace, 'Helper'), $file, DeclarationOrdinal::fromRank(0))),
            ruleName: $ruleName,
            code: $ruleName,
            message: "Violation from $ruleName in $namespace",
            severity: Severity::Warning,
        );
    }

    // --- Path exclusion tests ---

    #[Test]
    public function itFiltersFindingsByExcludePaths(): void
    {
        $excludedFinding = $this->createFindingWithFile('rule1', 'src/Generated/Model.php');
        $includedFinding = $this->createFindingWithFile('rule1', 'src/Core/Service.php');

        $rule = $this->createRule('rule1', [$excludedFinding, $includedFinding]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($includedFinding, $findings[0]);
    }

    #[Test]
    public function itExcludePathsAreIsolatedPerRule(): void
    {
        $v1 = $this->createFindingWithFile('rule1', 'src/Generated/Model.php');
        $v2 = $this->createFindingWithFile('rule2', 'src/Generated/Model.php');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule1, $rule2], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($v2, $findings[0]);
    }

    #[Test]
    public function itExcludePathsWithEmptyFilePassesThrough(): void
    {
        $finding = $this->createFindingWithFile('rule1', '');

        $rule = $this->createRule('rule1', [$finding]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = $this->createExecution([$rule], $registry);

        $findings = $executor->execute($this->createMinimalContext())->published;

        self::assertCount(1, $findings);
        self::assertSame($finding, $findings[0]);
    }

    private function createFindingWithFile(string $ruleName, string $file): Finding
    {
        $symbolPathFile = RelativePath::fromString($file !== '' ? $file : 'unknown');

        return new Finding(
            location: new Location(
                file: $file !== '' ? RelativePath::fromString($file) : null,
                line: 1,
            ),
            symbolPath: SymbolPath::forFile($symbolPathFile),
            subject: MetricSubject::aggregate(SymbolPath::forFile($symbolPathFile)),
            ruleName: $ruleName,
            code: $ruleName,
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
                    new FindingChannel('health.complexity'),
                    new FindingChannel('health.cohesion'),
                ];
            }
        });
    }

    /**
     * The enabled subset of the registry, filtered from the one enumeration
     * {@see RuleExecutionInterface::allRules()} publishes.
     *
     * The executor used to answer this itself. Nothing outside these tests ever
     * asked, so the operation was removed rather than kept as a third
     * enumeration of "every registered rule" — filtering here is what a real
     * caller would have had to do anyway.
     *
     * @return list<RuleMetadata>
     */
    private static function activeRules(RuleExecutionInterface $executor): array
    {
        return array_values(array_filter(
            $executor->allRules(),
            static fn(RuleMetadata $metadata): bool => $metadata->active,
        ));
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
    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }
    public function requires(): array
    {
        return [];
    }
    /**
     * A double with no producers of its own: an empty activity declares
     * nothing, and absence is not disablement.
     *
     * @return array<string, array<string, bool>>
     */
    public function levelActivity(): array
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
