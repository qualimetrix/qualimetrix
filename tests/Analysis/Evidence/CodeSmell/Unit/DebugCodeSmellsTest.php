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

    #[Test]
    public function itAppliesThePositionalReturnFlagOnlyToFunctionsThatHaveOne(): void
    {
        // var_dump(), dd() and dump() print every argument: a second `true` is one more printed value.
        $calls = (new NodeFinder())->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php var_dump($x, true); dd($x, true); dump($x, true); print_r($x, true); var_export($x, TRUE); print_r($x, return: true); var_export($x, false);') ?? [],
            FuncCall::class,
        );
        $smells = new DebugCodeSmells();

        $flagged = [];
        foreach ($calls as $call) {
            $flagged[] = $smells->location($call, null, 'file')?->extra;
        }

        self::assertSame(['var_dump', 'dd', 'dump', null, null, null, 'var_export'], $flagged);
    }

    #[Test]
    public function itDoesNotDetectDebugBacktraceBecauseItOnlyReturnsData(): void
    {
        $calls = (new NodeFinder())->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php debug_backtrace(); debug_print_backtrace();') ?? [],
            FuncCall::class,
        );
        $smells = new DebugCodeSmells();

        self::assertNull($smells->location($calls[0], null, 'file'));
        self::assertSame('debug_print_backtrace', $smells->location($calls[1], null, 'file')?->extra);
    }

    #[Test]
    public function itDoesNotResolveAnImportedFunctionAlias(): void
    {
        // Known limit: the collection pipeline runs no name resolver, so an alias is read as written.
        $calls = (new NodeFinder())->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php use function var_dump as vd; vd($x);') ?? [],
            FuncCall::class,
        );

        self::assertNull((new DebugCodeSmells())->location($calls[0], null, 'file'));
    }
}
