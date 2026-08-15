<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Pins the invariant {@see AbsolutePath} documents as "construction is lexical:
 * `..` segments are resolved without touching the filesystem" — the reason
 * `canonicalize()` exists as a separate, explicitly I/O-bound operation, and the
 * reason ADR 0015 §R3's per-construction budget is reachable at all.
 *
 * This counts an operation rather than timing one: PHP's realpath cache gains
 * an entry per resolved segment the moment anything resolves a path through the
 * filesystem, so a construction that stays lexical leaves the entry count
 * untouched on any machine, under any load. Verified against the regression by
 * calling `realpath()` inside `fromString()`: the count moved from 0 to 10.
 *
 * Blind spot worth knowing: `file_exists()`, `is_file()` and `is_dir()` populate
 * the stat cache, not the realpath cache, and PHP exposes no way to count that
 * one — measured as 0 with `file_exists()` injected into `fromString()`. This
 * catches path *resolution*, not every possible syscall.
 */
#[CoversClass(AbsolutePath::class)]
#[CoversClass(RelativePath::class)]
final class PathLexicalConstructionTest extends TestCase
{
    #[Test]
    public function itConstructsAnAbsolutePathWithoutResolvingItThroughTheFilesystem(): void
    {
        // An existing file with a "." segment: normalization has work to do, and
        // anything reaching for the filesystem would find something to resolve.
        // A non-existent path would make realpath() bail early and blind the check.
        $existing = __DIR__ . '/./' . basename(__FILE__);

        self::assertFileExists($existing);

        $delta = self::realpathCacheGrowthDuring(static function () use ($existing): void {
            AbsolutePath::fromString($existing);
        });

        self::assertSame(0, $delta, \sprintf(
            'AbsolutePath::fromString() resolved %d path segment(s) through the filesystem; '
            . 'construction must stay lexical — canonicalize() is where I/O belongs',
            $delta,
        ));
    }

    #[Test]
    public function itConstructsARelativePathWithoutResolvingItThroughTheFilesystem(): void
    {
        // Relative to the working directory PHPUnit runs in, i.e. the project root.
        $existing = 'tests/Core/Path/Unit/./' . basename(__FILE__);

        self::assertFileExists($existing);

        $delta = self::realpathCacheGrowthDuring(static function () use ($existing): void {
            RelativePath::fromString($existing);
        });

        self::assertSame(0, $delta, \sprintf(
            'RelativePath::fromString() resolved %d path segment(s) through the filesystem; '
            . 'construction must stay lexical',
            $delta,
        ));
    }

    /**
     * Number of realpath cache entries the callable adds.
     *
     * The callable runs once before measuring: the first call loads the class,
     * and class loading itself resolves files, which would otherwise be counted
     * as the subject's own I/O.
     */
    private static function realpathCacheGrowthDuring(callable $subject): int
    {
        $subject();

        clearstatcache(true);
        $before = \count(realpath_cache_get());

        $subject();

        return \count(realpath_cache_get()) - $before;
    }
}
