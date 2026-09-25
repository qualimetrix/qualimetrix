<?php

declare(strict_types=1);

/**
 * Loads the controls harness's classes, for its entry point and for the gate's
 * self-test, which reads what the controls require. One list, for the reason
 * the gate's own `classes.php` gives; the gate's classes are loaded first by
 * whoever requires this.
 */

foreach (
    [
        'Shell',
        'Scratch',
        'Mutation',
        'Expectation',
        'Control',
        'Outcome',
        'ChannelRenamePlants',
        'FindingControls',
        'CoverageControls',
        'DeclaredDeltaControls',
        'FingerprintControls',
        'RenameControls',
        'Controls',
        'Harness',
    ] as $class
) {
    require_once __DIR__ . '/' . $class . '.php';
}
