<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

/**
 * One entry line as the *file* spells it, read without turning it into a
 * {@see BaselineEntry}.
 *
 * The loader's verdict on a line depends on what this build *declares* — an
 * undeclared channel, a configuration-error channel, a shape that disagrees
 * with the channel's direction (ADR 0017) — which makes it the wrong reader
 * for a carry: a file written for another build would come back reshaped by
 * today's opinions. What this type reads instead is the part of the same
 * verdict the *document* decides on its own: whether an identity can be
 * formed at all, and from it where the line sorts among its siblings.
 *
 * **The identity is built from the real types, not from a second reading of
 * the rules.** `FindingChannel`, `BaselineEdge` and `BaselineIdentity` each
 * refuse what they refuse — an empty or `:`- or `#`-bearing channel name, an
 * empty edge target, an unknown dependency type, the identity key's separator
 * anywhere in a component — and every one of those is a rule
 * {@see BaselineEntryParser} applies too. Restating them here would be a
 * second copy that drifts, and the drift would show up as an entry sorted
 * where {@see BaselineWriter} would not have put it: a diff in a user's
 * baseline that no test explains.
 */
final readonly class BaselineEntryPayload
{
    /**
     * @param ?array<mixed, mixed> $fields the payload's fields when it is a JSON object at
     *                                     all, and `null` when it is not. Decided once, so
     *                                     no accessor below has to ask again
     */
    private function __construct(
        public string $subjectKey,
        public mixed $raw,
        private ?array $fields,
        private ?string $forcedUnreadableReason = null,
    ) {}

    public static function of(string $subjectKey, mixed $raw): self
    {
        $fields = \is_array($raw) && !array_is_list($raw) ? $raw : null;

        return new self($subjectKey, $raw, $fields);
    }

    /**
     * A whole subject block that is not a JSON array, read as the one line it
     * becomes.
     *
     * {@see BaselineLoader} demotes such a block to a single inert entry whose
     * raw value is the block, and {@see BaselineWriter} writes that entry back
     * as a one-element list. This constructor is what makes a carry answer the
     * same way: the block reads no `channel` — an object block would otherwise
     * look like an ordinary line and have its channel renamed, which the
     * loader never does — and it carries its own reason into the report.
     */
    public static function ofUncarriableBlock(string $subjectKey, mixed $block): self
    {
        return new self($subjectKey, $block, null, ChannelRenameReport::UNREADABLE_BLOCK_NOT_AN_ARRAY);
    }

    /**
     * The channel this line names, or `null` when it names none a rename
     * could address.
     */
    public function channel(): ?string
    {
        $channel = $this->fields['channel'] ?? null;

        return \is_string($channel) && $channel !== '' ? $channel : null;
    }

    /**
     * The same line under a different channel name. Only the one field
     * changes; the payload is otherwise the array that was decoded, in the
     * order it was decoded in.
     */
    public function withChannel(string $channel): self
    {
        if ($this->fields === null) {
            return $this;
        }

        $fields = $this->fields;
        $fields['channel'] = $channel;

        return new self($this->subjectKey, $fields, $fields);
    }

    /**
     * The identity two entries may not share, or `null` when this line forms
     * none.
     *
     * A line without an identity cannot take part in the collision check, and
     * that is the rule the typed side follows too: {@see Baseline} guards
     * duplicates over entries that parsed, and the loader demotes the rest
     * before they get there.
     */
    public function identityKey(): ?string
    {
        return $this->identity()?->key();
    }

    /**
     * Where this line sorts among its siblings — the rule
     * {@see BaselineWriter} applies to the objects this payload loads into,
     * branch for branch: the full identity when one forms, the channel as
     * written when it does not, and the raw selector when the line carries no
     * channel string at all.
     */
    public function orderingKey(): string
    {
        $identity = $this->identity();

        if ($identity !== null) {
            return BaselineEntryOrder::forComponents(
                $identity->channel->code,
                $identity->occurrenceKey,
                $identity->edge?->key(),
            );
        }

        $channelKey = $this->fields['channel'] ?? null;

        if (\is_string($channelKey)) {
            return BaselineEntryOrder::forComponents($channelKey, null, null);
        }

        return BaselineEntryOrder::forUnreadable($this->rawSelector());
    }

    /**
     * Why this build cannot read the line, or `null` when it can.
     *
     * Deliberately shorter than the loader's list of reasons: a carry runs no
     * analysis, so a `computed.*` channel declared by a configuration nobody
     * resolved here is not evidence of anything and is not called unreadable.
     */
    public function unreadableReason(): ?string
    {
        if ($this->forcedUnreadableReason !== null) {
            return $this->forcedUnreadableReason;
        }

        if ($this->fields === null) {
            return ChannelRenameReport::UNREADABLE_NOT_AN_OBJECT;
        }

        if ($this->channel() === null) {
            return ChannelRenameReport::UNREADABLE_NO_CHANNEL;
        }

        return $this->identity() === null ? ChannelRenameReport::UNREADABLE_MALFORMED_IDENTITY : null;
    }

    /**
     * The identity the parser would form from this line, or `null` where it
     * would refuse. Built rather than predicted — see the class docblock.
     */
    private function identity(): ?BaselineIdentity
    {
        $channel = $this->fields['channel'] ?? null;

        if (!\is_string($channel)) {
            return null;
        }

        try {
            return new BaselineIdentity(
                $this->subjectKey,
                new FindingChannel($channel),
                $this->occurrence(),
                BaselineEdge::fromArray($this->fields['edge'] ?? null),
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @throws InvalidArgumentException when the field is present but is not the non-empty
     *                                  string an occurrence has to be
     */
    private function occurrence(): ?string
    {
        $occurrence = $this->fields['occurrence'] ?? null;

        if ($occurrence === null) {
            return null;
        }

        if (!\is_string($occurrence) || $occurrence === '') {
            throw new InvalidArgumentException('"occurrence" must be a non-empty string when present');
        }

        return $occurrence;
    }

    /**
     * The selector {@see InertBaselineEntry::forRaw()} derives, reproduced so
     * an unreadable line sorts where the writer would put it.
     */
    private function rawSelector(): EntrySelector
    {
        $encoded = json_encode($this->raw);

        return EntrySelector::forKey(
            $this->subjectKey . "\x1F" . ($encoded !== false ? $encoded : serialize($this->raw)),
        );
    }
}
