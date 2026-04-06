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
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RulesCommand::class)]
final class RulesCommandTest extends TestCase
{
    #[Test]
    public function configuresSetsNameAndDescription(): void
    {
        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([]);

        $command = new RulesCommand($registry);

        self::assertSame('rules', $command->getName());
        self::assertSame('List all available analysis rules', $command->getDescription());
    }

    #[Test]
    public function configuresGroupOption(): void
    {
        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([]);

        $command = new RulesCommand($registry);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('group'));

        $option = $definition->getOption('group');
        self::assertSame('g', $option->getShortcut());
        self::assertTrue($option->isValueRequired());
    }

    #[Test]
    public function displaysNoRulesMessageWhenRegistryEmpty(): void
    {
        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([]);

        $tester = new CommandTester(new RulesCommand($registry));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found', $tester->getDisplay());
    }

    #[Test]
    public function displaysNoRulesMessageForUnknownGroup(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([$rule]);

        $tester = new CommandTester(new RulesCommand($registry));
        $tester->execute(['--group' => 'nonexistent']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No rules found in group "nonexistent"', $tester->getDisplay());
    }

    #[Test]
    public function listsRulesWithGroupHeaders(): void
    {
        $ruleA = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');
        $ruleB = $this->createRuleMock('size.class-count', RuleCategory::Size, 'Class count');

        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([$ruleA, $ruleB]);

        $tester = new CommandTester(new RulesCommand($registry));
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

        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([$ruleA, $ruleB]);

        $tester = new CommandTester(new RulesCommand($registry));
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
        $rule = $this->createRuleMock(
            'complexity.cyclomatic',
            RuleCategory::Complexity,
            'Cyclomatic complexity',
            ['cyclomatic-warning' => 'warning_threshold'],
        );

        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([$rule]);

        $tester = new CommandTester(new RulesCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--cyclomatic-warning', $display);
        self::assertStringContainsString('complexity.cyclomatic:warning_threshold', $display);
    }

    #[Test]
    public function displaysUsageHints(): void
    {
        $rule = $this->createRuleMock('complexity.cyclomatic', RuleCategory::Complexity, 'Cyclomatic complexity');

        $registry = self::createStub(RuleRegistryInterface::class);
        $registry->method('getAll')->willReturn([$rule]);

        $tester = new CommandTester(new RulesCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();

        self::assertStringContainsString('--disable-rule', $display);
        self::assertStringContainsString('--rule-opt', $display);
    }

    /**
     * @param array<string, string> $cliAliases
     */
    private function createRuleMock(
        string $name,
        RuleCategory $category,
        string $description,
        array $cliAliases = [],
    ): RuleInterface {
        // getCliAliases() is static — mocks can't handle it, so we use an anonymous class.
        // Caveat: $staticAliases is shared across all instances of this anonymous class.
        // This is safe as long as getCliAliases() is read before creating the next instance.
        $ruleClass = new class ($name, $category, $description, $cliAliases) implements RuleInterface {
            /** @var array<string, string> */
            private static array $staticAliases = [];

            /**
             * @param array<string, string> $cliAliases
             */
            public function __construct(
                private readonly string $name,
                private readonly RuleCategory $category,
                private readonly string $description,
                array $cliAliases,
            ) {
                self::$staticAliases = $cliAliases;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function getCategory(): RuleCategory
            {
                return $this->category;
            }

            public function requires(): array
            {
                return [];
            }

            public function analyze(\Qualimetrix\Core\Rule\AnalysisContext $context): array
            {
                return [];
            }

            public static function getCliAliases(): array
            {
                return self::$staticAliases;
            }

            public static function getOptionsClass(): string
            {
                return StubRuleOptions::class;
            }
        };

        return $ruleClass;
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
