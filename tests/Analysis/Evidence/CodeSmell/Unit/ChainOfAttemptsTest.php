<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow\ChainOfAttempts;

#[CoversClass(ChainOfAttempts::class)]
final class ChainOfAttemptsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideChainAttemptShapes(): iterable
    {
        yield 'return ends the try' => ['<?php foreach ($a as $x) { try { return work($x); } catch (Throwable) {} }', 1];
        yield 'continue skips a fallback' => ['<?php foreach ($a as $x) { try { work($x); continue; } catch (Throwable) {} fallback($x); }', 1];
        yield 'continue with nothing to skip' => ['<?php foreach ($a as $x) { try { work($x); continue; } catch (Throwable) {} }', 0];
        yield 'comment after the try skips nothing' => ["<?php foreach (\$a as \$x) { try { work(\$x); continue; } catch (Throwable) {}\n// done\n}", 0];
        yield 'return in a branch, more work after it' => ['<?php foreach ($a as $x) { try { check($x); if (valid()) { return; } collect(); } catch (Throwable) {} }', 1];
        yield 'continue in a branch skips a fallback' => ['<?php foreach ($a as $x) { try { if ($x) { work($x); continue; } } catch (Throwable) {} fallback($x); }', 1];
        yield 'continue in a branch with nothing to skip' => ['<?php foreach ($a as $x) { try { if ($x) { work($x); continue; } other(); } catch (Throwable) {} }', 0];
        yield 'no exit at all' => ['<?php foreach ($a as $x) { try { work($x); } catch (Throwable) {} if ($ok) { work(); } }', 0];
        yield 'try nested below the loop body' => ['<?php foreach ($a as $x) { if ($x) { try { return work($x); } catch (Throwable) {} } }', 0];
        yield 'break is not an attempt exit' => ['<?php foreach ($a as $x) { try { work($x); break; } catch (Throwable) {} }', 0];
    }

    #[Test]
    #[DataProvider('provideChainAttemptShapes')]
    public function itRecognizesOnlyTheChainAttemptShape(string $code, int $expected): void
    {
        self::assertCount($expected, (new ChainOfAttempts())->attempts($this->firstForeach($code)));
    }

    private function firstForeach(string $code): Foreach_
    {
        $loop = (new NodeFinder())->findFirstInstanceOf((new ParserFactory())->createForHostVersion()->parse($code) ?? [], Foreach_::class);
        self::assertInstanceOf(Foreach_::class, $loop);

        return $loop;
    }
}
