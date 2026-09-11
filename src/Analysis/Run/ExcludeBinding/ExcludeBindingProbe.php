<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use FilesystemIterator;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Which of the run's exclude patterns removed a directory, and which removed
 * nothing.
 *
 * **Why this walks the tree instead of reading the discovery output.**
 * `FinderFileDiscovery` hands `excludedDirs` straight to
 * `Symfony\Component\Finder\Finder::exclude()`, which drops the matching
 * directories *before* the iterator yields anything. Comparing the patterns
 * against the files that came out therefore reports zero matches for a pattern
 * that worked perfectly — the evidence is destroyed by the very step being
 * measured. So the question is asked at the moment of application: walk the
 * same roots, and ask each directory whether a pattern would have removed it.
 *
 * **The match model is Symfony's, read from
 * `Finder/Iterator/ExcludeDirectoryFilterIterator`, not from `is_dir()`.**
 * Two forms, and they are not the same predicate:
 *
 * - a pattern with no `/` matches a directory **basename** at any depth, so
 *   `node_modules` removes `src/a/node_modules` as readily as `node_modules`;
 * - a pattern containing `/` is quoted into `#(?:^|/)<pattern>(?:/|$)#` and run
 *   against the directory's path relative to the search root — anchored to a
 *   path-segment boundary, **not** to the root. `src/Legacy` therefore matches
 *   `vendor/acme/src/Legacy` too. Modelling it as "relative to the root" would
 *   call a genuine Finder hit a miss, which is the failure mode this class
 *   exists to avoid.
 *
 * A trailing slash is trimmed first, exactly as Finder trims it.
 *
 * **This is a second implementation of somebody else's contract, and it is
 * watched as one.** Calling the iterator instead is not available: it answers
 * per node and never says *which* pattern pruned a directory, which is the
 * whole question here. So the rule is restated, and
 * `ExcludeBindingProbeTest::itBindsExactlyWhenARealFinderRemovesSomething()`
 * asks both sides about every shape they could differ on — an edit to
 * {@see matches()} and a Symfony upgrade that moves the semantics under it
 * both redden it.
 *
 * **What the walk costs and how that is bounded.** Only directories are
 * visited, never files; the caller does not walk at all unless the author
 * wrote an exclude ({@see UnmatchedExcludeAudit}); and the walk stops the
 * moment every authored pattern has bound, since nothing after that can change
 * the answer. Built-in excludes are carried through the walk so it does not
 * descend into `vendor/`; the price of that is named rather than hidden — an
 * authored pattern pointing *inside* a built-in exclusion reads as unbound.
 */
final readonly class ExcludeBindingProbe
{
    /**
     * The authored patterns that matched no directory under `$roots`.
     *
     * @param list<AbsolutePath> $roots the run's analysed paths; files among them contribute no directories
     * @param list<string> $authored patterns whose binding is asked about
     * @param list<string> $pruned patterns the walk itself honours, so it does not descend where discovery would not
     *
     * @return list<string> in the order they were authored, duplicates collapsed
     */
    public function unboundPatterns(array $roots, array $authored, array $pruned): array
    {
        $patterns = self::normalized($authored);
        $directories = array_values(array_filter($roots, static fn(AbsolutePath $r): bool => $r->isDirectory()));

        // No pattern to ask about, or no directory tree for one to have
        // removed anything from. The second is not a miss: silence there is
        // the true answer, not a pattern-by-pattern accusation.
        if ($patterns === [] || $directories === []) {
            return [];
        }

        foreach ($directories as $root) {
            $this->walk($root, $patterns, $pruned);

            if (self::allBound($patterns)) {
                break;
            }
        }

        return self::unbound($patterns);
    }

    /**
     * The authored list as the walk wants it: trailing slashes trimmed the way
     * Finder trims them, blanks dropped, duplicates collapsed, and every entry
     * a pair rather than a key — PHP turns a numeric-looking array key into an
     * int, and "2024" is a legal directory name.
     *
     * @param list<string> $authored
     *
     * @return list<array{pattern: string, bound: bool}>
     */
    private static function normalized(array $authored): array
    {
        $patterns = [];
        $seen = [];

        foreach ($authored as $pattern) {
            $trimmed = rtrim($pattern, '/');

            if ($trimmed !== '' && !isset($seen[$trimmed])) {
                $seen[$trimmed] = true;
                $patterns[] = ['pattern' => $trimmed, 'bound' => false];
            }
        }

        return $patterns;
    }

    /**
     * @param list<array{pattern: string, bound: bool}> $patterns
     *
     * @return list<string>
     */
    private static function unbound(array $patterns): array
    {
        $unbound = [];

        foreach ($patterns as $entry) {
            if (!$entry['bound']) {
                $unbound[] = $entry['pattern'];
            }
        }

        return $unbound;
    }

    /**
     * @param list<array{pattern: string, bound: bool}> $patterns mutated in place
     * @param list<string> $pruned
     */
    private function walk(AbsolutePath $root, array &$patterns, array $pruned): void
    {
        $prunedPatterns = [];
        foreach ($pruned as $pattern) {
            $trimmed = rtrim($pattern, '/');
            if ($trimmed !== '') {
                $prunedPatterns[$trimmed] = true;
            }
        }
        // Deduplication keys the patterns, and PHP silently turns a numeric-string
        // array key into an int — so a directory legitimately named `7` came back
        // from array_keys() as an int and broke the string contract below.
        $prunedPatterns = array_map(strval(...), array_keys($prunedPatterns));

        $rootPrefix = rtrim(str_replace('\\', '/', $root->value()), '/') . '/';

        // The pattern test lives in the filter, and the filter's return value
        // is the pruning decision: `false` on a directory stops the recursion
        // there, which is how Finder itself declines to descend. Testing first
        // and pruning second is deliberate — an authored `vendor` binds to the
        // directory it names even though nothing below it is ever read.
        //
        // Pruning asks `matches()`, the same two-branch predicate the authored
        // patterns are asked: `ExcludeDirectoryFilterIterator::accept()` rejects
        // a directory on either branch, and a rejected directory is one the
        // recursion never enters. Pruning on the basename branch alone let the
        // probe walk into a subtree discovery never reads, where a pattern
        // could bind to a directory no run could ever have removed.
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(
                $root->value(),
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS | FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            static function (SplFileInfo $entry) use (&$patterns, $prunedPatterns, $rootPrefix): bool {
                if (!$entry->isDir()) {
                    return false;
                }

                $name = $entry->getFilename();
                $relative = str_replace('\\', '/', $entry->getPathname());
                $relative = str_starts_with($relative, $rootPrefix)
                    ? substr($relative, \strlen($rootPrefix))
                    : $relative;

                foreach ($patterns as $index => $candidate) {
                    if (!$candidate['bound'] && self::matches($candidate['pattern'], $name, $relative)) {
                        $patterns[$index]['bound'] = true;
                    }
                }

                foreach ($prunedPatterns as $prunedPattern) {
                    if (self::matches($prunedPattern, $name, $relative)) {
                        return false;
                    }
                }

                return true;
            },
        );

        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $_) {
            if (self::allBound($patterns)) {
                return;
            }
        }
    }

    /** @param list<array{pattern: string, bound: bool}> $patterns */
    private static function allBound(array $patterns): bool
    {
        foreach ($patterns as $entry) {
            if (!$entry['bound']) {
                return false;
            }
        }

        return true;
    }

    /** Symfony's own two-branch rule, restated for one directory. */
    private static function matches(string $pattern, string $name, string $relativePath): bool
    {
        if (!str_contains($pattern, '/')) {
            return $name === $pattern;
        }

        return preg_match('#(?:^|/)' . preg_quote($pattern, '#') . '(?:/|$)#', $relativePath) === 1;
    }
}
