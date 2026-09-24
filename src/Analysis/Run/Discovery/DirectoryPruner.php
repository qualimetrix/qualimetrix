<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\PatternMatch;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Applies ordered project-relative path selectors before directory descent. */
final readonly class DirectoryPruner
{
    /** @param list<PathPattern> $patterns */
    public function __construct(
        private AbsolutePath $projectRoot,
        private array $patterns,
    ) {}

    /** @return list<PathPattern> */
    public static function builtInPatterns(): array
    {
        return array_map(
            static fn(string $name): PathPattern => new PathPattern(new SelectorDefinition(
                SelectorKind::Regex,
                '(?:[^/]+/)*' . preg_quote($name, '~'),
            )),
            ['vendor', 'node_modules', '.git'],
        );
    }

    public function match(AbsolutePath $directory): ?PatternMatch
    {
        foreach ($this->patterns as $pattern) {
            if ($this->matchesPattern($pattern, $directory)) {
                return new PatternMatch($pattern->definition);
            }
        }

        return null;
    }

    /**
     * The directory relative to the project root when one of the built-in
     * patterns this pruner applies removes the directory itself; null when
     * none does, including when only an authored selector removes it.
     *
     * The distinction is provenance: an authored `exclude:` may remove a
     * composer root on purpose, while a default root under a built-in
     * directory is never produced, so a root matched here was written by hand.
     */
    public function builtInExclusion(AbsolutePath $directory): ?string
    {
        $relative = $directory->tryRelativizeTo($this->projectRoot);
        if ($relative === null) {
            return null;
        }

        $builtIn = array_map(
            static fn(PathPattern $pattern): string => $pattern->definition->display(),
            self::builtInPatterns(),
        );
        foreach ($this->patterns as $pattern) {
            if (\in_array($pattern->definition->display(), $builtIn, true) && $pattern->matches($relative)) {
                return $relative->value();
            }
        }

        return null;
    }

    /**
     * The directory whose pruning keeps a walk from the project root from ever
     * reaching this path: the outermost one among the path itself, when it is
     * a directory, and its ancestors. Null when the walk reaches it, or when
     * the path lies outside the project root, which no walk from it prunes.
     *
     * A walk asks {@see match()} of each directory it is about to enter, so a
     * path is out of its reach exactly when one of the directories above it
     * matches. A file is never asked about itself; neither is it here.
     *
     * @return ?string the directory relative to the project root
     */
    public function prunedAncestor(AbsolutePath $path): ?string
    {
        $relative = $path->tryRelativizeTo($this->projectRoot);
        if ($relative === null) {
            return null;
        }

        $outermost = null;
        $candidate = $path->isDirectory() ? $relative : $relative->parent();
        while ($candidate !== null) {
            if ($this->match($this->projectRoot->joinRelative($candidate)) !== null) {
                $outermost = $candidate->value();
            }
            $candidate = $candidate->parent();
        }

        return $outermost;
    }

    public function matchesPattern(PathPattern $pattern, AbsolutePath $directory): bool
    {
        $relative = $directory->tryRelativizeTo($this->projectRoot);

        return $relative !== null && $pattern->matches($relative);
    }

    /**
     * Whether pruning this directory prevents a sound unmatched judgement.
     *
     * Literal selectors can be located below the pruned path. An arbitrary
     * regex cannot be disproved without visiting the subtree, so it remains
     * unjudgeable whenever any candidate subtree is hidden from the probe.
     */
    public function hidesPossibleMatch(PathPattern $pattern, AbsolutePath $directory): bool
    {
        $relative = $directory->tryRelativizeTo($this->projectRoot);
        if ($relative === null) {
            return false;
        }

        if ($pattern->definition->kind === SelectorKind::Regex) {
            return true;
        }

        return str_starts_with($pattern->definition->value, $relative->value() . '/');
    }
}
