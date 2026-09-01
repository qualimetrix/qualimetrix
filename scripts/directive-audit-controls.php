<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

/**
 * The threshold audit's negative controls, as a re-runnable harness.
 *
 * The detector answers whether an inline directive does anything, and the only
 * evidence that it answers *correctly* is that it stops answering correctly
 * when broken. Each control here plants one breakage in a scratch clone of this
 * working tree, runs that clone's own tests, and asserts which cases noticed.
 *
 * It is a command rather than a transcript for the same reason the finding
 * gate's controls are: the mechanism under test has already been rewritten
 * three times inside one package, and a table from before a rewrite proves
 * nothing about what came after.
 *
 * Not part of `composer check`. It clones the tree once per control, which is
 * the price of planting breakages one at a time; run it when the audit changes.
 */

require __DIR__ . '/finding-gate/CommandLine.php';
require __DIR__ . '/finding-gate-controls/Shell.php';
require __DIR__ . '/finding-gate-controls/Scratch.php';
require __DIR__ . '/finding-gate-controls/Mutation.php';

foreach (['Probe', 'Probes', 'Suite', 'Outcome', 'Report', 'Harness'] as $part) {
    require __DIR__ . '/directive-audit-controls/' . $part . '.php';
}

exit(Harness::main(\QmxFindingGate\CommandLine::arguments()));
