<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects usage of exit() and die().
 *
 * exit()/die() should be avoided in library/application code
 * as they terminate the entire script. Use exceptions instead.
 */
final class ExitRule extends AbstractCodeSmellRule
{
    public const string NAME = 'exit';

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
        return 'Detects usage of exit() and die()';
    }

    protected function getSmellType(): string
    {
        return 'exit';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} exit()/die() call(s) - use exceptions instead';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
