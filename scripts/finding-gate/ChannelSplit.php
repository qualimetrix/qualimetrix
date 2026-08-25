<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What a declared rename splits, and the machine check that keeps a split from
 * retiring a compared field.
 *
 * A channel row translates the whole channel key and each differing half (a key
 * was a `rule#code` pair before the pair collapsed into one name).
 * When several rows disagree about one half — `design.type-coverage` becoming
 * three different rule names — no textual translation of that half is correct,
 * so RenameMaps stops substituting it. That alone would be a hole: `rule` and
 * `code` are fields the equivalence tuple compares, and an untranslatable half
 * would reach the surface comparison as a difference for a declared delta to
 * absorb. Normalization is explicitly forbidden from reaching a compared field
 * (`normalization-overreach`), and a declared delta gets no waiver normalization
 * did not get.
 *
 * So every occurrence of a split half is explained instead, per record rather
 * than per string: the reference finding's own `(rule, code)` pair must be named
 * by a declared row, and the candidate must publish the pair that row computes,
 * on the same finding. An occurrence no declared row accounts for is
 * `split-unmapped`. What that check proves is then what `delta-overreach` allows
 * a declared delta to cover, and nothing wider — {@see allowsMove()} stores the
 * *pairs* those matched records produced, so a delta may show a compared field
 * making a move the split performed and no other.
 *
 * A matched record also credits the row that named its key, through
 * {@see RenameMaps::creditExplanation()}. A row that moves a producer and
 * nothing else substitutes nothing anywhere, so explaining records is the only
 * work it can be seen doing; without the credit `map-stale` would refuse the
 * only shape such a declaration has. The credit travels by key, so it reaches
 * the one row that named it rather than the split it belongs to.
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
     * no row ever declared — see {@see Gate::checkSplitExplanation()}.
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

            // Two eras of key, and which one the reference speaks is a fact
            // about the reference rather than a choice: before the pair
            // collapsed, a channel row named `rule#code`; after it, the channel
            // is one name. A row exists for at most one of the two, so trying
            // both is unambiguous.
            $pairKey = self::string($finding, 'rule') . '#' . self::string($finding, 'code');
            $key = isset($this->channelKeys[$pairKey]) ? $pairKey : self::string($finding, 'code');
            $declared = $this->channelKeys[$key] ?? null;

            if ($declared === null) {
                $unexplained[] = \sprintf(
                    'reference finding #%d carries the split half "%s" in channel "%s", and no declared channel row'
                    . ' names that key. A half a map cannot translate must be explained record by record.',
                    $index,
                    $half,
                    $key,
                );

                continue;
            }

            // A row whose target is a single name says nothing about `rule`:
            // the rule survives a collapse as its own published field, so
            // constraining it here would demand a move no row declared.
            $halves = explode('#', $declared, 2);
            $expectedRule = \count($halves) === 2 ? $halves[0] : null;
            $expectedCode = $halves[\count($halves) - 1];
            $identity = self::identity($finding);
            $match = null;

            foreach ($candidateByIdentity[$identity] ?? [] as $candidate) {
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

            // The row that named this key is what explained the record, and the
            // maps are told so: a row of this shape substitutes nothing
            // anywhere, so explaining is the only work it can be seen doing and
            // staleness would otherwise report it as a rename that never
            // happened. Per key, and only on a match — a record the candidate
            // did not publish is reported above and credits nobody.
            $this->maps->creditExplanation($key);
        }

        return $unexplained;
    }

    /**
     * Whether a declared delta may show this field moving from this reference
     * value to this candidate value.
     *
     * A **move**, not a value. The first version asked only whether each side's
     * value belonged to the set of values explained records carry, which let a
     * line move `rule` between any two members of the split family on any
     * record — including `design.param-type-coverage` → `design.property-type-coverage`,
     * a pair no explained record ever produced. What is stored now is the pair
     * itself, taken from the two findings `unexplained()` matched, so the reach
     * of a declared delta is the set of moves the split actually performed and
     * nothing wider.
     *
     * Both directions are accepted for one reason: a declared diff is rendered
     * candidate-first, but the token order inside one line is the formatter's,
     * and asking the caller to know which side it is holding would move a
     * decision into the caller that belongs here.
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
