<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Closure;
use Exception;
use RecursiveArrayIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A recursive walk that says which branch it could not enter.
 *
 * `RecursiveIteratorIterator::CATCH_GET_CHILD` buys the same resilience for
 * nothing: a branch whose `getChildren()` fails costs that branch and not the
 * run. What it does not buy is a word about it — the subtree simply is not
 * there, and a subtree that was not read is indistinguishable from a subtree
 * with no content. A directory checked before the descent and refused during
 * it (permissions changed, the directory was removed, a network mount went
 * away) is exactly the loss the flag was hiding.
 *
 * So the flag is not set and this class is the only place a descent failure is
 * absorbed: it is handed to the caller's recorder first, and only then does
 * the walk continue with an empty branch. Returning `null` instead is not an
 * option — the engine then throws a second, uncatchable
 * `UnexpectedValueException` about the return type (measured).
 *
 * `Exception` rather than `UnexpectedValueException`: the flag it replaces
 * swallowed every failure shape the iterator could raise, and a shape this
 * class did not name would go back to being silent. `Error` is left alone:
 * that is a defect in this process, not a branch the filesystem refused.
 *
 * @extends RecursiveIteratorIterator<RecursiveIterator<mixed, mixed>>
 */
final class DirectoryWalk extends RecursiveIteratorIterator
{
    /** @var Closure(string, string): void */
    private readonly Closure $onRefusedBranch;

    /**
     * @param RecursiveIterator<mixed, mixed> $inner
     * @param RecursiveIteratorIterator::LEAVES_ONLY|RecursiveIteratorIterator::SELF_FIRST|RecursiveIteratorIterator::CHILD_FIRST $mode
     * @param Closure(string, string): void $onRefusedBranch Receives the pathname of the branch
     *                                                       and the failure message.
     */
    public function __construct(RecursiveIterator $inner, int $mode, Closure $onRefusedBranch)
    {
        parent::__construct($inner, $mode);

        $this->onRefusedBranch = $onRefusedBranch;
    }

    /**
     * Narrowed to non-null: the empty branch returned below is what keeps the
     * walk alive, and `null` is the one answer the engine refuses.
     *
     * @return RecursiveIterator<mixed, mixed>
     */
    public function callGetChildren(): RecursiveIterator
    {
        try {
            return parent::callGetChildren() ?? new RecursiveArrayIterator([]);
        } catch (Exception $failure) {
            ($this->onRefusedBranch)($this->refusedPathname(), $failure->getMessage());

            return new RecursiveArrayIterator([]);
        }
    }

    private function refusedPathname(): string
    {
        $current = $this->current();

        return $current instanceof SplFileInfo ? $current->getPathname() : (string) $this->key();
    }
}
