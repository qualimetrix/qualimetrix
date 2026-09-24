<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\ExcludeBinding;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeBindingProbe;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ExcludeBindingProbe::class)]
final class ExcludeBindingProbeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-exclude-probe-' . bin2hex(random_bytes(6));

        foreach (['Kept', 'Legacy/Deep', 'nested/Legacy/Inner', 'vendor/acme', '7/Seven'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o755, true);
            file_put_contents($this->root . '/' . $directory . '/File.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function itUsesProjectRelativeSelectorSemantics(): void
    {
        self::assertSame([], $this->unbound([
            $this->pattern(SelectorKind::Exact, 'Legacy/Deep'),
            $this->pattern(SelectorKind::Subtree, 'nested/Legacy'),
            $this->pattern(SelectorKind::Regex, '(?:[^/]+/)*Inner'),
        ]));
    }

    #[Test]
    public function itDoesNotRestoreFindersImplicitBasenameAtAnyDepthRule(): void
    {
        self::assertSame(
            ['exact:Deep'],
            $this->unbound([$this->pattern(SelectorKind::Exact, 'Deep')]),
        );
    }

    #[Test]
    public function itReportsEachDistinctUnmatchedAuthoredSelectorOnce(): void
    {
        $missing = $this->pattern(SelectorKind::Exact, 'Missing');

        self::assertSame(
            ['exact:Missing', 'subtree:AlsoMissing'],
            $this->unbound([$missing, $missing, $this->pattern(SelectorKind::Subtree, 'AlsoMissing')]),
        );
    }

    #[Test]
    public function itBindsASelectorToTheDirectoryThatPrunesIt(): void
    {
        $vendor = $this->pattern(SelectorKind::Subtree, 'vendor');

        self::assertSame([], $this->unbound([$vendor], [$vendor]));
    }

    #[Test]
    public function itKeepsALiteralSelectorBelowAPrunedParentUnjudgeable(): void
    {
        self::assertSame([], $this->unbound(
            [$this->pattern(SelectorKind::Exact, 'vendor/acme')],
            [$this->pattern(SelectorKind::Subtree, 'vendor')],
        ));
    }

    #[Test]
    public function itKeepsARegexUnjudgeableWhenAPrunedSubtreeCouldContainAMatch(): void
    {
        self::assertSame([], $this->unbound(
            [$this->pattern(SelectorKind::Regex, 'missing/.+')],
            [$this->pattern(SelectorKind::Subtree, 'vendor')],
        ));
    }

    #[Test]
    public function itStillReportsAnUnmatchedLiteralOutsideThePrunedParent(): void
    {
        self::assertSame(
            ['exact:missing'],
            $this->unbound(
                [$this->pattern(SelectorKind::Exact, 'missing')],
                [$this->pattern(SelectorKind::Subtree, 'vendor')],
            ),
        );
    }

    #[Test]
    public function itStaysSilentWhenNoRootIsADirectory(): void
    {
        $file = AbsolutePath::fromString($this->root . '/Kept/File.php');
        $pattern = $this->pattern(SelectorKind::Exact, 'Missing');

        self::assertSame([], (new ExcludeBindingProbe())->unboundPatterns(
            [$file],
            [$pattern],
            $this->pruner([]),
        ));
    }

    #[Test]
    public function itDoesNotDuplicateAnAnswerAcrossOverlappingRoots(): void
    {
        $pattern = $this->pattern(SelectorKind::Exact, 'Missing');

        self::assertSame(['exact:Missing'], $this->unbound(
            [$pattern, $pattern],
            [],
            [
                AbsolutePath::fromString($this->root),
                AbsolutePath::fromString($this->root . '/Legacy'),
                AbsolutePath::fromString($this->root),
            ],
        ));
    }

    /**
     * A subtree this process may not list hides what it holds exactly as a
     * pruned one does, and the probe answers about it the same way: the
     * selector is left unjudged rather than called unbound.
     *
     * Before the guard the walk did not answer at all — `getChildren()` on the
     * unlistable directory threw an `UnexpectedValueException` out of the
     * probe, and the run wore an unreadable subdirectory as an internal error.
     */
    #[Test]
    public function itKeepsASelectorBelowAnUnlistableSubtreeUnjudgeable(): void
    {
        $this->withUnlistable('blocked', function (): void {
            self::assertSame([], $this->unbound([$this->pattern(SelectorKind::Exact, 'blocked/Inner')]));
        });
    }

    /** The guard must not turn every selector into an unanswered one. */
    #[Test]
    public function itStillReportsAnUnmatchedSelectorOutsideTheUnlistableSubtree(): void
    {
        $this->withUnlistable('blocked', function (): void {
            self::assertSame(
                ['exact:Missing'],
                $this->unbound([$this->pattern(SelectorKind::Exact, 'Missing')]),
            );
        });
    }

    /** The root itself, where there is no parent walk to catch the refusal. */
    #[Test]
    public function itAnswersWhenTheScannedRootItselfIsUnlistable(): void
    {
        $this->withUnlistable('blocked', function (): void {
            self::assertSame([], $this->unbound(
                [$this->pattern(SelectorKind::Exact, 'blocked/Inner')],
                [],
                [AbsolutePath::fromString($this->root . '/blocked')],
            ));
        });
    }

    /**
     * Runs $body with `$relative` present but unlistable, and restores the mode
     * whatever happens — tearDown walks the same tree.
     */
    private function withUnlistable(string $relative, callable $body): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits.');
        }

        $path = $this->root . '/' . $relative;
        mkdir($path . '/Inner', 0o755, true);
        chmod($path, 0o000);

        try {
            $body();
        } finally {
            chmod($path, 0o755);
        }
    }

    /**
     * @param list<PathPattern> $authored
     * @param list<PathPattern> $pruned
     * @param list<AbsolutePath>|null $roots
     *
     * @return list<string>
     */
    private function unbound(array $authored, array $pruned = [], ?array $roots = null): array
    {
        return array_map(
            static fn(PathPattern $pattern): string => $pattern->definition->display(),
            (new ExcludeBindingProbe())->unboundPatterns(
                $roots ?? [AbsolutePath::fromString($this->root)],
                $authored,
                $this->pruner($pruned),
            ),
        );
    }

    /** @param list<PathPattern> $patterns */
    private function pruner(array $patterns): DirectoryPruner
    {
        return new DirectoryPruner(AbsolutePath::fromString($this->root), $patterns);
    }

    private function pattern(SelectorKind $kind, string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition($kind, $value));
    }
}
