<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Simple options for code smell rules.
 *
 * Most code smell rules just need enabled/disabled toggle.
 */
final readonly class CodeSmellOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Error : null;
    }
}
