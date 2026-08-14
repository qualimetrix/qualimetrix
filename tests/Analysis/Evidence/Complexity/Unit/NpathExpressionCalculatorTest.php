<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Complexity\Unit;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Complexity\NpathExpressionCalculator;

#[CoversClass(NpathExpressionCalculator::class)]
final class NpathExpressionCalculatorTest extends TestCase
{
    private NpathExpressionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new NpathExpressionCalculator();
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideTransparentExpressions(): iterable
    {
        yield 'arithmetic binary operation' => ['($flag ? 1 : 0) + 1', 2];
        yield 'comparison binary operation' => ['($flag ? 1 : 0) === 1', 2];
        yield 'assignment right-hand side' => ['$value = ($flag ? 1 : 0)', 2];
        yield 'dynamic assignment target and value' => ['${$left ? "a" : "b"} = ($right ? 1 : 0)', 4];
        yield 'array item key and value' => ['[($key ? 1 : 0) => ($value ? 1 : 0)]', 4];
        yield 'array unpack item' => ['[...($flag ? $first : $second)]', 2];
        yield 'function argument' => ['consume($flag ? 1 : 0)', 2];
        yield 'dynamic call name and argument' => ['($callable ? $a : $b)($argument ? 1 : 0)', 4];
        yield 'method receiver and argument' => ['($receiver ? $a : $b)->run($argument ? 1 : 0)', 4];
        yield 'cast' => ['(int) ($flag ? 1 : 0)', 2];
        yield 'interpolated array fetch' => ['"value: {$items[$flag ? 1 : 0]}"', 2];
        yield 'shell interpolation' => ['`echo ${$flag ? "a" : "b"}`', 2];
        yield 'array fetch' => ['$items[$flag ? 1 : 0]', 2];
        yield 'dynamic property fetch' => ['$object->{$flag ? "a" : "b"}', 2];
        yield 'new argument' => ['new Example($flag ? 1 : 0)', 2];
        yield 'clone operand' => ['clone ($flag ? $a : $b)', 2];
        yield 'isset operand' => ['isset($items[$flag ? 1 : 0])', 2];
        yield 'empty operand' => ['empty($items[$flag ? 1 : 0])', 2];
        yield 'error suppression' => ['@($flag ? foo() : bar())', 2];
        yield 'eval operand' => ['eval($flag ? $a : $b)', 2];
        yield 'exit operand' => ['exit($flag ? 1 : 0)', 2];
        yield 'include operand' => ['include ($flag ? $a : $b)', 2];
        yield 'instanceof left operand' => ['($flag ? $a : $b) instanceof Example', 2];
        yield 'pre-increment dynamic variable' => ['++${$flag ? "a" : "b"}', 2];
        yield 'print operand' => ['print ($flag ? 1 : 0)', 2];
        yield 'throw operand' => ['throw ($flag ? $a : $b)', 2];
        yield 'yield operand' => ['yield ($flag ? 1 : 0)', 2];
        yield 'yield-from operand' => ['yield from ($flag ? $first : $second)', 2];
        yield 'list assignment' => ['list($target) = [$flag ? 1 : 0]', 2];
        yield 'dynamic variable name' => ['${$flag ? "a" : "b"}', 2];
    }

    #[Test]
    #[DataProvider('provideTransparentExpressions')]
    public function itPreservesDecisionContributionsUnderTransparentWrappers(string $code, int $expected): void
    {
        self::assertSame($expected, $this->calculator->calculate($this->parseExpression($code)));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideNullsafeChains(): iterable
    {
        yield 'ordinary access' => ['$service->find()', 0];
        yield 'one nullsafe hop' => ['$service?->find()', 1];
        yield 'two nullsafe hops' => ['$service?->find()?->name', 2];
        yield 'nullsafe hop and nested argument decision' => ['$service?->find($flag ? 1 : 0)', 3];
    }

    #[Test]
    #[DataProvider('provideNullsafeChains')]
    public function itKeepsNullsafeAccessesAsZeroBasedExpressionContributions(string $code, int $expected): void
    {
        self::assertSame($expected, $this->calculator->calculate($this->parseExpression($code)));
    }

    /**
     * @return iterable<string, array{string, int, int, int}>
     */
    public static function provideSeparatedNullsafeContributions(): iterable
    {
        yield 'one root hop' => ['$service?->find()', 0, 1, 1];
        yield 'two root hops' => ['$service?->find()?->name', 0, 2, 2];
        yield 'ordinary call wrapper' => ['consume($service?->find())', 0, 1, 1];
        yield 'assignment wrapper' => ['$value = $service?->find()', 0, 1, 1];
        yield 'ordinary property wrapper' => ['$service?->find()->name', 0, 1, 1];
        yield 'coalesce wrapper' => ['$service?->find() ?? $fallback', 1, 1, 2];
        yield 'ternary wrapper' => ['$flag ? $service?->find() : $fallback', 2, 1, 3];
        yield 'root nullsafe with ternary argument' => ['$service?->find($flag ? 1 : 0)', 2, 1, 3];
        yield 'root nullsafe with coalesce argument' => ['$service?->find($fallback ?? "default")', 1, 1, 2];
        yield 'nested arrow is opaque' => ['consume(fn () => $service?->find())', 0, 0, 0];
        yield 'anonymous class body is opaque' => [
            'new class { public function run(): mixed { return $service?->find(); } }',
            0,
            0,
            0,
        ];
    }

    #[Test]
    #[DataProvider('provideSeparatedNullsafeContributions')]
    public function itSeparatesNullsafeContributionsWithoutChangingThePublicTotal(
        string $code,
        int $ordinary,
        int $nullsafe,
        int $total,
    ): void {
        $expression = $this->parseExpression($code);

        self::assertSame(
            ['ordinary' => $ordinary, 'nullsafe' => $nullsafe],
            $this->calculator->calculateContributions($expression),
        );
        self::assertSame($total, $this->calculator->calculate($expression));
    }

    #[Test]
    public function itCountsSimpleMatchArmsAndTheirConditionsWithoutDoubleCountingBodies(): void
    {
        $expression = $this->parseExpression(
            'match ($subject ? 1 : 0) {'
            . '($first ? 1 : 0) => ($body ? 1 : 0),'
            . '2 => 2,'
            . 'default => 3,'
            . '}',
        );

        // subject(2) + first condition(2) + bodies(2 + 1 + 1)
        self::assertSame(8, $this->calculator->calculate($expression));
    }

    #[Test]
    public function itDoesNotLeakNestedCallableOrAnonymousClassBodies(): void
    {
        self::assertSame(
            2,
            $this->calculator->calculate($this->parseExpression(
                'consume('
                . 'fn () => $arrow ? 1 : 0,'
                . 'function () { return $closure ? 1 : 0; },'
                . 'new class($argument ? 1 : 0) {'
                . 'public function nested() { return $method ? 1 : 0; }'
                . '},'
                . ')',
            )),
        );
    }

    #[Test]
    public function itSaturatesAdditionAndMultiplicationAtTheMaximum(): void
    {
        self::assertSame(
            NpathExpressionCalculator::MAX_NPATH,
            $this->calculator->saturatingAdd(NpathExpressionCalculator::MAX_NPATH - 1, 2),
        );
        self::assertSame(
            NpathExpressionCalculator::MAX_NPATH,
            $this->calculator->saturatingMultiply(50_000, 50_000),
        );
    }

    private function parseExpression(string $expression): Expr
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse("<?php return {$expression};");
        self::assertNotNull($ast);
        self::assertInstanceOf(Return_::class, $ast[0]);
        self::assertInstanceOf(Expr::class, $ast[0]->expr);

        return $ast[0]->expr;
    }
}
