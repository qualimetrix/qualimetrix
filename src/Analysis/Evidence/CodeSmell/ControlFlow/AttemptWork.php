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
use PhpParser\NodeFinder;

/** Recognizes work evaluated before a chain-of-attempts exit. */
final class AttemptWork
{
    public function in(Expr $expression): bool
    {
        return (new NodeFinder())->findFirst($expression, static fn(Node $node): bool => $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof Include_
            || $node instanceof Throw_) !== null;
    }
}
