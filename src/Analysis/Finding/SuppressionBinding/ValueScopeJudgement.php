<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Core\Util\GlobSyntax;

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
 * PSR-4 map and the paths as plain data. Reading the manifest here instead
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
 * honest answer is silence. `tests/Legacy` anchors at `tests/`, which
 * `qmx check src/` did not analyse, so it is unjudgeable there and judgeable
 * on `qmx check src tests` — where a genuine miss is still reported.
 *
 * **The anchor, and the cost of using it.** The anchor is the value's literal
 * leading path or namespace segments, up to the first glob character. Its
 * deepest *existing* ancestor is where the subject would be, so
 * `tests/Gone/Deep` anchors at `tests/`. A value that starts with a glob has
 * no anchor and is never judged: the price of the safe direction is a genuine
 * miss left unreported until a run whose paths reach it. Silence about a stale
 * entry costs one uncleaned line of configuration; the opposite error fails a
 * `--fail-on=warning` pipeline over a correct one.
 *
 * **Namespaces are located through the PSR-4 map, prefixes included.** A
 * namespace value cannot be resolved by walking the disk, so the map answers
 * instead: the value is unjudgeable when some PSR-4 prefix compatible with its
 * head — either is a prefix of the other on `\` boundaries — is served from a
 * directory this run did not analyse. `Acme\Tests\Unit` against
 * `"Acme\\Tests\\": "tests/"` on `qmx check src/` is therefore silent, while
 * `Acme\Gone`, compatible with no unanalysed root, is judged.
 *
 * **A glob-headed namespace value is never judged, exactly as a glob-headed
 * path value is not.** `*\Gone` has no literal head, so there is no place to
 * locate it and no root to compare the run against. Deriving the answer from
 * the map instead — "compatible with every prefix, so judged once every root
 * was analysed" — makes the same value judgeable or not depending on the
 * caller's paths, and on a whole-project run it published an unmatched warning
 * for a value this class cannot locate at all. One shape of value, one answer,
 * on both branches.
 */
final readonly class ValueScopeJudgement
{
    /**
     * @param string $projectRoot the tree the configured values are written against
     * @param array<string, list<string>> $psr4Roots the manifest's PSR-4 map, `autoload-dev` included:
     *                                               a value's subject is located through it
     * @param list<string> $analyzedPaths the run's own paths
     */
    public function __construct(
        private string $projectRoot,
        private array $psr4Roots,
        private array $analyzedPaths,
    ) {}

    /**
     * Whether a path-shaped value — `suppress_paths`, a per-rule ledger
     * entry — names a place this run analysed.
     */
    public function judgesPathValue(string $pattern): bool
    {
        $anchor = self::literalHead($pattern, '/');

        if ($anchor === '') {
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
    public function judgesNamespaceValue(string $pattern): bool
    {
        $head = trim(self::literalHead($pattern, '\\'), '\\');

        // Same answer as the path branch gives an unanchored value: with no
        // literal head there is no place to locate, so there is nothing to
        // compare the run's paths against.
        if ($head === '') {
            return false;
        }

        // No map, no location: a project without a PSR-4 manifest is judged
        // by the project-wide predicate alone, which already closes the gate
        // when a manifest exists and cannot be read.
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

    /**
     * The value's literal leading segments: everything before the first glob
     * character, cut back to the last separator so a half-typed segment
     * (`src/Le*`) does not pose as a directory name.
     */
    private static function literalHead(string $value, string $separator): string
    {
        $trimmed = rtrim($value, $separator);
        $glob = strcspn($trimmed, GlobSyntax::CHARACTERS);

        if ($glob === \strlen($trimmed)) {
            return $trimmed;
        }

        $literal = substr($trimmed, 0, $glob);
        $lastSeparator = strrpos($literal, $separator);

        return $lastSeparator === false ? '' : substr($literal, 0, $lastSeparator);
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
