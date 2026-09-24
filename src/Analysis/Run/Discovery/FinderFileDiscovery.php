<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use FilesystemIterator;
use Generator;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkipReportingDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Two decisions the traversal keeps apart: what may be a unit of analysis, and
 * where the walk may descend. They used to be one callback, which is how a
 * symlink to a directory became a leaf — accepted as a file, never descended
 * into — and then an analyzed file with no code in it.
 *
 * Whatever is neither is recorded rather than dropped: a subtree that is not
 * read looks exactly like a subtree with no code in it, and the difference is
 * the reader's to make.
 */
final class FinderFileDiscovery implements FileDiscoveryInterface, SkipReportingDiscoveryInterface
{
    private readonly DirectoryPruner $directoryPruner;

    /**
     * Keyed by path: overlapping roots (`src/ src/sub/`) walk the same entry
     * more than once, and two records for one path are two terminal states for
     * one path — which the coverage invariant refuses by throwing.
     *
     * @var array<string, SkippedEntry>
     */
    private array $skippedEntries = [];

    public function __construct(?DirectoryPruner $directoryPruner = null)
    {
        $workingDirectory = getcwd();
        $root = AbsolutePath::fromString($workingDirectory !== false ? $workingDirectory : '/');
        $this->directoryPruner = $directoryPruner
            ?? new DirectoryPruner($root, DirectoryPruner::builtInPatterns());
    }

    public function discover(AbsolutePath|array $paths): iterable
    {
        $this->skippedEntries = [];

        $paths = $paths instanceof AbsolutePath ? [$paths] : $paths;

        if ($paths === []) {
            return;
        }

        [$directories, $files] = $this->sortInputs($paths);

        usort($files, static fn(AbsolutePath $a, AbsolutePath $b): int => $a->value() <=> $b->value());

        // Tracks emitted file pathnames so overlapping inputs (e.g. `src/ src/sub/`,
        // or a single-file arg that also lives inside a directory arg) yield each
        // file exactly once. Pre-ADR-0015 this was handled implicitly by
        // iterator_to_array(..., true) collapsing duplicate string keys; with
        // AbsolutePath as the iterator key, dedup is now explicit at the source.
        $seen = [];

        foreach ($files as $file) {
            $key = $file->value();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $file => new SplFileInfo($key);
        }

        if ($directories !== []) {
            yield from $this->discoverInDirectories($directories, $seen);
        }
    }

    /** @return list<SkippedEntry> */
    public function skippedEntries(): array
    {
        return array_values($this->skippedEntries);
    }

    /**
     * What the caller named, sorted into the two things a named path can be.
     * A path that is neither — gone, or present as something no analysis can
     * read — leaves the run either silently, because nothing was ever promised
     * about a path that does not exist, or as a recorded skip.
     *
     * A named directory the built-in floor removes is refused instead, before
     * anything is yielded: the walk would never enter it, and a run over it
     * reported success over zero files.
     *
     * @param list<AbsolutePath> $paths
     *
     * @throws ConfigurationRefusal when a named directory is one the walk never enters
     *
     * @return array{list<AbsolutePath>, list<AbsolutePath>} Directories to walk, then files to yield
     */
    private function sortInputs(array $paths): array
    {
        $directories = [];
        $files = [];
        $neverWalked = [];

        foreach ($paths as $path) {
            if (!$path->exists()) {
                continue;
            }

            if ($path->isDirectory()) {
                $excluded = $this->directoryPruner->builtInExclusion($path);
                if ($excluded !== null) {
                    $neverWalked[$excluded] = true;
                }
                $directories[] = $path;

                continue;
            }

            if ($this->acceptRegularPhpFile(new SplFileInfo($path->value()), 'Explicit path is not a regular file')) {
                $files[] = $path;
            }
        }

        if ($neverWalked !== []) {
            throw self::neverWalkedRefusal(array_keys($neverWalked));
        }

        return [$directories, $files];
    }

    /** @param non-empty-list<string> $directories relative to the project root */
    private static function neverWalkedRefusal(array $directories): ConfigurationRefusal
    {
        $one = \count($directories) === 1;

        return ConfigurationRefusal::aboutResolvedInput(
            \sprintf(
                '%s %s, which analysis never enters, so this run would analyse nothing there.'
                . ' Name a file or a directory inside %s to analyse that code.',
                implode(', ', array_map(static fn(string $directory): string => '"' . $directory . '"', $directories)),
                $one ? 'is a vendor, node_modules or .git directory' : 'are vendor, node_modules or .git directories',
                $one ? 'it' : 'them',
            ),
            ConfigSchema::PATHS,
        );
    }

    /**
     * @param list<AbsolutePath> $directories
     * @param array<string, true> $seen Pathnames already yielded (mutated by reference).
     *
     * @return Generator<AbsolutePath, SplFileInfo>
     */
    private function discoverInDirectories(array $directories, array &$seen): Generator
    {
        $files = [];
        foreach ($directories as $directory) {
            if ($this->directoryPruner->match($directory) !== null) {
                continue;
            }

            foreach ($this->walk($directory) as $file) {
                $files[$file->getPathname()] = $file;
            }
        }
        ksort($files);

        foreach ($files as $file) {
            $pathname = $file->getPathname();
            if (isset($seen[$pathname])) {
                continue;
            }
            $seen[$pathname] = true;
            yield AbsolutePath::fromString($pathname) => $file;
        }
    }

    /**
     * One subtree, walked so that a branch it cannot enter costs that branch
     * and not the run. The recorded entry is what makes the loss visible, and
     * it is recorded for both shapes of refusal: the root that will not open
     * at all, and the branch that stops opening between the check in
     * {@see acceptDirectory()} and the descent {@see DirectoryWalk} performs.
     *
     * @return Generator<int, SplFileInfo>
     */
    private function walk(AbsolutePath $directory): Generator
    {
        try {
            $iterator = new DirectoryWalk(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $directory->value(),
                        FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
                            | FilesystemIterator::CURRENT_AS_FILEINFO,
                    ),
                    $this->accept(...),
                ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                $this->recordRefusedBranch(...),
            );
        } catch (UnexpectedValueException $e) {
            $this->record($directory, AnalysisFailureKind::UnreadableDirectory, $e->getMessage());

            return;
        }

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                yield $file;
            }
        }
    }

    /** A branch that passed the readability check and refused the descent anyway. */
    private function recordRefusedBranch(string $pathname, string $message): void
    {
        $this->record(
            AbsolutePath::fromString($pathname),
            AnalysisFailureKind::UnreadableDirectory,
            $message,
        );
    }

    /**
     * Accepts an entry as a unit of analysis, and — for a directory — as
     * somewhere to descend. A directory is never a unit of analysis, so the
     * two answers are never the same answer.
     */
    private function accept(SplFileInfo $entry): bool
    {
        if ($entry->isDir()) {
            return $this->acceptDirectory($entry);
        }

        return $this->acceptRegularPhpFile($entry, 'Discovered path is not a regular file');
    }

    /**
     * A `*.php` entry that is not a regular file: FIFO, socket, device, or a
     * link whose target is gone. Dropping it silently is what made the run's
     * file set smaller than its input with nothing said about it — which is
     * why the caller supplies how the entry was reached, not just that it was.
     */
    private function acceptRegularPhpFile(SplFileInfo $entry, string $detail): bool
    {
        if ($entry->getExtension() !== 'php') {
            return false;
        }

        if ($entry->isFile()) {
            return true;
        }

        $this->record(
            AbsolutePath::fromString($entry->getPathname()),
            AnalysisFailureKind::NotRegularFile,
            $detail,
        );

        return false;
    }

    private function acceptDirectory(SplFileInfo $entry): bool
    {
        $path = AbsolutePath::fromString($entry->getPathname());

        // An excluded subtree is excluded on purpose; nothing about it is lost.
        if ($this->directoryPruner->match($path) !== null) {
            return false;
        }

        if ($entry->isLink()) {
            // Not descended into, deliberately: following it would change which
            // files a run measures and could leave the project root entirely,
            // while a cycle would not terminate. What is said instead is that
            // this subtree was not read.
            $this->record(
                $path,
                AnalysisFailureKind::DirectorySymlink,
                'Symbolic link to a directory is not traversed',
            );

            return false;
        }

        if (!is_readable($entry->getPathname()) || !is_executable($entry->getPathname())) {
            $this->record(
                $path,
                AnalysisFailureKind::UnreadableDirectory,
                'Directory cannot be listed',
            );

            return false;
        }

        return true;
    }

    private function record(AbsolutePath $path, AnalysisFailureKind $reason, string $detail): void
    {
        $this->skippedEntries[$path->value()] ??= new SkippedEntry($path, $reason, $detail);
    }
}
