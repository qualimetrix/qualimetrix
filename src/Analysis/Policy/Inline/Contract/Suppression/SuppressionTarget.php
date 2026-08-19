<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Stringable;

/**
 * What an inline suppression directive filters on.
 *
 * Three states, and the last one is the point of this type:
 *
 * - **a channel selector** — an exact `violationCode`, or `X.*` for its strict
 *   descendants (see {@see NameSelector});
 * - **an explicit channel** — the `ruleName#violationCode` pair, both halves
 *   exact. It is the one spelling that says which half is meant, which
 *   matters wherever a rule name and a channel name coincide; a `*` inside
 *   either half is not accepted, because a group is what the one-part form
 *   already says;
 * - **no rule filter at all** — "every finding here, whatever it is". This is
 *   what `@qmx-ignore *` on a symbol or line means, and what a bare
 *   `@qmx-ignore-file` with no argument means.
 *
 * The second state used to ride on the same `*` token the selector machinery
 * carried, which made it look like a wildcard *selector* — the one spelling
 * that could never resolve to a name. It is not a selector: it is the absence
 * of a filter, and modelling it as such is what lets the wildcard token
 * disappear from the selector vocabulary without the two documented "suppress
 * everything here" spellings disappearing with it.
 *
 * Text that is none of the three filters nothing. The directive is then inert
 * rather than silently broad, and the inline-directive rule reports it as a
 * configuration error.
 */
final readonly class SuppressionTarget implements Stringable
{
    /**
     * The authored spelling of "no rule filter" for the symbol and next-line
     * forms; the file form spells it by omitting the argument entirely and is
     * desugared to this by
     * {@see \Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor}.
     */
    public const string NO_RULE_FILTER = '*';

    /** The separator of the explicit `ruleName#violationCode` form. */
    private const string CHANNEL_SEPARATOR = '#';

    /**
     * @param ?array{ruleName: string, violationCode: string} $channel
     */
    private function __construct(
        private string $raw,
        private ?NameSelector $selector,
        private bool $everyChannel,
        private ?array $channel = null,
    ) {}

    public static function fromAnnotation(string $rule): self
    {
        if ($rule === self::NO_RULE_FILTER) {
            return new self($rule, null, true);
        }

        if (str_contains($rule, self::CHANNEL_SEPARATOR)) {
            return new self($rule, null, false, self::parseChannel($rule));
        }

        return new self($rule, NameSelector::tryParse($rule), false);
    }

    /** Whether the directive carries no rule filter at all. */
    public function appliesToEveryChannel(): bool
    {
        return $this->everyChannel;
    }

    /**
     * The halves of the explicit `ruleName#violationCode` form, or `null`
     * when the directive was not written in it.
     *
     * Callers that have to decide whether the target addresses anything need
     * the pair, because the answer is a channel lookup rather than a name
     * expansion.
     *
     * @return ?array{ruleName: string, violationCode: string}
     */
    public function exactChannel(): ?array
    {
        return $this->channel;
    }

    /** Whether the authored text used the explicit pair separator at all. */
    public function looksLikeChannelPair(): bool
    {
        return str_contains($this->raw, self::CHANNEL_SEPARATOR);
    }

    public function matches(string $ruleName, string $violationCode): bool
    {
        if ($this->everyChannel) {
            return true;
        }

        if ($this->channel !== null) {
            return $this->channel['ruleName'] === $ruleName
                && $this->channel['violationCode'] === $violationCode;
        }

        return $this->selector?->matches($violationCode) === true;
    }

    /**
     * Both halves must be exact names, so the pair is validated through the
     * same grammar the one-part form uses and then refused the group suffix.
     *
     * @return ?array{ruleName: string, violationCode: string}
     */
    private static function parseChannel(string $raw): ?array
    {
        $parts = explode(self::CHANNEL_SEPARATOR, $raw);
        if (\count($parts) !== 2) {
            return null;
        }

        [$ruleName, $violationCode] = $parts;
        foreach ([$ruleName, $violationCode] as $half) {
            $parsed = NameSelector::tryParse($half);
            if ($parsed === null || $parsed->selectsDescendantsOnly()) {
                return null;
            }
        }

        return ['ruleName' => $ruleName, 'violationCode' => $violationCode];
    }

    /** The authored text, so a directive round-trips into diagnostics unchanged. */
    public function __toString(): string
    {
        return $this->raw;
    }
}
