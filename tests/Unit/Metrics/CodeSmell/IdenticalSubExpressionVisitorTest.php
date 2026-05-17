<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\IdenticalSubExpressionCollector;
use Qualimetrix\Metrics\CodeSmell\IdenticalSubExpressionFinding;
use Qualimetrix\Metrics\CodeSmell\IdenticalSubExpressionVisitor;
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

    #[Test]
    public function itFlagsIdenticalComparisonOperands(): void
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

    #[Test]
    public function itFlagsIdenticalEqualOperands(): void
    {
        $code = <<<'PHP'
<?php
$result = $a == $a;
PHP;

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertStringContainsString('==', $findings[0]->detail);
    }

    #[Test]
    public function itFlagsIdenticalNotIdenticalOperands(): void
    {
        $code = <<<'PHP'
<?php
$result = $a !== $a;
PHP;

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertStringContainsString('!==', $findings[0]->detail);
    }

    #[Test]
    public function itFlagsIdenticalGreaterOperands(): void
    {
        $code = '<?php $result = $a > $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalSmallerOperands(): void
    {
        $code = '<?php $result = $a < $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalGreaterOrEqualOperands(): void
    {
        $code = '<?php $result = $a >= $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalSmallerOrEqualOperands(): void
    {
        $code = '<?php $result = $a <= $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalSpaceshipOperands(): void
    {
        $code = '<?php $result = $a <=> $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalBooleanAndOperands(): void
    {
        $code = '<?php $result = $a && $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalBooleanOrOperands(): void
    {
        $code = '<?php $result = $a || $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalLogicalAndOperands(): void
    {
        // Parentheses needed because `and` has lower precedence than `=`
        $code = '<?php if ($a and $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalLogicalOrOperands(): void
    {
        $code = '<?php if ($a or $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalLogicalXorOperands(): void
    {
        $code = '<?php if ($a xor $a) {}';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalMinusOperands(): void
    {
        $code = '<?php $result = $a - $a;';
        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertStringContainsString('-', $findings[0]->detail);
    }

    #[Test]
    public function itFlagsIdenticalDivOperands(): void
    {
        $code = '<?php $result = $a / $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalModOperands(): void
    {
        $code = '<?php $result = $a % $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalBitwiseXorOperands(): void
    {
        $code = '<?php $result = $a ^ $a;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalCoalesceOperands(): void
    {
        $code = '<?php $result = $a ?? $a;';
        self::assertCount(1, $this->analyze($code));
    }

    // ── Operators that should NOT be flagged ─────────────────────────

    #[Test]
    public function itDoesNotFlagPlusOperator(): void
    {
        $code = '<?php $result = $a + $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagMultiplyOperator(): void
    {
        $code = '<?php $result = $a * $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagConcatOperator(): void
    {
        $code = '<?php $result = $a . $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagBitwiseAndOperator(): void
    {
        $code = '<?php $result = $a & $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagBitwiseOrOperator(): void
    {
        $code = '<?php $result = $a | $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagShiftLeftOperator(): void
    {
        $code = '<?php $result = $a << $a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagShiftRightOperator(): void
    {
        $code = '<?php $result = $a >> $a;';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Side effects — should NOT be flagged ────────────────────────

    #[Test]
    public function itDoesNotFlagFunctionCallOperands(): void
    {
        $code = '<?php $result = foo() === foo();';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagMethodCallOperands(): void
    {
        $code = '<?php $result = $obj->get() === $obj->get();';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagStaticCallOperands(): void
    {
        $code = '<?php $result = Foo::bar() === Foo::bar();';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagPreIncrementOperands(): void
    {
        $code = '<?php $result = ++$a === ++$a;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagNewExpressionOperands(): void
    {
        $code = '<?php $result = new Foo() === new Foo();';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagNestedFunctionCallOperands(): void
    {
        $code = '<?php $result = ($a + foo()) === ($a + foo());';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Pure complex expressions — SHOULD be flagged ─────────────────

    #[Test]
    public function itFlagsIdenticalPropertyAccessOperands(): void
    {
        $code = '<?php $result = $obj->prop === $obj->prop;';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalArrayAccessOperands(): void
    {
        $code = '<?php $result = $arr[0] === $arr[0];';
        self::assertCount(1, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalComplexExpressionOperands(): void
    {
        $code = '<?php $result = ($a + $b * $c) === ($a + $b * $c);';
        self::assertCount(1, $this->analyze($code));
    }

    // ── Different operands — should NOT be flagged ──────────────────

    #[Test]
    public function itDoesNotFlagDifferentOperands(): void
    {
        $code = '<?php $result = $a === $b;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagDifferentComplexExpressions(): void
    {
        $code = '<?php $result = ($a + $b) === ($a - $b);';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Duplicate Conditions ────────────────────────────────────────

    #[Test]
    public function itFlagsDuplicateIfElseifCondition(): void
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

    #[Test]
    public function itFlagsDuplicateConditionThreeWay(): void
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

    #[Test]
    public function itDoesNotFlagDistinctConditions(): void
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

    #[Test]
    public function itDoesNotCheckIfWithoutElseif(): void
    {
        $code = '<?php if ($x > 0) { echo "a"; }';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itFlagsDuplicateComplexCondition(): void
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

    #[Test]
    public function itFlagsIdenticalTernaryBranches(): void
    {
        $code = '<?php $result = $cond ? $a : $a;';

        $findings = $this->analyze($code);

        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
        self::assertSame(1, $findings[0]->line);
    }

    #[Test]
    public function itDoesNotFlagDifferentTernaryBranches(): void
    {
        $code = '<?php $result = $cond ? $a : $b;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagShortTernaryWithDifferentBranches(): void
    {
        $code = '<?php $result = $a ?: $b;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagTernaryWithSideEffects(): void
    {
        $code = '<?php $result = $cond ? foo() : foo();';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Duplicate Match Arms ────────────────────────────────────────

    #[Test]
    public function itFlagsDuplicateMatchArmCondition(): void
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

    #[Test]
    public function itDoesNotFlagDistinctMatchArms(): void
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

    #[Test]
    public function itDoesNotCompareMatchDefaultArm(): void
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

    #[Test]
    public function itFlagsDuplicateMatchArmComplexCondition(): void
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

    #[Test]
    public function itReportsMultipleFindingsInSameFile(): void
    {
        $code = <<<'PHP'
<?php
$a = $x === $x;
$b = $y - $y;
$c = $cond ? $z : $z;
PHP;

        self::assertCount(3, $this->analyze($code));
    }

    #[Test]
    public function itFlagsIdenticalBranchesInNestedTernary(): void
    {
        $code = '<?php $result = $cond1 ? ($cond2 ? $a : $a) : $b;';

        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
    }

    #[Test]
    public function itProducesNoFindingsForCleanCode(): void
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

    #[Test]
    public function itClearsStateOnReset(): void
    {
        $code1 = '<?php $a = $x === $x;';
        $code2 = '<?php $b = $y + $z;';

        $this->analyze($code1);
        self::assertCount(1, $this->visitor->getFindings());

        $this->visitor->reset();

        $this->analyze($code2);
        self::assertCount(0, $this->visitor->getFindings());
    }

    #[Test]
    public function itDoesNotFlagNullsafeMethodCallOperands(): void
    {
        $code = '<?php $result = $a?->get() === $a?->get();';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagYieldExpressionOperands(): void
    {
        $code = '<?php function gen() { $result = (yield 1) === (yield 1); }';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagAssignmentOperands(): void
    {
        $code = '<?php $result = ($a = 1) === ($a = 1);';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagCompoundAssignmentOperands(): void
    {
        $code = '<?php $result = ($a += 1) === ($a += 1);';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagShellExecOperands(): void
    {
        $code = '<?php $result = `ls` === `ls`;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagEvalExpressionOperands(): void
    {
        $code = '<?php $result = eval("1") === eval("1");';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagPrintExpressionOperands(): void
    {
        $code = '<?php $result = print("a") === print("a");';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Short Ternary ───────────────────────────────────────────────

    #[Test]
    public function itFlagsShortTernaryWithIdenticalBranches(): void
    {
        $code = '<?php $result = $a ?: $a;';
        $findings = $this->analyze($code);
        self::assertCount(1, $findings);
        self::assertSame('identical_ternary', $findings[0]->type);
    }

    #[Test]
    public function itDoesNotFlagShortTernaryWithDifferentOperands(): void
    {
        $code = '<?php $result = $a ?: $b;';
        self::assertCount(0, $this->analyze($code));
    }

    #[Test]
    public function itDoesNotFlagShortTernaryWithSideEffects(): void
    {
        $code = '<?php $result = foo() ?: foo();';
        self::assertCount(0, $this->analyze($code));
    }

    // ── Switch/Case Duplicates ──────────────────────────────────────

    #[Test]
    public function itFlagsDuplicateSwitchCase(): void
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

    #[Test]
    public function itDoesNotFlagDistinctSwitchCases(): void
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

    #[Test]
    public function itDoesNotCompareSwitchDefaultCase(): void
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

    #[Test]
    public function itFlagsMatchMultiConditionDuplicate(): void
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

    #[Test]
    public function itIntegratesWithCollectorCorrectly(): void
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

    #[Test]
    public function itProducesNoCollectorFindingsForCleanCode(): void
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

    #[Test]
    public function itReturnsCollectorName(): void
    {
        $collector = new IdenticalSubExpressionCollector();
        self::assertSame('identical-subexpression', $collector->getName());
    }

    #[Test]
    public function itProvidesExpectedMetricKeys(): void
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
