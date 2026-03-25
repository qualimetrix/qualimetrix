<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

use InvalidArgumentException;

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
    public function forLevel(RuleLevel $level): LevelOptionsInterface;

    /**
     * Checks if a specific level is enabled.
     */
    public function isLevelEnabled(RuleLevel $level): bool;

    /**
     * Returns all supported levels for this rule.
     *
     * @return list<RuleLevel>
     */
    public function getSupportedLevels(): array;
}
