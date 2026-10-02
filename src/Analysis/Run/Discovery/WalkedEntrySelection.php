<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeSelectorLedger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;

/** One run's entry admission and recursive metadata descent. */
final class WalkedEntrySelection
{
    /** @var array<string, true> */
    private array $visited = [];

    private readonly RemovedRunPhpEvidence $evidence;

    public function __construct(
        private readonly WalkRequest $request,
        private readonly EntryInspectorInterface $inspector,
        private readonly ExcludeSelectorLedger $ledger,
        private readonly WalkedEntryOutcome $outcome,
        private readonly Closure $inUniverse,
        private readonly Closure $builtInFloor,
        private readonly Closure $coveredByRun,
    ) {
        $this->evidence = new RemovedRunPhpEvidence($inspector);
    }

    public function namedBase(AbsolutePath $path, EntryKind $kind): ?RelativePath
    {
        $root = $this->request->run->projectScope->universe->projectRoot;
        if ($this->canonical($path)->equals($this->canonical($root))) {
            return null;
        }

        return $this->relative($path, $this->namedAdmission($kind));
    }

    public function scanNamedRoot(AbsolutePath $path, EntryKind $kind, ?RelativePath $base): void
    {
        $relative = $this->namedBase($path, $kind);
        $excluded = $this->scanEntry($path, $kind, $this->namedAdmission($kind), $relative, 'run', $base);
        if ($excluded !== null) {
            $this->outcome->namedExcluded($excluded);
        }
    }

    public function scanOutside(AbsolutePath $target): void
    {
        $this->scan($target, 'outside');
    }

    public function scanProject(AbsolutePath $root): void
    {
        $this->scan($root, 'project');
    }

    private function scan(AbsolutePath $path, string $zone, ?RelativePath $runBase = null): void
    {
        if ($zone === 'outside' && ($this->coveredByRun)($path)) {
            return;
        }
        $kind = $this->inspector->inspect($path->value());
        $this->scanEntry($path, $kind, $kind, $this->relative($path, $kind), $zone, $runBase);
    }

    private function scanEntry(AbsolutePath $path, EntryKind $originalKind, EntryKind $admittedKind, ?RelativePath $relative, string $zone, ?RelativePath $runBase): ?RelativePath
    {
        $matching = $relative === null ? [] : $this->ledger->matching($relative);
        if ($relative !== null) {
            $this->ledger->bind($relative, $matching);
        }
        if ($relative !== null && ($this->builtInFloor)($relative, $zone, $runBase)) {
            return null;
        }
        if ($matching !== []) {
            $this->recordRemovedRunEntry($path, $originalKind, $relative, $matching, $zone);
            $this->outcome->recordMatched($path, $admittedKind, $relative, $matching, $zone);

            return $relative;
        }
        if ($admittedKind === EntryKind::Directory) {
            $this->scanDirectory($path, $relative, $zone, $runBase);
        } else {
            $this->outcome->recordLeaf($path, $admittedKind, $relative, $zone);
        }

        return null;
    }

    /** @param non-empty-list<string> $matching */
    private function recordRemovedRunEntry(AbsolutePath $path, EntryKind $kind, RelativePath $relative, array $matching, string $zone): void
    {
        if ($zone !== 'run' || !($this->inUniverse)($path)) {
            return;
        }
        $evidence = $this->ledger->needsPhpEvidence($matching) ? $this->evidence->search($path, $kind) : null;
        $this->ledger->removedFromRun($relative, $matching, $evidence);
    }

    private function scanDirectory(AbsolutePath $path, ?RelativePath $relative, string $zone, ?RelativePath $runBase): void
    {
        $canonical = $this->canonical($path)->value();
        if (isset($this->visited[$zone . ':' . $canonical])) {
            return;
        }
        $this->visited[$zone . ':' . $canonical] = true;
        $children = $this->inspector->list($path->value());
        if ($children === null) {
            $this->outcome->unlistable($path, $relative, $zone, EntryKind::Directory);
            return;
        }
        foreach ($children as $name) {
            $child = AbsolutePath::fromString($path->value() . '/' . $name);
            $this->scan($child, $zone, $runBase);
        }
    }

    private function namedAdmission(EntryKind $kind): EntryKind
    {
        return match ($kind) {
            EntryKind::FileLink => EntryKind::RegularFile,
            EntryKind::DirectoryLink => EntryKind::Directory,
            default => $kind,
        };
    }

    private function canonical(AbsolutePath $path): AbsolutePath
    {
        $resolved = realpath($path->value());

        return $resolved === false ? $path : AbsolutePath::fromString($resolved);
    }

    private function relative(AbsolutePath $path, EntryKind $kind): ?RelativePath
    {
        $root = $this->request->run->projectScope->universe->projectRoot;
        if ($path->equals($root)) {
            return null;
        }
        $canonicalRoot = $this->canonical($root);
        if ($kind === EntryKind::Directory) {
            return $this->canonical($path)->tryRelativizeTo($canonicalRoot);
        }

        try {
            return PathFactory::published($path, $canonicalRoot);
        } catch (LogicException) {
            return null;
        }
    }
}
