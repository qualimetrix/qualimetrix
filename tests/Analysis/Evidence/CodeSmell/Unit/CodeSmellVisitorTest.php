<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellLocation;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;

#[CoversClass(CodeSmellVisitor::class)]
#[CoversClass(CodeSmellLocation::class)]
final class CodeSmellVisitorTest extends TestCase
{
    #[Test]
    public function itDetectsGlobalsAsASuperglobalAccess(): void
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
    public function itDetectsAllSuperglobalVariables(): void
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
    public function itDoesNotFlagCountInsideAClosureUsedInALoopCondition(): void
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
    public function itDoesNotFlagCountInsideAnArrowFunctionUsedInALoopCondition(): void
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
    public function itFlagsCountUsedDirectlyInALoopCondition(): void
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
    public function itFlagsSizeofUsedInALoopCondition(): void
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
    public function itFlagsACatchBlockContainingOnlyAComment(): void
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
    public function itFlagsACatchBlockWithNoStatements(): void
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
    public function itDoesNotFlagACatchBlockThatHandlesTheException(): void
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
    public function itDoesNotFlagAnEmptyCatchInAForeachThatReturnsAsAChainOfResponsibilityPattern(): void
    {
        // foreach + try { return ... } catch { } is a legitimate chain-of-responsibility pattern
        $code = <<<'PHP'
<?php
class ChainedPublicUrlGenerator {
    public function publicUrl(string $path): string {
        foreach ($this->generators as $generator) {
            try {
                return $generator->publicUrl($path);
            } catch (\Exception $e) {
            }
        }
        throw new \RuntimeException('No generator could handle the path');
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function itDoesNotFlagAnEmptyCatchWithACommentInAForeachThatReturnsAsAChainOfResponsibilityPattern(): void
    {
        // Same pattern with a comment in the catch block (Nop node)
        $code = <<<'PHP'
<?php
class Resolver {
    public function resolve(string $key): mixed {
        foreach ($this->resolvers as $resolver) {
            try {
                return $resolver->resolve($key);
            } catch (\Throwable $e) {
                // try next resolver
            }
        }
        return null;
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(0, $locations);
    }

    #[Test]
    public function itFlagsAnEmptyCatchInAForeachThatDoesNotReturn(): void
    {
        // foreach + try { ... } catch { } without return is NOT the chain pattern
        $code = <<<'PHP'
<?php
class Processor {
    public function process(): void {
        foreach ($this->items as $item) {
            try {
                $item->doSomething();
            } catch (\Exception $e) {
            }
        }
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function itFlagsAnEmptyCatchOutsideAForeachEvenWhenTheTryReturns(): void
    {
        // try { return ... } catch { } outside foreach is still flagged
        $code = <<<'PHP'
<?php
class Service {
    public function get(): mixed {
        try {
            return $this->fetch();
        } catch (\Exception $e) {
        }
        return null;
    }
}
PHP;
        $visitor = $this->analyze($code);

        $locations = $visitor->getLocationsByType('empty_catch');
        self::assertCount(1, $locations);
    }

    #[Test]
    public function itDoesNotFlagVarExportCalledWithReturnTrue(): void
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
    public function itDoesNotFlagPrintRCalledWithReturnTrue(): void
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
    public function itDoesNotFlagVarExportCalledWithANamedReturnArgument(): void
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
    public function itFlagsPrintRCalledWithoutReturnTrue(): void
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
    public function itFlagsVarExportCalledWithoutReturnTrue(): void
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
    public function itFlagsVarExportCalledWithAnExplicitFalseReturnArgument(): void
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
    public function itDoesNotFlagDebugFunctionsInsideADebugApiMethod(): void
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
    public function itFlagsDebugFunctionsOutsideADebugApiMethod(): void
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
    public function itDoesNotFlagDebugBacktrace(): void
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
    public function itFlagsDebugPrintBacktrace(): void
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
    public function itDoesNotFlagVarExportInsideDebugInfo(): void
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

    #[Test]
    public function itFlagsAnEmptyForeachCatchWhoseTryEndsWithAContinueThatSkipsNothing(): void
    {
        // Nothing follows the try in the loop body, so the continue is a no-op and the catch swallows every failure.
        $visitor = $this->analyze(<<<'PHP'
<?php
class Worker {
    public function run(array $items): void {
        foreach ($items as $item) {
            try {
                $this->work($item);
                continue;
            } catch (\Throwable $e) {
            }
        }
    }
}
PHP);

        self::assertCount(1, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itKeepsTheChainExceptionForAContinueThatSkipsAFallback(): void
    {
        $visitor = $this->analyze(<<<'PHP'
<?php
class Worker {
    public function run(array $items): void {
        foreach ($items as $item) {
            try {
                $this->fast($item);
                continue;
            } catch (\Throwable $e) {
            }
            $this->slow($item);
        }
    }
}
PHP);

        self::assertCount(0, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itKeepsTheChainExceptionForATryThatEndsByReturningAFoundValue(): void
    {
        $visitor = $this->analyze(<<<'PHP'
<?php
class Parser {
    public function parse(string $input): ?int {
        foreach ($this->parsers as $parser) {
            try {
                $result = $parser->parse($input);
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }
}
PHP);

        self::assertCount(0, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itKeepsTheChainExceptionForAnAttemptThatReturnsOnSuccessAndCollectsOtherwise(): void
    {
        // The any-of shape: return when an attempt validates, collect its errors otherwise, try the next on a throw.
        $visitor = $this->analyze(<<<'PHP'
<?php
class AnyOf {
    public function check(array $schemas): void {
        foreach ($schemas as $schema) {
            try {
                $schema->check();
                if ($schema->isValid()) {
                    return;
                }
                $this->collect($schema->errors());
            } catch (\RuntimeException $e) {
            }
        }
    }
}
PHP);

        self::assertCount(0, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itFlagsAnEmptyForeachCatchWhoseBranchContinueSkipsNothing(): void
    {
        $visitor = $this->analyze(<<<'PHP'
<?php
class Worker {
    public function run(array $items): void {
        foreach ($items as $item) {
            try {
                if ($item !== null) {
                    $this->work($item);
                    continue;
                }
                $this->other();
            } catch (\Throwable $e) {
            }
        }
    }
}
PHP);

        self::assertCount(1, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itFlagsAnEmptyCatchInsideAClosureDeclaredInAForeach(): void
    {
        // The closure body is its own scope: its try is not an attempt of the enclosing loop.
        $visitor = $this->analyze(<<<'PHP'
<?php
class Worker {
    public function run(array $items): void {
        foreach ($items as $item) {
            $callback = function () use ($item) {
                try {
                    return $this->work($item);
                } catch (\Throwable $e) {
                }
            };
        }
    }
}
PHP);

        self::assertCount(1, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itFlagsAnEmptyCatchNestedBelowTheForeachBody(): void
    {
        $visitor = $this->analyze(<<<'PHP'
<?php
class Worker {
    public function run(array $items): mixed {
        foreach ($items as $item) {
            if ($item !== null) {
                try {
                    return $this->work($item);
                } catch (\Throwable $e) {
                }
            }
        }
        return null;
    }
}
PHP);

        self::assertCount(1, $visitor->getLocationsByType('empty_catch'));
    }

    #[Test]
    public function itDetectsAFullyQualifiedExitCall(): void
    {
        // PHP 8.4 made exit()/die() functions: the fully qualified spelling parses as a function call.
        $visitor = $this->analyze('<?php function stop(): void { \exit(1); } function halt(): void { \die("x"); } function plain(): void { exit(1); }');

        self::assertCount(3, $visitor->getLocationsByType('exit'));
    }

    #[Test]
    public function itDoesNotTreatAFirstClassExitReferenceAsAnExit(): void
    {
        $visitor = $this->analyze('<?php $stop = \exit(...);');

        self::assertCount(0, $visitor->getLocationsByType('exit'));
    }

    #[Test]
    public function itDoesNotDetectAVariableVariableSpellingOfASuperglobal(): void
    {
        // Known limit: only the plain variable name is read; `${'_GET'}` is absent from the benchmark corpus.
        $visitor = $this->analyze('<?php function read(): mixed { return ${\'_GET\'}[\'id\'] ?? $_SERVER[\'x\']; }');

        self::assertSame(['_SERVER'], array_map(static fn(CodeSmellLocation $l): ?string => $l->extra, $visitor->getLocationsByType('superglobals')));
    }

    #[Test]
    public function itRecognizesFullyQualifiedAndUppercaseSpellingsOfNamedCalls(): void
    {
        $visitor = $this->analyze('<?php function f(array $a): void { @\FOPEN("x"); EVAL("1;"); for ($i = 0; $i < \COUNT($a); ++$i) {} \VAR_DUMP($a); }');

        self::assertSame(['fopen'], array_map(static fn(CodeSmellLocation $l): ?string => $l->extra, $visitor->getLocationsByType('error_suppression')));
        self::assertCount(1, $visitor->getLocationsByType('eval'));
        self::assertCount(1, $visitor->getLocationsByType('count_in_loop'));
        self::assertCount(1, $visitor->getLocationsByType('debug_code'));
    }

    #[Test]
    public function itDoesNotResolveAnImportedCountAlias(): void
    {
        // Known limit: no name resolution runs during collection, so an alias is read as written.
        $visitor = $this->analyze('<?php use function count as size; function f(array $a): void { for ($i = 0; $i < size($a); ++$i) {} }');

        self::assertCount(0, $visitor->getLocationsByType('count_in_loop'));
    }

    private function analyze(string $code): CodeSmellVisitor
    {
        $visitor = new CodeSmellVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor;
    }
}
