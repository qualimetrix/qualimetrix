<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\ExcludeBinding;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeBindingProbe;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeOptions;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A directory the walk cannot list stops the probe from judging a selector
 * that could have matched inside it. Not calling that selector stale is right;
 * answering it exactly as a selector that bound is what left the reader with
 * no way to tell "checked" from "could not check".
 */
#[CoversClass(ExcludeBindingProbe::class)]
#[CoversClass(UnmatchedExcludeAudit::class)]
final class UnjudgedExcludeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (posix_getuid() === 0) {
            self::markTestSkipped('Root ignores directory permission bits.');
        }

        $this->root = sys_get_temp_dir() . '/qmx-unjudged-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/blocked/Inner', 0o755, true);
        mkdir($this->root . '/Kept', 0o755, true);
        file_put_contents($this->root . '/Kept/File.php', "<?php\n");
        chmod($this->root . '/blocked', 0o000);
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return;
        }

        chmod($this->root . '/blocked', 0o755);
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
    public function itNamesTheSelectorItCouldNotJudgeAndTheDirectoryThatStoppedIt(): void
    {
        $verdict = (new ExcludeBindingProbe())->judge(
            [AbsolutePath::fromString($this->root)],
            [$this->pattern(SelectorKind::Exact, 'blocked/Inner')],
            $this->pruner([]),
        );

        self::assertSame([], $verdict->unbound);
        self::assertSame(['exact:blocked/Inner'], array_keys($verdict->unlistable));
        self::assertSame($this->root . '/blocked', $verdict->unlistable['exact:blocked/Inner']->value());
    }

    /**
     * A selector another exclude deliberately hid is unjudgeable too, and must
     * not be reported: the author asked for that subtree to go.
     */
    #[Test]
    public function itSaysNothingAboutASelectorHiddenByAnotherExclude(): void
    {
        $verdict = (new ExcludeBindingProbe())->judge(
            [AbsolutePath::fromString($this->root)],
            [$this->pattern(SelectorKind::Exact, 'Kept/Gone')],
            $this->pruner([$this->pattern(SelectorKind::Subtree, 'Kept')]),
        );

        self::assertSame([], $verdict->unbound);
        self::assertSame([], $verdict->unlistable);
    }

    /** A selector that bound is settled, whatever else the walk could not see. */
    #[Test]
    public function itSaysNothingAboutASelectorThatBound(): void
    {
        $verdict = (new ExcludeBindingProbe())->judge(
            [AbsolutePath::fromString($this->root)],
            [$this->pattern(SelectorKind::Regex, '(?:[^/]+/)*Kept')],
            $this->pruner([]),
        );

        self::assertSame([], $verdict->unbound);
        self::assertSame([], $verdict->unlistable);
    }

    #[Test]
    public function itReportsTheUnjudgedSelectorAsItsOwnFinding(): void
    {
        $findings = $this->findings($this->pattern(SelectorKind::Exact, 'blocked/Inner'));

        self::assertCount(1, $findings);
        self::assertStringContainsString('could not be checked', $findings[0]->message);
        self::assertStringContainsString('exact:blocked/Inner', $findings[0]->message);
        self::assertStringContainsString('blocked', $findings[0]->message);
    }

    /**
     * Two verdicts about one pattern are two facts, so accepting one must not
     * accept the other.
     */
    #[Test]
    public function itGivesTheUnjudgedVerdictAnIdentityOfItsOwn(): void
    {
        $unjudged = $this->findings($this->pattern(SelectorKind::Exact, 'blocked/Inner'));
        $stale = $this->findings($this->pattern(SelectorKind::Exact, 'NoSuchDir'));

        self::assertCount(1, $unjudged);
        self::assertCount(1, $stale);
        self::assertNotSame(
            $unjudged[0]->occurrenceKey?->value,
            $stale[0]->occurrenceKey?->value,
        );
    }

    /** @return list<Finding> */
    private function findings(PathPattern $authored): array
    {
        $root = AbsolutePath::fromString($this->root);

        return (new UnmatchedExcludeAudit(new UnmatchedExcludeOptions(), new ExcludeBindingProbe()))->findings(
            new RunConfiguration(
                paths: [$root],
                pathExcludes: [$authored],
                projectRoot: $root,
                generatedFilePolicy: GeneratedFilePolicy::Include,
                coversProjectScope: true,
                authoredPathExcludes: [$authored],
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
