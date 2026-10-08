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
