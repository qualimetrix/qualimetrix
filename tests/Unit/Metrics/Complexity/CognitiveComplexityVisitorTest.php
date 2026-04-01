<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Complexity;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Complexity\CognitiveComplexityVisitor;

#[CoversClass(CognitiveComplexityVisitor::class)]
final class CognitiveComplexityVisitorTest extends TestCase
{
    /**
     * @param array<string, int> $expected Map of FQN => expected complexity
     */
    #[DataProvider('provideComplexityCases')]
    public function testComplexity(string $code, array $expected): void
    {
        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        foreach ($expected as $fqn => $expectedComplexity) {
            self::assertArrayHasKey($fqn, $complexities, "Missing complexity for $fqn");
            self::assertSame(
                $expectedComplexity,
                $complexities[$fqn],
                "Cognitive complexity mismatch for $fqn",
            );
        }
    }

    /**
     * @return iterable<string, array{code: string, expected: array<string, int>}>
     */
    public static function provideComplexityCases(): iterable
    {
        // Base case: empty function = 0
        yield 'empty function' => [
            'code' => <<<'PHP'
<?php
function emptyFunc(): void {}
PHP,
            'expected' => ['emptyFunc' => 0],
        ];

        // Simple if = +1 (base = 0, no nesting yet)
        yield 'single if' => [
            'code' => <<<'PHP'
<?php
function simpleIf($a) {
    if ($a) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['simpleIf' => 1],
        ];

        // if-else = +1 (if) + +1 (else) = 2
        yield 'if-else' => [
            'code' => <<<'PHP'
<?php
function ifElse($a) {
    if ($a) {
        return 'yes';
    } else {
        return 'no';
    }
}
PHP,
            'expected' => ['ifElse' => 2],
        ];

        // Nesting: if inside if
        // Outer if: +1 (nesting=0), Inner if: +1 (base) + 1 (nesting=1) = 2
        // Total: 3
        yield 'nested if' => [
            'code' => <<<'PHP'
<?php
function nestedIf($a, $b) {
    if ($a) {
        if ($b) {
            return true;
        }
    }
    return false;
}
PHP,
            'expected' => ['nestedIf' => 3],
        ];

        // Triple nesting
        // Level 0 if: +1, Level 1 if: +2, Level 2 if: +3 = 6
        yield 'triple nested' => [
            'code' => <<<'PHP'
<?php
function tripleNested($a, $b, $c) {
    if ($a) {
        if ($b) {
            if ($c) {
                return true;
            }
        }
    }
    return false;
}
PHP,
            'expected' => ['tripleNested' => 6],
        ];

        // Logical operators: single && chain = +1 for if, +1 for logical chain
        yield 'and chain' => [
            'code' => <<<'PHP'
<?php
function andChain($a, $b, $c) {
    if ($a && $b && $c) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['andChain' => 2], // +1 (if) + 1 (logical chain)
        ];

        // Mixed logical operators: $a && $b || $c && $d
        // AST: BooleanOr(BooleanAnd($a, $b), BooleanAnd($c, $d))
        // BooleanOr: no boolean ancestor -> +1
        // BooleanAnd($a,$b): parent BooleanOr (different) -> +1
        // BooleanAnd($c,$d): parent BooleanOr (different) -> +1
        // Total: +1 (if) + 3 (logical) = 4
        yield 'mixed operators' => [
            'code' => <<<'PHP'
<?php
function mixedOps($a, $b, $c, $d) {
    if ($a && $b || $c && $d) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['mixedOps' => 4], // +1 (if) + 3 (logical: ||, left &&, right &&)
        ];

        // Switch: only +1, not for each case
        yield 'switch' => [
            'code' => <<<'PHP'
<?php
function switchCase($x) {
    switch ($x) {
        case 1:
            return 'one';
        case 2:
            return 'two';
        case 3:
            return 'three';
        default:
            return 'other';
    }
}
PHP,
            'expected' => ['switchCase' => 1],
        ];

        // Loops
        yield 'loops' => [
            'code' => <<<'PHP'
<?php
function loops($items) {
    for ($i = 0; $i < 10; $i++) {
        // for loop
    }

    foreach ($items as $item) {
        // foreach loop
    }

    while (true) {
        break;
    }

    do {
        break;
    } while (false);
}
PHP,
            'expected' => ['loops' => 4], // +1 each: for, foreach, while, do-while
        ];

        // Recursion
        yield 'recursion' => [
            'code' => <<<'PHP'
<?php
function factorial($n) {
    if ($n <= 1) {
        return 1;
    }
    return $n * factorial($n - 1);
}
PHP,
            'expected' => ['factorial' => 2], // +1 (if) + 1 (recursive call)
        ];

        // Ternary operator
        yield 'ternary' => [
            'code' => <<<'PHP'
<?php
function ternary($a, $b) {
    return $a > $b ? $a : $b;
}
PHP,
            'expected' => ['ternary' => 1], // +1 (ternary)
        ];

        // Null coalescing
        yield 'null coalescing' => [
            'code' => <<<'PHP'
<?php
function nullCoalesce($name) {
    return $name ?? 'Unknown';
}
PHP,
            'expected' => ['nullCoalesce' => 1], // +1 (??)
        ];

        // Try-catch
        yield 'try-catch' => [
            'code' => <<<'PHP'
<?php
function tryCatch() {
    try {
        riskyOperation();
    } catch (\Exception $e) {
        handleError();
    } catch (\Error $e) {
        handleFatal();
    }
}
PHP,
            'expected' => ['tryCatch' => 2], // +1 (first catch) + 1 (second catch)
        ];

        // Real-world example from the plan
        yield 'real world processOrder' => [
            'code' => <<<'PHP'
<?php
namespace App;

class OrderProcessor
{
    public function processOrder($order) {
        if (!$order) {                           // +1
            return null;
        }

        if ($order->isPaid()) {                  // +1
            foreach ($order->items as $item) {   // +2 (1 + nesting=1)
                if ($item->inStock()) {          // +3 (1 + nesting=2)
                    $this->ship($item);
                } else {                         // +1 (else: no nesting bonus)
                    $this->backorder($item);
                }
            }
        } elseif ($order->isPending()) {         // +1
            $this->remind($order);
        }

        return $order;
    }
}
PHP,
            'expected' => ['App\OrderProcessor::processOrder' => 9],
            // +1 (if !order)
            // +1 (if isPaid)
            // +2 (foreach at nesting=1)
            // +3 (if inStock at nesting=2)
            // +1 (else: no nesting bonus per SonarSource spec)
            // +1 (elseif isPending)
            // Total: 9
        ];

        // Closure
        yield 'closure' => [
            'code' => <<<'PHP'
<?php
namespace App;

class ClosureTest
{
    public function withClosure() {
        return function ($x) {
            if ($x > 0) {
                return $x * 2;
            }
            return $x;
        };
    }
}
PHP,
            'expected' => [
                'App\ClosureTest::withClosure' => 1, // +1 for closure (B1 lambda increment)
                'App\ClosureTest::{closure#1}' => 1, // +1 (if inside closure)
            ],
        ];

        // Anonymous class - methods inside should be skipped
        yield 'anonymous class' => [
            'code' => <<<'PHP'
<?php
namespace App;

class OuterClass
{
    public function createAnonymous() {
        if (true) {                             // +1
            return new class {
                public function complexMethod($a, $b) {
                    // This should NOT be tracked
                    if ($a) {
                        foreach ($b as $item) {
                            if ($item > 0) {
                                echo $item;
                            }
                        }
                    }
                    return null;
                }
            };
        }
        return null;
    }

    public function anotherMethod($x) {
        if ($x > 0) {                           // +1
            return $x * 2;
        }
        return $x;
    }
}
PHP,
            'expected' => [
                'App\OuterClass::createAnonymous' => 1, // +1 (if), anonymous class methods ignored
                'App\OuterClass::anotherMethod' => 1,   // +1 (if)
            ],
        ];

        // Nested anonymous classes
        yield 'nested anonymous classes' => [
            'code' => <<<'PHP'
<?php
namespace App;

class Container
{
    public function build() {
        if (true) {                             // +1
            $obj1 = new class {
                public function create() {
                    // Should be skipped
                    if (true) {
                        return new class {
                            public function doSomething() {
                                // Should also be skipped
                                if (true) {
                                    return 42;
                                }
                            }
                        };
                    }
                }
            };
        }
        return null;
    }
}
PHP,
            'expected' => [
                'App\Container::build' => 1, // +1 (if), all anonymous class methods ignored
            ],
        ];

        // Nested loops
        yield 'nested loops' => [
            'code' => <<<'PHP'
<?php
function nestedLoops($matrix) {
    foreach ($matrix as $row) {         // +1
        foreach ($row as $cell) {       // +2 (1 + nesting=1)
            if ($cell > 0) {            // +3 (1 + nesting=2)
                echo $cell;
            }
        }
    }
}
PHP,
            'expected' => ['nestedLoops' => 6], // +1 + 2 + 3
        ];

        // Match expression (PHP 8+)
        yield 'match expression' => [
            'code' => <<<'PHP'
<?php
function matchExpr($status) {
    return match ($status) {
        'active' => 'Active',
        'inactive' => 'Inactive',
        default => 'Unknown',
    };
}
PHP,
            'expected' => ['matchExpr' => 1], // +1 (match)
        ];

        // Complex example with multiple structures
        yield 'complex method' => [
            'code' => <<<'PHP'
<?php
namespace App\Service;

class ComplexService
{
    public function process($items, $validate) {
        if (empty($items)) {                        // +1
            return [];
        }

        $result = [];
        foreach ($items as $item) {                 // +1
            if ($validate && !$item->isValid()) {   // +2 (if at nesting=1) + 1 (logical op) = 3
                continue;
            }

            try {
                if ($item->value > 100) {           // +2 (if at nesting=1)
                    throw new \Exception();
                }
                $result[] = $item;
            } catch (\Exception $e) {               // +1 (catch at nesting=1) = 2
                // handle
            }
        }

        return $result;
    }
}
PHP,
            'expected' => ['App\Service\ComplexService::process' => 9],
            // +1 (if empty)
            // +1 (foreach)
            // +2 (if validate at nesting=1)
            // +1 (logical &&)
            // +2 (if value at nesting=1)
            // +2 (catch at nesting=1)
            // Total: 9
            // Wait, let me recalculate:
            // if (empty($items)): +1 (nesting=0)
            // foreach: +1 (nesting=0) -> enters nesting=1
            // if ($validate && ...): +2 (1 + nesting=1)
            // &&: +1 (logical)
            // if ($item->value > 100): +2 (1 + nesting=1)
            // catch: +2 (1 + nesting=1)
            // Total: 1 + 1 + 2 + 1 + 2 + 2 = 9
        ];

        // Two consecutive if statements with same logical operator
        // Each statement should reset lastLogicalOp tracking
        // First if: +1 (if) + 1 (&&) = 2
        // Second if: +1 (if) + 1 (&&) = 2
        // Total: 4
        yield 'consecutive ifs with same logical operator' => [
            'code' => <<<'PHP'
<?php
function consecutiveIfs($a, $b, $c, $d) {
    if ($a && $b) {
        echo 'first';
    }
    if ($c && $d) {
        echo 'second';
    }
}
PHP,
            'expected' => ['consecutiveIfs' => 4],
        ];

        // Single if with mixed operators: && then ||
        // if: +1, &&: +1 (first logical), ||: +1 (operator change) = 3
        yield 'single if with operator change' => [
            'code' => <<<'PHP'
<?php
function operatorChange($a, $b, $c) {
    if ($a && $b || $c) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['operatorChange' => 3],
        ];

        // Consecutive different statement types with logical operators
        // Each statement gets fresh logical operator tracking
        // if ($a && $b): +1 (if) + 1 (&&) = 2
        // while ($c || $d): +1 (while) + 1 (||) = 2
        // Total: 4
        yield 'different statements with logical operators' => [
            'code' => <<<'PHP'
<?php
function differentStatements($a, $b, $c, $d) {
    if ($a && $b) {
        echo 'if';
    }
    while ($c || $d) {
        break;
    }
}
PHP,
            'expected' => ['differentStatements' => 4],
        ];

        // Three consecutive ifs with && to prove the reset works consistently
        // Each if: +1 (if) + 1 (&&) = 2, total: 6
        yield 'three consecutive ifs with logical operators' => [
            'code' => <<<'PHP'
<?php
function threeConsecutiveIfs($a, $b, $c, $d, $e, $f) {
    if ($a && $b) {
        echo 'first';
    }
    if ($c && $d) {
        echo 'second';
    }
    if ($e && $f) {
        echo 'third';
    }
}
PHP,
            'expected' => ['threeConsecutiveIfs' => 6],
        ];

        // elseif chain
        yield 'elseif chain' => [
            'code' => <<<'PHP'
<?php
function grade($score) {
    if ($score >= 90) {             // +1
        return 'A';
    } elseif ($score >= 80) {       // +1
        return 'B';
    } elseif ($score >= 70) {       // +1
        return 'C';
    } else {                        // +1
        return 'F';
    }
}
PHP,
            'expected' => ['grade' => 4], // +1 for each branch
        ];

        // Closure inside nested scope: nesting level must be restored after closure
        yield 'closure inside if restores nesting' => [
            'code' => <<<'PHP'
<?php
function foo($a, $b, $c) {
    if ($a) {                       // +1 (nesting=0)
        $fn = function() use ($b) { // closure resets nesting to 0
            if ($b) {}              // +1 (nesting=0) — inside closure
        };                          // nesting restored to 1
        if ($c) {}                  // +2 (1 + nesting=1) — back in outer scope
    }
}
PHP,
            'expected' => [
                'foo' => 5,             // +1 (if $a) + 2 (closure at nesting=1: B1+B3) + 2 (if $c at nesting=1) = 5
                '::{closure#1}' => 1,   // +1 (if $b)
            ],
        ];

        // Arrow function inside nested scope: same save/restore behavior
        yield 'arrow function inside if restores nesting' => [
            'code' => <<<'PHP'
<?php
function bar($a, $c) {
    if ($a) {                           // +1 (nesting=0)
        $fn = fn($x) => $x > 0;        // arrow function, nesting reset
        if ($c) {}                      // +2 (1 + nesting=1)
    }
}
PHP,
            'expected' => [
                'bar' => 5,             // +1 (if $a) + 2 (arrow fn at nesting=1: B1+B3) + 2 (if $c at nesting=1) = 5
                '::{closure#1}' => 0,   // arrow function with no control flow
            ],
        ];

        // Tree-aware logical operator tracking: $a || $b || $c && $d
        // AST: BooleanOr(BooleanOr($a, $b), BooleanAnd($c, $d))
        // Inner BooleanOr($a,$b): no boolean ancestor -> +1
        // Outer BooleanOr: parent BooleanOr (same) -> +0
        // BooleanAnd($c,$d): parent BooleanOr (different) -> +1
        // Total: +1 (if) + 2 (logical) = 3
        yield 'or chain then and' => [
            'code' => <<<'PHP'
<?php
function orThenAnd($a, $b, $c, $d) {
    if ($a || $b || $c && $d) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['orThenAnd' => 3],
        ];

        // Nested parenthesized boolean expressions: ($a && $b) || ($c && ($d || $e))
        // AST: BooleanOr(BooleanAnd($a,$b), BooleanAnd($c, BooleanOr($d,$e)))
        // BooleanOr: no boolean ancestor -> +1
        // BooleanAnd($a,$b): parent BooleanOr (different) -> +1
        // BooleanAnd($c,...): parent BooleanOr (different) -> +1
        // BooleanOr($d,$e): parent BooleanAnd (different) -> +1
        // Total: +1 (if) + 4 (logical) = 5
        yield 'nested parenthesized boolean' => [
            'code' => <<<'PHP'
<?php
function nestedBool($a, $b, $c, $d, $e) {
    if (($a && $b) || ($c && ($d || $e))) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['nestedBool' => 5],
        ];

        // Deep same-type chain: $a && $b && $c && $d && $e
        // AST: BooleanAnd(BooleanAnd(BooleanAnd(BooleanAnd($a,$b), $c), $d), $e)
        // Innermost BooleanAnd: no boolean ancestor -> +1
        // All outer BooleanAnd: parent BooleanAnd (same) -> +0 each
        // Total: +1 (if) + 1 (logical) = 2
        yield 'long same-type chain' => [
            'code' => <<<'PHP'
<?php
function longChain($a, $b, $c, $d, $e) {
    if ($a && $b && $c && $d && $e) {
        return true;
    }
    return false;
}
PHP,
            'expected' => ['longChain' => 2],
        ];
    }

    /**
     * Fix 1: $other->process() inside process() should NOT be recursion.
     */
    public function testOtherObjectMethodCallIsNotRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public function process($other) {
        if ($other) {              // +1
            $other->process();     // NOT recursion (different receiver)
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Only +1 for the if, no recursion bonus
        self::assertSame(1, $complexities['App\Service::process']);
    }

    /**
     * Fix 1: $this->process() inside process() should BE recursion.
     */
    public function testThisMethodCallIsRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public function process() {
        if (true) {                // +1
            $this->process();      // +1 recursion
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 (if) + 1 (recursion) = 2
        self::assertSame(2, $complexities['App\Service::process']);
    }

    /**
     * Fix 1: self::process() inside process() should BE recursion.
     */
    public function testSelfStaticCallIsRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public static function process() {
        if (true) {                // +1
            self::process();       // +1 recursion
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 (if) + 1 (recursion) = 2
        self::assertSame(2, $complexities['App\Service::process']);
    }

    /**
     * Fix 1: OtherClass::process() inside process() should NOT be recursion.
     */
    public function testOtherClassStaticCallIsNotRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public function process() {
        if (true) {                    // +1
            OtherClass::process();     // NOT recursion
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Only +1 for the if, no recursion bonus
        self::assertSame(1, $complexities['App\Service::process']);
    }

    /**
     * Fix 2: nested else should get +1 regardless of nesting depth.
     */
    public function testNestedElseGetsNoNestingBonus(): void
    {
        $code = <<<'PHP'
<?php
function nestedElse($a, $b, $c) {
    if ($a) {                   // +1 (nesting=0)
        if ($b) {               // +2 (1 + nesting=1)
            if ($c) {           // +3 (1 + nesting=2)
                echo 'deep';
            } else {            // +1 (else: no nesting bonus per SonarSource)
                echo 'else';
            }
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 + 2 + 3 + 1 = 7
        self::assertSame(7, $complexities['nestedElse']);
    }

    /**
     * Problem #3: leaveNode for ClassMethod inside anonymous class must not call endMethod().
     * The method inside the anonymous class should not affect the outer method's complexity.
     */
    public function testAnonymousClassMethodDoesNotLeakToOuterMethod(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Outer
{
    public function outerMethod($x) {
        if ($x) {                           // +1
            $obj = new class {
                public function innerMethod($a, $b) {
                    // Complex logic inside anonymous class — must NOT affect outerMethod
                    if ($a) {
                        foreach ($b as $item) {
                            if ($item > 0) {
                                echo $item;
                            }
                        }
                    }
                }
            };
        }
        if ($x > 1) {                      // +1
            return true;
        }
        return false;
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // outerMethod should only have +1 (first if) + 1 (second if) = 2
        // The anonymous class method must NOT contribute to outerMethod's complexity
        self::assertSame(2, $complexities['App\Outer::outerMethod']);
    }

    /**
     * Problem #11: FuncCall inside ClassMethod must NOT be treated as recursion.
     * A method named count() calling built-in count($arr) is not recursive.
     */
    public function testFuncCallInClassMethodIsNotRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass
{
    public function count(array $items): int {
        if (empty($items)) {                // +1
            return 0;
        }
        return \count($items);              // NOT recursion — this is a global function call
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Only +1 for the if, NO recursion penalty
        self::assertSame(1, $complexities['App\MyClass::count']);
    }

    /**
     * Standalone function calling itself IS recursion (FuncCall recursion still works).
     */
    public function testFuncCallInStandaloneFunctionIsRecursion(): void
    {
        $code = <<<'PHP'
<?php
function myRecursive($n) {
    if ($n <= 0) {              // +1
        return 0;
    }
    return myRecursive($n - 1); // +1 recursion
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 (if) + 1 (recursion) = 2
        self::assertSame(2, $complexities['myRecursive']);
    }

    /**
     * ElseIf at deep nesting should get only +1 (no nesting bonus per SonarSource spec).
     */
    public function testElseifAtDeepNesting(): void
    {
        $code = '<?php function f($a, $b, $c) { if ($a) { if ($b) {} elseif ($c) {} } }';

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // if ($a): +1 (nesting=0)
        // if ($b): +2 (1 + nesting=1)
        // elseif ($c): +1 (B1 only, no nesting bonus)
        // Total: 4
        self::assertSame(4, $complexities['f']);
    }

    /**
     * Closure adds +1 structural increment to parent method.
     */
    public function testClosureAddsOneToParent(): void
    {
        $code = '<?php function f() { $fn = function() { return 1; }; }';

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Closure at nesting=0: +1 (B1) + 0 (B3 nesting=0) = +1 to parent
        self::assertSame(1, $complexities['f']);
    }

    /**
     * Arrow function adds +1 structural increment to parent method.
     */
    public function testArrowFunctionAddsOneToParent(): void
    {
        $code = '<?php function f() { $fn = fn() => 1; }';

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Arrow function at nesting=0: +1 (B1) + 0 (B3 nesting=0) = +1 to parent
        self::assertSame(1, $complexities['f']);
    }

    /**
     * Closure inside anonymous class method should NOT appear in metrics of outer class.
     */
    public function testClosureInsideAnonymousClassNotInOuterMetrics(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Outer
{
    public function outerMethod(): void
    {
        $obj = new class {
            public function innerMethod(): void
            {
                $fn = function() {
                    if (true) { return 1; }
                    return 0;
                };
            }
        };
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // outerMethod should have complexity 0 — the closure inside anonymous class is ignored
        self::assertSame(0, $complexities['App\Outer::outerMethod']);
    }

    /**
     * ArrowFunction inside anonymous class method should NOT appear in metrics of outer class.
     */
    public function testArrowFunctionInsideAnonymousClassNotInOuterMetrics(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Outer
{
    public function outerMethod(): void
    {
        $obj = new class {
            public function innerMethod(): void
            {
                $fn = fn() => true;
            }
        };
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // outerMethod should have complexity 0
        self::assertSame(0, $complexities['App\Outer::outerMethod']);
    }

    /**
     * Goto inside nested if should be +1 regardless of nesting depth (B1 only, no nesting bonus).
     */
    public function testGotoNoNestingBonus(): void
    {
        $code = <<<'PHP'
<?php
function f($a, $b) {
    if ($a) {
        if ($b) {
            goto end;
        }
    }
    end:
    return;
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // if ($a): +1 (nesting=0)
        // if ($b): +2 (1 + nesting=1)
        // goto: +1 (B1 only, NO nesting bonus)
        // Total: 4
        self::assertSame(4, $complexities['f']);
    }

    /**
     * Labeled break inside nested loop should be +1 regardless of nesting depth.
     */
    public function testLabeledBreakNoNestingBonus(): void
    {
        $code = <<<'PHP'
<?php
function f() {
    for ($i = 0; $i < 10; $i++) {
        for ($j = 0; $j < 10; $j++) {
            break 2;
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // for: +1 (nesting=0)
        // for: +2 (1 + nesting=1)
        // break 2: +1 (B1 only, NO nesting bonus)
        // Total: 4
        self::assertSame(4, $complexities['f']);
    }

    /**
     * Bug fix: nodeStack must be saved/restored for closures inside methods.
     * Without this fix, the nodeStack from the outer method leaks into the closure,
     * causing incorrect logical operator chain detection.
     */
    public function testNodeStackDoesNotLeakIntoClosure(): void
    {
        $code = <<<'PHP'
<?php
function outer($a, $b) {
    if ($a || $b) {                         // +1 (if) + 1 (||) = 2
        $fn = function($c, $d) {            // closure at nesting=1: +2 to outer
            if ($c || $d) {                 // +1 (if) + 1 (||) = 2 in closure
                return true;
            }
            return false;
        };
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // outer: +1 (if) + 1 (||) + 2 (closure at nesting=1) = 4
        self::assertSame(4, $complexities['outer']);
        // closure: +1 (if) + 1 (||) = 2 — nodeStack must be clean, so || starts fresh
        self::assertSame(2, $complexities['::{closure#1}']);
    }

    /**
     * Bug fix: nodeStack must be restored after closure ends.
     * Logical operators after a closure should use the outer nodeStack, not an empty one.
     */
    public function testNodeStackRestoredAfterClosure(): void
    {
        $code = <<<'PHP'
<?php
function outer($a, $b, $c) {
    if ($a || $b) {                         // +1 (if) + 1 (||) = 2
        $fn = function() { return 1; };     // closure at nesting=1: +2
        if ($c || $a) {                     // +2 (if at nesting=1) + 1 (||) = 3
            return true;
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // outer: +1 (if) + 1 (||) + 2 (closure) + 2 (if at nesting=1) + 1 (||) = 7
        self::assertSame(7, $complexities['outer']);
    }

    /**
     * Bug fix: \Other\Namespace\foo() called inside function foo() must NOT be detected as recursion.
     */
    public function testNamespacedFunctionCallIsNotFalseRecursion(): void
    {
        $code = <<<'PHP'
<?php
function foo($n) {
    if ($n > 0) {                           // +1
        return \Other\Namespace\foo($n);    // NOT recursion — different namespace
    }
    return 0;
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Only +1 for the if, no false recursion
        self::assertSame(1, $complexities['foo']);
    }

    /**
     * Bug fix: Fully-qualified call to same function IS still recursion.
     */
    public function testFullyQualifiedSameFunctionIsRecursion(): void
    {
        $code = <<<'PHP'
<?php
function factorial($n) {
    if ($n <= 1) {                  // +1
        return 1;
    }
    return $n * \factorial($n - 1); // +1 recursion (leading \ stripped, no namespace)
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 (if) + 1 (recursion) = 2
        self::assertSame(2, $complexities['factorial']);
    }

    /**
     * Bug fix: parent::method() is NOT recursion — it calls the parent class method.
     */
    public function testParentStaticCallIsNotRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Child
{
    public function process() {
        if (true) {                // +1
            parent::process();     // NOT recursion — calls parent's method
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // Only +1 for the if, no recursion
        self::assertSame(1, $complexities['App\Child::process']);
    }

    /**
     * Ensure static::method() IS still detected as recursion (late static binding calls self).
     */
    public function testStaticCallIsStillRecursion(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public static function process() {
        if (true) {                // +1
            static::process();     // +1 recursion (late static binding)
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $complexities = $visitor->getComplexities();

        // +1 (if) + 1 (recursion) = 2
        self::assertSame(2, $complexities['App\Service::process']);
    }

    public function testReset(): void
    {
        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();

        $code1 = <<<'PHP'
<?php
function first($a) {
    if ($a) {
        return true;
    }
    return false;
}
PHP;

        $ast1 = $parser->parse($code1) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast1);

        self::assertArrayHasKey('first', $visitor->getComplexities());

        // Reset
        $visitor->reset();

        $code2 = <<<'PHP'
<?php
function second() {
    return true;
}
PHP;

        $ast2 = $parser->parse($code2) ?? [];
        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor($visitor);
        $traverser2->traverse($ast2);

        $complexities = $visitor->getComplexities();

        // Should only contain second function
        self::assertArrayNotHasKey('first', $complexities);
        self::assertArrayHasKey('second', $complexities);
        self::assertSame(0, $complexities['second']);
    }

    public function testIncrementsTrackingWithNestedStructures(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public function process($items, $flag) {
        if ($flag) {               // +1 (if, nesting=0)
            foreach ($items as $item) {  // +2 (foreach, nesting=1)
                if ($item > 0) {         // +3 (if, nesting=2)
                    return $item;
                }
            }
        } else {                   // +1 (else)
            return null;
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $increments = $visitor->getIncrements();
        $fqn = 'App\Service::process';

        self::assertArrayHasKey($fqn, $increments);
        self::assertCount(4, $increments[$fqn]);

        // Verify first increment (if at nesting=0 → +1)
        self::assertSame('if', $increments[$fqn][0]['type']);
        self::assertSame(1, $increments[$fqn][0]['points']);

        // Verify second increment (foreach at nesting=1 → +2)
        self::assertSame('foreach', $increments[$fqn][1]['type']);
        self::assertSame(2, $increments[$fqn][1]['points']);

        // Verify third increment (nested if at nesting=2 → +3)
        self::assertSame('if', $increments[$fqn][2]['type']);
        self::assertSame(3, $increments[$fqn][2]['points']);

        // Verify fourth increment (else → +1)
        self::assertSame('else', $increments[$fqn][3]['type']);
        self::assertSame(1, $increments[$fqn][3]['points']);

        // All increments have line numbers
        foreach ($increments[$fqn] as $inc) {
            self::assertArrayHasKey('line', $inc);
            self::assertGreaterThan(0, $inc['line']);
        }
    }

    public function testIncrementsPassedViaMethodsWithMetrics(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class Service
{
    public function process($x) {
        if ($x) {          // +1
            return true;
        }
    }
}
PHP;

        $visitor = new CognitiveComplexityVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $methods = $visitor->getMethodsWithMetrics();
        self::assertCount(1, $methods);

        $entries = $methods[0]->metrics->entries('cognitive-complexity.increments');
        self::assertCount(1, $entries);
        self::assertSame('if', $entries[0]['type']);
        self::assertSame(1, $entries[0]['points']);
    }
}
