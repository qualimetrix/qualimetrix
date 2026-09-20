<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__) . '/ChildProcess.php';

/**
 * The three prefixes are the whole of how a caller tells one failure from
 * another: `run()` raises one exception class, and its docblock names
 * `str_starts_with($error->getMessage(), ChildProcess::START_FAILURE_PREFIX)`
 * as the way to dispatch on which failure happened. Callers across the tree
 * now report a failure without claiming which of the three it was, so the
 * prefix is the only thing left that says so.
 *
 * ## What this holds, and what it deliberately does not
 *
 * It holds the properties that dispatch depends on and nothing else: each
 * prefix is non-empty, they are pairwise distinct, and none is a prefix of
 * another. The last is the one that is easy to lose and impossible to see by
 * reading: if `READ_FAILURE_PREFIX` ever became `'Cannot '`, a
 * `str_starts_with` test for it would also match a start failure, and the
 * caller would be told the opposite of what happened.
 *
 * It does **not** hold that each `throw` inside the module reaches for the
 * right constant. Swapping two of them at the throw sites keeps every
 * property here true. Catching that needs the failures themselves, and this
 * is where the cost lands:
 *
 * - The start failure is raised when the child cannot be brought into
 *   existence, which in practice means an unusable working directory. Whether
 *   that surfaces in the parent at all depends on how the PHP build spawns:
 *   where the directory change happens after a fork, it is the *child* that
 *   fails, and the parent sees a started process. That differs between builds
 *   and between platforms, and this branch has already paid for assuming
 *   otherwise — `10978986` was green on the machine it was written on and red
 *   on both CI runners, for a platform difference of exactly this kind.
 * - The read failure needs `stream_select()` to fail or a pipe to become
 *   unreadable while the child is alive. Nothing portable produces either.
 * - The write failure needs a stdin descriptor that dies for a reason other
 *   than the far end closing, since that one is a normal path the module
 *   handles without raising.
 *
 * So the rest of the contract stays unheld rather than held by a test that
 * passes for the wrong reason on one platform. That is a gap, named here so
 * the next reader does not have to rediscover why it is a gap.
 */
final class ChildProcessFailurePrefixTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function providePrefixes(): array
    {
        return [
            ['START_FAILURE_PREFIX', ChildProcess::START_FAILURE_PREFIX],
            ['READ_FAILURE_PREFIX', ChildProcess::READ_FAILURE_PREFIX],
            ['WRITE_FAILURE_PREFIX', ChildProcess::WRITE_FAILURE_PREFIX],
        ];
    }

    #[Test]
    public function itGivesEveryFailureANonEmptyPrefix(): void
    {
        foreach (self::providePrefixes() as [$name, $prefix]) {
            self::assertNotSame('', $prefix, $name . ' must carry text; an empty prefix matches every message');
        }
    }

    /**
     * Pairwise distinctness and the prefix-of-another property are asserted
     * together because they fail together in the case worth catching: two
     * constants converging on one wording. Comparing every ordered pair also
     * covers the asymmetric half — `A` being a prefix of `B` says nothing
     * about `B` and `A`.
     */
    #[Test]
    public function itKeepsThePrefixesTellableApart(): void
    {
        $prefixes = self::providePrefixes();

        foreach ($prefixes as [$name, $prefix]) {
            foreach ($prefixes as [$otherName, $otherPrefix]) {
                if ($name === $otherName) {
                    continue;
                }

                self::assertFalse(
                    str_starts_with($otherPrefix, $prefix),
                    $name . ' is a prefix of ' . $otherName . ', so a caller dispatching on ' . $name
                    . ' would also accept a ' . $otherName . ' failure and report the wrong cause',
                );
            }
        }
    }
}
