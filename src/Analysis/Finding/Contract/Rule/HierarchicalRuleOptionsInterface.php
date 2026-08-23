<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;

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
     * @return list<SymbolLevel>
     */
    public function getSupportedLevels(): array;
}
