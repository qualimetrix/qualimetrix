<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use ReflectionClass;

final class RuleRegistryTest extends TestCase
{
    // The registry deliberately exposes no rule factory: rules may declare
    // constructor dependencies beyond their Options object (LayerViolationRule
    // takes the capability-owned ArchitecturePolicy), so only the DI container may
    // build them. Instance wiring is covered by RulesCommandWiringTest.

    #[Test]
    public function itReturnsRegisteredRuleClassNames(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ClassCountRule::class,
        ]);

        $classes = $registry->getClasses();
        self::assertCount(2, $classes);
        self::assertSame(ComplexityRule::class, $classes[0]);
        self::assertSame(ClassCountRule::class, $classes[1]);
        foreach ($classes as $class) {
            self::assertTrue((new ReflectionClass($class))->implementsInterface(RuleDefinitionInterface::class));
        }
    }

    #[Test]
    public function itCollectsCliAliasesFromAllRulesUsingReflection(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ClassCountRule::class,
        ]);

        $aliases = $registry->getAllCliAliases();

        // ComplexityRule defines: cyclomatic-warning, cyclomatic-error (for method level)
        self::assertArrayHasKey('cyclomatic-warning', $aliases);
        self::assertArrayHasKey('cyclomatic-error', $aliases);
        self::assertSame('complexity.cyclomatic', $aliases['cyclomatic-warning']['rule']);
        self::assertSame('method.warning', $aliases['cyclomatic-warning']['option']);

        // ClassCountRule defines: class-count-warning, class-count-error
        self::assertArrayHasKey('class-count-warning', $aliases);
        self::assertArrayHasKey('class-count-error', $aliases);
        self::assertSame('size.class-count', $aliases['class-count-warning']['rule']);
        self::assertSame('warning', $aliases['class-count-warning']['option']);
    }

    #[Test]
    public function itThrowsWhenTwoRulesShareACliAlias(): void
    {
        // Use two instances of the same rule class to create conflict
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ComplexityRule::class,
        ]);

        self::expectException(ConflictingCliAliasException::class);
        self::expectExceptionMessage('CLI alias "cyclomatic-warning" is defined by both "complexity.cyclomatic" and "complexity.cyclomatic" rules');

        $registry->getAllCliAliases();
    }

    #[Test]
    public function itReturnsEmptyResultsForAnEmptyRegistry(): void
    {
        $registry = new RuleRegistry([]);

        self::assertSame([], $registry->getClasses());
        self::assertSame([], $registry->getAllCliAliases());
    }

    #[Test]
    public function itReadsTheNameConstantWithoutInstantiatingRules(): void
    {
        // This test verifies that getAllCliAliases uses reflection to get NAME constant
        // Both rules have NAME constant, so no instances should be created for metadata
        $registry = new RuleRegistry([
            ComplexityRule::class,
        ]);

        $aliases = $registry->getAllCliAliases();

        // Verify the NAME constant is used correctly
        self::assertSame(ComplexityRule::NAME, $aliases['cyclomatic-warning']['rule']);
    }
}
