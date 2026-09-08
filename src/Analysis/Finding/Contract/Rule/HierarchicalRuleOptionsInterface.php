<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Options for rules that operate on multiple levels of hierarchy.
 *
 * Extends RuleOptionsInterface with level-specific capabilities.
 */
interface HierarchicalRuleOptionsInterface extends RuleOptionsInterface
{
    /**
     * Returns options for a specific level.
     *
     * @throws InvalidArgumentException if level is not supported
     */
    public function forLevel(SymbolLevel $level): LevelOptionsInterface;

    /**
     * Checks if a specific level is enabled.
     */
    public function isLevelEnabled(SymbolLevel $level): bool;

    /**
     * Returns all supported levels for this rule.
     *
     * Must agree with the keys of {@see self::levelOptionsClasses()}, which is
     * the declaration; this method is a view of it.
     *
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array;

    /**
     * Names the level options class behind each slot, keyed by the slot name
     * the user writes — a `SymbolLevel` value.
     *
     * Declared rather than derived: the slot `callable` is held by a
     * constructor parameter whose type is `MethodComplexityOptions`, and
     * nothing in the tree makes a parameter's name and its type agree.
     *
     * @return array<string, class-string<LevelOptionsInterface>>
     */
    public static function levelOptionsClasses(): array;
}
