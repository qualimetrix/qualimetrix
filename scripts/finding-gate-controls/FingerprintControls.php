<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/**
 * An unmapped channel rename; the historical control ID is retained.
 */
final class FingerprintControls
{
    /**
     * Eligible JSON records expose the undeclared channel movement. SARIF and
     * GitLab compare whole publications, so their failures prove changed bytes
     * rather than fingerprint decoding. Other expectations pin the mutation's
     * measured reach, including the producer listing and native case claims.
     */
    public static function fingerprintUnexplained(): Control
    {
        return Control::red(
            'fingerprint-no-map',
            'a channel moves with no finding-gate/maps/channels.tsv row naming it',
            ChannelRenamePlants::unusedPrivateChannelMutation(),
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:gitlab'),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells|format:sarif'),
                new Expectation(FailureClass::RECORD_UNDECLARED, 'case:smells|format:json', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:baseline-cycle|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:config-precedence|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:directive-placement|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:discovery|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:duplication-size|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:incomplete-directory-symlink|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:only-rules|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:parallel-files|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:scoped-layers|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:stderr-warning|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:suppression|format:suppressed', exactScope: true),
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:threshold-raising|format:suppressed', exactScope: true),
                ...ChannelRenamePlants::caseListingFailures(),
            ],
            [
                new Expectation(FailureClass::SURFACE_MISMATCH, 'case:smells'),
                new Expectation(FailureClass::RECORD_UNDECLARED, 'case:detectors|format:json', exactScope: true),
                new Expectation(FailureClass::RECORD_UNDECLARED, 'case:detectors-smells|format:json', exactScope: true),
                ...Controls::fieldValueToleration(),
                ...array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::SURFACE_MISMATCH, $scope, exactScope: true), [
                    'case:detectors-smells|baseline-file', 'case:detectors-smells|check:output:file',
                    'case:detectors-smells|explain:declaration:class:Corpus\\Smells\\Injection@src/Injection.php',
                    'case:detectors-smells|format:checkstyle', 'case:detectors-smells|format:github',
                    'case:detectors-smells|format:gitlab', 'case:detectors-smells|format:json',
                    'case:detectors-smells|format:sarif', 'case:detectors-smells|format:text',
                    'case:detectors-smells|show-suppressed',
                    'case:detectors|baseline-file', 'case:detectors|check:output:file',
                    'case:detectors|format:checkstyle', 'case:detectors|format:github',
                    'case:detectors|format:gitlab', 'case:detectors|format:json',
                    'case:detectors|format:sarif', 'case:detectors|format:text',
                    'case:detectors|show-suppressed',
                ]),
                ...ChannelRenamePlants::unusedPrivateClaimFailures(),
                new Expectation(
                    FailureClass::WITNESS_DISAGREEMENT,
                    'governance/Channel/Fixtures/declared.txt',
                ),
                ...ChannelRenamePlants::producerListingToleration(),
            ],
        );
    }

}
