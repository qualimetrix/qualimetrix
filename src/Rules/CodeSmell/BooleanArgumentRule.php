<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Violation\Severity;

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
    public const string NAME = 'code-smell.boolean-argument';

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
        return 'Boolean argument detected - consider splitting methods or using enums';
    }

    protected function getRecommendation(): string
    {
        return 'Replace boolean parameter with two explicit methods or use an enum.';
    }

    /**
     * Includes the parameter name in the message when available.
     *
     * @param array<string, mixed> $entry
     */
    protected function buildMessage(array $entry): string
    {
        $paramName = isset($entry['extra']) && \is_string($entry['extra']) ? $entry['extra'] : null;

        if ($paramName !== null) {
            return \sprintf('Boolean argument $%s detected - consider splitting methods or using enums', ltrim($paramName, '$'));
        }

        return $this->getMessageTemplate();
    }

    /**
     * @return class-string<CodeSmellOptions>
     */
    public static function getOptionsClass(): string
    {
        return CodeSmellOptions::class;
    }
}
