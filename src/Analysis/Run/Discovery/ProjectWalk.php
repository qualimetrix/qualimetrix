<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeSelectorLedger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use RuntimeException;
use SplFileInfo;

/** One filesystem walk answers selection, outside scope and selector binding. */
final class ProjectWalk
{
    /** @var array<string, SplFileInfo> */
    private array $candidates = [];

    /** @var array<string, SkippedEntry> */
    private array $skipped = [];

    /** @var array<string, RelativePath> */
    private array $namedExcluded = [];

    private ExcludeSelectorLedger $ledger;

    /** @var array<string, RelativePath> */
    private array $missing = [];

    /** @var array<string, RelativePath> */
    private array $unlistableOutside = [];

    /** @var array<string, RelativePath> */
    private array $hiddenOutside = [];

    private ?AbsolutePath $unlistableOutsideRoot = null;

    /** @var array<string, true> */
    private array $visited = [];

    private WalkRequest $request;

    /** @var list<AbsolutePath> */
    private array $runDirectories = [];

    /** @var array<string, true> */
    private array $runFiles = [];

    public function __construct(private readonly EntryInspectorInterface $inspector) {}

    public function walk(WalkRequest $request): WalkedProject
    {
        $this->reset($request);
        $run = $request->run;
        $root = $run->projectScope->universe->projectRoot;
        $namedFilesOnly = true;
        $namedFloor = [];
        $roots = [];

        foreach ($run->paths as $path) {
            $kind = $this->inspector->inspect($path->value());
            $base = $this->relative($path, $kind, $root, true);
            if (($kind === EntryKind::Directory || $kind === EntryKind::DirectoryLink)
                && $base !== null && $this->reservedName(basename($base->value()))) {
                $namedFloor[$base->value()] = true;
            }
            $roots[] = [$path, $kind, $base];
        }
        if ($namedFloor !== []) {
            $this->refuseNamedFloor(array_keys($namedFloor));
        }

        foreach ($roots as [$path, $kind, $base]) {
            if ($kind === EntryKind::Directory || $kind === EntryKind::DirectoryLink) {
                $namedFilesOnly = false;
                $this->runDirectories[] = $this->canonical($path);
            } else {
                $this->runFiles[$this->canonical($path)->value()] = true;
            }
            $this->scan($path, 'run', true, $kind, $base);
        }

        foreach ($run->projectScope->universe->denominator as $target) {
            $this->scanOutside($target['path']);
        }

        $knownUniverse = $run->projectScope->universe->denominator !== []
            || $run->projectScope->universe->containsProjectRoot($run->paths);
        if ($this->ledger->unsettled() && $knownUniverse) {
            $this->scan($root, 'project', false);
        }

        $verdicts = $this->ledger->verdicts($knownUniverse);
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
            $verdicts,
            $facts,
        );
    }

    private function reset(WalkRequest $request): void
    {
        $this->request = $request;
        $this->candidates = $this->skipped = $this->namedExcluded = [];
        $this->ledger = new ExcludeSelectorLedger($request->run->authoredPathExcludes);
        $this->missing = $this->unlistableOutside = $this->hiddenOutside = $this->visited = [];
        $this->unlistableOutsideRoot = null;
        $this->runDirectories = $this->runFiles = [];
    }

    private function scanOutside(AbsolutePath $target): void
    {
        $this->scan($target, 'outside', false);
    }

    private function scan(AbsolutePath $path, string $zone, bool $named, ?EntryKind $knownKind = null, ?RelativePath $runBase = null): void
    {
        if ($zone === 'outside' && $this->coveredByRun($path)) {
            return;
        }
        $kind = $knownKind ?? $this->inspector->inspect($path->value());
        $root = $this->request->run->projectScope->universe->projectRoot;
        $relative = $this->relative($path, $kind, $root, $named);
        $matching = $relative === null ? [] : $this->ledger->matching($relative);
        if ($relative !== null && $this->builtInFloor($relative, $zone, $runBase)) {
            if ($matching !== []) {
                $this->ledger->bind($relative, $matching);
            }
            return;
        }

        if ($matching !== []) {
            $this->ledger->bind($relative, $matching);
            if ($zone === 'run' && $this->inUniverse($path)) {
                $evidence = $this->ledger->needsPhpEvidence($matching) ? $this->phpSearch($path, $kind) : null;
                $this->ledger->removedFromRun($relative, $matching, $evidence);
            }
            if ($named) {
                $this->namedExcluded[$relative->value()] = $relative;
            }
            if ($kind === EntryKind::Directory || ($kind === EntryKind::DirectoryLink && $named && $zone === 'run')) {
                $this->ledger->hiddenDirectory($relative, $matching);
                if ($zone === 'outside') {
                    $this->hiddenOutside[$relative->value()] = $relative;
                }
            } elseif ($zone === 'outside' && $kind === EntryKind::RegularFile && $this->isPhp($path)) {
                $this->missing[$relative->value()] = $relative;
            }

            return;
        }

        if ($kind === EntryKind::Directory || ($kind === EntryKind::DirectoryLink && $named && $zone === 'run')) {
            $canonical = $this->canonical($path)->value();
            if (isset($this->visited[$zone . ':' . $canonical])) {
                return;
            }
            $this->visited[$zone . ':' . $canonical] = true;
            $children = $this->inspector->list($path->value());
            if ($children === null) {
                $this->unlistable($path, $relative, $zone, EntryKind::Directory);

                return;
            }
            foreach ($children as $name) {
                $child = AbsolutePath::fromString($path->value() . '/' . $name);
                $this->scan($child, $zone, false, runBase: $runBase);
            }

            return;
        }

        if ($kind === EntryKind::StatFailed) {
            $this->unlistable($path, $relative, $zone, $kind);

            return;
        }
        if ($relative === null) {
            return;
        }
        if ($zone === 'outside') {
            if ($kind === EntryKind::RegularFile && $this->isPhp($path)) {
                $this->missing[$relative->value()] = $relative;
            }

            return;
        }
        if ($zone !== 'run') {
            return;
        }
        if ($kind === EntryKind::RegularFile || ($kind === EntryKind::FileLink && $named)) {
            if ($this->isPhp($path)) {
                $this->candidates[$relative->value()] ??= new SplFileInfo($path->value());
            }

            return;
        }
        if ($kind === EntryKind::DirectoryLink) {
            $this->skip($relative, SkippedEntry::directorySymlink($path, 'Symbolic link to a directory is not traversed'));
        } elseif ($kind === EntryKind::FileLink) {
            if ($this->isPhp($path)) {
                $this->skip($relative, new SkippedEntry($path, \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind::FileSymlink, 'Symbolic link to a file is not analysed during directory traversal'));
            }
        } elseif ($this->isPhp($path)) {
            $this->skip($relative, SkippedEntry::nonRegular($path, 'Discovered entry is not a regular file'));
        }
    }

    private function unlistable(AbsolutePath $path, ?RelativePath $relative, string $zone, EntryKind $kind): void
    {
        if ($relative === null) {
            if ($zone === 'outside') {
                $this->unlistableOutsideRoot = $path;
                $this->ledger->unlistableRoot();

                return;
            }
            throw new RuntimeException(\sprintf('Project root "%s" cannot be inspected or listed', $path->value()));
        }
        $this->ledger->unlistable($relative);
        if ($zone === 'outside') {
            $this->unlistableOutside[$relative->value()] = $relative;
        } elseif ($zone === 'run') {
            $reason = $kind === EntryKind::StatFailed
                ? \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind::UnreadableEntry
                : \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind::UnreadableDirectory;
            $this->skip($relative, new SkippedEntry($path, $reason, 'Entry cannot be inspected or listed'));
        }
    }

    private function skip(RelativePath $relative, SkippedEntry $skip): void
    {
        $this->skipped[$relative->value()] ??= $skip;
    }

    private function builtInFloor(RelativePath $relative, string $zone, ?RelativePath $runBase): bool
    {
        if ($zone === 'run' && $runBase !== null) {
            $tail = $relative->tryWithoutPrefix($runBase);
            if ($tail === null) {
                return false;
            }
            $relative = $tail;
        }
        foreach (explode('/', $relative->value()) as $segment) {
            if ($this->reservedName($segment)) {
                return true;
            }
        }

        return false;
    }

    private function reservedName(string $name): bool
    {
        return \in_array($name, ['vendor', 'node_modules', '.git'], true);
    }

    /** @param non-empty-list<string> $directories */
    private function refuseNamedFloor(array $directories): never
    {
        $one = \count($directories) === 1;

        throw ConfigurationRefusal::aboutResolvedInput(
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

    private function inUniverse(AbsolutePath $path): bool
    {
        $universe = $this->request->run->projectScope->universe;
        if ($universe->denominator === []) {
            return true;
        }
        $canonical = $this->canonical($path);
        foreach ($universe->denominator as $target) {
            $base = $this->canonical($target['path']);
            if ($canonical->equals($base) || $canonical->tryRelativizeTo($base) !== null) {
                return true;
            }
        }

        return false;
    }

    private function canonical(AbsolutePath $path): AbsolutePath
    {
        $resolved = realpath($path->value());

        return $resolved === false ? $path : AbsolutePath::fromString($resolved);
    }

    private function coveredByRun(AbsolutePath $path): bool
    {
        $canonical = $this->canonical($path);
        foreach ($this->runDirectories as $directory) {
            if ($canonical->equals($directory) || $canonical->tryRelativizeTo($directory) !== null) {
                return true;
            }
        }

        return isset($this->runFiles[$canonical->value()]);
    }

    private function relative(AbsolutePath $path, EntryKind $kind, AbsolutePath $root, bool $named): ?RelativePath
    {
        $canonicalRoot = $this->canonical($root);
        if ($path->equals($root) || ($named && $this->canonical($path)->equals($canonicalRoot))) {
            return null;
        }
        if ($kind === EntryKind::Directory || ($kind === EntryKind::DirectoryLink && $named)) {
            $canonical = $this->canonical($path);

            return $canonical->tryRelativizeTo($canonicalRoot);
        }

        try {
            return PathFactory::published($path, $canonicalRoot);
        } catch (LogicException) {
            return null;
        }
    }

    private function isPhp(AbsolutePath $path): bool
    {
        return str_ends_with($path->value(), '.php');
    }

    private function phpSearch(AbsolutePath $path, EntryKind $kind): ?string
    {
        if ($kind === EntryKind::RegularFile) {
            return $this->isPhp($path) ? 'php-file' : null;
        }
        if ($kind !== EntryKind::Directory && $kind !== EntryKind::DirectoryLink) {
            return $kind === EntryKind::StatFailed ? 'unlistable' : null;
        }
        $children = $this->inspector->list($path->value());
        if ($children === null) {
            return 'unlistable';
        }
        $unlistable = false;
        foreach ($children as $name) {
            $child = AbsolutePath::fromString($path->value() . '/' . $name);
            $childKind = $this->inspector->inspect($child->value());
            if ($childKind === EntryKind::DirectoryLink) {
                continue;
            }
            $result = $this->phpSearch($child, $childKind);
            if ($result === 'php-file') {
                return $result;
            }
            $unlistable = $unlistable || $result === 'unlistable';
        }

        return $unlistable ? 'unlistable' : null;
    }

}
