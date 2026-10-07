<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ControlFlow;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/** Recognizes work evaluated before a chain-of-attempts exit. */
final class AttemptWork
{
    public function in(Expr $expression): bool
    {
        $visitor = new class extends NodeVisitorAbstract {
            public bool $found = false;

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Expr\ArrowFunction || $node instanceof Expr\Closure) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if (
                    ($node instanceof FuncCall || $node instanceof MethodCall || $node instanceof StaticCall)
                    && $node->isFirstClassCallable()
                ) {
                    return null;
                }

                if (
                    $node instanceof FuncCall
                    || $node instanceof MethodCall
                    || $node instanceof StaticCall
                    || $node instanceof New_
                    || $node instanceof Include_
                    || $node instanceof Throw_
                ) {
                    $this->found = true;

                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse([$expression]);

        return $visitor->found;
    }
}
