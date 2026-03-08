<?php

declare(strict_types=1);

namespace AiMessDetector\Rules;

use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use InvalidArgumentException;

/**
 * Base class for all analysis rules.
 *
 * Provides common functionality and protected access to options.
 * Validates that the options instance matches the expected class from getOptionsClass().
 */
abstract class AbstractRule implements RuleInterface
{
    /**
     * @param RuleOptionsInterface $options Rule options
     */
    public function __construct(
        protected readonly RuleOptionsInterface $options,
    ) {
        $expected = static::getOptionsClass();
        if (!$options instanceof $expected) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', $expected, $options::class),
            );
        }
    }

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
