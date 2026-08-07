<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\ScopeCoverage;

/**
 * §5.7's scope guard: a run narrower than the baseline's recorded scope is
 * refused, a wider or equal one is allowed.
 */
#[CoversClass(ScopeCoverage::class)]
final class ScopeCoverageTest extends TestCase
{
    #[Test]
    public function itRefusesARunNarrowerThanTheRecordedScope(): void
    {
        self::assertFalse(ScopeCoverage::covers(['src/Foo'], ['src']));
        self::assertSame(['src'], ScopeCoverage::uncoveredPaths(['src/Foo'], ['src']));
    }

    #[Test]
    public function itAllowsARunWiderThanTheRecordedScope(): void
    {
        self::assertTrue(ScopeCoverage::covers(['src'], ['src/Foo']));
        self::assertSame([], ScopeCoverage::uncoveredPaths(['src'], ['src/Foo']));
    }

    #[Test]
    public function itAllowsAnEqualScope(): void
    {
        self::assertTrue(ScopeCoverage::covers(['src', 'tests'], ['src', 'tests']));
        self::assertSame([], ScopeCoverage::uncoveredPaths(['src', 'tests'], ['src', 'tests']));
    }

    /**
     * `src` must not be read as covering `srcfoo` — a bare string prefix is
     * not a path-segment ancestor.
     */
    #[Test]
    public function itDoesNotTreatAStringPrefixAsCoverage(): void
    {
        self::assertFalse(ScopeCoverage::covers(['src'], ['srcfoo']));
        self::assertSame(['srcfoo'], ScopeCoverage::uncoveredPaths(['src'], ['srcfoo']));
    }

    /**
     * The mirror of the prefix case: a child does not stand in for its
     * parent, so `src/Foo` does not cover `src`.
     */
    #[Test]
    public function itDoesNotTreatAChildPathAsCoveringItsParent(): void
    {
        self::assertFalse(ScopeCoverage::covers(['src/Foo'], ['src']));
    }

    #[Test]
    public function itChecksEveryRecordedPathIndependently(): void
    {
        self::assertSame(
            ['tests'],
            ScopeCoverage::uncoveredPaths(['src'], ['src/Foo', 'tests']),
        );
    }

    #[Test]
    public function itTreatsTheFilesystemRootAsCoveringEveryPath(): void
    {
        self::assertTrue(ScopeCoverage::covers(['/'], ['src', 'tests/Foo']));
    }
}
