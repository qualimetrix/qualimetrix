<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency\Handler;

use PhpParser\Node;

interface NodeDependencyHandlerInterface
{
    /**
     * @return list<class-string<Node>>
     */
    public static function supportedNodeClasses(): array;

    public function handle(Node $node, DependencyContext $context): void;
}
