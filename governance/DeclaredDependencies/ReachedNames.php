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
 * What this does not see, for the reason the sibling gives about namespaces
 * written as strings: a name spelled in a string literal -- a dynamic call,
 * `call_user_func('mb_strlen', …)`, `define()`, a callable array -- and a
 * class constant another extension adds to someone else's class, such as
 * `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY`, whose member identifier is not a name
 * node at all and which reflection cannot attribute to the extension that
 * added it. Both are blind spots by construction, not oversights.
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

    /** @var array<string, array{reached: list<array{file: string, name: string, role: string}>, declarations: list<array{file: string, name: string, role: string}>}> */
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
     * @return array{reached: list<array{file: string, name: string, role: string}>, declarations: list<array{file: string, name: string, role: string}>}
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

        return self::$memo[$key] = ['reached' => $reached, 'declarations' => $declarations];
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
     * @return NodeVisitorAbstract&object{reached: list<array{name: string, role: string, shadowedBy: ?string}>, declared: list<array{short: string, qualified: string, role: string}>}
     */
    private static function collector(): object
    {
        return new class extends NodeVisitorAbstract {
            /** @var list<array{name: string, role: string, shadowedBy: ?string}> */
            public array $reached = [];

            /** @var list<array{short: string, qualified: string, role: string}> */
            public array $declared = [];

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
