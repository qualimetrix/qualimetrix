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
 * Supports `allowed_prefixes` option to whitelist self-documenting
 * boolean parameters (e.g., $isActive, $hasPermission).
 *
 * Bad:  function save(bool $overwrite) {}
 * Good: function save() {} and function saveOverwriting() {}
 */
final class BooleanArgumentRule extends AbstractCodeSmellRule
{
    public const string NAME = 'code-smell.boolean-argument';
    protected const string DESCRIPTION = 'Detects boolean arguments in method/function signatures';
    protected const string SMELL_TYPE = 'boolean_argument';
    protected const Severity SEVERITY = Severity::Warning;
    protected const string MESSAGE_TEMPLATE = 'Boolean argument detected - consider splitting methods or using enums';
    protected const ?string MESSAGE_TEMPLATE_WITH_EXTRA = 'Boolean argument $%s detected - consider splitting methods or using enums';
    protected const ?string RECOMMENDATION = 'Replace boolean parameter with two explicit methods or use an enum.';

    /**
     * @return class-string<BooleanArgumentOptions>
     */
    public static function getOptionsClass(): string
    {
        return BooleanArgumentOptions::class;
    }
}
