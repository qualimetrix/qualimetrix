<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnassignedClassRule}: one key, and it is the gate.
 *
 * `mode` decides both whether the channel reports and how loudly, so
 * {@see isEnabled()} is derived from it rather than sitting beside it. A
 * second `enabled` key would be a second switch for one decision — and the
 * one that is off by default would silently win over the one the author
 * wrote.
 *
 * It is also the only gate in fact and not only in intent, which took one fix
 * after the split: the shared walk in {@see LayerEvidenceCollector} read the
 * layer-violation rule's `enabled` as its entry condition, so
 * `layer-violation: {enabled: false}` silenced this channel from a sibling's
 * options. The walk now runs for either producer and every consumer checks its
 * own gate. `--disable-rule=architecture.layer-violation` never silenced this
 * rule — that is the selector, and it addresses the two producers separately.
 */
final readonly class UnassignedClassOptions implements RuleOptionsInterface
{
    /**
     * Duplicates {@see UnassignedClassRule::NAME} as a literal rather than
     * referencing the class constant, so this Options DTO does not gain a
     * dependency edge onto the rule it configures — the same reason
     * {@see LayerViolationOptions} spells its own rule name out.
     */
    private const string RULE_NAME = 'architecture.unassigned-class';

    public function __construct(
        public UnassignedClassMode $mode = UnassignedClassMode::Ignore,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException When `mode` is set to something no
     *                                  {@see UnassignedClassMode} case spells.
     */
    public static function fromArray(array $config): self
    {
        $mode = self::resolveMode($config['mode'] ?? null);
        self::assertNoContradictoryEnabled($config, $mode);

        return new self(mode: $mode);
    }

    /**
     * Refuses an `enabled` that would lie, and accepts the one that agrees.
     *
     * Every other rule takes `enabled`, so writing it here is the natural
     * mistake rather than a typo — and the generic "Unknown option" warning
     * ({@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}
     * builds its known-key set from constructor parameters) names the key
     * without saying what to write instead, while the run continues with a gate
     * the author believes they set. But refusing the key outright is worse,
     * because one spelling of it is not a mistake at all: `rules: {
     * architecture.unassigned-class: false }` is the idiom every rule in the
     * tool answers to, and it is normalised into `enabled: false` before it
     * arrives here. Refusing that would refuse an author switching off a rule
     * that is off by default — a hard error for asking for the status quo.
     *
     * So the two spellings are separated by what they would mean:
     * - `enabled: false` with the gate at `ignore` asks for what is already the
     *   case, and is accepted in silence;
     * - `enabled: false` beside `mode: warn|error` is two switches disagreeing,
     *   and the one written second would silently win;
     * - `enabled: true` promises to turn the gate on and does not, which is the
     *   lie the refusal exists to end.
     *
     * Naming the key and its replacement is the treatment
     * {@see LayerViolationOptions} already gives the three severity keys it
     * removed.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function assertNoContradictoryEnabled(array $config, UnassignedClassMode $mode): void
    {
        if (!\array_key_exists(RuleOptionKey::ENABLED, $config)) {
            return;
        }

        $enabled = (bool) $config[RuleOptionKey::ENABLED];

        if (!$enabled && $mode === UnassignedClassMode::Ignore) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'Option "%s" for rule "%s" does not exist, and here it would %s. "mode" is the only switch: write'
            . ' "mode: ignore" to decline the rule and "mode: warn" or "mode: error" to turn it on. A second switch'
            . ' would be a second answer to one question, and the one that is off by default would win over the one'
            . ' you wrote.',
            RuleOptionKey::ENABLED,
            self::RULE_NAME,
            $enabled
                ? 'promise to turn the rule on without doing so'
                : \sprintf('contradict "mode: %s" written beside it', $mode->value),
        ));
    }

    public function isEnabled(): bool
    {
        return $this->mode !== UnassignedClassMode::Ignore;
    }

    /**
     * The mode read as a severity, or null while the gate is off — the same
     * "disabled and within tolerance answer alike" contract every other rule's
     * options keep.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return match ($this->mode) {
            UnassignedClassMode::Ignore => null,
            UnassignedClassMode::Warn => Severity::Warning,
            UnassignedClassMode::Error => Severity::Error,
        };
    }

    /**
     * @throws InvalidArgumentException When $raw is set but not a recognized mode string.
     */
    private static function resolveMode(mixed $raw): UnassignedClassMode
    {
        if ($raw === null) {
            return UnassignedClassMode::Ignore;
        }

        if ($raw instanceof UnassignedClassMode) {
            return $raw;
        }

        if (!\is_string($raw)) {
            throw new InvalidArgumentException(\sprintf(
                'Option "mode" for rule "%s" must be a string, got %s.',
                self::RULE_NAME,
                get_debug_type($raw),
            ));
        }

        $normalized = strtolower($raw);
        foreach (UnassignedClassMode::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        $allowed = implode(', ', array_map(static fn(UnassignedClassMode $c): string => "'{$c->value}'", UnassignedClassMode::cases()));

        throw new InvalidArgumentException(\sprintf(
            'Option "mode" for rule "%s" has unknown value "%s"; expected one of %s.',
            self::RULE_NAME,
            $raw,
            $allowed,
        ));
    }
}
