<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell\ControlFlow;

use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\ControlFlow\ControlFlowSmells;

#[CoversClass(ControlFlowSmells::class)]
final class ControlFlowSmellsTest extends TestCase
{
    #[Test]
    public function itRecognizesOnlyTheClosedControlFlowSet(): void
    {
        $nodes = $this->nodes(<<<'PHP'
<?php
foreach ($items as $item) { try { work(); } catch (Throwable) { continue; } }
try { work(); } catch (Throwable) {}
goto retry;
exit;
for ($i = 0; count(values($i)); ++$i) {}
while (sizeof($items)) {}
do {} while (count($items));
PHP);
        $smells = new ControlFlowSmells();
        $locations = [];
        foreach ($nodes as $node) {
            array_push($locations, ...$smells->locations($node, 'file', $node instanceof \PhpParser\Node\Stmt\Foreach_ ? 1 : 0));
        }

        self::assertSame(['empty_catch', 'goto', 'exit', 'count_in_loop', 'count_in_loop', 'count_in_loop'], array_column($locations, 'type'));
    }

    #[Test]
    public function itSkipsCountCallsInsideClosuresAndArrows(): void
    {
        $nodes = $this->nodes('<?php while ((static fn() => count($items))()) {}');
        $while = (new NodeFinder())->findFirstInstanceOf($nodes, \PhpParser\Node\Stmt\While_::class);
        self::assertInstanceOf(\PhpParser\Node\Stmt\While_::class, $while);

        self::assertSame([], (new ControlFlowSmells())->locations($while, 'file', 0));
    }

    #[Test]
    public function itPrunesCountCallsInsideARealClosure(): void
    {
        $while = (new NodeFinder())->findFirstInstanceOf(
            $this->nodes('<?php while ((function () use ($items) { return count($items); })()) {}'),
            \PhpParser\Node\Stmt\While_::class,
        );
        self::assertInstanceOf(\PhpParser\Node\Stmt\While_::class, $while);

        self::assertSame([], (new ControlFlowSmells())->locations($while, 'file', 0));
    }

    #[Test]
    public function itDoesNotTreatFirstClassCountSyntaxAsALoopInvocation(): void
    {
        $while = (new NodeFinder())->findFirstInstanceOf(
            $this->nodes('<?php while (count(...)) {}'),
            \PhpParser\Node\Stmt\While_::class,
        );
        self::assertInstanceOf(\PhpParser\Node\Stmt\While_::class, $while);

        self::assertSame([], (new ControlFlowSmells())->locations($while, 'file', 0));
    }

    #[Test]
    public function itTreatsNestedIfElseifAndElseChainSignalsAsForeachControlFlow(): void
    {
        $tryCatch = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse(<<<'PHP'
<?php
foreach ($items as $item) {
    try { if ($first) { work(); } elseif ($second) { if ($nested) { continue; } } else { return; } } catch (Throwable) {}
}
PHP) ?? [],
            \PhpParser\Node\Stmt\TryCatch::class,
        );
        self::assertInstanceOf(\PhpParser\Node\Stmt\TryCatch::class, $tryCatch);

        self::assertSame([], (new ControlFlowSmells())->locations($tryCatch, 'file', 1));
    }

    #[Test]
    public function itDoesNotSuppressAnEmptyForeachCatchWithoutAChainSignal(): void
    {
        $tryCatch = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php foreach ($items as $item) { try { work(); } catch (Throwable) {} if ($ok) { work(); } }') ?? [],
            \PhpParser\Node\Stmt\TryCatch::class,
        );
        self::assertInstanceOf(\PhpParser\Node\Stmt\TryCatch::class, $tryCatch);

        self::assertSame(['empty_catch'], array_column((new ControlFlowSmells())->locations($tryCatch, 'file', 1), 'type'));
    }

    #[Test]
    public function itLeavesResidualAndOtherCategoryNodesToTheirOwners(): void
    {
        $nodes = $this->nodes('<?php eval($code); @work(); $GLOBALS["key"]; var_dump($value); function f(bool $enabled) {}');
        $finder = new NodeFinder();
        $negativeNodes = [
            $finder->findFirstInstanceOf($nodes, \PhpParser\Node\Expr\Eval_::class),
            $finder->findFirstInstanceOf($nodes, \PhpParser\Node\Expr\ErrorSuppress::class),
            $finder->findFirst($nodes, static fn(\PhpParser\Node $node): bool => $node instanceof \PhpParser\Node\Expr\Variable && $node->name === 'GLOBALS'),
            $finder->findFirst($nodes, static fn(\PhpParser\Node $node): bool => $node instanceof \PhpParser\Node\Expr\FuncCall && $node->name instanceof \PhpParser\Node\Name && $node->name->toString() === 'var_dump'),
            $finder->findFirstInstanceOf($nodes, \PhpParser\Node\Param::class),
        ];

        foreach ($negativeNodes as $node) {
            self::assertInstanceOf(\PhpParser\Node::class, $node);
            self::assertSame([], (new ControlFlowSmells())->locations($node, 'file', 0));
        }
    }

    /** @return list<\PhpParser\Node> */
    private function nodes(string $code): array
    {
        return array_values((new NodeFinder())->findInstanceOf((new ParserFactory())->createForHostVersion()->parse($code) ?? [], \PhpParser\Node::class));
    }
}
