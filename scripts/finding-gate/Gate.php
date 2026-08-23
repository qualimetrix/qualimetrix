<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Proves that the candidate and the reference tree produce equivalent findings
 * over the corpus, modulo the declared maps and the declared normalization.
 *
 * Not byte-identical artifacts: that gate was rejected, and why is written in
 * docs/internal/plans/rule-vocabulary/PLAN.md. Equivalence is the property that
 * still holds across a step that deliberately renames something.
 */
final class Gate
{
    private readonly Normalization $normalization;

    private readonly RenameMaps $maps;

    private readonly Corpus $corpus;

    private readonly ChannelWitness $witness;

    private readonly string $temporaryDirectory;

    /** @var array<string, list<array<string, mixed>>> */
    private array $findingsByCase = [];

    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
    ) {
        $root = $this->options->candidateRoot . '/finding-gate';
        $this->normalization = Normalization::load($root . '/normalization.tsv');
        $this->maps = RenameMaps::load($root . '/maps');
        $this->corpus = Corpus::load($this->options->candidateRoot, $this->options->cases);
        $this->witness = new ChannelWitness($this->options->candidateRoot);
        $this->temporaryDirectory = Fs::temporaryDirectory('finding-gate-run-');
    }

    public function compare(): void
    {
        $this->report->fact('candidate', $this->options->candidateRoot);
        $this->report->fact('reference', (string) $this->options->reference);
        $this->report->fact('cases', array_map(static fn(CaseDefinition $case): string => $case->id, $this->corpus->cases));
        $this->report->fact('formats', Surfaces::FORMATS);
        $this->report->fact('maps', $this->maps->isIdentity() ? 'empty (identity)' : 'declared');
        $this->report->fact('map rows', \count($this->maps->declaredRows()));

        if ($this->options->cases !== []) {
            $this->report->limit('the corpus was restricted to ' . implode(', ', $this->options->cases) . ' by --cases');
        }

        $this->checkTuple();
        $this->checkNormalizationScope();

        $first = $this->runTree($this->options->candidateRoot, 'candidate-1', reverseInput: false);
        $second = $this->runTree($this->options->candidateRoot, 'candidate-2', reverseInput: false);
        $this->checkDeterminism($first, $second);

        $reference = ReferenceTree::create($this->options->candidateRoot, (string) $this->options->reference);

        try {
            $mismatch = $reference->dependencySetMismatch();

            if ($mismatch !== null) {
                $this->report->fail(FailureClass::ENV_MISMATCH, 'reference tree', $mismatch);

                return;
            }

            $referenceArtifacts = $this->runTree($reference->root, 'reference', reverseInput: true);

            $this->checkFindings('candidate', $first, trackObserved: true);
            $this->checkFindings('reference', $referenceArtifacts, trackObserved: false);
            $this->compareSurfaces($first, $referenceArtifacts);
            $this->checkPathLeaks($first, $referenceArtifacts, $reference->root);
            $this->checkCoverage();
            $this->checkWitnesses();
            $this->checkStaleNormalization();
            $this->checkStaleMaps();
        } finally {
            $reference->remove();
            $this->cleanUp();
        }
    }

    /**
     * Measures the normalization list, and keeps a tracked row that still fires.
     *
     * The union is what makes re-measuring reproducible. Rounded to one decimal,
     * the summary line's duration crosses a boundary on some runs and not on
     * others, so a purely measured list would gain and lose that row at random —
     * and a list whose shape changes every time it is measured is not a measured
     * list either. Both properties the plan demands survive: a row still enters
     * only by measurement, and a row that fires nowhere is stale and leaves.
     */
    public function deriveNormalization(): string
    {
        try {
            $passes = [];

            for ($pass = 1; $pass <= NormalizationDeriver::passes(); ++$pass) {
                $passes[] = $this->runTree($this->options->candidateRoot, 'derive-' . $pass, reverseInput: false);
            }

            foreach ($passes[0] as $key => $content) {
                $this->normalization->normalize(Surfaces::surfaceClass($key), $content);
            }

            return Normalization::fromRules(self::unique([
                ...$this->normalization->activeRules(),
                ...NormalizationDeriver::derive($passes),
            ]))->render();
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * @param list<NormalizationRule> $rules
     *
     * @return list<NormalizationRule>
     */
    private static function unique(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            $unique[$rule->surface . "\0" . $rule->locator . "\0" . $rule->kind] = $rule;
        }

        return array_values($unique);
    }

    public function cleanUp(): void
    {
        Fs::removeRecursively($this->temporaryDirectory);
    }

    /** @return array<string, string> */
    private function runTree(string $treeRoot, string $label, bool $reverseInput): array
    {
        $run = new TreeRun($treeRoot, $this->temporaryDirectory, $label, $this->maps, $reverseInput);
        $artifacts = $run->rules();

        foreach ($this->corpus->cases as $case) {
            $artifacts += $run->forCase($case);
        }

        return $artifacts;
    }

    private function checkTuple(): void
    {
        $tracked = EquivalenceTuple::load($this->options->candidateRoot . '/finding-gate/equivalence-tuple.tsv');
        $derived = EquivalenceTuple::derive($this->options->candidateRoot);

        if (!$tracked->equals($derived)) {
            $this->report->fail(
                FailureClass::TUPLE_FIELD_DRIFT,
                'finding-gate/equivalence-tuple.tsv',
                'The published finding fields no longer match the tracked tuple. Re-derive it with --derive-tuple and'
                . ' review what a step added to or removed from the published surface.',
                Diff::betweenSets($tracked->fields, $derived->fields, 'tracked tuple', 'publishing code'),
            );
        }

        $this->report->fact('tuple fields', \count($derived->fields));
    }

    /**
     * Normalization and the tuple must not overlap.
     *
     * The two contracts pull in opposite directions: the tuple says which
     * published fields are compared, normalization says which fields are not.
     * A row that names a compared field would delete that comparison silently,
     * which is the same hole as a map row that erases evidence instead of
     * translating it. The measured deriver cannot emit such a row, but the tsv
     * is editable by hand, and that is what this guard is for.
     */
    private function checkNormalizationScope(): void
    {
        $fields = EquivalenceTuple::load($this->options->candidateRoot . '/finding-gate/equivalence-tuple.tsv')->fields;

        foreach ($this->normalization->rules() as $rule) {
            if ($rule->kind === NormalizationRule::KIND_LINE_REGEX) {
                continue;
            }

            $segments = explode('.', $rule->locator);
            $last = end($segments);

            if (!\in_array($last, $fields, true)) {
                continue;
            }

            $this->report->fail(
                FailureClass::NORMALIZATION_OVERREACH,
                \sprintf('%s / %s', $rule->surface, $rule->locator),
                \sprintf(
                    'This rule redacts "%s", which the equivalence tuple compares. Excluding a compared field would'
                    . ' retire it from the comparison while the tuple still claims it is guarded.',
                    $last,
                ),
            );
        }
    }

    /**
     * The same property, measured rather than read off the locators.
     *
     * A locator does not have to name a tuple field to reach one: a row on
     * `violations` alone would take the whole findings section out of the
     * comparison, and a line-regex row cannot be judged statically at all. So
     * the surface where the tuple is defined is normalized and its findings
     * section compared against the raw one.
     *
     * @param list<array<string, mixed>> $findings
     */
    private function checkNormalizationLeavesFindings(string $side, CaseDefinition $case, string $artifact, array $findings): void
    {
        $key = Surfaces::key('case:' . $case->id, 'format:json');
        $normalized = json_decode($this->normalization->normalize(Surfaces::surfaceClass($key), $artifact), true);
        $after = \is_array($normalized) && \is_array($normalized['violations'] ?? null)
            ? array_values($normalized['violations'])
            : null;

        if ($after === $findings) {
            return;
        }

        $this->report->fail(
            FailureClass::NORMALIZATION_OVERREACH,
            $side . ' / ' . $key,
            'Normalization changes the published findings of this surface, so a field the tuple compares is being'
            . ' redacted before the two sides are compared.',
        );
    }

    /**
     * @param array<string, string> $first
     * @param array<string, string> $second
     */
    private function checkDeterminism(array $first, array $second): void
    {
        // The union, not run 1's keys: a surface that only one run produces at
        // all — the conditional `stderr:` key is exactly that shape — would
        // otherwise be compared by nothing.
        foreach (array_keys($first + $second) as $key) {
            $surface = Surfaces::surfaceClass($key);

            if (!isset($first[$key]) || !isset($second[$key])) {
                $this->report->fail(
                    FailureClass::NONDETERMINISM_UNDECLARED,
                    $key,
                    \sprintf('Only run %d of the candidate tree produced this surface at all.', isset($first[$key]) ? 1 : 2),
                );

                continue;
            }

            $left = $this->normalization->normalize($surface, $first[$key]);
            $right = $this->normalization->normalize($surface, $second[$key]);

            if ($left !== $right) {
                $this->report->fail(
                    FailureClass::NONDETERMINISM_UNDECLARED,
                    $key,
                    'Two runs of the candidate tree differ after normalization, so the normalization list does not'
                    . ' cover everything that varies. Measure it again with --derive-normalization.',
                    Diff::between($left, $right, 'run 1', 'run 2'),
                );
            }
        }
    }

    /** @param array<string, string> $artifacts */
    private function checkFindings(string $side, array $artifacts, bool $trackObserved): void
    {
        $tuple = EquivalenceTuple::load($this->options->candidateRoot . '/finding-gate/equivalence-tuple.tsv');

        foreach ($this->corpus->cases as $case) {
            $key = Surfaces::key('case:' . $case->id, 'format:json');
            $report = json_decode($artifacts[$key] ?? '', true);

            if (!\is_array($report) || !\is_array($report['violations'] ?? null)) {
                $this->report->fail(FailureClass::RUN_FAILED, $side . ' / ' . $case->id, 'The JSON surface carries no findings section.');

                continue;
            }

            if (($report['violationsMeta']['truncated'] ?? false) === true) {
                $this->report->fail(
                    FailureClass::RUN_FAILED,
                    $side . ' / ' . $case->id,
                    'The JSON surface truncated its findings, so the comparison would silently cover a prefix.'
                    . ' Add --format-opt=violations=all to the case arguments.',
                );
            }

            $this->checkBaselineSurface($side, $case, $artifacts);

            /** @var list<array<string, mixed>> $findings */
            $findings = array_values($report['violations']);
            $this->checkTupleAgainstFindings($side, $case, $tuple, $findings);
            $this->checkNormalizationLeavesFindings($side, $case, $artifacts[$key], $findings);
            $this->checkFingerprints($side, $case, $findings, $artifacts);

            if ($trackObserved) {
                $this->findingsByCase[$case->id] = $findings;
            }
        }
    }

    /**
     * An absent surface must not read as a surface that agrees.
     *
     * `baseline-file` is captured as the file the command wrote, and a command
     * that wrote nothing captures as an empty string on both sides — which
     * compares equal, and would silently retire the whole baseline surface from
     * the comparison. So the surface's existence is asserted before it is
     * compared, on each side separately, together with the exit code of the
     * command that was supposed to produce it.
     *
     * @param array<string, string> $artifacts
     */
    private function checkBaselineSurface(string $side, CaseDefinition $case, array $artifacts): void
    {
        $scope = 'case:' . $case->id;
        $exit = $artifacts[Surfaces::key($scope, 'exit:baseline:generate')] ?? null;

        if ($exit !== '0') {
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline:generate',
                \sprintf('baseline:generate exited %s, so its file is not a surface either side can be held to.', $exit ?? 'nothing'),
            );
        }

        if (trim($artifacts[Surfaces::key($scope, 'baseline-file')] ?? '') === '') {
            $this->report->fail(
                FailureClass::RUN_FAILED,
                $side . ' / ' . $case->id . ' / baseline-file',
                'baseline:generate wrote no baseline. An empty baseline compares equal to an empty baseline, so the'
                . ' whole surface would drop out of the comparison unnoticed.',
            );
        }
    }

    /** @param list<array<string, mixed>> $findings */
    private function checkTupleAgainstFindings(string $side, CaseDefinition $case, EquivalenceTuple $tuple, array $findings): void
    {
        foreach ($findings as $index => $finding) {
            $keys = array_keys($finding);

            if ($keys === $tuple->fields) {
                continue;
            }

            $this->report->fail(
                FailureClass::FINDING_TUPLE_MISMATCH,
                \sprintf('%s / %s / finding #%d', $side, $case->id, $index),
                'A published finding object\'s key set is not the tracked tuple. A field that exists but is not'
                . ' compared must be impossible, so this is a failure rather than a wider comparison.',
                Diff::betweenSets($tuple->fields, array_map(strval(...), $keys), 'tuple', 'finding'),
            );

            return;
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, string> $artifacts
     */
    private function checkFingerprints(string $side, CaseDefinition $case, array $findings, array $artifacts): void
    {
        $expected = Fingerprints::expected($findings);
        $scope = 'case:' . $case->id;
        $comparisons = [
            'sarif partialFingerprints' => [
                $expected,
                Fingerprints::publishedInSarif($artifacts[Surfaces::key($scope, 'format:sarif')] ?? '{}'),
            ],
            'gitlab fingerprint' => [
                Fingerprints::md5Of($expected),
                Fingerprints::publishedInGitLab($artifacts[Surfaces::key($scope, 'format:gitlab')] ?? '[]'),
            ],
        ];

        foreach ($comparisons as $label => [$recomputed, $published]) {
            if ($recomputed === $published) {
                continue;
            }

            $this->report->fail(
                FailureClass::FINGERPRINT_MISMATCH,
                \sprintf('%s / %s / %s', $side, $case->id, $label),
                'The published fingerprints are not the ones recomputed from this same side\'s published finding'
                . ' fields, so the identity consumers track has moved for a reason the fields do not show.',
                Diff::betweenSets($recomputed, $published, 'recomputed', 'published'),
            );
        }
    }

    /**
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    private function compareSurfaces(array $candidate, array $reference): void
    {
        $this->compareFindingCounts($candidate, $reference);
        $keys = array_keys($candidate + $reference);
        sort($keys);

        foreach ($keys as $key) {
            $surface = Surfaces::surfaceClass($key);

            if (!isset($candidate[$key]) || !isset($reference[$key])) {
                $this->report->fail(
                    FailureClass::SURFACE_MISMATCH,
                    $key,
                    \sprintf('Surface produced by %s only.', isset($candidate[$key]) ? 'the candidate' : 'the reference'),
                );

                continue;
            }

            $left = $this->normalization->normalize($surface, $candidate[$key]);
            $right = $this->normalization->normalize($surface, $this->maps->forward($reference[$key]));

            if ($left !== $right) {
                $this->report->fail(
                    FailureClass::SURFACE_MISMATCH,
                    $key,
                    'The surface differs beyond what the declared maps and normalization account for.',
                    Diff::between($left, $right, 'candidate', 'reference (mapped)'),
                );
            }
        }
    }

    /**
     * Reported ahead of the surface comparison because "how many findings" is
     * the question a reader asks first, and a count change otherwise arrives as
     * a diff of eleven formats.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    private function compareFindingCounts(array $candidate, array $reference): void
    {
        foreach ($this->corpus->cases as $case) {
            $key = Surfaces::key('case:' . $case->id, 'format:json');
            $left = self::findingCount($candidate[$key] ?? '');
            $right = self::findingCount($this->maps->forward($reference[$key] ?? ''));

            if ($left !== $right) {
                $this->report->fail(
                    FailureClass::FINDING_COUNT_MISMATCH,
                    'case:' . $case->id,
                    \sprintf('The candidate reports %d finding(s), the reference %d.', $left, $right),
                    Diff::betweenSets(
                        self::findingIdentities($reference[$key] ?? ''),
                        self::findingIdentities($candidate[$key] ?? ''),
                        'reference',
                        'candidate',
                    ),
                );
            }
        }
    }

    /**
     * Only the paths that differ between the two sides are a leak worth failing
     * on: the reference checkout and the gate's own scratch directory. The
     * candidate root is deliberately not one of them — the corpus lives inside
     * it, and SARIF publishes the run's working directory as an absolute URI, so
     * both sides carry that same path by design.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    private function checkPathLeaks(array $candidate, array $reference, string $referenceRoot): void
    {
        $paths = [$referenceRoot, $this->temporaryDirectory];

        foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifacts) {
            foreach ($artifacts as $key => $content) {
                foreach ($paths as $path) {
                    if (str_contains($content, $path)) {
                        $this->report->fail(
                            FailureClass::PATH_LEAK,
                            $side . ' / ' . $key,
                            \sprintf(
                                'The artifact names the directory the run happened in ("%s"), so it is not comparable'
                                . ' between two checkouts and must not be published either.',
                                $path,
                            ),
                        );

                        break 2;
                    }
                }
            }
        }
    }

    private function checkCoverage(): void
    {
        $declared = $this->witness->staticChannels();
        $observed = [];
        $producers = [];

        foreach ($this->corpus->cases as $case) {
            $declared = [...$declared, ...$this->witness->computedChannels($case)];
            $caseObserved = self::observedChannels($this->findingsByCase[$case->id] ?? []);
            $observed = [...$observed, ...$caseObserved];

            foreach ($caseObserved as $channel) {
                $producers[$channel][] = $case->id;
            }

            $this->checkCaseClaim($case, $caseObserved);
        }

        $this->checkSingleProducer($producers);

        $declared = array_values(array_unique($declared));
        $observed = array_values(array_unique($observed));
        sort($declared);
        sort($observed);

        $this->report->fact('declared channels', \count($declared));
        $this->report->fact('observed channels', \count($observed));

        $shortfall = array_values(array_diff($declared, $observed));
        $surplus = array_values(array_diff($observed, $declared));

        if ($shortfall !== []) {
            $detail = \sprintf(
                '%d declared channel(s) no case observes: a fixture lost, or a channel that stopped firing.',
                \count($shortfall),
            );

            if ($this->options->incompleteCorpus) {
                $this->report->warn($detail . ' Downgraded by --incomplete-corpus: ' . implode(', ', \array_slice($shortfall, 0, 8)) . (\count($shortfall) > 8 ? ', …' : ''));
                $this->report->limit(\sprintf(
                    '%d declared channel(s) were observed by no case, and --incomplete-corpus downgraded that shortfall',
                    \count($shortfall),
                ));
            } else {
                $this->report->fail(FailureClass::COVERAGE_SHORTFALL, 'corpus', $detail, \array_slice($shortfall, 0, 20));
            }
        }

        if ($surplus !== []) {
            $this->report->fail(
                FailureClass::COVERAGE_SURPLUS,
                'corpus',
                'Channel(s) observed that nothing declares.',
                $surplus,
            );
        }
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
     * A declared map row that translated nothing is a lie about what the step
     * renamed — the same defect as a normalization rule that redacted nothing,
     * and it fails the same way.
     */
    private function checkStaleMaps(): void
    {
        foreach ($this->maps->staleRows() as $row) {
            $this->report->fail(
                FailureClass::MAP_STALE,
                $row,
                'This map row matched nothing in the whole run, in either direction. A rename nobody can point at is'
                . ' not a declaration of what changed, so it fails until it is either corrected or removed.',
            );
        }
    }

    /** @param list<string> $observed */
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
            'The case no longer fires exactly the channels its case.json claims. The claim is verified, not'
            . ' documentation.',
            Diff::betweenSets($claimed, $observed, 'claimed', 'fired'),
        );
    }

    private function checkWitnesses(): void
    {
        $container = $this->witness->staticChannels();
        sort($container);
        $fixture = $this->witness->fixtureChannels();

        if ($container === $fixture) {
            return;
        }

        $this->report->fail(
            FailureClass::WITNESS_DISAGREEMENT,
            'tests/Analysis/Finding/Fixtures/Channels/declared.txt',
            'The container and the tracked fixture disagree about the static declarations. Two artifacts disagreeing'
            . ' is the cheapest detector we have, so neither is trusted over the other here.',
            Diff::betweenSets($fixture, $container, 'fixture', 'container'),
        );
    }

    private function checkStaleNormalization(): void
    {
        foreach ($this->normalization->staleRules() as $rule) {
            $this->report->fail(
                FailureClass::NORMALIZATION_STALE,
                \sprintf('%s / %s', $rule->surface, $rule->locator),
                'This normalization rule matched nothing in the whole run. An exclusion nobody can point at is how'
                . ' the list grows into a blanket, so it fails until it is either justified or removed.',
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return list<string>
     */
    private static function observedChannels(array $findings): array
    {
        $channels = [];

        foreach ($findings as $finding) {
            if (\is_string($finding['channel'] ?? null)) {
                $channels[$finding['channel']] = $finding['channel'];
            }
        }

        return array_values($channels);
    }

    private static function findingCount(string $json): int
    {
        $decoded = json_decode($json, true);

        return \is_array($decoded) && \is_array($decoded['violations'] ?? null) ? \count($decoded['violations']) : -1;
    }

    /** @return list<string> */
    private static function findingIdentities(string $json): array
    {
        $decoded = json_decode($json, true);
        $identities = [];

        foreach ((array) (\is_array($decoded) ? $decoded['violations'] ?? [] : []) as $finding) {
            if (\is_array($finding)) {
                $identities[] = \sprintf('%s @ %s', $finding['channel'] ?? '?', $finding['subject'] ?? '?');
            }
        }

        sort($identities);

        return $identities;
    }
}
