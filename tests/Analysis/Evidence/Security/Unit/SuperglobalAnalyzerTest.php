<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Security\SuperglobalAnalyzer;

#[CoversClass(SuperglobalAnalyzer::class)]
final class SuperglobalAnalyzerTest extends TestCase
{
    private SuperglobalAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SuperglobalAnalyzer();
    }

    #[Test]
    #[DataProvider('provideExpressions')]
    public function itFindsTheSuperglobalWhoseValueTheExpressionCarries(string $expression, ?string $expected): void
    {
        self::assertSame($expected, $this->analyzer->findSuperglobal($this->parseExpression($expression)));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideExpressions(): iterable
    {
        yield '$_GET' => ['$_GET', '_GET'];
        yield '$_POST element' => ['$_POST["a"]', '_POST'];
        yield '$_REQUEST nested element' => ['$_REQUEST["a"]["b"]', '_REQUEST'];
        yield '$_COOKIE element' => ['$_COOKIE["a"]', '_COOKIE'];
        yield 'concat, superglobal on the right' => ['"a" . $_GET["x"]', '_GET'];
        yield 'nested concat' => ['"a" . ("b" . $_POST["x"])', '_POST'];
        yield 'interpolation' => ['"a {$_GET[\'x\']}"', '_GET'];
        yield 'coalesce, superglobal on the left' => ['$_GET["x"] ?? "d"', '_GET'];
        yield 'coalesce, superglobal as fallback' => ['$x ?? $_GET["x"]', '_GET'];
        yield 'ternary if branch' => ['$c ? $_GET["x"] : "d"', '_GET'];
        yield 'ternary else branch' => ['$c ? "d" : $_GET["x"]', '_GET'];
        yield 'short ternary condition is its value' => ['$_GET["x"] ?: "d"', '_GET'];
        yield 'string cast' => ['(string) $_GET["x"]', '_GET'];
        yield 'error suppression' => ['@$_GET["x"]', '_GET'];
        yield 'assignment' => ['$y = $_GET["x"]', '_GET'];
        yield 'match arm result' => ['match ($m) { 1 => "a", default => $_GET["x"] }', '_GET'];
        yield 'element of a coalesced superglobal' => ['($_GET["x"] ?? [])["y"]', '_GET'];
        yield 'first of two superglobals' => ['$_POST["a"] . $_GET["b"]', '_POST'];

        yield '$_SESSION is not user input' => ['$_SESSION["x"]', null];
        yield '$_SERVER is not user input' => ['$_SERVER["x"]', null];
        yield '$GLOBALS is not user input' => ['$GLOBALS["x"]', null];
        yield 'regular variable' => ['$x', null];
        yield 'full ternary condition is not its value' => ['$_GET["x"] ? "a" : "b"', null];
        yield 'match subject is not its value' => ['match ($_GET["x"]) { default => "a" }', null];
        yield 'int cast' => ['(int) $_GET["x"]', null];
        yield 'float cast' => ['(float) $_GET["x"]', null];
        yield 'sanitizer call' => ['htmlspecialchars($_GET["x"])', null];
        yield 'any other call' => ['trim($_GET["x"])', null];
        yield 'method call' => ['$f->clean($_GET["x"])', null];
        yield 'array key' => ['$map[$_GET["x"]]', null];
        yield 'isset' => ['isset($_GET["x"])', null];
        yield 'comparison' => ['$_GET["x"] === "a"', null];
    }

    #[Test]
    public function itFindsTheSuperglobalInBacktickCommandParts(): void
    {
        $shellExec = $this->parseExpression('`ls {$_COOKIE[\'d\']}`');
        self::assertInstanceOf(ShellExec::class, $shellExec);

        self::assertSame('_COOKIE', $this->analyzer->findSuperglobalInParts($shellExec->parts));
    }

    #[Test]
    public function itFindsNothingInBacktickCommandPartsWithoutASuperglobal(): void
    {
        $shellExec = $this->parseExpression('`ls {$dir}`');
        self::assertInstanceOf(ShellExec::class, $shellExec);

        self::assertNull($this->analyzer->findSuperglobalInParts($shellExec->parts));
    }

    private function parseExpression(string $expression): Expr
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse("<?php {$expression};") ?? [];
        self::assertInstanceOf(Expression::class, $statements[0] ?? null);

        return $statements[0]->expr;
    }

    // --- flattenConcat ---

    #[Test]
    public function itFlattensSimpleConcat(): void
    {
        $concat = new Concat(new String_('a'), new String_('b'));

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(2, $parts);
    }

    #[Test]
    public function itFlattensNestedConcat(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new String_('c'),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(3, $parts);
    }

    #[Test]
    public function itFlattensDeeplyNestedConcat(): void
    {
        $concat = new Concat(
            new Concat(new String_('a'), new String_('b')),
            new Concat(new String_('c'), new String_('d')),
        );

        $parts = $this->analyzer->flattenConcat($concat);

        self::assertCount(4, $parts);
    }
}
