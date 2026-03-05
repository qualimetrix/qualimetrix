<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects direct access to superglobals ($_GET, $_POST, etc).
 *
 * Direct superglobal access violates dependency injection principles
 * and makes code harder to test. Use Request objects instead.
 */
final class SuperglobalsRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.superglobals';

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
        return 'Detects direct access to superglobals';
    }

    protected function getSmellType(): string
    {
        return 'superglobals';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} direct superglobal access(es) - use dependency injection';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
