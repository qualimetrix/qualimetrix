<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;

/**
 * Options for {@see UnusedDirectiveRule}.
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
        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            unusedDirectiveSeverity: self::resolveSeverity(
                $config['unused_directive_severity'] ?? $config['unusedDirectiveSeverity'] ?? null,
            ),
        );
    }

    /**
     * Refuses a value it cannot honour instead of quietly substituting the
     * default.
     *
     * `Severity::tryFrom()` is case-sensitive, so the previous
     * `tryFrom($raw) ?? Info` turned both `Warning` and the typo `warnin`
     * into `info` — a config file saying one thing while the tool did
     * another, which is the same lie this rule exists to report about
     * annotations. Case is normalised because the enum's own spelling is an
     * implementation detail; an unknown word is a mistake and is named as
     * one.
     */
    private static function resolveSeverity(mixed $raw): Severity
    {
        if ($raw === null) {
            return Severity::Info;
        }

        if ($raw instanceof Severity) {
            return $raw;
        }

        if (!\is_string($raw)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "unused_directive_severity" for rule "%s" must be a string, got %s.',
                InlineDirectivePolicyInterface::PRODUCER_RULE_NAME,
                get_debug_type($raw),
            ));
        }

        $severity = Severity::tryFrom(strtolower($raw));
        if ($severity !== null) {
            return $severity;
        }

        throw new InvalidArgumentException(\sprintf(
            'Option "unused_directive_severity" for rule "%s" has unknown value "%s"; expected one of %s.',
            InlineDirectivePolicyInterface::PRODUCER_RULE_NAME,
            $raw,
            implode(', ', array_map(static fn(Severity $c): string => "'{$c->value}'", Severity::cases())),
        ));
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
