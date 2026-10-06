<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use Qualimetrix\Core\Ast\ResolvedName;

/** Recognizes references to the class whose private members are being inspected. */
final class OwnClassReference
{
    public static function node(Node $class, UnusedPrivateClassData $data): bool
    {
        if (!$class instanceof Name) {
            return false;
        }

        if (self::isSelfOrStatic($class)) {
            return true;
        }

        $resolved = ResolvedName::className($class);

        return $resolved !== null && self::literal($resolved, $data);
    }

    /**
     * @param array<string, true> $sameClassReceiverVariables
     */
    public static function receiver(Expr $receiver, UnusedPrivateClassData $data, array $sameClassReceiverVariables): bool
    {
        if ($receiver instanceof New_) {
            return self::node($receiver->class, $data);
        }

        return $receiver instanceof Variable
            && ($receiver->name === 'this' || (\is_string($receiver->name) && isset($sameClassReceiverVariables[$receiver->name])));
    }

    public static function literal(string $class, UnusedPrivateClassData $data): bool
    {
        if (\in_array(strtolower($class), ['self', 'static'], true)) {
            return true;
        }

        $own = ($data->namespace === null || $data->namespace === '')
            ? $data->className
            : $data->namespace . '\\' . $data->className;

        return ResolvedName::sameClass(ltrim($class, '\\'), $own);
    }

    private static function isSelfOrStatic(Name $name): bool
    {
        $lower = $name->toLowerString();

        return $lower === 'self' || $lower === 'static';
    }
}
