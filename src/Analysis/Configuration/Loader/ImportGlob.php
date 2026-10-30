<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Closure;
use Qualimetrix\Core\FileTarget\NativeCall;

/**
 * A glob entry of `imports`: `*`, `?` and `[...]` within one path segment.
 * The walk is our own because `glob()` skips a directory it cannot read
 * without saying so, and a silently shorter list changes what is enforced.
 */
final class ImportGlob
{
    public static function isPattern(string $entry): bool
    {
        return strpbrk($entry, '*?[') !== false;
    }

    /** Why a pattern is outside the grammar, or null. */
    public static function grammarProblem(string $pattern): ?string
    {
        return match (true) {
            str_contains($pattern, '**') => 'uses "**"; a glob stands for one directory level, so name each level',
            str_contains($pattern, '{') => 'uses "{"; braces are not expanded, write one entry per alternative',
            str_contains($pattern, '\\') => 'contains a backslash; a pattern cannot escape characters',
            self::hasUnclosedBracket($pattern) => 'has a "[" without its closing "]"',
            default => null,
        };
    }

    /**
     * The files the pattern matches, as [physical path, display path] in byte
     * order of the display path. A directory the walk has to list or enter and
     * cannot is refused; a name that cannot lead to a file is passed over.
     * Matching is case-sensitive.
     *
     * @param Closure(string): never $refuse completes "Import ... " with the problem
     *
     * @return list<array{string, string}>
     */
    public static function matches(string $pattern, string $physicalBase, string $displayBase, Closure $refuse): array
    {
        $segments = array_values(array_filter(explode('/', $pattern), static fn(string $segment): bool => $segment !== '' && $segment !== '.'));
        [$physical, $display] = str_starts_with($pattern, '/') ? ['/', '/'] : [$physicalBase, $displayBase];

        $first = 0;
        while (!self::isPattern($segments[$first])) {
            self::assertEnterable($physical, $display, $refuse);
            $physical = self::child($physical, $segments[$first]);
            $display = self::child($display, $segments[$first]);
            ++$first;
        }
        if (!is_dir($physical)) {
            $refuse(\sprintf('names the directory "%s" before its first glob character, which does not exist', $display));
        }

        $candidates = [[$physical, $display]];
        $last = \count($segments) - 1;
        for ($index = $first; $index <= $last; ++$index) {
            $candidates = self::descend($candidates, $segments[$index], $index === $last, $refuse);
        }

        usort($candidates, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

        return $candidates;
    }

    /**
     * One level of the walk: every candidate directory's children matching the
     * segment, which must be a file or link at the last level and a directory
     * before it.
     *
     * @param list<array{string, string}> $candidates [physical path, display path]
     * @param Closure(string): never $refuse
     *
     * @return list<array{string, string}>
     */
    private static function descend(array $candidates, string $segment, bool $isLast, Closure $refuse): array
    {
        $next = [];
        foreach ($candidates as [$directory, $shown]) {
            foreach (self::children($segment, $directory, $shown, $refuse) as $name) {
                $path = self::child($directory, $name);
                if (self::fitsLevel($path, $isLast)) {
                    $next[] = [$path, self::child($shown, $name)];
                }
            }
        }

        return $next;
    }

    private static function fitsLevel(string $path, bool $isLast): bool
    {
        return $isLast ? file_exists($path) || is_link($path) : is_dir($path);
    }

    /**
     * @param Closure(string): never $refuse
     *
     * @return list<string> the names in the directory the segment stands for, byte order
     */
    private static function children(string $segment, string $directory, string $shown, Closure $refuse): array
    {
        if (!self::isPattern($segment)) {
            self::assertEnterable($directory, $shown, $refuse);

            return [$segment];
        }

        [$names] = NativeCall::attempt(static fn(): array|false => scandir($directory, \SCANDIR_SORT_NONE));
        if ($names === false) {
            $refuse(\sprintf('needs the contents of the directory "%s", which cannot be listed', $shown));
        }

        $matching = [];
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..' && fnmatch($segment, (string) $name, \FNM_PERIOD)) {
                $matching[] = (string) $name;
            }
        }
        sort($matching, \SORT_STRING);

        return $matching;
    }

    /** @param Closure(string): never $refuse */
    private static function assertEnterable(string $directory, string $shown, Closure $refuse): void
    {
        if (is_dir($directory) && !is_executable($directory)) {
            $refuse(\sprintf('needs to enter the directory "%s", which cannot be entered', $shown));
        }
    }

    private static function child(string $directory, string $name): string
    {
        return $directory === '.' ? $name : rtrim($directory, '/') . '/' . $name;
    }

    private static function hasUnclosedBracket(string $pattern): bool
    {
        $offset = strpos($pattern, '[');
        while ($offset !== false) {
            // A leading "]" is a member of the set, so the closing one is looked for from the second character.
            $close = strpos($pattern, ']', $offset + 2);
            if ($close === false) {
                return true;
            }
            $offset = strpos($pattern, '[', $close + 1);
        }

        return false;
    }
}
