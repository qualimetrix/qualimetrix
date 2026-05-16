<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule\Attribute;

use Attribute;

/**
 * Declares a CLI short alias for a rule option.
 *
 * Repeatable class-level attribute on rule implementations. Read via
 * Reflection by CliAliasReader — no rule instantiation required.
 *
 * Example:
 *   #[CliAlias('cyclomatic-warning', 'method.warning')]
 *   #[CliAlias('cyclomatic-error', 'method.error')]
 *   final class ComplexityRule extends AbstractRule { }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class CliAlias
{
    public function __construct(
        public string $alias,
        public string $optionName,
    ) {}
}
