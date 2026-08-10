<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell\RepeatedExpression;

use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Ternary;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\RepeatedExpression\RepeatedExpressions;

#[CoversClass(RepeatedExpressions::class)]
final class RepeatedExpressionsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideOperatorCases')]
    public function itUsesTheOfficialSigilAsTheClosedOperatorBoundary(string $operator, bool $expected): void
    {
        $code = \in_array($operator, ['and', 'or', 'xor'], true)
            ? "<?php if (\$value {$operator} \$value) {}"
            : "<?php \$result = \$value {$operator} \$value;";
        $binary = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse($code) ?? [],
            BinaryOp::class,
        );
        self::assertInstanceOf(BinaryOp::class, $binary);
        self::assertSame($operator, $binary->getOperatorSigil());
        self::assertSame($expected, (new RepeatedExpressions())->findings($binary, 'file') !== []);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideOperatorCases(): iterable
    {
        foreach (['===', '==', '!==', '!=', '>', '<', '>=', '<=', '<=>', '&&', '||', 'and', 'or', 'xor', '-', '/', '%', '^', '??'] as $operator) {
            yield "suspicious {$operator}" => [$operator, true];
        }
        foreach (['+', '*', '.', '&', '|', '<<', '>>', '**'] as $operator) {
            yield "safe {$operator}" => [$operator, false];
        }
    }

    #[Test]
    public function itUsesTheCorrectTernaryBranchAndRejectsSideEffects(): void
    {
        $finder = new NodeFinder();
        $ternaries = $finder->findInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php $a = $x ?: $x; $b = foo() ? foo() : foo();') ?? [],
            Ternary::class,
        );
        $expressions = new RepeatedExpressions();

        self::assertCount(1, $expressions->findings($ternaries[0], 'file'));
        self::assertSame([], $expressions->findings($ternaries[1], 'file'));
    }

    #[Test]
    public function itComparesScalarsArraysAndNodesIterativelyWithoutAttributes(): void
    {
        $expressions = new RepeatedExpressions();
        self::assertTrue($expressions->areEqual(['key' => [1, 'x']], ['key' => [1, 'x']]));
        self::assertFalse($expressions->areEqual(['key' => 1], ['other' => 1]));
        self::assertFalse($expressions->areEqual(['key' => 1], ['key' => 1, 'other' => 2]));
        self::assertFalse($expressions->areEqual(['key' => 1], ['key' => 2]));
        self::assertFalse($expressions->areEqual(1, '1'));

        $nodes = (new NodeFinder())->findInstanceOf((new ParserFactory())->createForHostVersion()->parse('<?php $left === $right; $left === $right;') ?? [], BinaryOp::class);
        $nodes[0]->left->setAttribute('ignored', 'left');
        self::assertTrue($expressions->areEqual($nodes[0]->left, $nodes[1]->left));
        self::assertFalse($expressions->areEqual($nodes[0]->left, $nodes[0]->right));
        self::assertFalse($expressions->areEqual($this->binary('$value === 1')->left, $this->binary('$value === 1')->right));
    }

    #[Test]
    #[DataProvider('provideSideEffects')]
    public function itRejectsEveryEnumeratedSideEffect(string $expression): void
    {
        self::assertSame([], (new RepeatedExpressions())->findings($this->binary("{$expression} === {$expression}"), 'file'));
    }

    /** @return iterable<string, array{string}> */
    public static function provideSideEffects(): iterable
    {
        foreach ([
            'call()', '$object->call()', 'Type::call()', '$object?->call()', 'new Type()', 'yield $value', 'yield from $values',
            '++$value', '--$value', '$value++', '$value--', '$value = 1', '$value += 1', '$value =& $other', '`command`',
            'eval($code)', 'exit', 'print $value', 'include $file', 'throw $exception',
        ] as $expression) {
            yield $expression => [$expression];
        }
    }

    private function binary(string $expression): BinaryOp
    {
        $binary = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse("<?php {$expression};") ?? [],
            BinaryOp::class,
        );
        self::assertInstanceOf(BinaryOp::class, $binary);

        return $binary;
    }
}
