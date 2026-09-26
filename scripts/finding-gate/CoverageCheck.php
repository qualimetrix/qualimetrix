<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What the corpus covers: each case fires exactly the pairs it claims, each channel has one producing case,
 * the declared pairs are observed, and the fixture witnesses agree with the declarations.
 */
final class CoverageCheck
{
    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
        private readonly Corpus $corpus,
        private readonly ChannelWitness $witness,
    ) {}

    /**
     * Coverage, per channel-and-level pair, against a derived declaration.
     *
     * The declared side comes from the witnesses and never from a `case.json`:
     * a claim is hand-written on purpose, and an accounting whose two sides are
     * both hand-written cannot see a pair nobody wrote down twice. See
     * {@see ChannelCoverage} for what that hid.
     *
     * @param array<string, list<array<string, mixed>>> $findingsByCase
     */
    public function checkCoverage(array $findingsByCase): void
    {
        $declared = $this->witness->staticPairs();
        $observed = [];
        $producers = [];

        foreach ($this->corpus->cases as $case) {
            $caseClaims = self::observedClaims($findingsByCase[$case->id] ?? []);
            $caseObserved = self::channelsOf($caseClaims);
            $this->checkCaseClaim($case, $caseClaims);

            // An auxiliary case exists for an input, not for a channel: it fires
            // what an authoritative case already owns, so counting it would
            // report a second producer for every channel it touches and take the
            // sharpness out of the fixture-removal control. Its own claim is
            // still verified above — that is what makes it prove anything.
            if ($case->isAuxiliary()) {
                continue;
            }

            $declared = [...$declared, ...$this->witness->computedPairs($case)];
            $observed = [...$observed, ...$caseClaims];

            foreach ($caseObserved as $channel) {
                $producers[$channel][] = $case->id;
            }
        }

        $this->checkSingleProducer($producers);

        ChannelCoverage::check($this->report, $declared, $observed, $this->options->incompleteCorpus);
    }

    /**
     * Exactly one case per channel.
     *
     * The negative control on the gate's own input — delete a fixture, the gate
     * must go red — is only sharp where a channel has a single producer. With two
     * cases firing the same channel the deduplicated union does not shrink when
     * one of them loses its fixture, and every other check still passes: the
     * control would be vacuous on precisely the channels it covers twice.
     *
     * This counts cases per channel, and says nothing about how many times a
     * channel fires inside one case — the union it guards is a union of names.
     * Multiplicity *within* a case is the claim's business, and it is a claim
     * about `channel@level` pairs for exactly that reason: see
     * {@see checkCaseClaim()}.
     *
     * @param array<string, list<string>> $producers
     */
    private function checkSingleProducer(array $producers): void
    {
        $shared = [];

        foreach ($producers as $channel => $cases) {
            $cases = array_values(array_unique($cases));

            if (\count($cases) > 1) {
                $shared[] = \sprintf('%s: %s', $channel, implode(', ', $cases));
            }
        }

        if ($shared === []) {
            return;
        }

        sort($shared);
        $this->report->fail(
            FailureClass::COVERAGE_MULTIPLICITY,
            'corpus',
            'A channel fires in more than one case. Coverage is a union, so a duplicated channel cannot notice a lost'
            . ' fixture — one producer per channel is what makes the input control bite.',
            $shared,
        );
    }

    /**
     * The claim is verified per channel-and-level pair, not per channel.
     *
     * Both sides of the comparison are pair sets, so nothing here deduplicates a
     * level away: a case that keeps firing a channel but stops firing it at one
     * of its levels fails, which is the only place a lost level-fixture can be
     * seen at all — the corpus is shared by both trees, so no surface differs,
     * and the coverage union still holds the channel.
     *
     * @param list<string> $observed
     */
    private function checkCaseClaim(CaseDefinition $case, array $observed): void
    {
        $claimed = $case->channels;
        sort($claimed);
        sort($observed);

        if ($claimed === $observed) {
            return;
        }

        $this->report->fail(
            FailureClass::CASE_CLAIM_MISMATCH,
            'case:' . $case->id,
            'The case no longer fires exactly the channel-and-level pairs its case.json claims. The claim is'
            . ' verified, not documentation.',
            Diff::betweenSets($claimed, $observed, 'claimed', 'fired'),
        );
    }

    public function checkWitnesses(): void
    {
        ChannelWitness::checkAgreement($this->report, $this->witness->fixturePairs(), $this->witness->staticPairs());
        ChannelWitness::checkLevelVocabulary($this->report, $this->witness->productLevels());
    }

    /**
     * The channel-and-level pairs a case fired, deduplicated by pair.
     *
     * Keyed by the pair rather than by the channel, and that is the whole repair:
     * a channel firing at two levels inside one case used to collapse into one
     * observed entry, so the level whose fixture disappeared left no trace in
     * any check. Both trees read the corpus out of the candidate's case
     * directory, so a lost fixture produces no surface difference either — the
     * claim is the only place it can show up.
     *
     * @param list<array<string, mixed>> $findings
     *
     * @return list<string>
     */
    private static function observedClaims(array $findings): array
    {
        $claims = [];

        foreach ($findings as $finding) {
            if (\is_string($finding['channel'] ?? null) && \is_string($finding['subject'] ?? null)) {
                $claim = SubjectLevel::claim($finding['channel'], SubjectLevel::of($finding['subject']));
                $claims[$claim] = $claim;
            }
        }

        return array_values($claims);
    }

    /**
     * The channels behind a set of claims.
     *
     * Multiplicity stays accounted per channel, deliberately: the guarantee it
     * carries — one authoritative owner per channel, so the fixture-removal
     * control bites — is about names, and pairing it with levels would let two
     * cases own one channel as long as they fired it at different levels.
     * Coverage counts pairs; see {@see ChannelCoverage}.
     *
     * @param list<string> $claims
     *
     * @return list<string>
     */
    private static function channelsOf(array $claims): array
    {
        $channels = [];

        foreach ($claims as $claim) {
            $channel = SubjectLevel::channelOf($claim);
            $channels[$channel] = $channel;
        }

        return array_values($channels);
    }
}
