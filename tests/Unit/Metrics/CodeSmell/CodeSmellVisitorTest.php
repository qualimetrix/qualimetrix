<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\CodeSmell;

use AiMessDetector\Metrics\CodeSmell\CodeSmellLocation;
use AiMessDetector\Metrics\CodeSmell\CodeSmellVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeSmellVisitor::class)]
#[CoversClass(CodeSmellLocation::class)]
final class CodeSmellVisitorTest extends TestCase
{
    #[Test]
    public function globalsIsDetectedAsSuperglobal(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $x = $GLOBALS['foo'];
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('superglobals');
        self::assertCount(1, $locations);
        self::assertSame('GLOBALS', $locations[0]->extra);
    }

    #[Test]
    public function allSuperglobalsAreDetected(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    $a = $_GET['a'];
    $b = $_POST['b'];
    $c = $_REQUEST['c'];
    $d = $_COOKIE['d'];
    $e = $_SESSION['e'];
    $f = $_SERVER['f'];
    $g = $_FILES['g'];
    $h = $_ENV['h'];
    $i = $GLOBALS['i'];
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('superglobals');
        self::assertCount(9, $locations);

        $extras = array_map(static fn(CodeSmellLocation $loc) => $loc->extra, $locations);
        self::assertContains('GLOBALS', $extras);
        self::assertContains('_GET', $extras);
    }

    #[Test]
    public function countInClosureInsideLoopConditionIsNotFalsePositive(): void
    {
        // count() inside a closure that is in a loop condition should NOT be flagged
        $code = <<<'PHP'
<?php
class Foo {
    public function test(array $items) {
        $filter = function($item) { return count($item) > 0; };
        for ($i = 0; $i < 10; $i++) {
            // loop body
        }
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('count_in_loop');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function countInArrowFunctionInsideLoopConditionIsNotFalsePositive(): void
    {
        // count() inside an arrow function used in a loop condition should NOT be flagged
        $code = <<<'PHP'
<?php
class Foo {
    public function test(array $items) {
        while (array_filter($items, fn($item) => count($item) > 0) !== []) {
            array_shift($items);
        }
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('count_in_loop');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function countDirectlyInLoopConditionIsStillDetected(): void
    {
        $code = <<<'PHP'
<?php
class Foo {
    public function test(array $items) {
        for ($i = 0; $i < count($items); $i++) {
            // loop body
        }
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('count_in_loop');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function sizeofInLoopConditionIsDetected(): void
    {
        $code = <<<'PHP'
<?php
class Foo {
    public function test(array $items) {
        while (sizeof($items) > 0) {
            array_pop($items);
        }
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('count_in_loop');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function emptyCatchWithOnlyCommentIsDetected(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    try {
        doSomething();
    } catch (\Exception $e) {
        // intentionally empty
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function emptyCatchWithNoStatementsIsDetected(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    try {
        doSomething();
    } catch (\Exception $e) {
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function catchWithRealStatementIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    try {
        doSomething();
    } catch (\Exception $e) {
        // log and continue
        log($e->getMessage());
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function varExportWithReturnModeIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    $result = var_export($data, true);
    return $result;
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function printRWithReturnModeIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    $result = print_r($data, true);
    return $result;
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function varExportWithNamedReturnArgumentIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    $result = var_export(value: $data, return: true);
    return $result;
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function printRWithoutReturnModeIsFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    print_r($data);
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function varExportWithoutReturnModeIsFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    var_export($data);
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(1, $locations);
        self::assertSame('var_export', $locations[0]->extra);
    }

    #[Test]
    public function varExportWithFalseSecondArgIsFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test($data) {
    var_export($data, false);
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function debugFunctionInsideDebugApiMethodIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
class Dumpable {
    public function dump() {
        var_dump($this->data);
    }
    public function dd() {
        var_dump($this->data);
        die(1);
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function debugFunctionOutsideDebugApiMethodIsFlagged(): void
    {
        $code = <<<'PHP'
<?php
class Service {
    public function process() {
        var_dump($this->data);
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function debugBacktraceIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
function getCallerInfo() {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    return $trace[1]['function'] ?? 'unknown';
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function debugPrintBacktraceIsStillFlagged(): void
    {
        $code = <<<'PHP'
<?php
function test() {
    debug_print_backtrace();
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(1, $locations);
        self::assertSame('debug_print_backtrace', $locations[0]->extra);
    }

    #[Test]
    public function debugInfoMethodIsNotFlagged(): void
    {
        $code = <<<'PHP'
<?php
class Entity {
    public function __debugInfo(): array {
        return ['id' => var_export($this->id, true)];
    }
}
PHP;
        $visitor = $this->analyze($code);

        // var_export with true inside __debugInfo: both exclusions apply
        $locations = $visitor->getLocationsByType('debug_code');
        self::assertCount(0, $locations);
    }

    private function analyze(string $code): CodeSmellVisitor
    {
        $visitor = new CodeSmellVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor;
    }
}
