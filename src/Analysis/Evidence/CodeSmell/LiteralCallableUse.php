<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;

/** Identifies methods of this class referenced by literal callable values. */
final class LiteralCallableUse
{
    /**
     * @param array<string, true> $sameClassReceiverVariables
     */
    public static function method(Node $node, UnusedPrivateClassData $data, array $sameClassReceiverVariables): ?string
    {
        if ($node instanceof Array_) {
            return self::arrayMethod($node, $data, $sameClassReceiverVariables);
        }

        return $node instanceof Arg && $node->value instanceof String_
            ? self::stringMethod($node->value->value, $data)
            : null;
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     */
    private static function arrayMethod(Array_ $node, UnusedPrivateClassData $data, array $sameClassReceiverVariables): ?string
    {
        if (\count($node->items) !== 2) {
            return null;
        }

        [$receiver, $method] = $node->items;
        if (!self::isPositionalItem($receiver) || !self::isPositionalItem($method) || !$method->value instanceof String_) {
            return null;
        }

        return self::sameClassReceiver($receiver->value, $data, $sameClassReceiverVariables)
            ? strtolower($method->value->value)
            : null;
    }

    /** @phpstan-assert-if-true ArrayItem $item */
    private static function isPositionalItem(?ArrayItem $item): bool
    {
        return $item !== null && $item->key === null && !$item->unpack;
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     */
    private static function sameClassReceiver(Expr $receiver, UnusedPrivateClassData $data, array $sameClassReceiverVariables): bool
    {
        if ($receiver instanceof ClassConstFetch) {
            return OwnClassReference::node($receiver->class, $data)
                && $receiver->name instanceof Identifier
                && $receiver->name->toLowerString() === 'class';
        }

        if ($receiver instanceof String_) {
            return OwnClassReference::literal($receiver->value, $data);
        }

        return $receiver instanceof MagicConst\Class_ || OwnClassReference::receiver($receiver, $data, $sameClassReceiverVariables);
    }

    private static function stringMethod(string $callable, UnusedPrivateClassData $data): ?string
    {
        $separator = strrpos($callable, '::');
        if ($separator === false || $separator === 0 || $separator === \strlen($callable) - 2) {
            return null;
        }

        $class = substr($callable, 0, $separator);
        $method = substr($callable, $separator + 2);

        return OwnClassReference::literal($class, $data) ? strtolower($method) : null;
    }
}
