<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Rules\Architecture\CircularDependencyRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Symfony\Component\Console\Command\Command;

#[CoversClass(CheckCommandDefinition::class)]
final class CheckCommandDefinitionTest extends TestCase
{
    #[Test]
    public function booleanAliasUsesValueNone(): void
    {
        $command = new Command('test');
        $registry = new RuleRegistry([
            CircularDependencyRule::class,
        ]);

        CheckCommandDefinition::addOptions($command, $registry);

        $definition = $command->getDefinition();

        // 'circular-deps' maps to boolean 'enabled' — should be VALUE_NONE (no value accepted)
        $option = $definition->getOption('circular-deps');
        self::assertFalse(
            $option->acceptValue(),
            'Boolean alias "circular-deps" should not accept a value (VALUE_NONE)',
        );
    }

    #[Test]
    public function numericAliasUsesValueRequired(): void
    {
        $command = new Command('test');
        $registry = new RuleRegistry([
            ComplexityRule::class,
        ]);

        CheckCommandDefinition::addOptions($command, $registry);

        $definition = $command->getDefinition();

        // 'cyclomatic-warning' maps to numeric threshold — should be VALUE_REQUIRED
        $option = $definition->getOption('cyclomatic-warning');
        self::assertTrue(
            $option->isValueRequired(),
            'Numeric alias "cyclomatic-warning" should require a value',
        );
    }

    #[Test]
    public function addOptionsReturnsRuleSpecificOptionNames(): void
    {
        $command = new Command('test');
        $registry = new RuleRegistry([
            ComplexityRule::class,
        ]);

        $ruleOptionNames = CheckCommandDefinition::addOptions($command, $registry);

        self::assertNotEmpty($ruleOptionNames);
        self::assertContains('cyclomatic-warning', $ruleOptionNames);
        self::assertContains('cyclomatic-error', $ruleOptionNames);

        // Core options should NOT be in the returned list
        self::assertNotContains('format', $ruleOptionNames);
        self::assertNotContains('workers', $ruleOptionNames);
        self::assertNotContains('disable-rule', $ruleOptionNames);
    }

    #[Test]
    public function allOptionIsRegisteredAsValueNone(): void
    {
        $command = new Command('test');
        $registry = new RuleRegistry([]);

        CheckCommandDefinition::addOptions($command, $registry);

        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('all'));

        $option = $definition->getOption('all');
        self::assertFalse($option->acceptValue(), '--all should be VALUE_NONE (boolean flag)');
    }
}
