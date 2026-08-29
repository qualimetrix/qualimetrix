<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What the corpus covers, counted per channel-and-level pair.
 *
 * The claim direction and the coverage direction are different questions, and
 * until now only the first counted pairs. A case's `channels` claim says what
 * *that case* fires; coverage says whether anything at all fires what the
 * product declares. With a declared side made of names, a pair the product can
 * produce that fires in no case and is claimed in no case passed every check in
 * the gate: not claimed and not observed, so no claim mismatch; the channel
 * observed at its other level, so no shortfall. It was invisible, and the proof
 * that collapsing the level channels loses nothing is a statement about exactly
 * those pairs.
 *
 * Separated from {@see Gate} because the arithmetic is the whole content of the
 * check and it must be exercisable without a reference tree: `--self-test`
 * hands it a synthetic universe and asserts the verdict, including the one that
 * shows the same universe accounted by names is green.
 *
 * Multiplicity stays counted per channel — see {@see Gate::checkSingleProducer()}.
 */
final class ChannelCoverage
{
    /**
     * @param list<string> $declaredPairs from the declaration witnesses, never from a claim
     * @param list<string> $observedPairs what the authoritative cases fired
     */
    public static function check(
        GateReport $report,
        array $declaredPairs,
        array $observedPairs,
        bool $incompleteCorpus,
    ): void {
        $declared = self::unique($declaredPairs);
        $observed = self::unique($observedPairs);

        $report->fact('declared channels', \count(self::channels($declared)));
        $report->fact('observed channels', \count(self::channels($observed)));
        $report->fact('declared pairs', \count($declared));
        $report->fact('observed pairs', \count($observed));

        self::reportShortfall($report, $declared, $observed, $incompleteCorpus);
        self::reportSurplus($report, $declared, $observed);
    }

    /**
     * @param list<string> $declared
     * @param list<string> $observed
     */
    private static function reportShortfall(
        GateReport $report,
        array $declared,
        array $observed,
        bool $incompleteCorpus,
    ): void {
        $shortfall = array_values(array_diff($declared, $observed));

        if ($shortfall === []) {
            return;
        }

        $detail = \sprintf(
            '%d declared channel%slevel pair(s) no case observes, %d of them at a level of a channel that does fire:'
            . ' a fixture lost, or a channel that stopped reporting at one of the levels it declares.',
            \count($shortfall),
            SubjectLevel::SEPARATOR,
            \count(array_intersect(self::channels($shortfall), self::channels($observed))),
        );

        if (!$incompleteCorpus) {
            $report->fail(FailureClass::COVERAGE_SHORTFALL, 'corpus', $detail, \array_slice($shortfall, 0, 20));

            return;
        }

        $report->warn($detail . ' Downgraded by --incomplete-corpus: ' . self::summary($shortfall));
        $report->limit(\sprintf(
            '%d declared channel%slevel pair(s) were observed by no case, and --incomplete-corpus downgraded that'
            . ' shortfall',
            \count($shortfall),
            SubjectLevel::SEPARATOR,
        ));
    }

    /**
     * @param list<string> $declared
     * @param list<string> $observed
     */
    private static function reportSurplus(GateReport $report, array $declared, array $observed): void
    {
        $surplus = array_values(array_diff($observed, $declared));

        if ($surplus === []) {
            return;
        }

        $report->fail(
            FailureClass::COVERAGE_SURPLUS,
            'corpus',
            \sprintf(
                'Channel%slevel pair(s) observed that nothing declares, %d of them a level a declared channel does'
                . ' not say it reports at.',
                SubjectLevel::SEPARATOR,
                \count(array_intersect(self::channels($surplus), self::channels($declared))),
            ),
            $surplus,
        );
    }

    /**
     * @param list<string> $pairs
     *
     * @return list<string>
     */
    private static function unique(array $pairs): array
    {
        $pairs = array_values(array_unique($pairs));
        sort($pairs);

        return $pairs;
    }

    /**
     * @param list<string> $pairs
     *
     * @return list<string>
     */
    private static function channels(array $pairs): array
    {
        return self::unique(array_map(SubjectLevel::channelOf(...), $pairs));
    }

    /** @param list<string> $pairs */
    private static function summary(array $pairs): string
    {
        return implode(', ', \array_slice($pairs, 0, 8)) . (\count($pairs) > 8 ? ', …' : '');
    }
}
