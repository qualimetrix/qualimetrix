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
}
