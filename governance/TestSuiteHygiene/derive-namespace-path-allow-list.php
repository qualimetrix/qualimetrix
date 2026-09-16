<?php

declare(strict_types=1);

/*
 * Writes the namespace-versus-path allow-list from a fresh scan of the tree.
 *
 * A write, not a check: it never exits 0. 4 means it wrote and the guard should
 * now be run against it; 5 means the scan failed and the tracked file was left
 * exactly as it was.
 */

use Qualimetrix\Governance\TestSuiteHygiene\NamespacePathAllowList;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

exit(NamespacePathAllowList::derive());
