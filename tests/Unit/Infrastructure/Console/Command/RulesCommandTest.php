<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RulesCommand::class)]
final class RulesCommandTest extends TestCase
{
    #[Test]
    public function configuresSetsNameAndDescription(): void
    {
        $command = new RulesCommand([]);

        self::assertSame('rules', $command->getName());
        self::assertSame('List all available analysis rules', $command->getDescription());
    }

    #[Test]
    public function configuresGroupOption(): void
    {
        $command = new RulesCommand([]);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('group'));

        $option = $definition->getOption('group');
        self::assertSame('g', $option->getShortcut());
        self::assertTrue($option->isValueRequired());
    }

    #[Test]
    public function displaysNoRulesMessageWhenRegistryEmpty(): void
    {
        $tester = new CommandTester(new RulesCommand([]));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found', $tester->getDisplay());
    }

    #[Test]
    public function displaysNoRulesMessageForUnknownGroup(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $tester = new CommandTester(new RulesCommand([$rule]));
        $tester->execute(['--group' => 'nonexistent']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found in group "nonexistent"', $tester->getDisplay());
    }

    #[Test]
    public function listsRulesWithGroupHeaders(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', RuleCategory::Size, 'Class count');

        $tester = new CommandTester(new RulesCommand([$ruleA, $ruleB]));
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
    public function filtersRulesByGroup(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', RuleCategory::Size, 'Class count');

        $tester = new CommandTester(new RulesCommand([$ruleA, $ruleB]));
        $tester->execute(['--group' => 'complexity']);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 rules available', $display);
        self::assertStringContainsString('complexity.cyclomatic', $display);
        self::assertStringNotContainsString('size.class-count', $display);
    }

    #[Test]
    public function displaysCliAliases(): void
    {
        $rule = $this->createCyclomaticRuleWithAlias();

        $tester = new CommandTester(new RulesCommand([$rule]));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--cyclomatic-warning', $display);
        self::assertStringContainsString('complexity.cyclomatic:warning_threshold', $display);
    }

    #[Test]
    public function displaysUsageHints(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $tester = new CommandTester(new RulesCommand([$rule]));
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
#[\Qualimetrix\Core\Rule\Attribute\CliAlias('cyclomatic-warning', 'warning_threshold')]
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

    public function analyze(\Qualimetrix\Core\Rule\AnalysisContext $context): array
    {
        return [];
    }

    public static function getOptionsClass(): string
    {
        return StubRuleOptions::class;
    }
}
