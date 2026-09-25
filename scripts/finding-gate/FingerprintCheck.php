<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Fingerprints against the fields they are composed from, on each side, and the identities that replace
 * the opaque hashes before the surfaces are compared.
 */
final class FingerprintCheck
{
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

    public function __construct(private readonly GateReport $report) {}

    /**
     * @param list<array<string, mixed>> $findings
     * @param array<string, string> $artifacts
     */
    public function checkFingerprints(string $side, CaseDefinition $case, array $findings, array $artifacts): void
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
     * A non-parsing artifact used to throw `JsonException` out of
     * {@see Fingerprints::publishedInSarif()} / {@see Fingerprints::publishedInGitLab()}
     * and kill the whole gate process without writing a report, so the
     * harness could not tell a broken product from a broken instrument. Every
     * decode now goes through here, named by the artifact and the exit code
     * of the `check` invocation that produced it — the same two facts
     * {@see CaseOutcomeCheck::checkBaselineSurface()} already reports for a missing baseline
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
    public function substituteFingerprints(string $side, string $key, string $text): string
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

    public function substitutedCount(): int
    {
        return $this->substitutedFingerprints;
    }
}
