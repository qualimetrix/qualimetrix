<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\CodeSmell;

use AiMessDetector\Metrics\CodeSmell\IdenticalSubExpressionCollector;
use AiMessDetector\Metrics\CodeSmell\IdenticalSubExpressionFinding;
use AiMessDetector\Metrics\CodeSmell\IdenticalSubExpressionVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(IdenticalSubExpressionVisitor::class)]
#[CoversClass(IdenticalSubExpressionCollector::class)]
#[CoversClass(IdenticalSubExpressionFinding::class)]
final class IdenticalSubExpressionVisitorTest extends TestCase
{
    private IdenticalSubExpressionVisitor $visitor;

    protected function setUp(): void
    {
        $this->visitor = new IdenticalSubExpressionVisitor();
    }

    // ── Identical Operands ───────────────────────────────────────────

    public function testIdenticalComparisonOperands(): void
    {
        $code = <<<'PHP'
<?php
$result = $a === $a;
PHP;

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertSame('identical_operands', $findings[0]->type);
        self::assertSame(2, $findings[0]->line);
        self::assertStringContainsString('===', $findings[0]->detail);
    }

    public function testIdenticalEqualOperands(): void
    {
        $code = <<<'PHP'
<?php
$result = $a == $a;
PHP;

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertStringContainsString('==', $findings[0]->detail);
    }

    public function testIdenticalNotIdenticalOperands(): void
    {
        $code = <<<'PHP'
<?php
$result = $a !== $a;
PHP;

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertStringContainsString('!==', $findings[0]->detail);
    }

    public function testIdenticalGreaterOperands(): void
    {
        $code = '<?php $result = $a > $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalSmallerOperands(): void
    {
        $code = '<?php $result = $a < $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalGreaterOrEqualOperands(): void
    {
        $code = '<?php $result = $a >= $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalSmallerOrEqualOperands(): void
    {
        $code = '<?php $result = $a <= $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalSpaceshipOperands(): void
    {
        $code = '<?php $result = $a <=> $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalBooleanAndOperands(): void
    {
        $code = '<?php $result = $a && $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalBooleanOrOperands(): void
    {
        $code = '<?php $result = $a || $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalLogicalAndOperands(): void
    {
        // Parentheses needed because `and` has lower precedence than `=`
        $code = '<?php if ($a and $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalLogicalOrOperands(): void
    {
        $code = '<?php if ($a or $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalLogicalXorOperands(): void
    {
        $code = '<?php if ($a xor $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalMinusOperands(): void
    {
        $code = '<?php $result = $a - $a;';
        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertStringContainsString('-', $findings[0]->detail);
    }

    public function testIdenticalDivOperands(): void
    {
        $code = '<?php $result = $a / $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalModOperands(): void
    {
        $code = '<?php $result = $a % $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalBitwiseXorOperands(): void
    {
        $code = '<?php $result = $a ^ $a;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testIdenticalCoalesceOperands(): void
    {
        $code = '<?php $result = $a ?? $a;';
        self::assertCount(1, $this->analyze($code));
    }

    // ── Operators that should NOT be flagged ─────────────────────────

    public function testPlusNotFlagged(): void
    {
        $code = '<?php $result = $a + $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testMultiplyNotFlagged(): void
    {
        $code = '<?php $result = $a * $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testConcatNotFlagged(): void
    {
        $code = '<?php $result = $a . $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testBitwiseAndNotFlagged(): void
    {
        $code = '<?php $result = $a & $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testBitwiseOrNotFlagged(): void
    {
        $code = '<?php $result = $a | $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testShiftLeftNotFlagged(): void
    {
        $code = '<?php $result = $a << $a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testShiftRightNotFlagged(): void
    {
        $code = '<?php $result = $a >> $a;';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Side effects — should NOT be flagged ────────────────────────

    public function testFunctionCallNotFlagged(): void
    {
        $code = '<?php $result = foo() === foo();';
        self::assertCount(0, $this->analyze($code));
    }

    public function testMethodCallNotFlagged(): void
    {
        $code = '<?php $result = $obj->get() === $obj->get();';
        self::assertCount(0, $this->analyze($code));
    }

    public function testStaticCallNotFlagged(): void
    {
        $code = '<?php $result = Foo::bar() === Foo::bar();';
        self::assertCount(0, $this->analyze($code));
    }

    public function testPreIncrementNotFlagged(): void
    {
        $code = '<?php $result = ++$a === ++$a;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testNewNotFlagged(): void
    {
        $code = '<?php $result = new Foo() === new Foo();';
        self::assertCount(0, $this->analyze($code));
    }

    public function testNestedFunctionCallNotFlagged(): void
    {
        $code = '<?php $result = ($a + foo()) === ($a + foo());';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Pure complex expressions — SHOULD be flagged ─────────────────

    public function testPropertyAccessFlagged(): void
    {
        $code = '<?php $result = $obj->prop === $obj->prop;';
        self::assertCount(1, $this->analyze($code));
    }

    public function testArrayAccessFlagged(): void
    {
        $code = '<?php $result = $arr[0] === $arr[0];';
        self::assertCount(1, $this->analyze($code));
    }

    public function testComplexExpressionFlagged(): void
    {
        $code = '<?php $result = ($a + $b * $c) === ($a + $b * $c);';
        self::assertCount(1, $this->analyze($code));
    }

    // ── Different operands — should NOT be flagged ──────────────────

    public function testDifferentOperandsNotFlagged(): void
    {
        $code = '<?php $result = $a === $b;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testDifferentComplexExpressionsNotFlagged(): void
    {
        $code = '<?php $result = ($a + $b) === ($a - $b);';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Duplicate Conditions ────────────────────────────────────────

    public function testDuplicateIfElseifCondition(): void
    {
        $code = <<<'PHP'
<?php
if ($x > 0) {
    echo 'a';
} elseif ($x > 0) {
    echo 'b';
}
PHP;

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertSame('duplicate_condition', $findings[0]->type);
        self::assertSame(4, $findings[0]->line);
    }

    public function testDuplicateConditionThreeWay(): void
    {
        $code = <<<'PHP'
<?php
if ($x > 0) {
    echo 'a';
} elseif ($x < 0) {
    echo 'b';
} elseif ($x > 0) {
    echo 'c';
}
PHP;

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame(6, $findings[0]->line);
    }

    public function testNoDuplicateConditions(): void
    {
        $code = <<<'PHP'
<?php
if ($x > 0) {
    echo 'positive';
} elseif ($x < 0) {
    echo 'negative';
} elseif ($x === 0) {
    echo 'zero';
}
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    public function testIfWithoutElseifNotChecked(): void
    {
        $code = '<?php if ($x > 0) { echo "a"; }';
        self::assertCount(0, $this->analyze($code));
    }

    public function testDuplicateComplexCondition(): void
    {
        $code = <<<'PHP'
<?php
if ($a > 0 && $b < 10) {
    echo 'a';
} elseif ($a > 0 && $b < 10) {
    echo 'b';
}
PHP;

        self::assertCount(1, $this->analyze($code));
    }

    // ── Identical Ternary Branches ──────────────────────────────────

    public function testIdenticalTernaryBranches(): void
    {
        $code = '<?php $result = $cond ? $a : $a;';

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
        self::assertSame(1, $findings[0]->line);
    }

    public function testDifferentTernaryBranchesNotFlagged(): void
    {
        $code = '<?php $result = $cond ? $a : $b;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testShortTernaryNotFlagged(): void
    {
        $code = '<?php $result = $a ?: $b;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testTernaryWithSideEffectsNotFlagged(): void
    {
        $code = '<?php $result = $cond ? foo() : foo();';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Duplicate Match Arms ────────────────────────────────────────

    public function testDuplicateMatchArmCondition(): void
    {
        $code = <<<'PHP'
<?php
$result = match ($x) {
    1 => 'a',
    2 => 'b',
    1 => 'c',
};
PHP;

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertSame('duplicate_match_arm', $findings[0]->type);
        self::assertSame(5, $findings[0]->line);
    }

    public function testNoDuplicateMatchArms(): void
    {
        $code = <<<'PHP'
<?php
$result = match ($x) {
    1 => 'a',
    2 => 'b',
    3 => 'c',
};
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    public function testMatchDefaultNotCompared(): void
    {
        $code = <<<'PHP'
<?php
$result = match ($x) {
    1 => 'a',
    default => 'b',
};
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    public function testDuplicateMatchArmComplexCondition(): void
    {
        $code = <<<'PHP'
<?php
$result = match (true) {
    $x > 0 => 'positive',
    $x < 0 => 'negative',
    $x > 0 => 'also positive',
};
PHP;

        self::assertCount(1, $this->analyze($code));
    }

    // ── Edge Cases ──────────────────────────────────────────────────

    public function testMultipleFindingsInSameFile(): void
    {
        $code = <<<'PHP'
<?php
$a = $x === $x;
$b = $y - $y;
$c = $cond ? $z : $z;
PHP;

        self::assertCount(3, $this->analyze($code));
    }

    public function testNestedTernary(): void
    {
        $code = '<?php $result = $cond1 ? ($cond2 ? $a : $a) : $b;';

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
    }

    public function testCleanCode(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Service;

class Calculator
{
    public function calculate(int $a, int $b): int
    {
        if ($a > $b) {
            return $a - $b;
        } elseif ($a < $b) {
            return $b - $a;
        }

        return 0;
    }

    public function format(int $value): string
    {
        return match (true) {
            $value > 0 => 'positive',
            $value < 0 => 'negative',
            default => 'zero',
        };
    }
}
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    public function testReset(): void
    {
        $code1 = '<?php $a = $x === $x;';
        $code2 = '<?php $b = $y + $z;';

        $this->analyze($code1);
        self::assertCount(1, $this->visitor->getFindings());

        $this->visitor->reset();

        $this->analyze($code2);
        self::assertCount(0, $this->visitor->getFindings());
    }

    public function testNullsafeMethodCallNotFlagged(): void
    {
        $code = '<?php $result = $a?->get() === $a?->get();';
        self::assertCount(0, $this->analyze($code));
    }

    public function testYieldNotFlagged(): void
    {
        $code = '<?php function gen() { $result = (yield 1) === (yield 1); }';
        self::assertCount(0, $this->analyze($code));
    }

    public function testAssignNotFlagged(): void
    {
        $code = '<?php $result = ($a = 1) === ($a = 1);';
        self::assertCount(0, $this->analyze($code));
    }

    public function testAssignOpNotFlagged(): void
    {
        $code = '<?php $result = ($a += 1) === ($a += 1);';
        self::assertCount(0, $this->analyze($code));
    }

    public function testShellExecNotFlagged(): void
    {
        $code = '<?php $result = `ls` === `ls`;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testEvalNotFlagged(): void
    {
        $code = '<?php $result = eval("1") === eval("1");';
        self::assertCount(0, $this->analyze($code));
    }

    public function testPrintNotFlagged(): void
    {
        $code = '<?php $result = print("a") === print("a");';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Short Ternary ───────────────────────────────────────────────

    public function testShortTernaryIdentical(): void
    {
        $code = '<?php $result = $a ?: $a;';
        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
    }

    public function testShortTernaryDifferent(): void
    {
        $code = '<?php $result = $a ?: $b;';
        self::assertCount(0, $this->analyze($code));
    }

    public function testShortTernaryWithSideEffectsNotFlagged(): void
    {
        $code = '<?php $result = foo() ?: foo();';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Switch/Case Duplicates ──────────────────────────────────────

    public function testDuplicateSwitchCase(): void
    {
        $code = <<<'PHP'
<?php
switch ($x) {
    case 1:
        echo 'a';
        break;
    case 2:
        echo 'b';
        break;
    case 1:
        echo 'c';
        break;
}
PHP;

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('duplicate_switch_case', $findings[0]->type);
        self::assertSame(9, $findings[0]->line);
    }

    public function testNoDuplicateSwitchCase(): void
    {
        $code = <<<'PHP'
<?php
switch ($x) {
    case 1:
        break;
    case 2:
        break;
    case 3:
        break;
}
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    public function testSwitchDefaultNotCompared(): void
    {
        $code = <<<'PHP'
<?php
switch ($x) {
    case 1:
        break;
    default:
        break;
}
PHP;

        self::assertCount(0, $this->analyze($code));
    }

    // ── Match Multi-Condition Arms ──────────────────────────────────

    public function testMatchMultiConditionDuplicate(): void
    {
        $code = <<<'PHP'
<?php
$result = match ($x) {
    1, 2 => 'a',
    3, 2 => 'b',
};
PHP;

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('duplicate_match_arm', $findings[0]->type);
    }

    // ── Collector Integration ───────────────────────────────────────

    public function testCollectorIntegration(): void
    {
        $code = <<<'PHP'
<?php
$a = $x === $x;
if ($y > 0) {} elseif ($y > 0) {}
PHP;

        $collector = new IdenticalSubExpressionCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $bag = $collector->collect(new SplFileInfo(__FILE__), $ast);

        self::assertSame(1, $bag->entryCount('identicalSubExpression.identical_operands'));
        self::assertSame(2, $bag->entries('identicalSubExpression.identical_operands')[0]['line']);
        self::assertSame(1, $bag->entryCount('identicalSubExpression.duplicate_condition'));
        self::assertSame(3, $bag->entries('identicalSubExpression.duplicate_condition')[0]['line']);
        self::assertSame(0, $bag->entryCount('identicalSubExpression.identical_ternary'));
        self::assertSame(0, $bag->entryCount('identicalSubExpression.duplicate_match_arm'));
    }

    public function testCollectorNoFindings(): void
    {
        $code = '<?php $a = $x + $y;';

        $collector = new IdenticalSubExpressionCollector();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector->getVisitor());
        $traverser->traverse($ast);

        $bag = $collector->collect(new SplFileInfo(__FILE__), $ast);

        foreach (IdenticalSubExpressionCollector::FINDING_TYPES as $type) {
            self::assertSame(0, $bag->entryCount("identicalSubExpression.{$type}"));
        }
    }

    public function testCollectorName(): void
    {
        $collector = new IdenticalSubExpressionCollector();
        self::assertSame('identical-subexpression', $collector->getName());
    }

    public function testCollectorProvides(): void
    {
        $collector = new IdenticalSubExpressionCollector();
        $provides = $collector->provides();

        self::assertContains('identicalSubExpression.identical_operands', $provides);
        self::assertContains('identicalSubExpression.duplicate_condition', $provides);
        self::assertContains('identicalSubExpression.identical_ternary', $provides);
        self::assertContains('identicalSubExpression.duplicate_match_arm', $provides);
        self::assertContains('identicalSubExpression.duplicate_switch_case', $provides);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @return list<IdenticalSubExpressionFinding>
     */
    private function analyze(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        return $this->visitor->getFindings();
    }
}
