<?php

declare(strict_types=1);

/*
 * Writes the namespace-versus-path allow-list from a fresh scan of the tree.
 *
 * A write, not a check: it never exits 0. 4 means it wrote and the guard should
 * now be run against it. Everything else leaves the tracked file exactly as it
 * was: 5 the scan failed, 6 the tree carries more violations than the ceiling
 * admits, 7 it carries a namespace the list does not already allow, 8 the
 * tracked list itself could not be read.
 */

use Qualimetrix\Governance\TestSuiteHygiene\NamespacePathAllowList;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

exit(NamespacePathAllowList::derive());
