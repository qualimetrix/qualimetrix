<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/**
 * What one run could not read while following chains out of the analysed path.
 *
 * Lives for exactly one {@see DitGlobalCollector::calculate()} call, which is
 * why it needs no reset: the collector builds one, hands it to the resolver it
 * also builds, and reads it back in the same frame. Nothing outside that frame
 * holds it.
 *
 * It counts **child declarations**, not distinct ancestors: two classes
 * extending one parent the walk could not get past are two of the reader's own
 * classes, and that is the quantity they can act on. Counting ancestors instead
 * understates it -- measured on `symfony/routing`, two distinct ancestors stand
 * for ten child declarations.
 *
 * What it does not claim is why a walk stopped, or what that means for the
 * number published. Both vary: four of the six outcomes leave a depth that is a
 * genuine lower bound, a cycle leaves the length of a loop, and a builtin the
 * registry does not list leaves a depth that is simply correct.
 */
final class UnreadChainTally
{
    private int $chains = 0;

    /** @var array<string, true> */
    private array $names = [];

    private bool $sawMissingInstall = false;

    public function record(ExternalDepth $depth): void
    {
        if ($depth->outcome === ExternalChainOutcome::ReachedRoot) {
            return;
        }

        ++$this->chains;

        if ($depth->outcome === ExternalChainOutcome::NoMapForIt) {
            $this->sawMissingInstall = true;

            return;
        }

        if ($depth->unresolved !== null) {
            $this->names[$depth->unresolved] = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->chains === 0;
    }

    public function chains(): int
    {
        return $this->chains;
    }

    /**
     * Sorted so that two runs over one tree produce one string.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->names);
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * Whether at least one chain stopped because the run had no install to read.
     *
     * Named for what it observes rather than for the run: the question is only
     * asked when a class actually extends something outside the analysed path,
     * so a tree with no such class reports false whether or not an install
     * exists. That is the honest answer — with nothing to look up, a missing
     * install costs this metric nothing.
     */
    public function sawMissingInstall(): bool
    {
        return $this->sawMissingInstall;
    }
}
