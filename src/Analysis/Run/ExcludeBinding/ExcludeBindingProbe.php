<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use FilesystemIterator;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Analysis\Run\Discovery\DirectoryWalk;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/** Measures authored directory selectors through the same pruner as discovery. */
final readonly class ExcludeBindingProbe
{
    /**
     * Selectors no directory in these roots matched.
     *
     * A selector the walk could not judge is not one of them, and not a
     * finding either: see {@see judge()} for the answer that keeps the two
     * apart.
     *
     * @param list<AbsolutePath> $roots
     * @param list<PathPattern> $authored
     *
     * @return list<PathPattern>
     */
    public function unboundPatterns(array $roots, array $authored, DirectoryPruner $pruner): array
    {
        return $this->judge($roots, $authored, $pruner)->unbound;
    }

    /**
     * Both halves of the answer: what bound nothing, and what could not be
     * asked because a directory would not open.
     *
     * @param list<AbsolutePath> $roots
     * @param list<PathPattern> $authored
     */
    public function judge(array $roots, array $authored, DirectoryPruner $pruner): ExcludeBindingVerdict
    {
        $patterns = self::unique($authored);
        $directories = array_values(array_filter($roots, static fn(AbsolutePath $root): bool => $root->isDirectory()));

        if ($patterns === [] || $directories === []) {
            return new ExcludeBindingVerdict([], []);
        }

        $bound = [];
        $unjudgeable = [];
        $unlistable = [];
        foreach ($directories as $root) {
            $this->walk($root, $patterns, $bound, $unjudgeable, $unlistable, $pruner);
            if (\count($bound + $unjudgeable) === \count($patterns)) {
                break;
            }
        }

        $unbound = array_values(array_filter(
            $patterns,
            static fn(PathPattern $pattern): bool => !isset($bound[$pattern->definition->display()])
                && !isset($unjudgeable[$pattern->definition->display()]),
        ));

        return new ExcludeBindingVerdict($unbound, array_diff_key($unlistable, $bound));
    }

    /**
     * @param list<PathPattern> $patterns
     * @param array<string, true> $bound
     * @param array<string, true> $unjudgeable
     * @param array<string, AbsolutePath> $unlistable
     */
    private function walk(
        AbsolutePath $root,
        array $patterns,
        array &$bound,
        array &$unjudgeable,
        array &$unlistable,
        DirectoryPruner $pruner,
    ): void {
        $this->measureDirectory($root, $patterns, $bound, $unjudgeable, $pruner);
        if ($pruner->match($root) !== null) {
            return;
        }

        if (!self::isListable($root)) {
            $this->markUnjudgeable($root, $patterns, $unjudgeable, $unlistable, $pruner);

            return;
        }

        try {
            $directoryIterator = new RecursiveDirectoryIterator(
                $root->value(),
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS | FilesystemIterator::CURRENT_AS_FILEINFO,
            );
        } catch (UnexpectedValueException) {
            // The root stopped being listable between the check above and here.
            $this->markUnjudgeable($root, $patterns, $unjudgeable, $unlistable, $pruner);

            return;
        }

        $filter = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $entry) use ($patterns, &$bound, &$unjudgeable, &$unlistable, $pruner): bool {
                return $this->descend($entry, $patterns, $bound, $unjudgeable, $unlistable, $pruner);
            },
        );

        // A branch that stops being listable between the check above and the
        // descent is refused inside the iterator, where nothing but the walk
        // itself can see it. {@see DirectoryWalk} hands it back here, so that
        // window ends in the same unjudged verdict the check produces rather
        // than in a pattern quietly dropped from the answer.
        $walk = new DirectoryWalk(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST,
            function (string $pathname) use ($patterns, &$unjudgeable, &$unlistable, $pruner): void {
                $this->markUnjudgeable(
                    AbsolutePath::fromString($pathname),
                    $patterns,
                    $unjudgeable,
                    $unlistable,
                    $pruner,
                );
            },
        );

        foreach ($walk as $_) {
            if (\count($bound + $unjudgeable) === \count($patterns)) {
                return;
            }
        }
    }

    /**
     * Whether the walk may descend into this entry, measuring it on the way.
     *
     * @param list<PathPattern> $patterns
     * @param array<string, true> $bound
     * @param array<string, true> $unjudgeable
     * @param array<string, AbsolutePath> $unlistable
     */
    private function descend(
        SplFileInfo $entry,
        array $patterns,
        array &$bound,
        array &$unjudgeable,
        array &$unlistable,
        DirectoryPruner $pruner,
    ): bool {
        if (!$entry->isDir()) {
            return false;
        }

        $directory = AbsolutePath::fromString($entry->getPathname());
        $this->measureDirectory($directory, $patterns, $bound, $unjudgeable, $pruner);

        if ($pruner->match($directory) !== null) {
            return false;
        }

        // A subtree this process may not list is the same evidence gap
        // a pruned one is: what it holds cannot be seen, so a selector
        // it could have matched must not be called unbound.
        if (!self::isListable($directory)) {
            $this->markUnjudgeable($directory, $patterns, $unjudgeable, $unlistable, $pruner);

            return false;
        }

        return true;
    }

    /**
     * Whether this process can read the entries of a directory at all. Both
     * bits are asked, because listing needs read and descending needs execute,
     * and a directory carrying only one of them fails in the same way.
     */
    private static function isListable(AbsolutePath $directory): bool
    {
        return is_readable($directory->value()) && is_executable($directory->value());
    }

    /**
     * A directory whose contents this process cannot see, and the selectors
     * that leaves unanswered.
     *
     * Both records are kept: `$unjudgeable` stops the selector being called
     * unbound, `$unlistable` is what lets the caller say so out loud. Only
     * this refusal writes the second one — a subtree hidden by another
     * exclude is the configuration doing its job, not evidence going missing.
     *
     * @param list<PathPattern> $patterns
     * @param array<string, true> $unjudgeable
     * @param array<string, AbsolutePath> $unlistable
     */
    private function markUnjudgeable(
        AbsolutePath $directory,
        array $patterns,
        array &$unjudgeable,
        array &$unlistable,
        DirectoryPruner $pruner,
    ): void {
        foreach ($patterns as $pattern) {
            if ($pruner->hidesPossibleMatch($pattern, $directory)) {
                $display = $pattern->definition->display();
                $unjudgeable[$display] = true;
                $unlistable[$display] ??= $directory;
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
