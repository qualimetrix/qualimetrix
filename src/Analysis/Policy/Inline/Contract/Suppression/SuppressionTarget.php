<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Suppression;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Stringable;

/**
 * What an inline suppression directive filters on.
 *
 * Two states, and the second one is the point of this type:
 *
 * - **a channel selector** — an exact channel name, `X.*` for its strict
 *   descendants, and either of those narrowed to one level of the aggregation
 *   tree with `:level`. All of it is {@see ChannelLevelSelector}, which is the
 *   whole of the grammar and is shared with configuration's channel-keyed
 *   surfaces;
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
 * Text that is neither filters nothing. The directive is then inert
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

    private function __construct(
        private string $raw,
        private ?ChannelLevelSelector $selector,
        private bool $everyChannel,
    ) {}

    public static function fromAnnotation(string $rule): self
    {
        if ($rule === self::NO_RULE_FILTER) {
            return new self($rule, null, true);
        }

        return new self($rule, ChannelLevelSelector::tryParse($rule), false);
    }

    /** Whether the directive carries no rule filter at all. */
    public function appliesToEveryChannel(): bool
    {
        return $this->everyChannel;
    }

    /**
     * Whether the authored text is written in the retired `rule#code` spelling.
     *
     * Kept as a question of its own because such a target parses to nothing —
     * the separator is out of the name grammar — and "parses to nothing" is the
     * same state a typo produces. Only this predicate separates the two, and
     * the difference is what the author is told.
     */
    public function usesRetiredChannelPair(): bool
    {
        return FindingChannel::isRetiredPairSpelling($this->raw);
    }

    /** The parsed selector, or `null` when the text is not one. */
    public function selector(): ?ChannelLevelSelector
    {
        return $this->selector;
    }

    public function matches(string $code, ?SymbolLevel $level): bool
    {
        if ($this->everyChannel) {
            return true;
        }

        return $this->selector?->matches($code, $level) === true;
    }

    /** The authored text, so a directive round-trips into diagnostics unchanged. */
    public function __toString(): string
    {
        return $this->raw;
    }
}
