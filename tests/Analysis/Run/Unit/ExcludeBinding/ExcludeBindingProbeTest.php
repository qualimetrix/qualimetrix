<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\ExcludeBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeBindingProbe;
use Qualimetrix\Core\Path\AbsolutePath;
use Symfony\Component\Finder\Finder;

/**
 * The probe against the thing it models.
 *
 * Each case asserts the probe's answer **and** cross-checks it with a real
 * `Finder` over the same tree, because the value of this class is entirely in
 * agreeing with Symfony: a probe that is self-consistently wrong would report
 * a working exclusion as the author's mistake, which is worse than the silence
 * it replaces.
 */
#[CoversClass(ExcludeBindingProbe::class)]
final class ExcludeBindingProbeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-exclude-probe-' . bin2hex(random_bytes(6));

        foreach (['/Kept', '/Legacy/Deep', '/nested/Legacy', '/vendor/acme'] as $dir) {
            mkdir($this->root . $dir, 0o755, true);
            file_put_contents($this->root . $dir . '/File.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        foreach (['/Kept', '/Legacy/Deep', '/nested/Legacy', '/vendor/acme'] as $dir) {
            @unlink($this->root . $dir . '/File.php');
        }

        foreach (['/Kept', '/Legacy/Deep', '/Legacy', '/nested/Legacy', '/nested', '/vendor/acme', '/vendor', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    #[Test]
    public function itBindsABareNameToADirectoryAtAnyDepth(): void
    {
        self::assertSame([], $this->unbound(['Deep']));
        self::assertSame(1, $this->filesRemovedByFinder('Deep'));
    }

    /**
     * The form the plan described as "relative to the search root": measured,
     * Symfony anchors it to any path-segment boundary instead, so one and the
     * same pattern matches `nested/Legacy` as well as `Legacy`.
     */
    #[Test]
    public function itBindsASlashedPatternAtAnySegmentBoundaryRatherThanOnlyAtTheRoot(): void
    {
        self::assertSame([], $this->unbound(['nested/Legacy']));
        self::assertSame(1, $this->filesRemovedByFinder('nested/Legacy'));

        // The boundary claim itself: a pattern anchored to the root would not
        // have matched this one, and Finder does.
        self::assertSame([], $this->unbound(['Legacy/Deep']));
        self::assertSame(1, $this->filesRemovedByFinder('Legacy/Deep'));
    }

    #[Test]
    public function itReportsAPatternNoDirectoryMatches(): void
    {
        self::assertSame(['NoSuchDir'], $this->unbound(['NoSuchDir']));
        self::assertSame(0, $this->filesRemovedByFinder('NoSuchDir'));
    }

    #[Test]
    public function itKeepsEachPatternsAnswerSeparate(): void
    {
        self::assertSame(['NoSuchDir', 'AlsoMissing'], $this->unbound(['NoSuchDir', 'Kept', 'AlsoMissing']));
    }

    /** Finder trims a trailing slash before matching, so the probe must too. */
    #[Test]
    public function itIgnoresATrailingSlashTheWayFinderDoes(): void
    {
        self::assertSame([], $this->unbound(['Kept/']));
        self::assertSame(1, $this->filesRemovedByFinder('Kept/'));
    }

    /**
     * A directory the walk is told not to enter still binds a pattern naming
     * it: the author excluded `vendor` explicitly, and it is there.
     */
    #[Test]
    public function itBindsAPatternNamingAPrunedDirectoryItself(): void
    {
        self::assertSame([], $this->unbound(['vendor'], pruned: ['vendor']));
    }

    /**
     * The named cost of pruning: a pattern pointing *inside* a built-in
     * exclusion reads as unbound, because discovery never looks there either.
     */
    #[Test]
    public function itReportsAPatternPointingInsideAPrunedDirectory(): void
    {
        self::assertSame(['acme'], $this->unbound(['acme'], pruned: ['vendor']));
    }

    /** A file among the roots contributes no directory, and no root at all means no question. */
    #[Test]
    public function itStaysSilentWhenNoRootIsADirectory(): void
    {
        $file = AbsolutePath::fromString($this->root . '/Kept/File.php');

        self::assertSame([], (new ExcludeBindingProbe())->unboundPatterns([$file], ['NoSuchDir'], []));
    }

    /** Overlapping and repeated roots are one boolean answer, not several. */
    #[Test]
    public function itDoesNotDuplicateAnAnswerAcrossOverlappingRoots(): void
    {
        $probe = new ExcludeBindingProbe();
        $roots = [
            AbsolutePath::fromString($this->root),
            AbsolutePath::fromString($this->root . '/Legacy'),
            AbsolutePath::fromString($this->root),
        ];

        self::assertSame(['NoSuchDir'], $probe->unboundPatterns($roots, ['NoSuchDir', 'NoSuchDir'], []));
    }

    /**
     * @param list<string> $authored
     * @param list<string> $pruned
     *
     * @return list<string>
     */
    private function unbound(array $authored, array $pruned = []): array
    {
        return (new ExcludeBindingProbe())
            ->unboundPatterns([AbsolutePath::fromString($this->root)], $authored, $pruned);
    }

    /** How many PHP files a real Finder drops when given this one exclusion. */
    private function filesRemovedByFinder(string $pattern): int
    {
        return $this->finderCount([]) - $this->finderCount([$pattern]);
    }

    /** @param list<string> $excluded */
    private function finderCount(array $excluded): int
    {
        return iterator_count(
            (new Finder())->files()->name('*.php')->in($this->root)->exclude($excluded)->getIterator(),
        );
    }
}
