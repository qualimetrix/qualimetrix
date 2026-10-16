<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Ast\SuperglobalRead;

#[CoversClass(SuperglobalRead::class)]
final class SuperglobalReadTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function forms(): iterable
    {
        yield 'direct' => ['$_GET', '_GET'];
        yield 'literal variable variable' => ["\${'_POST'}", '_POST'];
        yield 'double quoted variable variable' => ['${"_GET"}', '_GET'];
        yield 'concatenated variable variable' => ["\${'_' . 'REQUEST'}", '_REQUEST'];
        yield 'GLOBALS key' => ["\$GLOBALS['_COOKIE']", '_COOKIE'];
        yield 'GLOBALS concatenated key' => ["\$GLOBALS['_' . 'GET']", '_GET'];
        yield 'GLOBALS double quoted key' => ['$GLOBALS["_GET"]', '_GET'];
        yield 'GLOBALS direct' => ['$GLOBALS', 'GLOBALS'];
        yield 'unknown variable variable' => ['$$name', null];
        yield 'unknown GLOBALS key' => ['$GLOBALS[$name]', null];
        yield 'unknown name' => ['$ordinary', null];
        yield 'case differs' => ['$_get', null];
    }

    #[Test]
    #[DataProvider('forms')]
    public function itRecognizesOnlyFiniteAstNames(string $source, ?string $expected): void
    {
        $expression = $this->expression($source);
        $read = SuperglobalRead::in($expression);
        self::assertSame($expected, $read?->name);
        if ($expression instanceof Variable) {
            self::assertSame($expected, SuperglobalRead::ofVariable($expression));
        }
        if ($read !== null) {
            self::assertSame($expression, $read->node);
        }
    }

    private function expression(string $source): Expr
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse("<?php {$source};") ?? [];
        self::assertInstanceOf(Expression::class, $statements[0] ?? null);

        return $statements[0]->expr;
    }

}
