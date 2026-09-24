<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\BooleanArgument\BooleanArgumentSmells;

#[CoversClass(BooleanArgumentSmells::class)]
final class BooleanArgumentSmellsTest extends TestCase
{
    #[Test]
    public function itPreservesPromotedPropertyEvidence(): void
    {
        $method = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php class C { function __construct(bool $ordinary, public bool $promoted) {} }') ?? [],
            ClassMethod::class,
        );
        self::assertInstanceOf(ClassMethod::class, $method);

        $locations = (new BooleanArgumentSmells())->locations($method, 'file');
        self::assertSame(['ordinary', 'promoted'], array_map(static fn($location): string => (string) $location->extra, $locations));
        self::assertSame([false, true], array_map(static fn($location): bool => (bool) $location->promoted, $locations));
    }

    #[Test]
    public function itReadsTheTypeDeclarationOnly(): void
    {
        // Known limit: the declared type is the evidence; an untyped flag or a `true`-only type is not a bool switch here.
        $method = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php class C { /** @param bool $doc */ function f($untyped = false, $doc = null, true $literal = true, ?bool $nullable = null, int|BOOL $union = 0) {} }') ?? [],
            ClassMethod::class,
        );
        self::assertInstanceOf(ClassMethod::class, $method);

        $locations = (new BooleanArgumentSmells())->locations($method, 'file');
        self::assertSame(['nullable', 'union'], array_map(static fn($location): string => (string) $location->extra, $locations));
    }
}
