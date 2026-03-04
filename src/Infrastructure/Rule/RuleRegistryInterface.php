<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Rule;

use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Infrastructure\Rule\Exception\ConflictingCliAliasException;

/**
 * Registry of available rules.
 *
 * Provides access to rule instances and their CLI metadata.
 */
interface RuleRegistryInterface
{
    /**
     * Returns all registered rules.
     *
     * @return iterable<RuleInterface>
     */
    public function getAll(): iterable;

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
