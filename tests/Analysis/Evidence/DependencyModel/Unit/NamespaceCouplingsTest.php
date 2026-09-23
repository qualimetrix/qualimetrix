<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\NamespaceCouplings;
use Qualimetrix\Analysis\Evidence\DependencyModel\StringSet;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(NamespaceCouplings::class)]
final class NamespaceCouplingsTest extends TestCase
{
    /**
     * The four numbers are one row per namespace precisely so that a reader
     * cannot take an efferent count from one region and an afferent count from
     * the other: all four here are deliberately distinct, so any crossed wire
     * shows up as a number belonging to the wrong scope rather than as a
     * plausible one.
     */
    #[Test]
    public function itKeepsTheSubtreeAndOwnScopesApart(): void
    {
        $namespace = SymbolPath::forNamespace('App\\Service');
        $key = $namespace->toCanonical();

        $couplings = NamespaceCouplings::fromScopes(
            [$key => StringSet::fromArray(['a', 'b', 'c', 'd'])],
            [$key => StringSet::fromArray(['e', 'f', 'g'])],
            [$key => StringSet::fromArray(['h', 'i'])],
            [$key => StringSet::fromArray(['j'])],
        );

        self::assertSame(4, $couplings->subtreeCe($namespace));
        self::assertSame(3, $couplings->subtreeCa($namespace));
        self::assertSame(2, $couplings->ownCe($namespace));
        self::assertSame(1, $couplings->ownCa($namespace));
    }

    /** A namespace the index never heard of is not an error; it crosses no boundary. */
    #[Test]
    public function itAnswersZeroForANamespaceItDoesNotHold(): void
    {
        $couplings = NamespaceCouplings::none();
        $namespace = SymbolPath::forNamespace('App\\Absent');

        self::assertSame(0, $couplings->subtreeCe($namespace));
        self::assertSame(0, $couplings->subtreeCa($namespace));
        self::assertSame(0, $couplings->ownCe($namespace));
        self::assertSame(0, $couplings->ownCa($namespace));
    }

    /**
     * The rollup pass names parent namespaces the own-scope pass never saw, so
     * the two sets of keys genuinely differ; a namespace present in one scope
     * only must answer zero in the other rather than falling out of the index.
     */
    #[Test]
    public function itHoldsANamespaceOnlyOneScopeNames(): void
    {
        $parent = SymbolPath::forNamespace('App');
        $leaf = SymbolPath::forNamespace('App\\Service');

        $couplings = NamespaceCouplings::fromScopes(
            [$parent->toCanonical() => StringSet::fromArray(['a', 'b'])],
            [],
            [$leaf->toCanonical() => StringSet::fromArray(['c'])],
            [],
        );

        self::assertSame(2, $couplings->subtreeCe($parent));
        self::assertSame(0, $couplings->ownCe($parent));
        self::assertSame(0, $couplings->subtreeCe($leaf));
        self::assertSame(1, $couplings->ownCe($leaf));
    }
}
