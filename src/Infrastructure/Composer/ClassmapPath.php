<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use PhpParser\Node;

/**
 * The path a generated classmap entry names, without running the file.
 *
 * Composer writes entries as `$vendorDir . '/acme/src/Thing.php'`, so the value
 * is an expression rather than a string and the two variables the file defines
 * have to be substituted. Anything else -- a call, a constant, a shape a future
 * composer might emit -- yields null rather than a guess, so an entry this
 * cannot read is skipped instead of pointing somewhere wrong.
 */
final readonly class ClassmapPath
{
    /**
     * @param array<string, string> $variables
     */
    public function resolve(Node $node, array $variables): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\Variable && \is_string($node->name)) {
            return $variables[$node->name] ?? null;
        }

        if (!$node instanceof Node\Expr\BinaryOp\Concat) {
            return null;
        }

        $left = $this->resolve($node->left, $variables);
        $right = $this->resolve($node->right, $variables);

        return $left === null || $right === null ? null : $left . $right;
    }
}
