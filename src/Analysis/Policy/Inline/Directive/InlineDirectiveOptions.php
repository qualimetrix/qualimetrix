<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see InlineDirectiveRule}.
 *
 * There is deliberately no severity key for the three configuration-error
 * channels. Their acceptability makes them gate unconditionally, past
 * `fail_on`, so a severity key there would look like a behaviour switch while
 * changing nothing but a word in the report — the same lie as a directive
 * that does nothing.
 *
 * The one severity that is a real choice is the unused-directive channel's:
 * leftover suppressions are ordinary cleanup, and a project mid-cleanup may
 * legitimately want them louder or quieter. It defaults below `Warning` so
 * that adopting this release does not fail a build over annotations that were
 * merely stale.
 */
final readonly class InlineDirectiveOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public Severity $unusedDirectiveSeverity = Severity::Info,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $severity = $config['unused_directive_severity'] ?? $config['unusedDirectiveSeverity'] ?? null;

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            unusedDirectiveSeverity: \is_string($severity)
                ? (Severity::tryFrom($severity) ?? Severity::Info)
                : Severity::Info,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Every channel this rule emits carries the severity its own emission
     * site decided: `Error` for the three configuration errors, because
     * acceptability already makes them unconditional, and the configured
     * value for the unused-directive channel. There is no metric value to
     * grade, so the answer does not depend on the argument.
     */
    public function getSeverity(int|float $value): Severity
    {
        return Severity::Error;
    }
}
