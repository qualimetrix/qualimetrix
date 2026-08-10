<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/**
 * An entry the loader read but the mechanism cannot apply.
 *
 * **Why a separate type rather than a flag on {@see BaselineEntry}.** The
 * governing invariant is that an entry which cannot be applied does not
 * suppress; a boolean on the valid type would let a caller forget to check
 * it, and every such omission suppresses something it should have reported.
 * With two types the mistake does not compile: whatever consumes entries
 * takes `list<BaselineEntry>` and an inert one cannot get in. It also fits
 * what the two carry: a valid entry has an identity, a count and possibly
 * magnitudes; an inert one may have none of those, and pretending otherwise
 * would mean nullable everything.
 *
 * **What it must not lose.** `check` reports inert entries so a user can act
 * on them (ADR 0017), which needs the symbol, the channel, the selector and the
 * reason. It also keeps `$raw` — the entry exactly as the file spelled it —
 * so a rewrite of the file preserves the line verbatim. That is not a
 * convenience: `cleanup` never removes an entry on its own, and dropping an
 * unreadable line on the next write would be removal by inference, done by
 * the one component with no idea what the line meant.
 */
final readonly class InertBaselineEntry
{
    /**
     * @param string $subjectKey the file key this entry sat under
     * @param ?string $channelKey the entry's `channel` as written, when it was a string at all
     * @param ?BaselineIdentity $identity present when the identity parsed but the entry is
     *                                    still inapplicable (an undeclared channel, a shape
     *                                    mismatch, an unrecognized mode, a duplicate). When
     *                                    present, the selector is the identity's own, so the
     *                                    handle a user is shown is the same handle a valid
     *                                    entry for that identity would have
     * @param mixed $raw the decoded entry, preserved for rewrite
     */
    public function __construct(
        public string $subjectKey,
        public ?string $channelKey,
        public ?BaselineIdentity $identity,
        public EntrySelector $selector,
        public InertEntryReason $reason,
        public string $detail,
        public mixed $raw,
    ) {}

    /**
     * An entry whose identity parsed but which still cannot be applied.
     */
    public static function forIdentity(
        BaselineIdentity $identity,
        InertEntryReason $reason,
        string $detail,
        mixed $raw,
    ): self {
        return new self(
            subjectKey: $identity->subjectKey,
            channelKey: $identity->channel->toKey(),
            identity: $identity,
            selector: $identity->selector(),
            reason: $reason,
            detail: $detail,
            raw: $raw,
        );
    }

    /**
     * An entry whose identity could not be formed. Its selector is derived
     * from the raw payload instead, so it is still addressable — a user has
     * to be able to remove a line precisely because it is unreadable.
     */
    public static function forRaw(
        string $subjectKey,
        ?string $channelKey,
        InertEntryReason $reason,
        string $detail,
        mixed $raw,
    ): self {
        return new self(
            subjectKey: $subjectKey,
            channelKey: $channelKey,
            identity: null,
            selector: EntrySelector::forKey($subjectKey . "\x1F" . self::encodeRaw($raw)),
            reason: $reason,
            detail: $detail,
            raw: $raw,
        );
    }

    /**
     * Human-readable form for reports.
     */
    public function describe(): string
    {
        return $this->identity?->describe()
            ?? $this->subjectKey . ' ' . ($this->channelKey ?? '(no channel)');
    }

    /**
     * A deterministic string for a payload that failed validation. The
     * payload came from `json_decode`, so re-encoding it cannot fail on
     * anything but depth; `serialize()` covers that residue rather than
     * leaving the selector undefined.
     */
    private static function encodeRaw(mixed $raw): string
    {
        $encoded = json_encode($raw);

        return $encoded !== false ? $encoded : serialize($raw);
    }
}
