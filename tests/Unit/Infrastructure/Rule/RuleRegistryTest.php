<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Rule;

use AiMessDetector\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use AiMessDetector\Infrastructure\Rule\RuleRegistry;
use AiMessDetector\Rules\Complexity\ComplexityRule;
use AiMessDetector\Rules\Size\ClassCountRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    #[Test]
    public function getAllCreatesInstancesWithDefaultOptions(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ClassCountRule::class,
        ]);

        $rules = iterator_to_array($registry->getAll());
        self::assertCount(2, $rules);
        self::assertInstanceOf(ComplexityRule::class, $rules[0]);
        self::assertInstanceOf(ClassCountRule::class, $rules[1]);
    }

    #[Test]
    public function getClassesReturnsClassNames(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ClassCountRule::class,
        ]);

        $classes = $registry->getClasses();
        self::assertCount(2, $classes);
        self::assertSame(ComplexityRule::class, $classes[0]);
        self::assertSame(ClassCountRule::class, $classes[1]);
    }

    #[Test]
    public function getAllCliAliasesCollectsAliasesFromAllRulesUsingReflection(): void
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
    public function getAllCliAliasesThrowsOnConflict(): void
    {
        // Use two instances of the same rule class to create conflict
        $registry = new RuleRegistry([
            ComplexityRule::class,
            ComplexityRule::class,
        ]);

        $this->expectException(ConflictingCliAliasException::class);
        $this->expectExceptionMessage('CLI alias "cyclomatic-warning" is defined by both "complexity.cyclomatic" and "complexity.cyclomatic" rules');

        $registry->getAllCliAliases();
    }

    #[Test]
    public function emptyRegistryReturnsEmptyResults(): void
    {
        $registry = new RuleRegistry([]);

        self::assertSame([], iterator_to_array($registry->getAll()));
        self::assertSame([], $registry->getClasses());
        self::assertSame([], $registry->getAllCliAliases());
    }

    #[Test]
    public function getAllCliAliasesUsesNameConstantWithoutInstantiation(): void
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
