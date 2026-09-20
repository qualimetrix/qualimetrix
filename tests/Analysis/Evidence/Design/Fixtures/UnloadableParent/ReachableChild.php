<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Fixtures\UnloadableParent;

/**
 * Reachable by the autoloader, impossible to finish loading.
 *
 * This is the shape a standalone install of the tool carries on its own
 * vendored packages: the file resolves, so `class_exists()` includes it, and
 * the include then fails on a parent nothing can supply. The parent is named
 * outside `Qualimetrix\Tests\` on purpose -- a missing name inside that prefix
 * is what `scripts/dangling-test-names.py` exists to report.
 */
class ReachableChild extends \QmxNeverShipped\AbsentParent {}
