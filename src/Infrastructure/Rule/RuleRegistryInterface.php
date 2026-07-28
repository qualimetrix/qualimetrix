<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;

/**
 * Registry of available rules.
 *
 * Provides access to rule classes and their CLI metadata. Rule *instances* are
 * built by the DI container only — a rule may declare constructor dependencies
 * beyond its Options object, so the registry deliberately exposes no factory.
 */
interface RuleRegistryInterface
{
    /**
     * Returns class names of all registered rules.
     *
     * @return list<class-string<RuleInterface>>
     */
    public function getClasses(): array;

    /**
     * Collects all CLI aliases from all registered rules.
     *
     *
     * @throws ConflictingCliAliasException when two rules define the same alias
     *
     * @return array<string, array{rule: string, option: string}>
     */
    public function getAllCliAliases(): array;
}
