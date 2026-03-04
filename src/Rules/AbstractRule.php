<?php

declare(strict_types=1);

namespace AiMessDetector\Rules;

use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleOptionsInterface;

/**
 * Base class for all analysis rules.
 *
 * Provides common functionality and protected access to options.
 */
abstract class AbstractRule implements RuleInterface
{
    /**
     * @param RuleOptionsInterface $options Rule options
     */
    public function __construct(
        protected readonly RuleOptionsInterface $options,
    ) {}

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    abstract public function getCategory(): RuleCategory;

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [];
    }
}
