<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;

/** Whole SARIF and GitLab publications retain unmapped fingerprint changes. */
final class FingerprintControls
{
    public static function fingerprintUnexplained(): Control
    {
        return Control::red(
            'fingerprint-no-map',
            'published fingerprints move while their source finding identity stays unchanged',
            ChannelRenamePlants::publishedUnusedPrivateFingerprintMutation(),
            array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::SURFACE_MISMATCH, $scope, exactScope: true), [
                'case:smells|format:gitlab', 'case:smells|format:sarif',
                'case:detectors-smells|format:gitlab', 'case:detectors-smells|format:sarif',
                'case:detectors|format:gitlab', 'case:detectors|format:sarif',
            ]),
        );
    }
}
