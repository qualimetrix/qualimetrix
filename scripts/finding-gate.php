<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The finding-equivalence gate.
 *
 * Proves that a declared vocabulary change altered nothing observable except
 * what a declared map says it changed. See
 * finding-gate/README.md for the corpus layout and the surface list.
 *
 * Deliberately outside `src/`: it is not product code, it must run against two
 * trees at once, and it must keep working while the product's own vocabulary is
 * being renamed under it.
 */

require __DIR__ . '/finding-gate/classes.php';

exit(GateModes::main(CommandLine::arguments()));
