<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnboundSuppressionRule}: one key, the gate.
 *
 * On by default, for the reason its discovery sibling is: the channels can
 * only speak about a value the author wrote, so a project that suppresses
 * nothing never hears from them.
 *
 * No severity key. The three channels report one kind of fact — a configured
 * suppression names something this run does not contain — and a project that
 * wants it to fail a pipeline has `--fail-on=warning`, while one that does not
 * has `--disable-rule`.
 */
final readonly class UnboundSuppressionOptions implements RuleOptionsInterface
{
    /**
     * The channel names live here rather than on the rule because both the
     * rule and the audit need them, and constants on the rule would make the
     * audit depend on the class that depends on it — the two-class cycle this
     * project's own `architecture.circular-dependency` reports. Options is the
     * one member of the trio neither of the other two can avoid naming.
     */
    public const string UNMATCHED_PATH = 'suppression.unmatched-path';

    public const string UNMATCHED_NAMESPACE = 'suppression.unmatched-namespace';

    public const string UNMATCHED_RULE_LEDGER = 'suppression.unmatched-rule-ledger';

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
