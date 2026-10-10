<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Exact producer movements explained by a declared channel correspondence.
 *
 * A channel row translates a whole channel key and each differing half. When
 * several rows disagree about one producer half, no textual rewrite of that
 * half is valid. The reference record's pair must instead name a declared row,
 * and the candidate must publish its exact target on the same logical finding.
 * An occurrence with no matched target is `split-unmapped`.
 *
 * A matched row licenses the producer movement itself. Record projections may
 * restate only that observed field/from/to move while retaining their exact
 * finding identity and channel. The same pairs bound compared-field movement
 * in a measured surface diff; an unrelated pair obtains no permission from
 * another member of the split family.
 *
 * A matched record also credits the row that named its key, through
 * {@see RenameMaps::creditExplanation()}, and only where that row declared a
 * MOVEMENT the record did not already have. A row that moves a producer and
 * nothing else substitutes nothing anywhere, so explaining records is the only
 * work it can be seen doing; without the credit `map-stale` would refuse the
 * only shape such a declaration has. A row whose target is what the record
 * already publishes declares no movement and keeps none of that credit. The
 * credit travels by key, so it reaches the one row that named it rather than
 * the split it belongs to.
 */
final class ChannelSplit
{
    /** The finding fields whose values a split rewrites. */
    private const FIELDS = ['channel', 'rule', 'code'];

    /** @var array<string, true> "field\0from\0to" moves an explained record accounts for */
    private array $allowed = [];

    /**
     * @param array<string, list<string>> $splits old half => the several new halves
     * @param array<string, string> $channelKeys old whole key => new whole key
     */
    private function __construct(
        private readonly RenameMaps $maps,
        private readonly array $splits,
        private readonly array $channelKeys,
    ) {}

    public static function of(RenameMaps $maps): self
    {
        return new self($maps, $maps->splits(), $maps->channelKeys());
    }

    public function isEmpty(): bool
    {
        return $this->splits === [];
    }

    /** @return list<string> */
    public function halves(): array
    {
        return array_keys($this->splits);
    }

    /**
     * Explains every reference finding that carries a split half, and reports
     * the ones nothing explains.
     *
     * The reference findings arrive **raw**, in the reference's own vocabulary,
     * so that the pair read off one of them is a key a declared row can name.
     * Handing them over forward-mapped translates the `code` half and leaves the
     * untranslatable `rule` half in place, and the resulting pair is an identity
     * no row ever declared — see {@see RenameMapCheck::checkSplitExplanation()}.
     *
     * @param list<array<string, mixed>> $referenceFindings
     * @param list<array<string, mixed>> $candidateFindings
     *
     * @return list<string>
     */
    public function unexplained(array $referenceFindings, array $candidateFindings): array
    {
        if ($this->splits === []) {
            return [];
        }

        $candidateByIdentity = [];

        foreach ($candidateFindings as $finding) {
            $candidateByIdentity[self::identity($finding)][] = $finding;
        }

        $unexplained = [];

        foreach ($referenceFindings as $index => $finding) {
            $half = $this->halfIn($finding);

            if ($half === null) {
                continue;
            }

            // A record is named by its `rule#code` pair and by nothing else.
            // Only a row written as a pair reaches this lookup at all — a row
            // whose old side is one name declares no channel key
            // ({@see RenameMaps::channelKeys()}) — and a second lookup on the
            // bare code was tried here and could never hit. The debt that
            // leaves is named rather than hidden: a split declared in the
            // post-collapse vocabulary, where the old side is one name, has no
            // way to explain a record, so every occurrence of its half is
            // `split-unmapped` and the step that first needs that shape has to
            // give it one.
            $pairKey = self::string($finding, 'rule') . '#' . self::string($finding, 'code');
            $declared = $this->channelKeys[$pairKey] ?? null;

            if ($declared === null) {
                $unexplained[] = \sprintf(
                    'reference finding #%d carries the split half "%s" in channel "%s", and no declared channel row'
                    . ' names that key. A half a map cannot translate must be explained record by record.',
                    $index,
                    $half,
                    $pairKey,
                );

                continue;
            }

            // A row whose target is a single name says nothing about `rule`:
            // the rule survives a collapse as its own published field, so
            // constraining it here would demand a move no row declared.
            $halves = explode('#', $declared, 2);
            $expectedRule = \count($halves) === 2 ? $halves[0] : null;
            $expectedCode = $halves[\count($halves) - 1];
            $referenceChannel = self::string($finding, 'channel');
            $expectedChannel = match (true) {
                $referenceChannel === '' => '',
                $referenceChannel === $pairKey => $declared,
                $referenceChannel === self::string($finding, 'code') => $expectedCode,
                isset($this->channelKeys[$referenceChannel]) => $this->channelKeys[$referenceChannel],
                default => $referenceChannel,
            };
            $identity = self::identity($finding);
            $match = null;

            foreach ($candidateByIdentity[$identity] ?? [] as $candidate) {
                if (self::string($candidate, 'channel') !== $expectedChannel) {
                    continue;
                }
                if ($expectedRule !== null && self::string($candidate, 'rule') !== $expectedRule) {
                    continue;
                }

                if (self::string($candidate, 'code') === $expectedCode) {
                    $match = $candidate;

                    break;
                }
            }

            if ($match === null) {
                $unexplained[] = \sprintf(
                    'reference finding #%d on %s is declared to become "%s", but the candidate publishes no finding'
                    . ' with that key on the same subject.',
                    $index,
                    $identity,
                    $declared,
                );

                continue;
            }

            $this->allowMove($finding, $match);

            // Credit is for a MOVEMENT the row declared, not for a match. A row
            // is compared against what the record it names already publishes,
            // in the fields that row constrains: a pair-to-pair row against the
            // record's own pair, a row collapsing the pair into one name
            // against the record's own code, because such a row says nothing
            // about `rule`. `computed.health#health.complexity ->
            // health.complexity#health.complexity` moves the rule and is
            // credited; `a.rule#a.code -> a.code` claims a code the record
            // already publishes, so matching it proves nothing about the row
            // and it stays as stale as it was before this credit existed.
            //
            // Only on a match, too: a record the candidate did not publish is
            // reported above and credits nobody. The record is still explained
            // either way — an uncredited row is a statement about staleness,
            // not about `split-unmapped`.
            $ownKey = $expectedRule === null ? self::string($finding, 'code') : $pairKey;

            if ($declared !== $ownKey) {
                $this->maps->creditExplanation($pairKey);
            }
        }

        return $unexplained;
    }

    /**
     * Whether a matched channel correspondence licenses this exact field move.
     *
     * The permission stores field/from/to pairs from matched findings. It does
     * not authorize any other pair in the same split family, and a record
     * projection must still retain its own identity and channel. A measured
     * surface diff can use the same finite pairs without widening that scope.
     *
     * Both directions are accepted because a rendered diff is candidate-first
     * while token order within one line belongs to its formatter.
     */
    public function allowsMove(string $field, string $from, string $to): bool
    {
        return isset($this->allowed[$field . "\0" . $from . "\0" . $to])
            || isset($this->allowed[$field . "\0" . $to . "\0" . $from]);
    }

    /**
     * Records the moves one explained record performed, field by field.
     *
     * A field whose value did not move is recorded as a move to itself, so a
     * line that repeats an unchanged compared field inside a changed record is
     * not read as an unexplained move.
     *
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $candidate
     */
    private function allowMove(array $reference, array $candidate): void
    {
        foreach (self::FIELDS as $field) {
            $from = self::string($reference, $field);
            $to = self::string($candidate, $field);

            if ($from === '' && $to === '') {
                continue;
            }

            $this->allowed[$field . "\0" . $from . "\0" . $to] = true;
        }
    }

    /** @param array<string, mixed> $finding */
    private function halfIn(array $finding): ?string
    {
        foreach (['rule', 'code'] as $field) {
            $value = self::string($finding, $field);

            if (isset($this->splits[$value])) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A finding's identity with the channel left out — what stays the same
     * across a rename of the channel, and therefore what pairs the two sides.
     *
     * @param array<string, mixed> $finding
     */
    private static function identity(array $finding): string
    {
        $encoded = json_encode(
            [
                'subject' => $finding['subject'] ?? null,
                'occurrence' => $finding['occurrence'] ?? null,
                'edge' => $finding['edge'] ?? null,
            ],
            \JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '?' : $encoded;
    }

    /** @param array<string, mixed> $finding */
    private static function string(array $finding, string $field): string
    {
        $value = $finding[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}
