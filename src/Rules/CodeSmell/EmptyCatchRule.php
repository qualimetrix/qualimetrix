<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects empty catch blocks.
 *
 * Empty catch blocks silently swallow exceptions, hiding potential errors.
 * At minimum, exceptions should be logged.
 */
final class EmptyCatchRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.empty-catch';

    public function __construct(RuleOptionsInterface $options)
    {
        if (!$options instanceof CodeSmellOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', CodeSmellOptions::class, $options::class),
            );
        }
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects empty catch blocks';
    }

    protected function getSmellType(): string
    {
        return 'empty_catch';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} empty catch block(s) - exceptions should not be silently ignored';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
