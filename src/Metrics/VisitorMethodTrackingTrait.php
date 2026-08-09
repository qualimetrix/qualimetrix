<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics;

use PhpParser\Node;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Provides common FQN-building and class-like node detection methods
 * for metric visitors that track methods, functions, and closures.
 *
 * Expects the using class to declare these properties:
 * - ?string $currentNamespace
 * - ?string $currentClass
 * - int $closureCounter
 */
trait VisitorMethodTrackingTrait
{
    /** @var array<string, int> */
    private array $callableTraversalKeyCounters = [];

    private function resetCallableTraversalKeys(): void
    {
        $this->callableTraversalKeyCounters = [];
    }

    private function createCallableTraversalKey(string $logicalFqn, int $startFilePos): string
    {
        $base = $logicalFqn . '@' . $startFilePos;
        $ordinal = $this->callableTraversalKeyCounters[$base] ?? 0;
        $this->callableTraversalKeyCounters[$base] = $ordinal + 1;

        return $base . '#' . $ordinal;
    }

    /**
     * Creates the final declaration-aware callable payload from a visitor's
     * private traversal record. The record is intentionally not exposed as a
     * transport contract: old FQN maps may remain only for file-level bags.
     *
     * @param array{namespace: ?string, class: ?string, method: string, startFilePos: int, sourceLine: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int} $info
     */
    private function createCallableWithMetrics(
        array $info,
        RelativePath $file,
        MetricBag $metrics,
        ?int $ordinal = null,
    ): CallableWithMetrics {
        $namespace = $info['namespace'] ?? '';
        $isAnonymous = $info['kind'] === CallableKind::AnonymousCallable;
        $logical = $info['class'] !== null && !$isAnonymous
            ? SymbolPath::forMethod($namespace, $info['class'], $info['method'])
            : SymbolPath::forGlobalFunction($namespace, $info['method']);

        $lexicalClassContext = null;
        $classAggregationOwner = null;
        if ($info['class'] !== null && $info['classStartFilePos'] !== null) {
            $classPath = SymbolPath::forClass($namespace, $info['class']);
            $lexicalClassContext = new DeclarationPath($classPath, $file, $info['classStartFilePos']);

            if (\in_array($info['kind'], [CallableKind::Method, CallableKind::PropertyHook], true)
                && !str_starts_with($info['class'], '{anonymous@')
            ) {
                $classAggregationOwner = new LogicalClassPath($classPath);
            }
        }

        return new CallableWithMetrics(
            declarationPath: new DeclarationPath($logical, $file, $info['startFilePos'], $ordinal),
            kind: $info['kind'],
            anonymousSyntax: $info['anonymousSyntax'],
            lexicalClassContext: $lexicalClassContext,
            classAggregationOwner: $classAggregationOwner,
            metrics: $metrics,
            sourceLine: $info['sourceLine'],
        );
    }

    /**
     * @param array<string, array{namespace: ?string, class: ?string, method: string, startFilePos: int, sourceLine: int, kind: CallableKind, anonymousSyntax: ?string, classStartFilePos: ?int}> $infos
     *
     * @return array<string, int|null>
     */
    private function callableCollisionOrdinals(array $infos): array
    {
        $groups = [];
        foreach ($infos as $key => $info) {
            $group = implode("\0", [
                $info['namespace'] ?? '',
                $info['class'] ?? '',
                $info['method'],
                (string) $info['startFilePos'],
                $info['kind']->value,
            ]);
            $groups[$group][] = $key;
        }

        $ordinals = array_fill_keys(array_keys($infos), null);
        foreach ($groups as $keys) {
            if (\count($keys) < 2) {
                continue;
            }

            foreach ($keys as $ordinal => $key) {
                $ordinals[$key] = $ordinal;
            }
        }

        return $ordinals;
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, array{logicalFqn: string}> $infos
     *
     * @return array<string, mixed>
     */
    private function projectLogicalMetricMap(array $metrics, array $infos): array
    {
        $projected = [];
        foreach ($metrics as $key => $value) {
            $projected[$infos[$key]['logicalFqn'] ?? $key] = $value;
        }

        return $projected;
    }

    private function buildMethodFqn(string $methodName): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = $methodName;

        return implode('', $parts);
    }

    private function buildFunctionFqn(string $functionName): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $functionName;
        }

        return $functionName;
    }

    private function buildClosureFqn(): string
    {
        $parts = [];

        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            $parts[] = $this->currentNamespace;
        }

        if ($this->currentClass !== null) {
            if ($parts !== []) {
                $parts[] = '\\';
            }
            $parts[] = $this->currentClass;
        }

        $parts[] = '::';
        $parts[] = '{closure#' . $this->closureCounter . '}';

        return implode('', $parts);
    }

    private function buildAnonymousClassName(int $startFilePos): string
    {
        return '{anonymous@' . $startFilePos . '}';
    }

    /**
     * Extracts class name from class-like nodes (class, interface, trait, enum).
     * Returns null for anonymous classes or non-class-like nodes.
     */
    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Node\Stmt\Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Node\Stmt\Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    /**
     * Checks if node is a class-like type (class, interface, trait, enum).
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\Class_
            || $node instanceof Node\Stmt\Interface_
            || $node instanceof Node\Stmt\Trait_
            || $node instanceof Node\Stmt\Enum_;
    }
}
