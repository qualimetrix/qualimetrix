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
 * text sweep cannot tell a call from a word in a docblock. Measured on this
 * tree, the regular expression this replaced produced 971 candidate function
 * names that are not functions at all — `the(`, `and(`, `if(`, `match(`,
 * `__construct(` — and every one of them was dropped by a `function_exists()`
 * that also drops a real call into an extension this PHP lacks. The two were
 * indistinguishable, so neither could be refused.
 *
 * Roles come from the syntax, which is the only place they are a fact:
 * `foo()` is a call, `FOO` in expression position is a constant read, and a
 * name in any other position is a class-like reference. Trait uses, attribute
 * names, method calls and strings are distinct nodes, so they cannot leak in.
 *
 * Two shapes need care and get it here:
 *
 * - an unqualified call inside a namespace falls back to the global function
 *   only when the namespaced candidate does not exist, so the namespaced
 *   candidate is checked against the functions the tree itself declares. Were
 *   it not, the day this repository declares a global-ish helper the control
 *   would refuse its own code.
 * - an import nothing references resolves to no name at all, so `use`
 *   statements are read separately, with their own role — `use function` and
 *   `use const` are not class imports.
 */
final class ReachedNames
{
    public const string FUNCTION = 'function';
    public const string CLASS_LIKE = 'class';
    public const string CONSTANT = 'constant';

    /**
     * @param list<string> $files absolute paths
     *
     * @return list<array{file: string, name: string, role: string}>
     */
    public static function in(array $files): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        /** @var list<array{file: string, name: string, role: string, shadowedBy: ?string}> $raw */
        $raw = [];
        /** @var array<string, true> $declaredFunctions */
        $declaredFunctions = [];

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

            foreach ($collector->declaredFunctions as $declared => $ignored) {
                $declaredFunctions[$declared] = true;
            }

            foreach ($collector->reached as $record) {
                $raw[] = ['file' => $file, ...$record];
            }
        }

        $reached = [];

        foreach ($raw as $record) {
            $shadowedBy = $record['shadowedBy'];

            if ($shadowedBy !== null && isset($declaredFunctions[strtolower($shadowedBy)])) {
                continue;
            }

            $reached[] = ['file' => $record['file'], 'name' => $record['name'], 'role' => $record['role']];
        }

        return $reached;
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
     * @return NodeVisitorAbstract&object{reached: list<array{name: string, role: string, shadowedBy: ?string}>, declaredFunctions: array<string, true>}
     */
    private static function collector(): object
    {
        return new class extends NodeVisitorAbstract {
            /** @var list<array{name: string, role: string, shadowedBy: ?string}> */
            public array $reached = [];

            /** @var array<string, true> */
            public array $declaredFunctions = [];

            /** @var array<int, true> */
            private array $claimed = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Function_) {
                    $this->declaredFunctions[strtolower($node->namespacedName?->toString() ?? $node->name->toString())] = true;
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
