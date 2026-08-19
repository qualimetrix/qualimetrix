<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Stringable;

/**
 * A user-authored selector that addresses a **full channel**.
 *
 * It is {@see NameSelector} plus the one thing a name alone cannot say. Two
 * forms:
 *
 * - **one part** — a {@see NameSelector} read against the channel's violation
 *   code: `health.cohesion` for that channel exactly, `health.*` for its
 *   strict descendants;
 * - **two parts** — `ruleName#violationCode`, both halves exact. It is the
 *   one spelling that says which half is meant, which matters wherever a rule
 *   name and a channel name coincide, and wherever a channel's `ruleName` half
 *   is not the rule that produces it. A `*` inside either half is refused: a
 *   group is what the one-part form already says.
 *
 * The two forms used to be parsed once per surface — three times in total,
 * and one of the three (`exclude_namespace_channels`) had no two-part branch
 * at all, so a key naming a channel in full excluded nothing and said nothing.
 * This type exists so that "which strings address a channel" has one answer.
 *
 * Text that is neither form parses to `null` and therefore addresses nothing.
 * Turning that into a loud error is the job of whichever surface validates
 * against a resolved channel universe, not of the grammar.
 */
final readonly class ChannelSelector implements Stringable
{
    private function __construct(
        private string $raw,
        private ?NameSelector $selector,
        private ?ViolationChannel $channel,
    ) {}

    /**
     * Parses selector text, or answers `null` when the text is neither form.
     */
    public static function tryParse(string $raw): ?self
    {
        if (!str_contains($raw, ViolationChannel::SEPARATOR)) {
            $selector = NameSelector::tryParse($raw);

            return $selector === null ? null : new self($raw, $selector, null);
        }

        $channel = self::parsePair($raw);

        return $channel === null ? null : new self($raw, null, $channel);
    }

    /** Whether the authored text used the two-part separator at all. */
    public static function looksLikePair(string $raw): bool
    {
        return str_contains($raw, ViolationChannel::SEPARATOR);
    }

    public function matches(ViolationChannel $channel): bool
    {
        return $this->matchesNames($channel->ruleName, $channel->violationCode);
    }

    /**
     * The same question asked of the two halves separately, for callers that
     * hold them as strings.
     *
     * They are not asked to build a {@see ViolationChannel} first: the inline
     * suppression filter asks this once per directive per finding, and the
     * constructor would both allocate on that path and refuse a half no
     * producer can emit anyway — turning "does not match" into an exception.
     */
    public function matchesNames(string $ruleName, string $violationCode): bool
    {
        if ($this->channel !== null) {
            return $this->channel->ruleName === $ruleName
                && $this->channel->violationCode === $violationCode;
        }

        return $this->selector?->matches($violationCode) === true;
    }

    /**
     * The addressed channel for the two-part form, `null` for the one-part
     * form — whose expansion is a question for the channel universe rather
     * than for the text.
     */
    public function exactChannel(): ?ViolationChannel
    {
        return $this->channel;
    }

    /**
     * What the text addresses, as one of the two things it can be: a name to
     * be expanded against the channel universe, or the channel itself.
     *
     * Returned as a union rather than as two nullable accessors because the
     * two are exhaustive — a caller that must handle both should not have to
     * prove to a reader, or to a static analyser, that the second `null` check
     * is unreachable.
     */
    public function target(): NameSelector|ViolationChannel
    {
        return $this->channel ?? $this->selector
            ?? throw new LogicException('A ChannelSelector is always one of its two forms.');
    }

    /** The authored text, so a selector round-trips into diagnostics unchanged. */
    public function __toString(): string
    {
        return $this->raw;
    }

    /**
     * Both halves must be exact names, so the pair is validated through the
     * same grammar the one-part form uses and then refused the group suffix.
     */
    private static function parsePair(string $raw): ?ViolationChannel
    {
        $parts = explode(ViolationChannel::SEPARATOR, $raw);
        if (\count($parts) !== 2) {
            return null;
        }

        foreach ($parts as $half) {
            $parsed = NameSelector::tryParse($half);
            if ($parsed === null || $parsed->selectsDescendantsOnly()) {
                return null;
            }
        }

        return new ViolationChannel($parts[0], $parts[1]);
    }
}
