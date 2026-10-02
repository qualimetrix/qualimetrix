<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use LogicException;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;

/** Explicit metadata query for consumers that require a full PHP path set. */
final readonly class ProjectTree implements ProjectTreeQueryInterface
{
    public function __construct(private EntryInspectorInterface $inspector) {}

    public function snapshot(ProjectScopeUniverse $universe): ProjectTreeSnapshot
    {
        $files = [];
        $unknown = [];
        $rootAccessible = true;
        foreach ($universe->denominator as $target) {
            $rootAccessible = $this->visit($target['path'], $universe->projectRoot, $files, $unknown) && $rootAccessible;
        }
        ksort($files, \SORT_STRING);
        ksort($unknown, \SORT_STRING);

        return new ProjectTreeSnapshot(array_values($files), array_values($unknown), $universe->denominator !== [] && $rootAccessible);
    }

    public function hasFile(AbsolutePath $root, RelativePath $file): ProjectEntryPresence
    {
        $current = $root;
        $segments = $file->segments();
        foreach ($segments as $index => $segment) {
            $names = $this->inspector->list($current->value());
            if ($names === null) {
                return ProjectEntryPresence::Unknown;
            }
            if (!\in_array($segment, $names, true)) {
                return ProjectEntryPresence::Absent;
            }
            $current = AbsolutePath::fromString($current->value() . '/' . $segment);
            $kind = $this->inspector->inspect($current->value());
            if ($kind === EntryKind::StatFailed) {
                return ProjectEntryPresence::Unknown;
            }
            if ($index < \count($segments) - 1) {
                if ($kind !== EntryKind::Directory) {
                    return ProjectEntryPresence::Absent;
                }
                continue;
            }

            return $kind === EntryKind::RegularFile && str_ends_with($segment, '.php')
                ? ProjectEntryPresence::Present
                : ProjectEntryPresence::Absent;
        }

        return ProjectEntryPresence::Unknown;
    }

    /**
     * @param array<string, RelativePath> $files
     * @param array<string, RelativePath> $unknown
     */
    private function visit(AbsolutePath $path, AbsolutePath $root, array &$files, array &$unknown): bool
    {
        $relative = $this->relative($path, $root);
        if ($relative !== null && $this->builtInFloor($relative)) {
            return true;
        }
        $kind = $this->inspector->inspect($path->value());
        if ($kind === EntryKind::StatFailed) {
            if ($relative !== null) {
                $unknown[$relative->value()] = $relative;
            }
            return false;
        }
        if ($kind === EntryKind::Directory) {
            $children = $this->inspector->list($path->value());
            if ($children === null) {
                if ($relative !== null) {
                    $unknown[$relative->value()] = $relative;
                }
                return false;
            }
            foreach ($children as $name) {
                $this->visit(AbsolutePath::fromString($path->value() . '/' . $name), $root, $files, $unknown);
            }
            return true;
        }
        if ($kind === EntryKind::RegularFile && $relative !== null && str_ends_with($path->value(), '.php')) {
            $files[$relative->value()] = $relative;
        }
        return true;
    }

    private function relative(AbsolutePath $path, AbsolutePath $root): ?RelativePath
    {
        if ($path->equals($root)) {
            return null;
        }
        try {
            return PathFactory::published($path, $root);
        } catch (LogicException) {
            return null;
        }
    }

    private function builtInFloor(RelativePath $path): bool
    {
        foreach ($path->segments() as $segment) {
            if (\in_array($segment, ['vendor', 'node_modules', '.git'], true)) {
                return true;
            }
        }
        return false;
    }
}
