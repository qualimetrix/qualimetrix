<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Ast;

use PhpParser\ErrorHandler;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final class NameResolution
{
    /** @param array<Node> $ast */
    public static function resolve(array $ast, ?ErrorHandler $errorHandler = null): void
    {
        $traverser = new NodeTraverser(new NameResolver($errorHandler ?? new Collecting(), ['replaceNodes' => false]));
        $traverser->traverse($ast);
    }
}
