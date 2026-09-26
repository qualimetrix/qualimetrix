<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The declared maps against what the run needed translated: a reference addressed in its own vocabulary,
 * every split half explained by a row, and no row that translated nothing.
 */
final class RenameMapCheck
{
    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
        private readonly RenameMaps $maps,
        private readonly ChannelSplit $split,
    ) {}

    /**
     * The reference binary must be addressable in its own vocabulary.
     *
     * Exit code 3 is the product's "config/input error", so a reference run that
     * exits 3 where the candidate does not was handed a name that does not exist
     * yet — a rule renamed by the step and written into a case's input with no
     * `inputs.tsv` row to restate it. Left to itself that arrives as twelve
     * surface diffs and an empty findings section, which reads as a product
     * change; it is neither, and it says so.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function checkReferenceInput(array $candidate, array $reference): void
    {
        foreach ($reference as $key => $exit) {
            $separator = strpos($key, '|exit:');

            if ($separator === false || $exit !== '3' || ($candidate[$key] ?? null) === '3') {
                continue;
            }

            $stderrKey = substr($key, 0, $separator) . '|stderr:' . substr($key, $separator + \strlen('|exit:'));

            $this->report->fail(
                FailureClass::REFERENCE_INPUT_UNTRANSLATED,
                'reference / ' . substr($key, 0, $separator),
                \sprintf(
                    'The reference refused its input (exit 3) where the candidate did not, so it was addressed in a'
                    . ' vocabulary it does not know. Declare the token in %s. Surface: %s. It said: %s',
                    RenameMaps::INPUTS,
                    $key,
                    trim($reference[$stderrKey] ?? '(nothing on stderr)'),
                ),
            );
        }
    }

    /**
     * Every occurrence of a split half must be explained by a declared row.
     *
     * The reference findings go in **raw**, in the reference's own vocabulary.
     * Passing them forward-mapped first is what the first real split caught:
     * the map translates the `code` half and leaves the untranslatable `rule`
     * half alone, so the pair read off such a finding is a chimera
     * (`old-rule#new-code`) that no declared key can ever be, and the lookup
     * misses every time. A self-test on {@see ChannelSplit} could not see it —
     * it fed the class the raw pair the class expects, which is to say it
     * exercised a call this call site was not making.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function checkSplitExplanation(array $candidate, array $reference): void
    {
        if ($this->split->isEmpty()) {
            return;
        }

        foreach ($this->corpus->cases as $case) {
            $key = Surfaces::key('case:' . $case->id, 'format:json');

            foreach ($this->split->unexplained(
                self::findings($reference[$key] ?? ''),
                self::findings($candidate[$key] ?? ''),
            ) as $problem) {
                $this->report->fail(FailureClass::SPLIT_UNMAPPED, 'case:' . $case->id, $problem);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function findings(string $json): array
    {
        $decoded = json_decode($json, true);
        $findings = [];

        foreach ((array) (\is_array($decoded) ? $decoded['violations'] ?? [] : []) as $finding) {
            if (\is_array($finding)) {
                /** @var array<string, mixed> $finding */
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * A declared map row that translated nothing is a lie about what the step
     * renamed — the same defect as a normalization rule that redacted nothing,
     * and it fails the same way.
     */
    public function checkStaleMaps(): void
    {
        // Staleness means "declared, but there was nothing to translate". When a
        // tree run failed, there was nothing to translate for *any* row of that
        // case, so every one of them reads as stale and the real failure is
        // buried under its own consequences — measured: the control that makes
        // one reference run fail reported nine of them. The run is already red,
        // so this adds noise rather than signal, and a row that is genuinely
        // idle will still be caught by every run that does not fail.
        if (array_intersect(
            [FailureClass::RUN_FAILED, FailureClass::REFERENCE_INPUT_UNTRANSLATED],
            $this->report->failureClasses(),
        ) !== []) {
            return;
        }

        foreach ($this->maps->staleRows() as $row) {
            $this->report->fail(
                FailureClass::MAP_STALE,
                $row,
                'This map row matched nothing in the whole run, in either direction. A rename nobody can point at is'
                . ' not a declaration of what changed, so it fails until it is either corrected or removed.',
            );
        }
    }
}
