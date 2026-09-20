<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Marker;

/**
 * Grandparent of L1. Used to prove the transitive `extends:` closure does not
 * pick up an anonymous class's ancestry through its enclosing class.
 */
abstract class L0 {}
