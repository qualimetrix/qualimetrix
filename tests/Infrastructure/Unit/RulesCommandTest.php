<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerEvidenceCollector;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RulesCommand::class)]
final class RulesCommandTest extends TestCase
{
    #[Test]
    public function itSetsTheCommandNameAndDescription(): void
    {
        $command = $this->createCommand([]);

        self::assertSame('rules', $command->getName());
        self::assertSame('List all available analysis rules', $command->getDescription());
    }

    #[Test]
    public function itConfiguresTheGroupOption(): void
    {
        $command = $this->createCommand([]);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('group'));

        $option = $definition->getOption('group');
        self::assertSame('g', $option->getShortcut());
        self::assertTrue($option->isValueRequired());
    }

    #[Test]
    public function itDisplaysNoRulesMessageWhenNoRulesAreRegistered(): void
    {
        $tester = new CommandTester($this->createCommand([]));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found', $tester->getDisplay());
    }

    /**
     * A group nobody has is a typo, and a typo used to be answered with an
     * empty listing and exit 0 — the same answer as "this group exists and is
     * empty", which no group is. The failure names the groups that do exist,
     * because the reader who typed it needs the list, not the refusal.
     *
     * The command has no machine format and no catch-ladder of its own: it
     * throws the refusal carrier and
     * leaves it to `Application`'s ladder to turn into exit code 3, so under
     * `CommandTester` — which runs `Command::run()` directly, with nothing
     * above it to catch — the carrier flies out of `execute()` rather than
     * being reported as a status code.
     */
    #[Test]
    public function itFailsOnAGroupNoProducerHas(): void
    {
        $rule = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');
        $other = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$rule, $other]));

        try {
            $tester->execute(['--group' => 'complexty']);
            self::fail('Expected a ConfigurationRefusal to be thrown.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('No rule group "complexty"', $refusal->summary());
            self::assertStringContainsString('Groups: complexity, size', $refusal->summary());
        }
    }

    /**
     * The comparison stays exact: `--group` reads the very value the heading is
     * printed from, so a case-folded match here would make the option answer a
     * question the listing does not.
     */
    #[Test]
    public function itFailsOnAGroupThatDiffersOnlyInCase(): void
    {
        $rule = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));

        try {
            $tester->execute(['--group' => 'Complexity']);
            self::fail('Expected a ConfigurationRefusal to be thrown.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('No rule group "Complexity"', $refusal->summary());
        }
    }

    #[Test]
    public function itListsRulesUnderGroupHeaders(): void
    {
        $ruleA = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$ruleA, $ruleB]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2 rules available', $display);
        self::assertStringContainsString('Complexity', $display);
        self::assertStringContainsString('complexity.ccn', $display);
        self::assertStringContainsString('Cyclomatic complexity', $display);
        self::assertStringContainsString('Size', $display);
        self::assertStringContainsString('size.class-count', $display);
    }

    #[Test]
    public function itFiltersRulesByGroup(): void
    {
        $ruleA = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$ruleA, $ruleB]));
        $tester->execute(['--group' => 'complexity']);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 rules available', $display);
        self::assertStringContainsString('complexity.ccn', $display);
        self::assertStringNotContainsString('size.class-count', $display);
    }

    #[Test]
    public function itDisplaysCliAliases(): void
    {
        $rule = $this->createCyclomaticRuleWithAlias();

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--cyclomatic-warning', $display);
        self::assertStringContainsString('complexity.ccn:warning_threshold', $display);
    }

    #[Test]
    public function itDisplaysTheLayerViolationSeverityAlias(): void
    {
        $options = new LayerViolationOptions();
        $rule = new LayerViolationRule(
            $options,
            new LayerEvidenceCollector($options, new UnassignedClassOptions(), new ArchitecturePolicy()),
        );

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--layer-violation-severity', $display);
        self::assertStringContainsString('architecture.layer-violation:severity', $display);

        // The three per-diagnostic severity aliases are gone with their
        // options: those channels report a configuration error, so there is
        // no severity left for a CLI flag to set.
        self::assertStringNotContainsString('--layer-violation-unreachable-layer-severity', $display);
        self::assertStringNotContainsString('--layer-violation-potential-shadow-severity', $display);
        self::assertStringNotContainsString('--layer-violation-empty-template-severity', $display);
    }

    #[Test]
    public function itDisplaysUsageHints(): void
    {
        $rule = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--disable-rule', $display);
        self::assertStringContainsString('--rule-opt', $display);
    }

    /**
     * The listing is where a reader looks a rule up, so it is where the
     * declared "this channel judges that metric" pair has to be readable: the
     * report prints channel codes and the metric catalog prints metric keys,
     * and nothing between them used to say which belonged to which.
     */
    #[Test]
    public function itPrintsTheMetricsAChannelDeclaresItJudges(): void
    {
        $rule = $this->createRuleMock('complexity.ccn', 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand(
            [$rule],
            ['complexity.ccn' => ['complexity.ccn' => ['complexity.ccn', 'complexity.ccn.max']]],
        ));
        $tester->execute([], ['decorated' => false]);

        self::assertStringContainsString(
            'complexity.ccn judges complexity.ccn, complexity.ccn.max',
            $tester->getDisplay(),
        );
    }

    /**
     * Thirty of the fifty-two declared channels judge no catalog metric —
     * `architecture.circular-dependency` reports a cycle's member count, and
     * `coupling.class-rank` stays an occurrence by ADR 0017 — so the listing
     * must stay silent about them rather than invent a metric from the
     * channel's own spelling.
     */
    #[Test]
    public function itSaysNothingAboutAChannelThatJudgesNoMetric(): void
    {
        $rule = $this->createRuleMock('architecture.circular-dependency', 'Circular dependencies');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([], ['decorated' => false]);

        self::assertStringNotContainsString('judges', $tester->getDisplay());
    }

    private function createRuleMock(
        string $name,
        string $description,
    ): RuleInterface {
        $rule = self::createStub(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('getDescription')->willReturn($description);

        return $rule;
    }

    /**
     * `StubRuleOptions` accepts nothing, which is the one shape the live
     * population has no example of: every registered rule accepts at least
     * `enabled` or a threshold. The branch still has to be right, because a
     * blank `options:` line reads as "this rule takes no options" while
     * meaning "nobody asked".
     */
    #[Test]
    public function itPrintsNoOptionsLineForARuleWhoseDeclarationAcceptsNothing(): void
    {
        $tester = new CommandTester($this->createCommand([new FixtureRuleWithCyclomaticAlias()]));
        $tester->execute([]);

        self::assertStringNotContainsString('options: ', $tester->getDisplay());
    }

    /**
     * The three framework keys are legal under every rule and declared by none,
     * so naming them per rule would add four lines to fifty-four bodies. They
     * are not in the footer because they are universal, though: `enabled` is
     * nearly universal and stays inline, because `architecture.unassigned-class`
     * does not accept it and a footer would lie about that one rule.
     */
    #[Test]
    public function itNamesTheFrameworkKeysOnceInTheFooterRatherThanInEveryRuleBody(): void
    {
        $tester = new CommandTester($this->createCommand([new FixtureRuleWithCyclomaticAlias()]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString(
            'Every rule also takes: ' . implode(', ', FrameworkOptionKeys::all()),
            $display,
        );
        self::assertSame(1, substr_count($display, 'Every rule also takes:'));
    }

    /**
     * The alias fixture targets `warning_threshold`, which the stub declaration
     * does not accept. Restating it in canonical kebab would invent a spelling
     * for a key nothing has, so the authored one is printed unchanged — the
     * listing is not where a dangling alias is discovered.
     */
    #[Test]
    public function itLeavesAnAliasTargetNothingAcceptsInTheSpellingItsAuthorGave(): void
    {
        $tester = new CommandTester($this->createCommand([new FixtureRuleWithCyclomaticAlias()]));
        $tester->execute([]);

        self::assertStringContainsString('warning_threshold=...', $tester->getDisplay());
    }

    /**
     * A channel absent from `$judged` is declared without judged metrics.
     *
     * @param list<RuleInterface> $rules
     * @param array<string, array<string, non-empty-list<string>>> $judged rule name => channel code => judged metric keys
     */
    private function createCommand(array $rules, array $judged = []): RulesCommand
    {
        $metadata = array_map(
            static fn(RuleInterface $rule): RuleMetadata => new RuleMetadata(
                name: $rule->getName(),
                optionsClass: StubRuleOptions::class,
                description: $rule->getDescription(),
                aliases: CliAliasReader::read($rule::class),
                active: true,
            ),
            $rules,
        );
        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('allRules')->willReturn($metadata);

        $channelsByRule = [];
        $declarationByCode = [];
        foreach ($judged as $ruleName => $byChannel) {
            foreach ($byChannel as $code => $metricKeys) {
                $channelsByRule[$ruleName][] = new FindingChannel($code);
                $declarationByCode[$code] = ChannelDeclaration::judging(
                    WorseDirection::Higher,
                    JudgedMetrics::of(...$metricKeys),
                    SymbolLevel::Class_,
                );
            }
        }

        $channels = self::createStub(RuleChannelRegistryInterface::class);
        $channels->method('channelsProducedBy')->willReturnCallback(
            static fn(string $ruleName): array => $channelsByRule[$ruleName] ?? [],
        );

        $registry = self::createStub(ChannelDeclarationRegistryInterface::class);
        $registry->method('declarationFor')->willReturnCallback(
            static fn(FindingChannel $channel): ?ChannelDeclaration => $declarationByCode[$channel->code] ?? null,
        );

        return new RulesCommand($execution, $channels, $registry);
    }

    private function createCyclomaticRuleWithAlias(): RuleInterface
    {
        return new FixtureRuleWithCyclomaticAlias();
    }
}

/**
 * Minimal RuleOptionsInterface stub for testing.
 *
 * @internal
 */
final readonly class StubRuleOptions implements RuleOptionsInterface
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

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([]);
    }
}

/**
 * @internal
 */
#[\Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias('cyclomatic-warning', 'warning_threshold')]
final class FixtureRuleWithCyclomaticAlias implements RuleInterface
{
    public function getName(): string
    {
        return 'complexity.ccn';
    }

    public function getDescription(): string
    {
        return 'Cyclomatic complexity';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Magnitude;
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

    public function analyze(\Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext $context): array
    {
        return [];
    }

    public static function getOptionsClass(): string
    {
        return StubRuleOptions::class;
    }
}
