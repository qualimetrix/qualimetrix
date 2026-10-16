<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * Combines measured run judgement with each configured value's literal location.
 *
 * Regex values have no literal anchor. Path regex needs measured selector
 * completeness without authored removal; namespace regex also needs open
 * namespace claims and a declared production autoload. A complete named PHP
 * roster can provide the same measured completeness as a directory selection.
 * Generated removal closes namespace claims while leaving selector completeness
 * open, so it does not by itself withhold path regex judgement.
 *
 * Literal paths use their deepest existing ancestor: `tests/Gone/Deep` anchors
 * at `tests/`. A run that did not analyse that location cannot distinguish
 * removed code from code it never looked at. Authored removed-entry anchors
 * likewise withhold judgement of literal paths beneath them.
 *
 * Literal namespaces are located through compatible PSR-4 prefixes, including
 * development roots. A prefix compatible on `\` boundaries can serve the
 * value's subject, so an unanalysed compatible root withholds judgement even
 * when namespace claims remain open. Without a declared production autoload,
 * no namespace value is judged; path values retain their on-disk location.
 */
final readonly class ValueScopeJudgement
{
    /**
     * @param string $projectRoot the tree the configured values are written against
     * @param array<string, list<string>> $psr4Roots the manifest's PSR-4 map, `autoload-dev` included:
     *                                               a value's subject is located through it
     * @param list<string> $analyzedPaths the run's own paths
     * @param bool $projectDeclared whether the manifest declares a readable production autoload;
     *                              without one no namespace value is located, and none is judged
     */
    public function __construct(
        private string $projectRoot,
        private array $psr4Roots,
        private array $analyzedPaths,
        private bool $projectDeclared,
        private ProjectScopeJudgement $scope,
    ) {}

    /**
     * Whether a path-shaped value — `suppress_paths`, a per-rule ledger
     * entry — names a place this run analysed.
     */
    public function judgesPathValue(PathPattern $pattern): bool
    {
        if ($pattern->definition->kind === SelectorKind::Regex) {
            return $this->scope->judgesExcludeSelectors() && !$this->hasAuthoredRemoval();
        }

        $anchor = $pattern->definition->value;
        if ($this->underRemovedEntry($anchor)) {
            return false;
        }

        $root = self::normalize($this->projectRoot);
        $candidate = $root . '/' . trim($anchor, '/');

        // The subject need not exist: `src/Gone` is exactly the value this
        // channel is for. Its deepest existing ancestor is where it would
        // have been, and that is the location the run either analysed or did
        // not.
        while (!file_exists($candidate) && \strlen($candidate) > \strlen($root)) {
            $candidate = \dirname($candidate);
        }

        return $this->isWithinAnalysed($candidate);
    }

    /**
     * Whether a namespace-shaped value names code this run could have
     * declared.
     */
    public function judgesNamespaceValue(NamespacePattern $pattern): bool
    {
        // Before the regex branch: a whole-tree run would otherwise judge a
        // regex namespace value here while refusing every literal one.
        if (!$this->projectDeclared || !$this->scope->judgesNamespaceClaims()) {
            return false;
        }

        if ($pattern->definition->kind === SelectorKind::Regex) {
            return $this->scope->judgesExcludeSelectors() && !$this->hasAuthoredRemoval();
        }

        return !$this->hasUnanalysedNamespaceRoot(trim($pattern->definition->value, '\\'), $this->projectRoot, $this->psr4Roots);
    }

    /** @param array<string, list<string>> $psr4Roots */
    private function hasUnanalysedNamespaceRoot(string $head, string $projectRoot, array $psr4Roots): bool
    {
        foreach ($psr4Roots as $prefix => $paths) {
            if (!self::compatible($head, trim($prefix, '\\'))) {
                continue;
            }

            if ($this->hasUnanalysedDirectory($paths, $projectRoot)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $paths */
    private function hasUnanalysedDirectory(array $paths, string $projectRoot): bool
    {
        foreach ($paths as $path) {
            $directory = self::normalize($projectRoot) . '/' . trim($path, '/');
            if (file_exists($directory) && !$this->isWithinAnalysed($directory)) {
                return true;
            }
        }

        return false;
    }

    private function hasAuthoredRemoval(): bool
    {
        foreach ($this->scope->excludeSelectors() as $selector) {
            if ($selector->removedEntries !== []) {
                return true;
            }
        }

        return false;
    }

    private function underRemovedEntry(string $path): bool
    {
        foreach ($this->scope->excludeSelectors() as $selector) {
            foreach ($selector->removedEntries as $removed) {
                if ($path === $removed || str_starts_with($path, rtrim($removed, '/') . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether two namespace names can name the same code: one is a prefix of
     * the other on a `\` boundary, or they are equal. A PSR-4 map may serve
     * the root namespace (`"": "src/"`), and that prefix can hold anything.
     *
     * There is deliberately no branch for an empty head: a value with no
     * literal head is refused by {@see judgesNamespaceValue()} before it gets
     * here, and a permissive branch left standing for it is how "no anchor"
     * came to read as "compatible with everything, therefore judged".
     */
    private static function compatible(string $head, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }

        return $head === $prefix
            || str_starts_with($head . '\\', $prefix . '\\')
            || str_starts_with($prefix . '\\', $head . '\\');
    }

    private function isWithinAnalysed(string $location): bool
    {
        $target = self::resolve($location);

        foreach ($this->analyzedPaths as $analyzedPath) {
            $analyzed = self::resolve($analyzedPath);

            if ($target === $analyzed || str_starts_with($target, $analyzed . '/')) {
                return true;
            }
        }

        return false;
    }

    /** The location as the filesystem knows it, or as written when it does not exist. */
    private static function resolve(string $path): string
    {
        $resolved = realpath($path);

        return self::normalize($resolved === false ? $path : $resolved);
    }

    private static function normalize(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
