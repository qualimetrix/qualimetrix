<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects usage of error suppression operator (@).
 *
 * The @ operator hides errors which can make debugging difficult.
 * Handle errors explicitly instead.
 */
final class ErrorSuppressionRule extends AbstractCodeSmellRule
{
    public const string NAME = 'error-suppression';

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
        return 'Detects usage of error suppression operator (@)';
    }

    protected function getSmellType(): string
    {
        return 'error_suppression';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} error suppression operator(s) (@) - handle errors explicitly';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
