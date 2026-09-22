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
