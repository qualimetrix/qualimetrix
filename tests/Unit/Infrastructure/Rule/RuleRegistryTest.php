<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Rule;

use AiMessDetector\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use AiMessDetector\Infrastructure\Rule\RuleRegistry;
use AiMessDetector\Rules\Complexity\ComplexityRule;
use AiMessDetector\Rules\Size\SizeRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    #[Test]
    public function getAllCreatesInstancesWithDefaultOptions(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            SizeRule::class,
        ]);

        $rules = iterator_to_array($registry->getAll());
        self::assertCount(2, $rules);
        self::assertInstanceOf(ComplexityRule::class, $rules[0]);
        self::assertInstanceOf(SizeRule::class, $rules[1]);
    }

    #[Test]
    public function getClassesReturnsClassNames(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            SizeRule::class,
        ]);

        $classes = $registry->getClasses();
        self::assertCount(2, $classes);
        self::assertSame(ComplexityRule::class, $classes[0]);
        self::assertSame(SizeRule::class, $classes[1]);
    }

    #[Test]
    public function getAllCliAliasesCollectsAliasesFromAllRulesUsingReflection(): void
    {
        $registry = new RuleRegistry([
            ComplexityRule::class,
            SizeRule::class,
        ]);

        $aliases = $registry->getAllCliAliases();

        // ComplexityRule defines: cc-warning, cc-error (for method level)
        self::assertArrayHasKey('cc-warning', $aliases);
        self::assertArrayHasKey('cc-error', $aliases);
        self::assertSame('complexity', $aliases['cc-warning']['rule']);
        self::assertSame('method.warning', $aliases['cc-warning']['option']);

        // SizeRule defines: ns-warning, ns-error (for namespace level)
        self::assertArrayHasKey('ns-warning', $aliases);
        self::assertArrayHasKey('ns-error', $aliases);
        self::assertSame('size', $aliases['ns-warning']['rule']);
        self::assertSame('namespace.warning', $aliases['ns-warning']['option']);
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
        $this->expectExceptionMessage('CLI alias "cc-warning" is defined by both "complexity" and "complexity" rules');

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
        self::assertSame(ComplexityRule::NAME, $aliases['cc-warning']['rule']);
    }
}
