<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The two trees' surfaces compared: finding counts first, then every surface after the reference is
 * translated by the maps and both sides are normalized, and neither side naming a directory it ran in.
 */
final class SurfaceComparison
{
    public function __construct(
        private readonly GateReport $report,
        private readonly Corpus $corpus,
        private readonly RenameMaps $maps,
        private readonly Normalization $normalization,
        private readonly FingerprintCheck $fingerprintCheck,
        private readonly DeclaredDeltaCheck $declaredDeltaCheck,
        private readonly string $temporaryDirectory,
    ) {}

    /**
     * @param array<string, string> $candidate
     * @param array<string, string> $reference
     */
    public function compareSurfaces(array $candidate, array $reference): void
    {
        $this->compareFindingCounts($candidate, $reference);
        $keys = array_keys($candidate + $reference);
        sort($keys);

        foreach ($keys as $key) {
            // The third decision point. The two others are in poll loops, so
            // without this an interrupt arriving once every child has exited
            // would not be acted on until the run had finished anyway.
            Interruption::raiseIfRequested();

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
            $candidateArtifact = $candidate[$key];
            $referenceArtifact = $reference[$key];

            // The HTML report is compared through its payload; the shell and the
            // report application's bundle are the tool ({@see ReportPayload}).
            //
            // Reduced FIRST, before anything is substituted or translated. A row
            // of a map counts as used the moment it substitutes something, so
            // translating the whole file would let a row fire inside the bundle
            // — minified JavaScript that carries every metric key as a literal —
            // and stop being reported stale, having proved nothing on the
            // surface that is actually compared.
            if ($surface === 'format:html') {
                try {
                    $candidateArtifact = ReportPayload::of($candidateArtifact, $key, 'candidate');
                    $referenceArtifact = ReportPayload::of($referenceArtifact, $key, 'reference');
                } catch (GateError $error) {
                    $this->report->fail(FailureClass::REPORT_PAYLOAD_UNREADABLE, $key, $error->getMessage());

                    continue;
                }
            }

            // The reference's records are translated and then put back into the
            // order their new names give, because a rename moves them: the
            // product sorts findings by an identity whose first component is the
            // channel code, and translating in place leaves the reference in the
            // new vocabulary and the old order. Sound only while both sides are
            // in the order of their own producer's key, which is asserted here
            // on the raw artifacts and is a failure of its own when it does not
            // hold — see {@see PublishedOrder}.
            $ordered = $this->checkPublishedOrder($key, $surface, $candidateArtifact, $referenceArtifact);

            $left = $this->normalization->normalize($surface, $this->fingerprintCheck->substituteFingerprints('candidate', $key, $candidateArtifact));
            $translated = $this->maps->forward(
                $this->fingerprintCheck->substituteFingerprints('reference', $key, $referenceArtifact),
                $surface,
            );

            if ($ordered && PublishedOrder::handles($surface)) {
                $translated = PublishedOrder::reorder($surface, $translated);
            }

            $right = $this->normalization->normalize($surface, $translated);

            if ($left === $right) {
                continue;
            }

            $this->declaredDeltaCheck->checkDifference($key, $left, $right);
        }
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
                $disorder = PublishedOrder::disorder($surface, $artifact);
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
    public function checkPathLeaks(array $candidate, array $reference, string $referenceRoot): void
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
