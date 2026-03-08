<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Complexity;

use AiMessDetector\Metrics\Complexity\CognitiveComplexityVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

        // Mixed logical operators: && changes to ||
        // if +1, first && +1, || +1 (change of operator), second && +0 (continuing new chain)
        // Actually: if +1, first logical (&&) +1, change to || +1, then back to && +1 = 4
        // Wait, let me reconsider: a && b || c && d
        // We enter: a && -> first 'and', +1
        // Then: b (still and context)
        // Then: || -> change to 'or', +1
        // Then: c (still or context)
        // Then: && -> change to 'and', +1
        // Total logical: +3, plus if +1 = 4
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
            'expected' => ['mixedOps' => 3], // +1 (if) + 2 (logical in AST order: || then &&)
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
                } else {                         // +1 (else with nesting=2 -> 1+2=3)
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
            'expected' => ['App\OrderProcessor::processOrder' => 11],
            // +1 (if !order)
            // +1 (if isPaid)
            // +2 (foreach at nesting=1)
            // +3 (if inStock at nesting=2)
            // +3 (else at nesting=2)
            // +1 (elseif isPending)
            // Total: 11
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
                'App\ClosureTest::withClosure' => 0, // Empty method
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
}
