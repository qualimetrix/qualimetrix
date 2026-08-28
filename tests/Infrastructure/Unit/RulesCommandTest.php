<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
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
     */
    #[Test]
    public function itFailsOnAGroupNoProducerHas(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', 'Cyclomatic complexity');
        $other = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$rule, $other]));
        $tester->execute(['--group' => 'complexty']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No rule group "complexty"', $tester->getDisplay());
        self::assertStringContainsString('Groups: complexity, size', $tester->getDisplay());
    }

    /**
     * The comparison stays exact: `--group` reads the very value the heading is
     * printed from, so a case-folded match here would make the option answer a
     * question the listing does not.
     */
    #[Test]
    public function itFailsOnAGroupThatDiffersOnlyInCase(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute(['--group' => 'Complexity']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No rule group "Complexity"', $tester->getDisplay());
    }

    #[Test]
    public function itListsRulesUnderGroupHeaders(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$ruleA, $ruleB]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2 rules available', $display);
        self::assertStringContainsString('Complexity', $display);
        self::assertStringContainsString('complexity.cyclomatic', $display);
        self::assertStringContainsString('Cyclomatic complexity', $display);
        self::assertStringContainsString('Size', $display);
        self::assertStringContainsString('size.class-count', $display);
    }

    #[Test]
    public function itFiltersRulesByGroup(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', 'Class count');

        $tester = new CommandTester($this->createCommand([$ruleA, $ruleB]));
        $tester->execute(['--group' => 'complexity']);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 rules available', $display);
        self::assertStringContainsString('complexity.cyclomatic', $display);
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
        self::assertStringContainsString('complexity.cyclomatic:warning_threshold', $display);
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
        $rule = $this->createRuleMock('complexity.cyclomatic', 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--disable-rule', $display);
        self::assertStringContainsString('--rule-opt', $display);
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

    /** @param list<RuleInterface> $rules */
    private function createCommand(array $rules): RulesCommand
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

        return new RulesCommand($execution);
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
}

/**
 * @internal
 */
#[\Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias('cyclomatic-warning', 'warning_threshold')]
final class FixtureRuleWithCyclomaticAlias implements RuleInterface
{
    public function getName(): string
    {
        return 'complexity.cyclomatic';
    }

    public function getDescription(): string
    {
        return 'Cyclomatic complexity';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Magnitude;
    }

    public function requires(): array
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
