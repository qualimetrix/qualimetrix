<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects usage of eval() function.
 *
 * The eval() function is a security risk and should be avoided.
 * It executes arbitrary PHP code which can lead to code injection vulnerabilities.
 */
final class EvalRule extends AbstractCodeSmellRule
{
    public const string NAME = 'eval';

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
        return 'Detects usage of eval() function';
    }

    protected function getSmellType(): string
    {
        return 'eval';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Error;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} eval() call(s) - security risk';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
