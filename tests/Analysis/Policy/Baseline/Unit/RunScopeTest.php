<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * ADR 0017 scope guard and the portable form it compares: a run narrower than
 * the baseline's recorded scope is refused, a wider or equal one is allowed,
 * and the widest run of all — the project root — is recorded as `.` rather
 * than as the machine path it happens to sit at.
 */
#[CoversClass(RunScope::class)]
final class RunScopeTest extends TestCase
{
    #[Test]
    public function itRefusesARunNarrowerThanTheRecordedScope(): void
    {
        $run = RunScope::fromRecorded(['src/Foo']);

        self::assertFalse($run->covers(['src']));
        self::assertSame(['src'], $run->uncoveredPaths(['src']));
    }

    #[Test]
    public function itAllowsARunWiderThanTheRecordedScope(): void
    {
        $run = RunScope::fromRecorded(['src']);

        self::assertTrue($run->covers(['src/Foo']));
        self::assertSame([], $run->uncoveredPaths(['src/Foo']));
    }

    #[Test]
    public function itAllowsAnEqualScope(): void
    {
        $run = RunScope::fromRecorded(['src', 'tests']);

        self::assertTrue($run->covers(['src', 'tests']));
        self::assertSame([], $run->uncoveredPaths(['src', 'tests']));
    }

    /**
     * `src` must not be read as covering `srcfoo` — a bare string prefix is
     * not a path-segment ancestor.
     */
    #[Test]
    public function itDoesNotTreatAStringPrefixAsCoverage(): void
    {
        $run = RunScope::fromRecorded(['src']);

        self::assertFalse($run->covers(['srcfoo']));
        self::assertSame(['srcfoo'], $run->uncoveredPaths(['srcfoo']));
    }

    /**
     * The mirror of the prefix case: a child does not stand in for its
     * parent, so `src/Foo` does not cover `src`.
     */
    #[Test]
    public function itDoesNotTreatAChildPathAsCoveringItsParent(): void
    {
        self::assertFalse(RunScope::fromRecorded(['src/Foo'])->covers(['src']));
    }

    #[Test]
    public function itChecksEveryRecordedPathIndependently(): void
    {
        self::assertSame(['tests'], RunScope::fromRecorded(['src'])->uncoveredPaths(['src/Foo', 'tests']));
    }

    #[Test]
    public function itTreatsTheFilesystemRootAsCoveringEveryPath(): void
    {
        self::assertTrue(RunScope::fromRecorded(['/'])->covers(['src', 'tests/Foo']));
    }

    /**
     * **The case the guard used to get backwards.** A run over the project
     * root is the widest run there is; a segment comparison against an
     * absolute machine path read it as covering nothing at all.
     */
    #[Test]
    public function itTreatsTheProjectRootAsCoveringEveryProjectRelativePath(): void
    {
        $run = RunScope::fromRecorded(['.']);

        self::assertTrue($run->covers(['src', 'tests/Foo']));
        self::assertSame([], $run->uncoveredPaths(['src']));
    }

    /**
     * The other direction stays refused: a run over `src` is narrower than a
     * file recorded over the whole project.
     */
    #[Test]
    public function itDoesNotTreatASubdirectoryAsCoveringTheProjectRoot(): void
    {
        self::assertFalse(RunScope::fromRecorded(['src'])->covers(['.']));
        self::assertSame(['.'], RunScope::fromRecorded(['src'])->uncoveredPaths(['.']));
    }

    /**
     * `.` is the root of the *project* tree, not of the filesystem: a
     * recorded path outside the project has no reason to be considered
     * covered by it.
     */
    #[Test]
    public function itDoesNotTreatTheProjectRootAsCoveringAnAbsolutePath(): void
    {
        self::assertFalse(RunScope::fromRecorded(['.'])->covers(['/elsewhere/src']));
    }

    /**
     * **The absolute machine path that must never reach a tracked file.**
     * `bin/qmx baseline:generate baseline.json .` analyses the project root,
     * which has no relative form — it used to be recorded verbatim, putting
     * a developer's home directory into a committed baseline (CLAUDE.md §10)
     * and making the widest possible run read as the narrowest.
     */
    #[Test]
    public function itRecordsAPathEqualToTheProjectRootAsTheProjectRoot(): void
    {
        $projectRoot = AbsolutePath::fromString('/Users/dev/projects/app');

        $scope = RunScope::record([$projectRoot], $projectRoot);

        self::assertSame(['.'], $scope->paths());
    }

    #[Test]
    public function itRecordsAPathUnderTheProjectRootRelatively(): void
    {
        $projectRoot = AbsolutePath::fromString('/Users/dev/projects/app');

        $scope = RunScope::record(
            [AbsolutePath::fromString('/Users/dev/projects/app/src')],
            $projectRoot,
        );

        self::assertSame(['src'], $scope->paths());
    }

    /**
     * A path genuinely outside the project has no relative form, and
     * inventing one would misstate what was measured. This is the one case
     * an absolute path still reaches the file, and the user created it by
     * naming a path outside the project.
     */
    #[Test]
    public function itKeepsAPathOutsideTheProjectRootAsGiven(): void
    {
        $scope = RunScope::record(
            [AbsolutePath::fromString('/elsewhere/vendor')],
            AbsolutePath::fromString('/Users/dev/projects/app'),
        );

        self::assertSame(['/elsewhere/vendor'], $scope->paths());
    }

    /**
     * The recorded form is normalised, so two runs naming the same paths in
     * a different order or with a trailing separator record one scope.
     */
    #[Test]
    public function itNormalisesWhatItRecords(): void
    {
        $projectRoot = AbsolutePath::fromString('/Users/dev/projects/app');

        $scope = RunScope::record(
            [
                AbsolutePath::fromString('/Users/dev/projects/app/tests'),
                AbsolutePath::fromString('/Users/dev/projects/app/src'),
                AbsolutePath::fromString('/Users/dev/projects/app/src'),
            ],
            $projectRoot,
        );

        self::assertSame(['src', 'tests'], $scope->paths());
    }

    /**
     * The two halves of the guard read one form: what a run over the project
     * root records is exactly what covers a file recorded over `src`.
     */
    #[Test]
    public function itComparesWhatItRecordsWithoutAnySecondNormalisation(): void
    {
        $projectRoot = AbsolutePath::fromString('/Users/dev/projects/app');

        $scope = RunScope::record([$projectRoot], $projectRoot);

        self::assertTrue($scope->covers(['src']), 'a run over the project root covers a file recorded over src');
    }
}
