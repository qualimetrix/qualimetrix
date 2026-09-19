<?php

declare(strict_types=1);

namespace QmxTautologyControls;

/**
 * Stage 05's repaired tautologies, as a re-runnable bench.
 *
 * Fourteen assertions in the ledger could not fail: their expected side was
 * computed by the very code the actual side came from, so no edit to the
 * product could move one without moving the other. Each was replaced with an
 * assertion whose expectation comes from somewhere else — a fixture, the
 * compiled container, a contract read by reflection, a tree scan.
 *
 * "It can fail now" is a claim about the new assertion, and a paragraph in a
 * report making that claim does not re-run. This does: every repair declares
 * the production edit it is supposed to reject, the harness plants that edit
 * in an isolated clone one at a time, and requires that the repair's own case
 * goes red and no undeclared case does.
 *
 * Not part of `composer check`: it clones the tree once per control, which is
 * the price of planting breakages one at a time. Run it when a repaired
 * assertion or the code under it changes.
 */

require __DIR__ . '/finding-gate-controls/Shell.php';
require __DIR__ . '/finding-gate-controls/Scratch.php';
require __DIR__ . '/finding-gate-controls/Mutation.php';

foreach (['Control', 'Controls', 'Suite', 'Outcome', 'Report', 'Harness'] as $part) {
    require __DIR__ . '/tautology-controls/' . $part . '.php';
}

exit(Harness::main());
