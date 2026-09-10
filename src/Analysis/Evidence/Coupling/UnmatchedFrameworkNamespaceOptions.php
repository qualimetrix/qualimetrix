<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnmatchedFrameworkNamespaceRule}: one key, the gate.
 *
 * On by default, unlike most opt-in diagnostics: the rule can only report
 * about prefixes the author wrote, so a project that declares no
 * `coupling.frameworkNamespaces` never hears from it, and one that does has
 * asked for the classification this rule checks actually happened.
 *
 * No severity key. The signal is one thing — the application scope is wider
 * than the configuration asks for — and a project that wants it to fail a
 * pipeline has `--fail-on=warning`, while one that does not has the baseline
 * and `--disable-rule`. A second dial would only let the same fact be reported
 * as an error in one repository and ignored in the next.
 */
final readonly class UnmatchedFrameworkNamespaceOptions implements RuleOptionsInterface
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
        return $value > 0 ? Severity::Warning : null;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of('enabled');
    }
}
