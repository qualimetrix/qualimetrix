<?php

declare(strict_types=1);

/**
 * `composer enumeration:directives` — the authored `@qmx-threshold` sites of a
 * directory tree, as TSV, and the freshness check over the tracked table.
 *
 * The measurement lives in {@see \QmxDirectiveAudit\ThresholdDirectiveScan} and
 * the command around it in {@see \QmxDirectiveAudit\Enumerator}, not here: a
 * script that runs on include cannot be called from a test, and this scan is
 * one of the two measures whose agreement the suite has to be able to assert.
 *
 * Loaded by an explicit require list rather than through Composer: these
 * namespaces have no PSR-4 entry, the same way `scripts/finding-gate.php` loads
 * its own parts.
 *
 * Usage: php scripts/enumerate-inline-directives.php [directory] [--check]
 */

foreach (['EnumeratedSite', 'ThresholdDirectiveScan', 'Enumerator'] as $part) {
    require __DIR__ . '/directive-audit/' . $part . '.php';
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

exit(\QmxDirectiveAudit\Enumerator::main($arguments));
