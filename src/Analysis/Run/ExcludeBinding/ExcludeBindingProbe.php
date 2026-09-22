<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use FilesystemIterator;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Measures authored directory selectors through the same pruner as discovery. */
final readonly class ExcludeBindingProbe
{
    /**
     * @param list<AbsolutePath> $roots
     * @param list<PathPattern> $authored
     *
     * @return list<PathPattern>
     */
    public function unboundPatterns(array $roots, array $authored, DirectoryPruner $pruner): array
    {
        $patterns = self::unique($authored);
        $directories = array_values(array_filter($roots, static fn(AbsolutePath $root): bool => $root->isDirectory()));

        if ($patterns === [] || $directories === []) {
            return [];
        }

        $bound = [];
        $unjudgeable = [];
        foreach ($directories as $root) {
            $this->walk($root, $patterns, $bound, $unjudgeable, $pruner);
            if (\count($bound + $unjudgeable) === \count($patterns)) {
                break;
            }
        }

        return array_values(array_filter(
            $patterns,
            static fn(PathPattern $pattern): bool => !isset($bound[$pattern->definition->display()])
                && !isset($unjudgeable[$pattern->definition->display()]),
        ));
    }

    /**
     * @param list<PathPattern> $patterns
     * @param array<string, true> $bound
     * @param array<string, true> $unjudgeable
     */
    private function walk(
        AbsolutePath $root,
        array $patterns,
        array &$bound,
        array &$unjudgeable,
        DirectoryPruner $pruner,
    ): void {
        $this->measureDirectory($root, $patterns, $bound, $unjudgeable, $pruner);
        if ($pruner->match($root) !== null) {
            return;
        }

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                $root->value(),
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS | FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            function (SplFileInfo $entry) use ($patterns, &$bound, &$unjudgeable, $pruner): bool {
                if (!$entry->isDir()) {
                    return false;
                }

                $directory = AbsolutePath::fromString($entry->getPathname());
                $this->measureDirectory($directory, $patterns, $bound, $unjudgeable, $pruner);

                return $pruner->match($directory) === null;
            },
        );

        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $_) {
            if (\count($bound + $unjudgeable) === \count($patterns)) {
                return;
            }
        }
    }

    /**
     * @param list<PathPattern> $patterns
     * @param array<string, true> $bound
     * @param array<string, true> $unjudgeable
     */
    private function measureDirectory(
        AbsolutePath $directory,
        array $patterns,
        array &$bound,
        array &$unjudgeable,
        DirectoryPruner $pruner,
    ): void {
        $pruned = $pruner->match($directory) !== null;
        foreach ($patterns as $pattern) {
            $display = $pattern->definition->display();
            if ($pruner->matchesPattern($pattern, $directory)) {
                $bound[$display] = true;
            } elseif ($pruned && $pruner->hidesPossibleMatch($pattern, $directory)) {
                $unjudgeable[$display] = true;
            }
        }
    }

    /**
     * @param list<PathPattern> $patterns
     *
     * @return list<PathPattern>
     */
    private static function unique(array $patterns): array
    {
        $unique = [];
        foreach ($patterns as $pattern) {
            $unique[$pattern->definition->display()] = $pattern;
        }

        return array_values($unique);
    }
}
