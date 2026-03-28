<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Shared logic for classifying AST nodes as member usages.
 *
 * Recognises five patterns:
 * - $this->method()           → usedMethods
 * - self::method() / static:: → usedMethods
 * - $this->property           → usedProperties
 * - self::$prop / static::    → usedProperties
 * - self::CONST / static::    → usedConstants
 */
trait UsageTrackingTrait
{
    /**
     * Classify a single AST node and record the referenced member in $data.
     */
    private function trackUsage(Node $node, UnusedPrivateClassData $data): void
    {
        // $this->method()
        if ($node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $data->usedMethods[$node->name->toString()] = true;

            return;
        }

        // self::method() / static::method()
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Identifier
        ) {
            $data->usedMethods[$node->name->toString()] = true;

            return;
        }

        // $this->property
        if ($node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $data->usedProperties[$node->name->toString()] = true;

            return;
        }

        // self::$property / static::$property
        if ($node instanceof StaticPropertyFetch
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Node\VarLikeIdentifier
        ) {
            $data->usedProperties[$node->name->toString()] = true;

            return;
        }

        // self::CONSTANT / static::CONSTANT
        if ($node instanceof ClassConstFetch
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Identifier
            && $node->name->toString() !== 'class'
        ) {
            $data->usedConstants[$node->name->toString()] = true;
        }
    }

    private function isSelfOrStatic(Name $name): bool
    {
        $lower = $name->toLowerString();

        return $lower === 'self' || $lower === 'static';
    }
}
