<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\Debug\DebugCodeSmells;

#[CoversClass(DebugCodeSmells::class)]
final class DebugCodeSmellsTest extends TestCase
{
    #[Test]
    public function itRecognizesOutputCallsButNotReturnModeOrDebugApis(): void
    {
        $calls = (new NodeFinder())->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php var_dump($x); var_export($x, true); function dump() { dd($x); }') ?? [],
            FuncCall::class,
        );
        $smells = new DebugCodeSmells();

        self::assertSame('debug_code', $smells->location($calls[0], null, 'file')?->type);
        self::assertNull($smells->location($calls[1], null, 'file'));
        self::assertNull($smells->location($calls[2], 'dump', 'file'));
    }

    #[Test]
    public function itDetectsDebugZvalDump(): void
    {
        $calls = (new NodeFinder())->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php debug_zval_dump($x);') ?? [],
            FuncCall::class,
        );
        $smells = new DebugCodeSmells();

        $location = $smells->location($calls[0], null, 'file');

        self::assertNotNull($location);
        self::assertSame('debug_code', $location->type);
    }
}
