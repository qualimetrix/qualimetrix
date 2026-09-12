<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnmatchedExcludeRule}: one key, the gate.
 *
 * On by default, for the reason its coupling sibling is: the channel can only
 * speak about a pattern the author wrote, so a project that excludes nothing
 * never hears from it, and one that does has asked for those directories to be
 * left out — which is the claim being checked.
 *
 * No severity key. The fact reported is a single one — the run looked at code
 * the configuration meant to skip — and a project that wants it to fail a
 * pipeline has `--fail-on=warning`, while one that does not has the baseline
 * and `--disable-rule`.
 */
final readonly class UnmatchedExcludeOptions implements RuleOptionsInterface
{
    /**
     * The channel's name lives here rather than on the rule because both the
     * rule and the audit need it, and a constant on the rule would make the
     * audit depend on the class that depends on it — a two-class cycle the
     * project's own `architecture.circular-dependency` reports. Options is the
     * one member of the trio neither of the other two can avoid naming.
     */
    public const string CHANNEL = 'discovery.unmatched-exclude';

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
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
        ]);
    }
}
