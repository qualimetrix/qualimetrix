<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * Whether this run is wide enough to judge one configured value.
 *
 * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage} answers
 * about the run; this class answers about the pair (run, value), and the
 * second question is the one a channel about a value that bound to nothing
 * actually needs.
 *
 * **Why it lives with its consumer and not beside that predicate.** The two
 * belong to the same subject and would sit together, but the coarse owner
 * graph refuses the edge: `Analysis\Finding` importing from `Analysis\Run`
 * closes a cycle through `Analysis\Evidence\CircularDependency`, which the
 * generated allow graph rejects outright. A port introduced for dependency
 * inversion belongs to its consumer (ADR 0022), and this class has exactly
 * one — the suppression channel beside it. It is not a second copy of the
 * coverage predicate: it answers a different question, of a different
 * argument, and neither can be derived from the other.
 *
 * **The run's shape is handed over, not fetched.** The caller has already
 * read `composer.json` to answer the project-wide question, so it passes the
 * PSR-4 map, the paths and whether the manifest declares the project at all as
 * plain data. Reading the manifest here instead
 * would parse it once per configured value and give this class a second
 * opinion about what the run analysed. Measured on a plain repository
 * whose `composer.json` autoloads `src/` for production and `tests/` for dev:
 * `qmx check src/` is a whole-project run by the first question, so
 * `suppress_paths: [tests/Legacy]` — written for `qmx check .` and perfectly
 * correct — was reported as binding to nothing, three channels at once,
 * accusing the author of the caller's choice of path.
 *
 * **The question asked here is where the value's own subject lives.** A value
 * names a place; if that place can lie outside the analysed paths, this run
 * cannot tell "the code is gone" from "the code was not looked at", and the
 * honest answer is no finding — the value is named as unjudged instead. `tests/Legacy` anchors at `tests/`, which
 * `qmx check src/` did not analyse, so it is unjudgeable there and judgeable
 * on `qmx check src tests` — where a genuine miss is still reported.
 *
 * **The anchor, and the cost of using it.** Exact and subtree definitions carry
 * a literal path or namespace subject. Its
 * deepest *existing* ancestor is where the subject would be, so
 * `tests/Gone/Deep` anchors at `tests/`. A regex definition has no literal
 * anchor, so it is judged only when the run covers the complete project
 * universe. Silence about a stale entry on a partial run costs one uncleaned
 * line of configuration; the opposite error fails a `--fail-on=warning`
 * pipeline over a correct one.
 *
 * **Namespaces are located through the PSR-4 map, prefixes included.** A
 * namespace value cannot be resolved by walking the disk, so the map answers
 * instead: the value is unjudgeable when some PSR-4 prefix compatible with its
 * head — either is a prefix of the other on `\` boundaries — is served from a
 * directory this run did not analyse. `Acme\Tests\Unit` against
 * `"Acme\\Tests\\": "tests/"` on `qmx check src/` is therefore silent, while
 * `Acme\Gone`, compatible with no unanalysed root, is judged.
 *
 * **Regex definitions are judged only on a complete universe.** Their fragment
 * does not promise a locatable subject, so a partial run stays silent rather
 * than guessing where a match might have existed.
 *
 * **Without a declared production autoload no namespace value is judged.** The
 * PSR-4 map is the only thing that locates a namespace, and a project whose
 * manifest declares no readable production autoload has none for its own code:
 * `suppress_namespaces: [{subtree: Tests}]` on `qmx check src` may name code
 * under a directory the run never read, and "matched nothing" would be a
 * guess. A path value keeps its on-disk anchor and is judged as above. The
 * cost is the whole-tree run of such a project, where every namespace was in
 * reach and a miss would have been a fact; it is not reported either, and the
 * report names each namespace value that went unjudged.
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
    ) {}

    /**
     * Whether a path-shaped value — `suppress_paths`, a per-rule ledger
     * entry — names a place this run analysed.
     */
    public function judgesPathValue(PathPattern $pattern): bool
    {
        if ($pattern->definition->kind === SelectorKind::Regex) {
            return $this->coversCompleteUniverse();
        }

        $anchor = $pattern->definition->value;

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
        if (!$this->projectDeclared) {
            return false;
        }

        if ($pattern->definition->kind === SelectorKind::Regex) {
            return $this->coversCompleteUniverse();
        }

        $head = trim($pattern->definition->value, '\\');

        foreach ($this->psr4Roots as $prefix => $paths) {
            if (!self::compatible($head, trim($prefix, '\\'))) {
                continue;
            }

            foreach ($paths as $path) {
                $directory = self::normalize($this->projectRoot) . '/' . trim($path, '/');

                if (file_exists($directory) && !$this->isWithinAnalysed($directory)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function coversCompleteUniverse(): bool
    {
        $root = self::resolve($this->projectRoot);

        foreach ($this->analyzedPaths as $analyzedPath) {
            if (self::resolve($analyzedPath) === $root) {
                return true;
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
        $slashed = str_replace('\\', '/', $path);
        $trimmed = rtrim($slashed, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
