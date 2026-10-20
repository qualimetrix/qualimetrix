<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\ExcludeBinding\ExcludeSelectorLedger;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/**
 * One filesystem walk answers selection, outside scope and selector binding.
 *
 * @qmx-ignore coupling.instability:class -- Two callers enter this private walk seam; splitting its eleven outgoing types merely moves the small-population ratio between admission and measured outcomes.
 */
final class ProjectWalk
{
    public function __construct(private readonly EntryInspectorInterface $inspector) {}

    public function walk(WalkRequest $request): WalkedProject
    {
        $run = $request->run;
        $root = $run->projectScope->universe->projectRoot;
        $ledger = new ExcludeSelectorLedger($run->authoredPathExcludes);
        $outcome = new WalkedEntryOutcome($ledger);
        $runDirectories = [];
        $runFiles = [];
        $selection = new WalkedEntrySelection(
            $request,
            $this->inspector,
            $ledger,
            $outcome,
            fn(AbsolutePath $path): bool => $this->inUniverse($path, $request),
            $this->builtInFloor(...),
            function (AbsolutePath $path) use (&$runDirectories, &$runFiles): bool {
                return $this->coveredByRun($path, $runDirectories, $runFiles);
            },
        );
        $roots = $this->namedRoots($request, $selection);
        $namedFilesOnly = $this->scanRunRoots($roots, $selection, $runDirectories, $runFiles);

        foreach ($run->projectScope->universe->denominator as $target) {
            $selection->scanOutside($target['path']);
        }

        $knownUniverse = $run->projectScope->universe->denominator !== []
            || $run->projectScope->universe->containsProjectRoot($run->paths);
        if ($ledger->unsettled() && $knownUniverse) {
            $selection->scanProject($root);
        }

        return $outcome->result($namedFilesOnly, $knownUniverse);
    }

    /** @return list<array{AbsolutePath, EntryKind, ?RelativePath}> */
    private function namedRoots(WalkRequest $request, WalkedEntrySelection $selection): array
    {
        $namedFloor = [];
        $roots = [];
        foreach ($request->run->paths as $path) {
            $kind = $this->inspector->inspect($path->value());
            $base = $selection->namedBase($path, $kind);
            if (($kind === EntryKind::Directory || $kind === EntryKind::DirectoryLink)
                && $base !== null && $this->reservedName(basename($base->value()))) {
                $namedFloor[$base->value()] = true;
            }
            $roots[] = [$path, $kind, $base];
        }
        if ($namedFloor !== []) {
            $this->refuseNamedFloor(array_keys($namedFloor));
        }

        return $roots;
    }

    /**
     * @param list<array{AbsolutePath, EntryKind, ?RelativePath}> $roots
     * @param list<AbsolutePath> $runDirectories
     * @param array<string, true> $runFiles
     */
    private function scanRunRoots(array $roots, WalkedEntrySelection $selection, array &$runDirectories, array &$runFiles): bool
    {
        $namedFilesOnly = true;
        foreach ($roots as [$path, $kind, $base]) {
            if ($kind === EntryKind::Directory || $kind === EntryKind::DirectoryLink) {
                $namedFilesOnly = false;
                $runDirectories[] = $this->canonical($path);
            } else {
                $runFiles[$this->canonical($path)->value()] = true;
            }
            $selection->scanNamedRoot($path, $kind, $base);
        }

        return $namedFilesOnly;
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

    private function inUniverse(AbsolutePath $path, WalkRequest $request): bool
    {
        $universe = $request->run->projectScope->universe;
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

    /**
     * @param list<AbsolutePath> $runDirectories
     * @param array<string, true> $runFiles
     */
    private function coveredByRun(AbsolutePath $path, array $runDirectories, array $runFiles): bool
    {
        $canonical = $this->canonical($path);
        foreach ($runDirectories as $directory) {
            if ($canonical->equals($directory) || $canonical->tryRelativizeTo($directory) !== null) {
                return true;
            }
        }

        return isset($runFiles[$canonical->value()]);
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
}
