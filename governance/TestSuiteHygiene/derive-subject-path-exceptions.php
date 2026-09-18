<?php

declare(strict_types=1);

/*
 * Writes the subject-path exception lists from a fresh scan of the tree.
 *
 * A write, not a check: it never exits 0. 4 means it wrote and the guard should
 * now be run against it. Everything else leaves the tracked file exactly as it
 * was: 5 the scan failed, 6 a list measured more members than its ceiling
 * admits, 7 the tree carries a path that names no owner and level at all, 8 the
 * tracked file itself could not be read.
 */

use Qualimetrix\Governance\TestSuiteHygiene\SubjectPathExceptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

exit(SubjectPathExceptions::derive());
