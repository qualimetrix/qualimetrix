<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DeclaredDependencies;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Every name the shipped tree reaches, and what it reaches it as.
 *
 * The group already reads one population two ways; this is the second half of
 * the same argument {@see ShippedTree} makes. What a file reaches has to be
 * read from the parse, not from a regular expression over its text, because a
 * text sweep cannot tell a call from a word in a docblock -- most of what the
 * expression this replaced produced were exactly that, and they were dropped
 * through the same branch as a real call into a missing extension, so neither
 * could be refused.
 *
 * Roles come from the syntax, which is the only place they are a fact:
 * `foo()` is a call, `FOO` in expression position is a constant read, and a
 * name in any other position is a class-like reference. Trait uses, attribute
 * names, method calls and strings are distinct nodes, so they cannot leak in.
 *
 * Two shapes need care and get it here:
 *
 * - an unqualified call or constant read inside a namespace falls back to the
 *   global one only when the namespaced candidate does not exist, so the
 *   namespaced candidate is checked against what the tree itself declares --
 *   functions against declared functions, constants against declared
 *   constants. Checking one against the other loses the reach entirely: a
 *   function named `T_COMMENT` would erase every read of the tokenizer
 *   constant by that name.
 * - an import nothing references resolves to no name at all, so `use`
 *   statements are read separately, with their own role: `use function` and
 *   `use const` are not class imports.
 *
 * The shadow check is tree-wide rather than per-file, which is what PHP's own
 * fallback rule is: a declaration is visible wherever its file is loaded, and
 * nothing static can say which files a given call has loaded. That
 * approximation can hide a real reach behind a declaration that never runs,
 * so {@see self::declarationsIn()} hands the declarations back and
 * {@see ShippedCodeRunsOnlyOnDeclaredExtensionsTest} refuses the collision
 * itself rather than letting it silence a name.
 *
 * A name spelled inside a string literal is not one of those roles and never
 * becomes one. The language did not resolve it, so calling it a reach would be
 * a guess. Class-shaped ones are still worth reading, because a class reached
 * through `class_exists()`, a service id or a callable array is spelled
 * exactly that way, so {@see self::quotedClassNamesIn()} hands them over
 * through a door of their own, labelled as candidates. Which of them mean
 * anything is not decided here:
 * {@see ShippedCodeReachesOnlyDeclaredPackagesTest} judges them under a
 * weaker stance than it applies to the roles above, and says there why the
 * two differ.
 *
 * What nothing here sees: a name built by concatenation or reached by
 * reflection; a function or constant named in a string, such as
 * `call_user_func('mb_strlen', …)` or `define()`, which is a bare word no
 * shape can tell from prose the way a backslash-separated name can; and a class
 * constant another extension adds to someone else's class, such as
 * `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY`, whose member identifier is not a name
 * node at all and which reflection cannot attribute to the extension that
 * added it. Blind spots by construction, not oversights.
 *
 * That last one is closed as unclosable rather than left open, because the
 * reason is a property of what PHP exposes and not of how hard anyone looked.
 * Reflection does not decline to attribute such a constant -- it attributes it
 * to the WRONG owner, and confidently: on PHP 8.4
 * `(new ReflectionClass('PDO'))->getReflectionConstants()` reports
 * `MYSQL_ATTR_USE_BUFFERED_QUERY` with `getDeclaringClass()` of `PDO` and an
 * extension of `PDO`, while asking `pdo_mysql` -- the extension that really
 * adds it -- for its own constants yields none. A reader cannot tell that
 * answer apart from the true one for `PDO::ATTR_ERRMODE`, so no
 * reflection-based cure exists. The
 * only remaining cure is a hand-kept `PDO::{MYSQL,PGSQL,SQLITE}_*` prefix
 * table -- a list in a file standing in for a question put to PHP, which is
 * the shape this group exists to avoid.
 *
 * It is also narrower than it looks. `pdo_mysql` owns the class `Pdo\Mysql`
 * (`getExtensionName()` returns `pdo_mysql`), so the PHP 8.4 spelling
 * `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` is attributed correctly through the
 * class-like channel above, with no member identifier needed. Only the legacy
 * `PDO::MYSQL_*` spelling is blind, and the extension-owned classes this tree
 * reads constants from take those constants from the extension that provides
 * the class.
 */
final class ReachedNames
{
    public const string FUNCTION = 'function';
    public const string CLASS_LIKE = 'class';
    public const string CONSTANT = 'constant';

    /**
     * Reserved words the parser reports as constant reads. They are literals
     * fixed by the grammar, not constants any extension provides, and the
     * three of them are the whole set.
     */
    private const array LITERALS = ['true', 'false', 'null'];

    /**
     * What a quoted string has to look like before it is offered as a class
     * name: two or more PHP identifier segments separated by single
     * backslashes.
     *
     * A filter rather than a judgement. It decides which literals are worth
     * offering, never which ones mean anything -- that is the caller's
     * question, and the caller answers it by asking whether an installed
     * package owns the name.
     *
     * The segments follow PHP's identifier grammar rather than the
     * convention that they are capitalized. Composer carries packages that
     * do not capitalize -- `phpDocumentor\Reflection\`, `voku\helper\` --
     * and under a capitalized-only shape a quoted name of such a package is
     * not a candidate at all, so the loose stance ignores it and nothing
     * anywhere reports it. Measured on this tree, the wider grammar returns
     * the same population the narrow one did, so the narrowness bought
     * nothing and cost a blind spot.
     *
     * `D` is load-bearing: without it `$` also matches before a trailing
     * newline, so a literal ending in one would be offered as a class name
     * and attributed by prefix to a package it merely starts with.
     */
    private const string CLASS_NAME_SHAPE = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D';

    /** @var array<string, array{reached: list<array{file: string, name: string, role: string}>, declarations: list<array{file: string, name: string, role: string}>, quoted: list<array{file: string, name: string}>}> */
    private static array $memo = [];

    /**
     * @param list<string> $files absolute paths
     *
     * @return list<array{file: string, name: string, role: string}>
     */
    public static function in(array $files): array
    {
        return self::read($files)['reached'];
    }

    /**
     * Functions and constants the shipped tree declares itself, by the short
     * name each one would shadow in its own namespace.
     *
     * @param list<string> $files absolute paths
     *
     * @return list<array{file: string, name: string, role: string}>
     */
    public static function declarationsIn(array $files): array
    {
        return self::read($files)['declarations'];
    }

    /**
     * Quoted literals shaped like a namespaced class name, deduplicated per
     * file, with any leading backslash removed so both spellings of one name
     * arrive the same way.
     *
     * A candidate, not a reach. This is the one thing this class reports that
     * the language did not resolve, which is why it has a door of its own
     * instead of a role: a caller that wants resolved names cannot be handed
     * guesses by accident, and a caller that wants these has to ask.
     *
     * @param list<string> $files absolute paths
     *
     * @return list<array{file: string, name: string}>
     */
    public static function quotedClassNamesIn(array $files): array
    {
        return self::read($files)['quoted'];
    }

    /**
     * Names reached that live in no namespace, deduplicated, each carrying one
     * file that reaches it. A global name is the whole population of the
     * extension question: an extension's functions, classes and constants are
     * never namespaced.
     *
     * @param list<string> $files absolute paths
     *
     * @return list<array{file: string, name: string, role: string}>
     */
    public static function globalsIn(array $files): array
    {
        $seen = [];

        foreach (self::in($files) as $record) {
            if (str_contains($record['name'], '\\')) {
                continue;
            }

            $seen[$record['role'] . ' ' . $record['name']] ??= $record;
        }

        ksort($seen);

        return array_values($seen);
    }

    /**
     * @param list<string> $files
     *
     * @return array{reached: list<array{file: string, name: string, role: string}>, declarations: list<array{file: string, name: string, role: string}>, quoted: list<array{file: string, name: string}>}
     */
    private static function read(array $files): array
    {
        // The group asks several questions of one tree, and each would
        // otherwise pay for its own parse of every file. The key covers each
        // file's size and modification time as well as its path, so a tree
        // that changed under the same file list is re-read rather than
        // answered from a parse of what it used to say.
        $signature = [];

        foreach ($files as $file) {
            $size = filesize($file);
            $modified = filemtime($file);

            $signature[] = \sprintf(
                '%s:%s:%s',
                $file,
                $size === false ? 'unreadable' : (string) $size,
                $modified === false ? 'unreadable' : (string) $modified,
            );
        }

        $key = hash('xxh128', implode("\0", $signature));

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        /** @var list<array{file: string, name: string, role: string, shadowedBy: ?string}> $raw */
        $raw = [];
        /** @var list<array{file: string, name: string, role: string}> $declarations */
        $declarations = [];
        /** @var array<string, array<string, true>> $declaredByRole */
        $declaredByRole = [self::FUNCTION => [], self::CONSTANT => []];
        /** @var list<array{file: string, name: string}> $quoted */
        $quoted = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                throw new RuntimeException('Unreadable file in the shipped tree: ' . $file);
            }

            $statements = $parser->parse($source);

            if ($statements === null) {
                throw new RuntimeException('Unparsable file in the shipped tree: ' . $file);
            }

            $collector = self::collector();

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($collector);
            $traverser->traverse($statements);

            foreach ($collector->declared as $declared) {
                $declaredByRole[$declared['role']][self::fold($declared['qualified'], $declared['role'])] = true;
                $declarations[] = ['file' => $file, 'name' => $declared['short'], 'role' => $declared['role']];
            }

            foreach ($collector->reached as $record) {
                $raw[] = ['file' => $file, ...$record];
            }

            $candidates = [];

            foreach ($collector->strings as $literal) {
                if (preg_match(self::CLASS_NAME_SHAPE, $literal) === 1) {
                    $candidates[ltrim($literal, '\\')] = true;
                }
            }

            foreach (array_keys($candidates) as $candidate) {
                $quoted[] = ['file' => $file, 'name' => $candidate];
            }
        }

        $reached = [];

        foreach ($raw as $record) {
            if ($record['role'] === self::CONSTANT && \in_array(strtolower($record['name']), self::LITERALS, true)) {
                continue;
            }

            $shadowedBy = $record['shadowedBy'];

            if ($shadowedBy !== null && isset($declaredByRole[$record['role']][self::fold($shadowedBy, $record['role'])])) {
                continue;
            }

            $reached[] = ['file' => $record['file'], 'name' => $record['name'], 'role' => $record['role']];
        }

        return self::$memo[$key] = ['reached' => $reached, 'declarations' => $declarations, 'quoted' => $quoted];
    }

    /**
     * PHP resolves a function name without regard to case and a constant name
     * with it, so a shipped `const t_comment` does not shadow `T_COMMENT`
     * while a `function T_COMMENT` does shadow `token_get_all`'s neighbours.
     * Folding both the same way loses the reach on one side.
     */
    private static function fold(string $name, string $role): string
    {
        return $role === self::CONSTANT ? $name : strtolower($name);
    }

    /**
     * @return NodeVisitorAbstract&object{reached: list<array{name: string, role: string, shadowedBy: ?string}>, declared: list<array{short: string, qualified: string, role: string}>, strings: list<string>}
     */
    private static function collector(): object
    {
        return new class extends NodeVisitorAbstract {
            /** @var list<array{name: string, role: string, shadowedBy: ?string}> */
            public array $reached = [];

            /** @var list<array{short: string, qualified: string, role: string}> */
            public array $declared = [];

            /** @var list<string> every quoted literal, unfiltered */
            public array $strings = [];

            /** @var array<int, true> */
            private array $claimed = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Function_) {
                    $this->declare($node->name->toString(), $node->namespacedName?->toString(), ReachedNames::FUNCTION);
                }

                // A free-standing `const X = …`. A class constant is a
                // different node and shadows nothing global.
                if ($node instanceof Node\Stmt\Const_) {
                    foreach ($node->consts as $const) {
                        $this->declare($const->name->toString(), $const->namespacedName?->toString(), ReachedNames::CONSTANT);
                    }
                }

                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                    $this->claim($node->name, ReachedNames::FUNCTION);
                }

                if ($node instanceof Node\Expr\ConstFetch) {
                    $this->claim($node->name, ReachedNames::CONSTANT);
                }

                if ($node instanceof Node\Stmt\GroupUse) {
                    foreach ($node->uses as $use) {
                        $type = $use->type === Node\Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                        $this->record($node->prefix->toString() . '\\' . $use->name->toString(), self::roleOfImport($type), null);
                    }
                }

                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $this->record($use->name->toString(), self::roleOfImport($node->type), null);
                    }
                }

                // Everything the name resolver resolved and nothing above
                // claimed is a class-like reference: a type, an instantiation,
                // a catch, an attribute, a parent, an implemented interface.
                // Defaulting here rather than enumerating those positions is
                // deliberate — an enumeration silently misses the position
                // nobody thought of, and this direction misses nothing.
                if ($node instanceof Node\Name\FullyQualified && !isset($this->claimed[spl_object_id($node)])) {
                    $this->record($node->toString(), ReachedNames::CLASS_LIKE, null);
                }

                // Collected whole and filtered afterwards: what makes a
                // literal a candidate is a shape the caller's question
                // defines, not a fact about this node.
                if ($node instanceof Node\Scalar\String_) {
                    $this->strings[] = $node->value;
                }

                return null;
            }

            private function declare(string $short, ?string $qualified, string $role): void
            {
                $this->declared[] = ['short' => $short, 'qualified' => $qualified ?? $short, 'role' => $role];
            }

            private function claim(Node\Name $name, string $role): void
            {
                $this->claimed[spl_object_id($name)] = true;

                if ($name instanceof Node\Name\FullyQualified) {
                    $this->record($name->toString(), $role, null);

                    return;
                }

                // Unqualified: PHP tries the current namespace, then global.
                $namespaced = $name->getAttribute('namespacedName');
                $this->record(
                    $name->toString(),
                    $role,
                    $namespaced instanceof Node\Name ? $namespaced->toString() : null,
                );
            }

            private function record(string $name, string $role, ?string $shadowedBy): void
            {
                $this->reached[] = ['name' => $name, 'role' => $role, 'shadowedBy' => $shadowedBy];
            }

            private static function roleOfImport(int $type): string
            {
                return match ($type) {
                    Node\Stmt\Use_::TYPE_FUNCTION => ReachedNames::FUNCTION,
                    Node\Stmt\Use_::TYPE_CONSTANT => ReachedNames::CONSTANT,
                    default => ReachedNames::CLASS_LIKE,
                };
            }
        };
    }
}
