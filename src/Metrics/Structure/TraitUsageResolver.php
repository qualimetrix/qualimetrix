<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;

/**
 * Resolves same-file trait usages for unused private member detection.
 *
 * When a class uses a trait defined in the same file, this resolver scans the trait's
 * method bodies for member references ($this->method(), $this->prop, self::CONST, etc.)
 * and records them in the class's usage sets to prevent false positives.
 *
 * Supports recursive trait resolution (trait using another trait in the same file)
 * with cycle detection. Cross-file trait resolution is not supported.
 */
final readonly class TraitUsageResolver
{
    use UsageTrackingTrait;
    /**
     * @param array<string, Trait_> $traitDefinitions Same-file trait definitions keyed by FQN
     * @param string|null $currentNamespace Current namespace context for name resolution
     */
    public function __construct(
        private array $traitDefinitions,
        private ?string $currentNamespace,
    ) {}

    /**
     * Resolve trait usages for a class/enum and record member references in the class data.
     *
     * @param Node\Stmt[] $classStmts Statements of the class/enum body
     * @param UnusedPrivateClassData $data Class data to record usages into
     */
    public function resolve(array $classStmts, UnusedPrivateClassData $data): void
    {
        $this->resolveRecursive($classStmts, $data, []);
    }

    /**
     * Recursively resolve trait usages, tracking already-resolved traits to prevent cycles.
     *
     * @param Node\Stmt[] $stmts Class or trait statements
     * @param list<string> $resolvedFqns FQNs already resolved (cycle prevention)
     */
    private function resolveRecursive(array $stmts, UnusedPrivateClassData $data, array $resolvedFqns): void
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $traitName) {
                $traitNode = $this->findTraitDefinition($traitName);
                if ($traitNode === null) {
                    continue;
                }

                $traitFqn = $this->getTraitFqn($traitNode);
                if (\in_array($traitFqn, $resolvedFqns, true)) {
                    continue; // Prevent infinite recursion
                }

                $resolvedFqns[] = $traitFqn;

                // Scan trait method bodies for usages
                $this->scanTraitForUsages($traitNode, $data);

                // Recursively resolve traits used by this trait
                $this->resolveRecursive($traitNode->stmts, $data, $resolvedFqns);
            }
        }
    }

    /**
     * Find a trait definition from the same-file definitions map.
     *
     * Matches by exact FQN, namespace-prefixed name, or short name (last segment).
     */
    private function findTraitDefinition(Name $traitName): ?Trait_
    {
        $requestedName = $traitName->toString();

        // Try exact FQN match first
        if (isset($this->traitDefinitions[$requestedName])) {
            return $this->traitDefinitions[$requestedName];
        }

        // Try with current namespace prefix
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $fqn = $this->currentNamespace . '\\' . $requestedName;
            if (isset($this->traitDefinitions[$fqn])) {
                return $this->traitDefinitions[$fqn];
            }
        }

        // Try matching by short name (last segment)
        $requestedShort = $this->getShortName($requestedName);
        foreach ($this->traitDefinitions as $fqn => $trait) {
            if ($this->getShortName($fqn) === $requestedShort) {
                return $trait;
            }
        }

        return null;
    }

    /**
     * Scan all method bodies in a trait for member usages ($this->method(), self::CONST, etc.).
     */
    private function scanTraitForUsages(Trait_ $trait, UnusedPrivateClassData $data): void
    {
        $finder = new NodeFinder();

        foreach ($trait->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }

            if ($stmt->stmts === null) {
                continue;
            }

            // Find all usage nodes within the method body
            $nodes = $finder->find($stmt->stmts, fn(Node $node): bool => $node instanceof MethodCall
                    || $node instanceof StaticCall
                    || $node instanceof PropertyFetch
                    || $node instanceof StaticPropertyFetch
                    || $node instanceof ClassConstFetch);

            foreach ($nodes as $usageNode) {
                $this->trackUsage($usageNode, $data);
            }
        }
    }

    private function getTraitFqn(Trait_ $trait): string
    {
        $shortName = $trait->name?->toString() ?? '';
        foreach ($this->traitDefinitions as $fqn => $t) {
            if ($t === $trait) {
                return $fqn;
            }
        }

        return $shortName;
    }

    private function getShortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos !== false ? substr($fqn, $pos + 1) : $fqn;
    }

    /**
     * Collect all trait definitions from a file's root AST nodes.
     *
     * @param Node[] $rootNodes Root AST nodes of the file
     *
     * @return array<string, Trait_> Trait definitions keyed by FQN
     */
    public static function collectTraitDefinitions(array $rootNodes): array
    {
        $definitions = [];
        $finder = new NodeFinder();
        $traits = $finder->findInstanceOf($rootNodes, Trait_::class);

        foreach ($traits as $trait) {
            \assert($trait instanceof Trait_);
            if ($trait->name === null) {
                continue;
            }

            $namespace = self::resolveTraitNamespace($trait, $rootNodes);
            $shortName = $trait->name->toString();
            $fqn = ($namespace !== null && $namespace !== '')
                ? $namespace . '\\' . $shortName
                : $shortName;

            $definitions[$fqn] = $trait;
        }

        return $definitions;
    }

    /**
     * Resolve the namespace for a trait node by finding its enclosing namespace in the AST.
     *
     * @param Node[] $rootNodes
     */
    private static function resolveTraitNamespace(Trait_ $trait, array $rootNodes): ?string
    {
        foreach ($rootNodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt === $trait) {
                        return $node->name?->toString() ?? '';
                    }
                }
            }
        }

        return null;
    }
}
