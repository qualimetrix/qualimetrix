<?php

declare(strict_types=1);

/**
 * How many statically enumerable `throw`s of `InvalidArgumentException` (or a
 * subclass of it) still sit outside the configuration-refusal carrier, across
 * `src/` and `bin/`.
 *
 * This is the fallback recognised by the round's own rule 1
 * (`docs/internal/plans/configuration-refusal/00-overview.md`): a general
 * `catch (InvalidArgumentException) -> exit 3` stays in every command, named as
 * a secondary signal and a named debt rather than an oversight, because the set
 * of throw sites is open (three independent enumeration attempts each missed a
 * different subset — see the same file, "Полнота и остаток"). This script
 * counts that debt's upper bound so a later change can be compared against it.
 *
 * **What it counts.** Two syntactic forms only, by AST:
 *   - `throw new InvalidArgumentException(...)` or `throw new <subclass>(...)`;
 *   - `throw $variable;` where `$variable` was assigned, somewhere earlier in
 *     the same function-like scope, from `new InvalidArgumentException(...)`
 *     or a subclass of it.
 *
 * **What it does not count, named rather than silently dropped:**
 *   - a throw built through a static factory method (`throw self::pcreFailure(...)`)
 *     — the source of `InvalidArgumentException` there is the factory body, one
 *     level removed from the `throw` statement itself;
 *   - a throw of a variable reassigned across branches, or assigned from
 *     anything but a literal `new` expression (a ternary, a method call, a
 *     parameter) — the single-assignment-of-a-`new`-expression form is what the
 *     round measured `03-catch-clauses.md` against, and a wider heuristic would
 *     move the number without changing what the plan's prose describes;
 *   - **reachability from the CLI.** A throw site counted here may be dead code,
 *     may sit behind a branch no configuration can reach, or may never run
 *     under the product's own entry points. This script has no call graph and
 *     does not build one — a rule-option-key reader in this same repository
 *     already established that a single method body's AST is as far as this
 *     class of script goes (`scripts/enumerate-rule-option-keys.php`). The
 *     number below is therefore an upper bound on the fallback's population,
 *     not a count of how much of it a user input can actually reach.
 *
 * **Population**: every `.php` file under `src/`, plus `bin/qmx` (the one file
 * in `bin/` and the one without a `.php` extension). `tests/`, `scripts/` and
 * `vendor/` are excluded — this is a statement about the product, not about the
 * scripts and tests that measure it.
 *
 * Usage: php scripts/enumerate-refusal-fallback.php
 */

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Walks the population, finds qualifying `throw` sites and prints them.
 */
final class RefusalFallbackEnumeration
{
    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    public static function main(): int
    {
        $enumeration = new self();
        $sites = $enumeration->scan();

        echo "# scripts/enumerate-refusal-fallback.php\n";
        echo "#\n";
        echo "# Counts statically enumerable `throw new InvalidArgumentException(...)` / `throw new <subclass>(...)`\n";
        echo "# / `throw \$variable` (single same-scope `new` assignment) across src/ and bin/qmx.\n";
        echo "#\n";
        echo "# Blind spots (named, not counted): static-factory throws (`throw self::foo(...)`); a thrown\n";
        echo "# variable assigned across branches or from anything but a literal `new` expression; and\n";
        echo "# reachability from the CLI, which this script neither computes nor claims — it has no call\n";
        echo "# graph, the same limit named in scripts/enumerate-rule-option-keys.php. The number below is an\n";
        echo "# upper bound on the fallback's population, useful only to compare against a later run of the\n";
        echo "# same command on the same population definition.\n";
        echo "#\n";
        echo "# Population: every .php file under src/, plus bin/qmx. Excludes tests/, scripts/, vendor/.\n";
        echo "#\n";
        echo "path:line\tclass\tform\n";

        foreach ($sites as $site) {
            echo $site['path'] . ':' . $site['line'] . "\t" . $site['class'] . "\t" . $site['form'] . "\n";
        }

        echo "#\n";
        echo '# total: ' . count($sites) . "\n";

        return 0;
    }

    /**
     * @return list<array{path: string, line: int, class: class-string, form: string}>
     */
    private function scan(): array
    {
        $root = dirname(__DIR__);
        $sites = [];

        foreach ($this->population($root) as $relativePath) {
            $absolute = $root . '/' . $relativePath;
            $code = file_get_contents($absolute);
            if ($code === false) {
                continue;
            }

            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
            if ($ast === null) {
                continue;
            }

            $resolved = (new NodeTraverser(new NameResolver()))->traverse($ast);

            foreach ($this->throwSitesIn($resolved) as $site) {
                $sites[] = ['path' => $relativePath, ...$site];
            }
        }

        usort($sites, static function (array $a, array $b): int {
            $byPath = $a['path'] <=> $b['path'];

            return $byPath !== 0 ? $byPath : $a['line'] <=> $b['line'];
        });

        return $sites;
    }

    /**
     * @return list<string> paths relative to the repository root
     */
    private function population(string $root): array
    {
        $paths = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src',
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = 'src/' . ltrim(str_replace($root . '/src', '', $file->getPathname()), '/');
        }

        $paths[] = 'bin/qmx';

        sort($paths);

        return $paths;
    }

    /**
     * @param array<Node> $ast
     *
     * @return list<array{line: int, class: class-string, form: string}>
     */
    private function throwSitesIn(array $ast): array
    {
        $sites = [];

        foreach ($this->finder->findInstanceOf($ast, Throw_::class) as $throw) {
            assert($throw instanceof Throw_);

            if ($throw->expr instanceof New_) {
                $class = $this->qualifyingClass($throw->expr);
                if ($class !== null) {
                    $sites[] = ['line' => $throw->getStartLine(), 'class' => $class, 'form' => 'throw new'];
                }

                continue;
            }

            if ($throw->expr instanceof Variable && is_string($throw->expr->name)) {
                $class = $this->classAssignedToVariable($ast, $throw, $throw->expr->name);
                if ($class !== null) {
                    $sites[] = ['line' => $throw->getStartLine(), 'class' => $class, 'form' => 'throw $variable'];
                }
            }
        }

        return $sites;
    }

    /**
     * @return class-string|null
     */
    private function qualifyingClass(New_ $new): ?string
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        $resolved = $new->class->getAttribute('resolvedName');
        $fqcn = $resolved instanceof Name ? $resolved->toString() : $new->class->toString();

        if (!class_exists($fqcn)) {
            return null;
        }

        /** @var class-string $fqcn */
        return is_a($fqcn, InvalidArgumentException::class, true) ? $fqcn : null;
    }

    /**
     * Finds the function-like enclosing `$throw` and looks for a single `new`
     * assignment to `$variableName` inside it, textually before the throw.
     *
     * @param array<Node> $ast
     *
     * @return class-string|null
     */
    private function classAssignedToVariable(array $ast, Throw_ $throw, string $variableName): ?string
    {
        $enclosing = $this->enclosingFunctionLike($ast, $throw);
        if ($enclosing === null) {
            return null;
        }

        $body = $enclosing->getStmts() ?? [];
        $matches = [];

        foreach ($this->finder->findInstanceOf($body, Assign::class) as $assign) {
            assert($assign instanceof Assign);

            if (!$assign->var instanceof Variable || $assign->var->name !== $variableName) {
                continue;
            }

            if ($assign->getStartLine() >= $throw->getStartLine()) {
                continue;
            }

            $matches[] = $assign;
        }

        if (count($matches) !== 1) {
            // Reassigned, or never assigned in this scope by a literal `new`
            // this walk can see: named blind spot, not counted.
            return null;
        }

        $only = $matches[0];

        return $only->expr instanceof New_ ? $this->qualifyingClass($only->expr) : null;
    }

    /**
     * @param array<Node> $ast
     */
    private function enclosingFunctionLike(array $ast, Throw_ $throw): ?FunctionLike
    {
        $best = null;

        foreach ($this->finder->findInstanceOf($ast, FunctionLike::class) as $candidate) {
            assert($candidate instanceof FunctionLike);

            $stmts = $candidate->getStmts();
            if ($stmts === null) {
                continue;
            }

            if (!in_array($throw, $this->finder->findInstanceOf($stmts, Throw_::class), true)) {
                continue;
            }

            // The innermost enclosing scope: the last (deepest) match found by
            // a top-down walk of the AST.
            $best = $candidate;
        }

        return $best;
    }
}

exit(RefusalFallbackEnumeration::main());
