<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;
use InvalidArgumentException;

/**
 * Detects boolean arguments in method/function signatures.
 *
 * Boolean arguments often indicate that a method does too many things
 * and should be split into separate methods. They harm readability
 * since the caller must know what `true` or `false` means.
 *
 * Bad:  function save(bool $overwrite) {}
 * Good: function save() {} and function saveOverwriting() {}
 */
final class BooleanArgumentRule extends AbstractCodeSmellRule
{
    public const string NAME = 'boolean-argument';

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
        return 'Detects boolean arguments in method/function signatures';
    }

    protected function getSmellType(): string
    {
        return 'boolean_argument';
    }

    protected function getSeverity(): Severity
    {
        return Severity::Warning;
    }

    protected function getMessageTemplate(): string
    {
        return 'Found {count} boolean argument(s) - consider splitting methods or using enums';
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
