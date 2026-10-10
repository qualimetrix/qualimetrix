<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeSelectorLedger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;
use SplFileInfo;

/**
 * Accumulated consequences of already inspected and admitted entries.
 *
 * @qmx-ignore coupling.instability:class -- Two callers publish this private walk outcome; its eight outgoing measured values share one lifecycle, and splitting the publication only transfers the small-population ratio.
 */
final class WalkedEntryOutcome
{
    /** @var array<string, SplFileInfo> */
    private array $candidates = [];

    /** @var array<string, SkippedEntry> */
    private array $skipped = [];

    /** @var array<string, RelativePath> */
    private array $namedExcluded = [];

    /** @var array<string, RelativePath> */
    private array $missing = [];

    /** @var array<string, RelativePath> */
    private array $unlistableOutside = [];

    /** @var array<string, RelativePath> */
    private array $hiddenOutside = [];

    private ?AbsolutePath $unlistableOutsideRoot = null;

    public function __construct(private readonly ExcludeSelectorLedger $ledger) {}

    public function namedExcluded(RelativePath $relative): void
    {
        $this->namedExcluded[$relative->value()] = $relative;
    }

    /** @param non-empty-list<string> $matching */
    public function recordMatched(AbsolutePath $path, EntryKind $kind, RelativePath $relative, array $matching, string $zone): void
    {
        if ($kind === EntryKind::Directory) {
            $this->ledger->hiddenDirectory($relative, $matching);
            if ($zone === 'outside') {
                $this->hiddenOutside[$relative->value()] = $relative;
            }
        } elseif ($zone === 'outside') {
            $this->recordOutsideLeaf($path, $kind, $relative);
        }
    }

    public function recordLeaf(AbsolutePath $path, EntryKind $kind, ?RelativePath $relative, string $zone): void
    {
        if ($kind === EntryKind::StatFailed) {
            $this->unlistable($path, $relative, $zone, $kind);
            return;
        }
        if ($relative === null) {
            return;
        }
        if ($zone === 'outside') {
            $this->recordOutsideLeaf($path, $kind, $relative);
        } elseif ($zone === 'run') {
            $this->recordRunLeaf($path, $kind, $relative);
        }
    }

    private function recordOutsideLeaf(AbsolutePath $path, EntryKind $kind, RelativePath $relative): void
    {
        if ($kind === EntryKind::RegularFile && $this->isPhp($path)) {
            $this->missing[$relative->value()] = $relative;
        }
    }

    private function recordRunLeaf(AbsolutePath $path, EntryKind $kind, RelativePath $relative): void
    {
        if ($kind === EntryKind::RegularFile) {
            if ($this->isPhp($path)) {
                $this->candidates[$relative->value()] ??= new SplFileInfo($path->value());
            }
            return;
        }
        if ($kind === EntryKind::DirectoryLink) {
            $this->skip($relative, SkippedEntry::directorySymlink($path, 'Symbolic link to a directory is not traversed'));
            return;
        }
        if ($this->isPhp($path)) {
            $this->skip($relative, $this->skippedLeaf($path, $kind));
        }
    }

    private function skippedLeaf(AbsolutePath $path, EntryKind $kind): SkippedEntry
    {
        if ($kind === EntryKind::FileLink) {
            return new SkippedEntry($path, AnalysisFailureKind::FileSymlink, 'Symbolic link to a file is not analysed during directory traversal');
        }

        return SkippedEntry::nonRegular($path, 'Discovered entry is not a regular file');
    }

    public function unlistable(AbsolutePath $path, ?RelativePath $relative, string $zone, EntryKind $kind): void
    {
        if ($relative === null) {
            $this->unlistableRoot($path, $zone);
            return;
        }
        $this->ledger->unlistable($relative);
        if ($zone === 'outside') {
            $this->unlistableOutside[$relative->value()] = $relative;
        } elseif ($zone === 'run') {
            $reason = $kind === EntryKind::StatFailed
                ? AnalysisFailureKind::UnreadableEntry
                : AnalysisFailureKind::UnreadableDirectory;
            $this->skip($relative, new SkippedEntry($path, $reason, 'Entry cannot be inspected or listed'));
        }
    }

    private function unlistableRoot(AbsolutePath $path, string $zone): void
    {
        if ($zone === 'outside') {
            $this->unlistableOutsideRoot = $path;
            $this->ledger->unlistableRoot();
            return;
        }

        throw new RuntimeException(\sprintf('Project root "%s" cannot be inspected or listed', $path->value()));
    }

    private function skip(RelativePath $relative, SkippedEntry $skip): void
    {
        $this->skipped[$relative->value()] ??= $skip;
    }

    private function isPhp(AbsolutePath $path): bool
    {
        return str_ends_with($path->value(), '.php');
    }

    /** @qmx-ignore code-smell.boolean-argument -- The booleans publish measured named-file selection and denominator knowledge, not requested behavior. */
    public function result(bool $namedFilesOnly, bool $knownUniverse): WalkedProject
    {
        $facts = new ScopeFacts(
            array_values($this->missing),
            array_values($this->unlistableOutside),
            array_values($this->hiddenOutside),
            $namedFilesOnly,
            unlistableOutsideRoot: $this->unlistableOutsideRoot,
        );

        return new WalkedProject(
            array_values($this->candidates),
            array_values($this->skipped),
            array_values($this->namedExcluded),
            $this->ledger->verdicts($knownUniverse),
            $facts,
        );
    }
}
