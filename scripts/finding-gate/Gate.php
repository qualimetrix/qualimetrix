<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

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

    /** What the candidate's own source says a metric name may be. */
    private readonly MetricVocabulary $vocabulary;

    private readonly Corpus $corpus;

    private readonly ChannelWitness $witness;

    private readonly DeclaredDelta $declaredDelta;

    private readonly ChannelSplit $split;

    private readonly string $temporaryDirectory;

    /** @var array<string, list<array<string, mixed>>> */
    private array $findingsByCase = [];

    /**
     * What each side needs to hand its opaque fingerprint surface an identity
     * instead of a hash: the verified hash-to-identity pairs of that side's own
     * raw findings, and how many fingerprints that surface published.
     *
     * @var array<string, array{preimages: array<string, string>, published: int}>
     */
    private array $fingerprintIdentities = [];

    /** Substituted fingerprint values, per side, so a GREEN run can say what was not compared as bytes. */
    private int $substitutedFingerprints = 0;

    /**
     * Surface key => measured diff, while deriving the declared delta instead of
     * holding the run to it.
     *
     * @var array<string, string>|null
     */
    private ?array $derived = null;

    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
    ) {
        $root = $this->options->candidateRoot . '/finding-gate';
        $this->normalization = Normalization::load($root . '/normalization.tsv');
        $this->vocabulary = MetricVocabulary::ofTree($this->options->candidateRoot);
        $this->maps = RenameMaps::load($root . '/maps', $this->vocabulary);
        $this->declaredDelta = DeclaredDelta::load($root);
        $this->split = ChannelSplit::of($this->maps);
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
        $this->report->fact('auxiliary cases', array_map(
            static fn(CaseDefinition $case): string => $case->id,
            array_values(array_filter($this->corpus->cases, static fn(CaseDefinition $case): bool => $case->isAuxiliary())),
        ));
        $this->report->fact('maps', $this->maps->isIdentity() ? 'empty (identity)' : 'declared');
        $this->report->fact('map rows', \count($this->maps->declaredRows()));
        $this->report->fact('aggregation suffixes', $this->vocabulary->suffixes);
        $this->report->fact('split halves', $this->split->halves());

        // Loud on purpose. A declared delta is the one declaration that lets a
        // surface differ, so how many there are and how big they are is the
        // first thing a reader of a GREEN run has to be able to see.
        $this->report->fact('declared deltas', \sprintf(
            '%d surface(s), %d byte(s) total',
            $this->declaredDelta->count(),
            $this->declaredDelta->totalBytes(),
        ));
        $this->report->countDeclaredDeltas($this->declaredDelta->count());

        if (!$this->declaredDelta->isEmpty()) {
            $this->report->warn(\sprintf(
                '%d surface(s) are compared against a declared delta rather than for equality: %s.',
                $this->declaredDelta->count(),
                implode(', ', $this->declaredDelta->surfaces()),
            ));
        }

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

            $this->checkReferenceInput($first, $referenceArtifacts);
            $this->checkFindings('candidate', $first, trackObserved: true);
            $this->checkFindings('reference', $referenceArtifacts, trackObserved: false);
            $this->checkSplitExplanation($first, $referenceArtifacts);
            $this->compareSurfaces($first, $referenceArtifacts);

            // Said out loud for the same reason the declared-delta count is: a
            // reader of a GREEN run has to be able to see that one published
            // value was compared as the identity it hashes rather than as the
            // bytes the product wrote.
            $this->report->fact('fingerprints substituted', \sprintf(
                '%d value(s) on %s, both sides',
                $this->substitutedFingerprints,
                Fingerprints::OPAQUE_SURFACE,
            ));
            $this->checkPathLeaks($first, $referenceArtifacts, $reference->root);
            $this->checkCoverage();
            $this->checkWitnesses();
            $this->checkStaleNormalization();
            $this->checkStaleMaps();
            $this->checkStaleDeclaredDelta();
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
     * Measures every surface that differs and writes it out as the declared
     * delta, so no declaration is a diff somebody typed.
     *
     * @return list<string> the files written
     */
    public function deriveDeclaredDelta(): array
    {
        $this->derived = [];
        $this->compare();
        $derived = $this->derived ?? [];

        return $this->declaredDelta->rewrite($derived);
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
        $tracked = EquivalenceTuple::load($this->options->candidateRoot);
        $derived = EquivalenceTuple::derive($this->options->candidateRoot);

        if (!$tracked->equals($derived)) {
            $this->report->fail(
                FailureClass::TUPLE_FIELD_DRIFT,
                EquivalenceTuple::TRACKED_PATH,
                'The published finding fields no longer match the tracked tuple. Re-derive it with --derive-tuple and'
                . ' review what a step added to or removed from the published surface.',
                Diff::betweenSets($tracked->fields, $derived->fields, 'tracked tuple', 'publishing code'),
            );
        }

        $this->report->fact('tuple fields', \count($derived->fields));

        // The licence for substituting the opaque fingerprint, asserted where
        // the tuple is loaded rather than argued in a docblock. An input the
        // tuple does not compare would be a datum only the hash carries, and
        // replacing the hash would retire it from the comparison — the same hole
        // `normalization-overreach` exists for.
        $outside = array_values(array_diff(Fingerprints::INPUT_FIELDS, $derived->fields));

        if ($outside !== []) {
            $this->report->fail(
                FailureClass::TUPLE_FIELD_DRIFT,
                EquivalenceTuple::TRACKED_PATH,
                \sprintf(
                    'The fingerprint is composed from published field(s) the tuple does not compare: %s. Until the'
                    . ' tuple covers them, the GitLab hash cannot be replaced by the identity it states.',
                    implode(', ', $outside),
                ),
            );
        }
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
        $fields = EquivalenceTuple::load($this->options->candidateRoot)->fields;

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
        $tuple = EquivalenceTuple::load($this->options->candidateRoot);

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

        $sarif = $this->decodeFingerprintSurface(
            $side,
            $case,
            $scope,
            'format:sarif',
            $artifacts,
            static fn(string $raw): array => Fingerprints::publishedInSarif($raw),
        );
        $gitlab = $this->decodeFingerprintSurface(
            $side,
            $case,
            $scope,
            'format:gitlab',
            $artifacts,
            static fn(string $raw): array => Fingerprints::publishedInGitLab($raw),
        );

        // Recorded from the RAW findings of this side, before any map touches
        // them: this is what lets the opaque surface be compared as an identity
        // rather than as hex, and recomputing it from translated fields is the
        // one thing that would make it a lie. See Fingerprints' docblock.
        if ($gitlab !== null) {
            $this->fingerprintIdentities[$side . '|' . $case->id] = [
                'preimages' => Fingerprints::preimagesByHash($findings),
                'published' => \count($gitlab),
            ];
        }

        $comparisons = [
            'sarif partialFingerprints' => [$expected, $sarif],
            'gitlab fingerprint' => [Fingerprints::md5Of($expected), $gitlab],
        ];

        foreach ($comparisons as $label => [$recomputed, $published]) {
            // A surface that failed to decode already reported RUN_FAILED
            // below and has nothing left to compare against — reporting a
            // mismatch on top would blame the finding identity for what is
            // really a dead artifact.
            if ($published === null) {
                continue;
            }

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
     * Decodes one fingerprint surface, or reports RUN_FAILED and returns
     * `null` when it cannot be decoded.
     *
     * Ш4b left this uncaught: a non-parsing artifact threw `JsonException` out
     * of {@see Fingerprints::publishedInSarif()} / {@see Fingerprints::publishedInGitLab()}
     * and killed the whole gate process without writing a report, so the
     * harness could not tell a broken product from a broken instrument (see
     * the Ш4c entry in docs/internal/plans/rule-vocabulary/PLAN.md). Every
     * decode now goes through here, named by the artifact and the exit code
     * of the `check` invocation that produced it — the same two facts
     * {@see checkBaselineSurface()} already reports for a missing baseline
     * file, so a dead artifact and a dead run read the same way.
     *
     * @param array<string, string> $artifacts
     * @param callable(string): list<string> $decode
     *
     * @return list<string>|null
     */
    private function decodeFingerprintSurface(
        string $side,
        CaseDefinition $case,
        string $scope,
        string $surface,
        array $artifacts,
        callable $decode,
    ): ?array {
        $raw = $artifacts[Surfaces::key($scope, $surface)] ?? null;

        if ($raw === null) {
            return null;
        }

        try {
            return $decode($raw);
        } catch (JsonException $exception) {
            $exit = $artifacts[Surfaces::key($scope, 'exit:' . $surface)] ?? null;

            $this->report->fail(
                FailureClass::RUN_FAILED,
                \sprintf('%s / %s / %s', $side, $case->id, $surface),
                \sprintf(
                    'The %s artifact does not parse as JSON (%s). Its producing run exited %s, so this is a dead'
                    . ' artifact, not a fingerprint disagreement.',
                    $surface,
                    $exception->getMessage(),
                    $exit ?? 'nothing',
                ),
            );

            return null;
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

            // Substitute first, translate second. The candidate's text is not
            // translated at all; the reference's is, and by then its hashes have
            // already become the identities they hash, so a declared row reaches
            // them like it reaches every other name.
            $left = $this->normalization->normalize($surface, $this->substituteFingerprints('candidate', $key, $candidate[$key]));
            $right = $this->normalization->normalize(
                $surface,
                $this->maps->forward(
                    $this->substituteFingerprints('reference', $key, $reference[$key]),
                    $surface,
                ),
            );

            if ($left === $right) {
                continue;
            }

            $diff = ExactDiff::between($left, $right, 'candidate', 'reference (mapped)');

            if ($this->derived !== null) {
                $this->derived[$key] = $diff->render();

                continue;
            }

            $declared = $this->declaredDelta->claim($key);

            if ($declared === null) {
                $this->report->fail(
                    FailureClass::SURFACE_MISMATCH,
                    $key,
                    'The surface differs beyond what the declared maps and normalization account for.',
                    Diff::between($left, $right, 'candidate', 'reference (mapped)'),
                );

                continue;
            }

            $this->checkAgainstDeclaredDelta($key, $diff, $declared);
        }
    }

    /**
     * Hands one surface its identities in place of its opaque fingerprints.
     *
     * Only {@see Fingerprints::OPAQUE_SURFACE} is touched, and only with the
     * pairs {@see checkFingerprints()} verified for that side and case. A
     * surface whose findings section or fingerprint artifact was unusable has no
     * recorded pairs at all; that already failed as `run-failed`, and inventing
     * a substitution on top would blame the identity for a dead artifact.
     *
     * A hash that is published and not replaced is a failure of its own. The
     * comparison would still run — on hex — and hex compares equal to itself
     * until the day something renames a channel, which is precisely the day this
     * mechanism is supposed to speak.
     */
    private function substituteFingerprints(string $side, string $key, string $text): string
    {
        if (Surfaces::surfaceClass($key) !== Fingerprints::OPAQUE_SURFACE) {
            return $text;
        }

        $scope = substr($key, 0, (int) strpos($key, '|'));
        $identities = $this->fingerprintIdentities[$side . '|' . substr($scope, \strlen('case:'))] ?? null;

        if (!str_starts_with($scope, 'case:') || $identities === null) {
            return $text;
        }

        $substitution = Fingerprints::substitute($text, $identities['preimages'], $identities['published']);
        $this->substitutedFingerprints += $substitution->replaced;

        if (!$substitution->isComplete()) {
            $this->report->fail(
                FailureClass::FINGERPRINT_OPAQUE,
                $side . ' / ' . $key,
                \sprintf(
                    'The gate %s, so this surface would be compared as opaque hex: a hash that no longer states the'
                    . ' identity it hashes agrees with itself under any rename.',
                    $substitution->shortfall(),
                ),
            );
        }

        return $substitution->text;
    }

    /**
     * Holds a differing surface to its declaration, on all four properties.
     *
     * Size and reach are judged on the MEASURED diff, not on the declared text:
     * a declaration that reaches too far must fail for reaching too far, and not
     * be excused by also failing to match.
     */
    private function checkAgainstDeclaredDelta(string $key, ExactDiff $diff, string $declared): void
    {
        if ($diff->changedLineCount() > DeclaredDelta::MAX_CHANGED_LINES) {
            $this->report->fail(
                FailureClass::DELTA_TOO_LARGE,
                $key,
                \sprintf(
                    'The measured diff is %d changed line(s), and a declaration may be %d. Declare the rename as map'
                    . ' rows instead of dropping in a blob.',
                    $diff->changedLineCount(),
                    DeclaredDelta::MAX_CHANGED_LINES,
                ),
            );
        }

        foreach ($this->overreachingLines($diff) as $problem) {
            $this->report->fail(
                FailureClass::DELTA_OVERREACH,
                $key,
                $problem . ' A declared delta may change a compared field only inside a record whose (rule, code)'
                . ' pair a declared split already explains — the waiver normalization was refused is refused here too.',
            );
        }

        if ($diff->render() === $declared) {
            return;
        }

        $this->report->fail(
            FailureClass::DELTA_MISMATCH,
            $key,
            \sprintf(
                'The measured diff is not the declared one (%s). Re-derive it with --derive-declared-delta and review'
                . ' what moved.',
                $this->declaredDelta->fileOf($key),
            ),
            [
                ...Diff::between($declared, $diff->render(), 'declared delta', 'measured diff'),
                ...$diff->tokenDetail(),
            ],
        );
    }

    /**
     * The diff lines that change a field the equivalence tuple compares.
     *
     * Changed, not mentioned: a compact JSON record names `channel` on the same
     * line as the magnitude it records, so "the line contains a compared field"
     * would flag every such line. Only the published `"field": value` syntax is
     * read, which covers the JSON family and the HTML report's embedded payload;
     * the plain-text surfaces print a bare name that no field syntax marks, and
     * for those the record-level split check is the guard.
     *
     * @return list<string>
     */
    private function overreachingLines(ExactDiff $diff): array
    {
        $fields = EquivalenceTuple::load($this->options->candidateRoot)->fields;
        $problems = [];

        foreach ($diff->pairs() as $index => [$candidateLine, $referenceLine]) {
            foreach ($fields as $field) {
                $onCandidate = self::publishedValues($candidateLine, $field);
                $onReference = self::publishedValues($referenceLine, $field);

                if ($onCandidate === $onReference) {
                    continue;
                }

                // A line that publishes a different *number* of values for a
                // compared field is not a rename of anything: the record set on
                // that line changed, and no declared split can account for it.
                if (\count($onCandidate) !== \count($onReference)) {
                    $problems[] = \sprintf(
                        'Hunk line %d publishes %d value(s) of the compared field "%s" where the reference publishes'
                        . ' %d, so the change is not a rename a declared split could explain.',
                        $index + 1,
                        \count($onCandidate),
                        $field,
                        \count($onReference),
                    );

                    continue;
                }

                // Paired by position within the line, the same principle
                // ExactDiff::pairs() uses across the hunk: a payload publishes
                // its records in one order on both sides, so the n-th value of a
                // field on one line answers the n-th on the other. Asking about
                // the pair rather than about each value separately is what keeps
                // a delta from moving a compared field between two values no
                // explained record ever paired.
                foreach ($onReference as $position => $referenceValue) {
                    $candidateValue = $onCandidate[$position];

                    if ($referenceValue === $candidateValue) {
                        continue;
                    }

                    if ($this->split->allowsMove($field, $referenceValue, $candidateValue)) {
                        continue;
                    }

                    $problems[] = \sprintf(
                        'Hunk line %d changes the compared field "%s" ("%s" -> "%s"), a move no declared split'
                        . ' explains.',
                        $index + 1,
                        $field,
                        $referenceValue,
                        $candidateValue,
                    );
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Every value the line publishes for one field.
     *
     * @return list<string>
     */
    private static function publishedValues(?string $line, string $field): array
    {
        if ($line === null) {
            return [];
        }

        $values = [];

        foreach (self::spellingsOf($field) as $spelling) {
            $pattern = \sprintf('~"%s"\s*:\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^,}\]\s]+))~', preg_quote($spelling, '~'));

            if (preg_match_all($pattern, $line, $matches) === false) {
                throw new GateError(\sprintf('Cannot read published values of "%s".', $spelling));
            }

            foreach ($matches[2] as $index => $bare) {
                $values[] = $bare === '' ? $matches[1][$index] : $bare;
            }
        }

        return $values;
    }

    /**
     * Every key a surface publishes one tuple field under.
     *
     * The tuple is named after the JSON report, and the HTML report's embedded
     * payload publishes three of its fields under other keys. Reading only the
     * tuple's own spelling therefore meant `delta-overreach` read *nothing* on
     * `case:*|format:html` — the surface where every changed line is a compared
     * datum moving — while both this class's docblock and finding-gate/README.md
     * claimed the check covered that payload. It covered its syntax, not its
     * vocabulary.
     *
     * Measured against `HtmlFindingPartitioner::partition()`, which is the one
     * place the payload's keys are written, and pinned there by
     * {@see SelfTest::htmlPayloadVocabulary()} so a rename on that side is loud
     * rather than a silently unread field.
     *
     * @return list<string>
     */
    private static function spellingsOf(string $field): array
    {
        $aliases = [
            'rule' => 'ruleName',
            'code' => 'violationCode',
            'symbol' => 'symbolPath',
        ];

        return isset($aliases[$field]) ? [$field, $aliases[$field]] : [$field];
    }

    /**
     * A surface a declared delta covers that turned out to be equal.
     *
     * The same lie as a stale map row: a declaration of a change nobody can
     * point at.
     */
    private function checkStaleDeclaredDelta(): void
    {
        if ($this->derived !== null) {
            return;
        }

        foreach ($this->declaredDelta->staleSurfaces() as $surface) {
            $this->report->fail(
                FailureClass::DELTA_STALE,
                $surface,
                'A delta is declared for this surface, and the two trees agree on it. A declaration of a change that'
                . ' did not happen fails until it is corrected or removed.',
            );
        }
    }

    /**
     * The reference binary must be addressable in its own vocabulary.
     *
     * Exit code 3 is the product's "config/input error", so a reference run that
     * exits 3 where the candidate does not was handed a name that does not exist
     * yet — a rule renamed by the step and written into a case's input with no
     * `inputs.tsv` row to restate it. Left to itself that arrives as eleven
     * surface diffs and an empty findings section, which reads as a product
     * change; it is neither, and it says so.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    private function checkReferenceInput(array $candidate, array $reference): void
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
    private function checkSplitExplanation(array $candidate, array $reference): void
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
            $right = self::findingCount($this->maps->forward($reference[$key] ?? '', Surfaces::surfaceClass($key)));

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

    /**
     * Coverage, per channel-and-level pair, against a derived declaration.
     *
     * The declared side comes from the witnesses and never from a `case.json`:
     * a claim is hand-written on purpose, and an accounting whose two sides are
     * both hand-written cannot see a pair nobody wrote down twice. See
     * {@see ChannelCoverage} for what that hid.
     */
    private function checkCoverage(): void
    {
        $declared = $this->witness->staticPairs();
        $observed = [];
        $producers = [];

        foreach ($this->corpus->cases as $case) {
            $caseClaims = self::observedClaims($this->findingsByCase[$case->id] ?? []);
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
     * A declared map row that translated nothing is a lie about what the step
     * renamed — the same defect as a normalization rule that redacted nothing,
     * and it fails the same way.
     */
    private function checkStaleMaps(): void
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

    private function checkWitnesses(): void
    {
        ChannelWitness::checkAgreement($this->report, $this->witness->fixturePairs(), $this->witness->staticPairs());
        ChannelWitness::checkLevelVocabulary($this->report, $this->witness->productLevels());
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
