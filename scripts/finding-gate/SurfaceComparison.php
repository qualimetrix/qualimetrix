<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The two trees' surfaces compared: finding counts first, then every surface after the reference is
 * translated by the maps and both sides are normalized, and neither side naming a directory it ran in.
 */
final class SurfaceComparison
{
    /**
     * The steps every surface goes through, in this order; a form's registered
     * {@see SurfaceStage} runs just before the step it names. A surface a step
     * settles — equal, or reported as not comparable — goes no further.
     *
     * - `presence`: both sides produced it.
     * - `payload`: the HTML report is reduced to its payload, before anything is
     *   substituted or translated ({@see ReportPayload}).
     * - `published-order`: each side is in the order of its own producer's key.
     * - `fingerprints`: each side's published hashes become the identities they hash.
     * - `translation`: the reference is translated by the maps.
     * - `reorder`: the translated reference is put back into its key's order.
     * - `normalization`: both sides lose the fields normalization excludes.
     * - `difference`: equal, or held to the declared delta.
     */
    public const array STAGES = [
        'presence',
        'payload',
        'published-order',
        'fingerprints',
        'translation',
        'reorder',
        'normalization',
        'difference',
    ];

    /** @var array<string, list<SurfaceStage>> built-in step => the registered stages that run before it */
    private readonly array $registered;

    /** @param list<SurfaceStage> $stages the forms' registered stages, in wiring order */
    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
        private readonly RenameMaps $maps,
        private readonly Normalization $normalization,
        private readonly FingerprintCheck $fingerprintCheck,
        private readonly DeclaredDeltaCheck $declaredDeltaCheck,
        private readonly string $temporaryDirectory,
        array $stages = [],
        private readonly ?RecordCheck $records = null,
    ) {
        $registered = [];

        foreach ($stages as $stage) {
            if (!\in_array($stage->before(), self::STAGES, true)) {
                throw new GateError(\sprintf(
                    '%s runs before "%s", which is no step of SurfaceComparison::STAGES.',
                    $stage::class,
                    $stage->before(),
                ));
            }

            $registered[$stage->before()][] = $stage;
        }

        $this->registered = $registered;
    }

    /**
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function compareSurfaces(array $candidate, array $reference): void
    {
        $countCandidate = $candidate;
        $countReference = $reference;
        foreach ($this->registered['difference'] ?? [] as $stage) {
            if ($stage instanceof RecordStage) {
                [$countCandidate, $countReference] = $stage->countInputs($candidate, $reference);
            }
        }
        $this->compareFindingCounts($countCandidate, $countReference);
        $keys = array_keys($candidate + $reference);
        sort($keys);

        foreach ($keys as $key) {
            // The third decision point. The two others are in poll loops, so
            // without this an interrupt arriving once every child has exited
            // would not be acted on until the run had finished anyway.
            Interruption::raiseIfRequested();

            $pair = new SurfacePair($key, Surfaces::surfaceClass($key), $candidate[$key] ?? null, $reference[$key] ?? null);

            foreach (self::STAGES as $step) {
                foreach ($this->registered[$step] ?? [] as $stage) {
                    $stage->applyStage($pair);

                    if ($pair->settled) {
                        continue 3;
                    }
                }

                $this->step($step, $pair);

                if ($pair->settled) {
                    continue 2;
                }
            }
        }
    }

    private function step(string $step, SurfacePair $pair): void
    {
        match ($step) {
            'presence' => $this->checkPresence($pair),
            'payload' => $this->extractPayload($pair),
            'published-order' => $this->verifyPublishedOrder($pair),
            'fingerprints' => $this->fingerprints($pair),
            'translation' => $this->translation($pair),
            'reorder' => $this->reorder($pair),
            'normalization' => $this->normalization($pair),
            'difference' => $this->compareFinalBytes($pair),
            default => throw new GateError(\sprintf('"%s" is no step of SurfaceComparison::STAGES.', $step)),
        };
    }

    private function checkPresence(SurfacePair $pair): void
    {
        if ($pair->candidate === null || $pair->reference === null) {
            $this->mismatch($pair->key, \sprintf('Surface produced by %s only.', $pair->candidate !== null ? 'the candidate' : 'the reference'));

            $pair->settle();
        }
    }

    /**
     * Reduced first, before anything is substituted or translated. A row of a
     * map counts as used the moment it substitutes something, so translating
     * the whole file would let a row fire inside the bundle — minified
     * JavaScript that carries every metric key as a literal — and stop being
     * reported stale, having proved nothing on the surface that is actually
     * compared.
     */
    private function extractPayload(SurfacePair $pair): void
    {
        if ($pair->surface !== 'format:html') {
            return;
        }

        try {
            $pair->candidate = ReportPayload::of((string) $pair->candidate, $pair->key, 'candidate');
            $pair->reference = ReportPayload::of((string) $pair->reference, $pair->key, 'reference');
        } catch (GateError $error) {
            $this->report->fail(FailureClass::REPORT_PAYLOAD_UNREADABLE, $pair->key, $error->getMessage());

            $pair->settle();
        }
    }

    /**
     * The reference's records are translated and then put back into the order
     * their new names give, because a rename moves them: the product sorts
     * findings by an identity whose first component is the channel code, and
     * translating in place leaves the reference in the new vocabulary and the
     * old order. Sound only while both sides are in the order of their own
     * producer's key, which is asserted here on the raw artifacts and is a
     * failure of its own when it does not hold — see {@see PublishedOrder}.
     */
    private function verifyPublishedOrder(SurfacePair $pair): void
    {
        $pair->ordered = $this->checkPublishedOrder($pair->key, $pair->surface, (string) $pair->candidate, (string) $pair->reference);
    }

    /**
     * Substitute first, translate second. The candidate's text is not
     * translated at all; the reference's is, and by then its hashes have
     * already become the identities they hash, so a declared row reaches them
     * like it reaches every other name.
     */
    private function fingerprints(SurfacePair $pair): void
    {
        $pair->candidate = $this->fingerprintCheck->substituteFingerprints('candidate', $pair->key, (string) $pair->candidate);
        $pair->reference = $this->fingerprintCheck->substituteFingerprints('reference', $pair->key, (string) $pair->reference);
    }

    private function translation(SurfacePair $pair): void
    {
        $pair->reference = $this->maps->forward((string) $pair->reference, $pair->surface);
    }

    private function reorder(SurfacePair $pair): void
    {
        if ($pair->ordered && PublishedOrder::handles($pair->surface)) {
            $complete = null;
            if ($pair->surface === 'format:json' && $this->records !== null) {
                $document = ReportRecords::decode((string) $pair->reference);
                if (($document['violationsMeta']['truncated'] ?? false) === true) {
                    $case = substr($pair->key, 5, (int) strpos($pair->key, '|') - 5);
                    $complete = $this->records->authority($case, 'format:json', 'reference');
                }
            }
            $pair->reference = PublishedOrder::reorder($pair->surface, (string) $pair->reference, $complete);
        }
    }

    private function normalization(SurfacePair $pair): void
    {
        $pair->candidate = $this->normalization->normalize($pair->surface, (string) $pair->candidate);
        $pair->reference = $this->normalization->normalize($pair->surface, (string) $pair->reference);
    }

    /** @param list<string> $diff */
    private function mismatch(string $key, string $detail, array $diff = []): void
    {
        $this->report->fail(FailureClass::SURFACE_MISMATCH, $key, $detail, $diff);
    }

    private function compareFinalBytes(SurfacePair $pair): void
    {
        if ($pair->candidate !== $pair->reference) {
            if ($this->declaredDeltaCheck->hasIntention($pair->key)) {
                $this->declaredDeltaCheck->checkDifference($pair->key, (string) $pair->candidate, (string) $pair->reference);
            } else {
                $this->mismatch(
                    $pair->key,
                    'The surface differs outside every declared structural intention.',
                    Diff::between((string) $pair->candidate, (string) $pair->reference, 'candidate', 'reference (mapped)'),
                );
            }
        } else {
            $this->declaredDeltaCheck->observeEqual($pair->key);
        }

        $pair->settle();
    }

    /**
     * Whether both sides publish this surface in the order of their own key.
     *
     * Asserted on the raw artifacts, each side against its own vocabulary and
     * neither against the other. A side that fails it is reported and the
     * reordering step is skipped for that surface, so the comparison stays the
     * byte comparison it was: sorting a side the product did not sort would hide
     * the very defect being reported.
     */
    private function checkPublishedOrder(string $key, string $surface, string $candidate, string $reference): bool
    {
        if (!PublishedOrder::handles($surface)) {
            return false;
        }

        $ordered = true;

        foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifact) {
            try {
                $complete = null;
                if ($surface === 'format:json' && $this->records !== null) {
                    $document = ReportRecords::decode($artifact);
                    if (($document['violationsMeta']['truncated'] ?? false) === true) {
                        $case = substr($key, 5, (int) strpos($key, '|') - 5);
                        $complete = $this->records->rawAuthority($case, 'format:json', $side);
                    }
                }
                $disorder = PublishedOrder::disorder($surface, $artifact, $complete);
            } catch (GateError $error) {
                $disorder = $error->getMessage();
            }

            if ($disorder !== null) {
                $this->report->fail(FailureClass::PUBLISHED_ORDER_DRIFT, $key, 'The ' . $side . ': ' . $disorder);
                $ordered = false;
            }
        }

        return $ordered;
    }

    /**
     * Reported ahead of the surface comparison because "how many findings" is
     * the question a reader asks first, and a count change otherwise arrives as
     * a diff across every format that publishes the findings it moved.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    private function compareFindingCounts(array $candidate, array $reference): void
    {
        foreach ($this->corpus->cases as $case) {
            if (!CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, CaseOutcome::of($case, 'candidate'))
                || !CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, CaseOutcome::of($case, 'reference'))) {
                continue;
            }
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
     * The declared SARIF source URI belongs to the capture, whose input is
     * materialized in isolation. Paths in finding evidence remain leaks.
     *
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function checkPathLeaks(array $candidate, array $reference, string $referenceRoot): void
    {
        $paths = [$referenceRoot, $this->temporaryDirectory];

        foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $artifacts) {
            foreach ($artifacts as $key => $content) {
                $content = $this->normalization->normalizeCaptureMetadata(Surfaces::surfaceClass($key), $content);
                if (str_ends_with($key, '|stderr:check:output')) {
                    $content = preg_replace_callback('~^Report written to [^\r\n]+$~m', fn(array $marker): string => $this->normalization->normalize('stderr:check:output', $marker[0]), $content) ?? throw new GateError('Cannot inspect the exact output diagnostic marker.');
                }
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

                        break;
                    }
                }
            }
        }
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
