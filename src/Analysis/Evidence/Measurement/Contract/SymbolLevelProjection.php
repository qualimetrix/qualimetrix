<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Symbol\SymbolType;

/**
 * The one projection from "what kind of declaration is this?"
 * ({@see SymbolType}, a fact about PHP) onto "what level of the aggregation
 * tree does it measure at?" ({@see SymbolLevel}, a fact about our model).
 *
 * The two enums stay separate because they answer different questions, and
 * they meet in exactly one place — here — so that the collapse of `method`
 * and `function` into `callable` is stated once rather than re-derived at
 * each call site from whichever fields of a {@see \Qualimetrix\Core\Symbol\SymbolPath}
 * happened to be at hand.
 */
final class SymbolLevelProjection
{
    public static function ofDeclaration(SymbolType $type): SymbolLevel
    {
        return match ($type) {
            SymbolType::Method, SymbolType::Function_ => SymbolLevel::Callable,
            SymbolType::Class_ => SymbolLevel::Class_,
            SymbolType::File => SymbolLevel::File,
            SymbolType::Namespace_ => SymbolLevel::Namespace_,
            SymbolType::Project => SymbolLevel::Project,
        };
    }
}
