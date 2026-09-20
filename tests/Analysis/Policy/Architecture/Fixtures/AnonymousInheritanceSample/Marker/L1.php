<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Marker;

/**
 * Direct parent used by the nested anonymous classes in the Host fixtures.
 * Extends L0 so classifying by `extends: [L0]` exercises the BFS closure
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory::collectTransitiveParents()}.
 */
abstract class L1 extends L0 {}
