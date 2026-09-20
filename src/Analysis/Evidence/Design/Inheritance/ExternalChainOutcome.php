<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/**
 * How following an inheritance chain out of the analysed path ended.
 *
 * Three answers, because a depth of 0 used to mean all three at once: a class
 * that really has no parent, a chain nobody could follow, and a run with no
 * install to follow it through.
 */
enum ExternalChainOutcome
{
    /** The chain ended at a class with no parent, or at a PHP builtin. */
    case ReachedRoot;

    /** This run has no autoload map, so no chain could be followed at all. */
    case NoMapForIt;

    /** The map placed some of the chain and then stopped carrying it. */
    case BrokeAt;
}
