<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
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

    #[Test]
    public function itDisplaysNoRulesMessageForAnUnknownGroup(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute(['--group' => 'nonexistent']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found in group "nonexistent"', $tester->getDisplay());
    }

    #[Test]
    public function itListsRulesUnderGroupHeaders(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', RuleCategory::Size, 'Class count');

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
        $ruleA = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', RuleCategory::Size, 'Class count');

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
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $tester = new CommandTester($this->createCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--disable-rule', $display);
        self::assertStringContainsString('--rule-opt', $display);
    }

    private function createRuleMock(
        string $name,
        RuleCategory $category,
        string $description,
    ): RuleInterface {
        $rule = self::createStub(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('getCategory')->willReturn($category);
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
                category: $rule->getCategory(),
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

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Complexity;
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
