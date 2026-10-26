<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation;

use ArrayAccess;
use Closure;
use LogicException;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\FunctionNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;
use Throwable;

/**
 * The reads and conditional operands one native evaluation of a formula entered.
 *
 * A private identity exception stops an absent strict read before PHP or a
 * function can coerce it. Eager sibling operands still run once so a reached
 * invalid value cannot be hidden by an independent absence. Native conditional
 * nodes retain sole ownership of branch selection. Callbacks capture run state
 * rather than this per-subject owner, and eager recovery points only forward,
 * so a completed trace does not wait for cyclic garbage collection.
 */
final class ComputedMetricBranchTrace
{
    /** A copy of the formula whose reads and conditional operands report entry. */
    public readonly Node $traced;

    /** @var array<string, true> */
    private array $entered = [];

    /** @var array<int, true> */
    private array $reached = [];

    /** @var array<string, true> */
    private array $visited = [];

    /** @var Closure(string): bool */
    private Closure $isPresent;

    private readonly LogicException $missing;

    private function __construct(private readonly Node $root, private readonly MetricLookup $metrics)
    {
        $metrics = $this->metrics;
        $this->isPresent = static fn(string $key): bool => isset($metrics[$key]);
        $this->missing = new LogicException('The evaluation reached an absent metric.');
        $this->traced = $this->copyOf($root, null);
    }

    public static function of(Node $root, MetricLookup $metrics): self
    {
        return new self($root, $metrics);
    }

    public function isMissing(Throwable $failure): bool
    {
        return $failure === $this->missing;
    }

    /**
     * The absent keys the run reached where it could not use `null`.
     *
     * @return list<string>
     */
    public function missingInRun(): array
    {
        $entered = $this->entered;
        $reached = $this->reached;

        return ComputedMetricReads::missingOf(
            $this->root,
            $this->isPresent,
            static fn(Node $node, string $operand): bool => isset($entered[self::operandId($node, $operand)]),
            static fn(Node $node): bool => isset($reached[spl_object_id($node)]),
        );
    }

    /** @param Closure(?float, float, float): ?float $evaluate */
    public static function nullableMeanClamps(Node $node, Closure $evaluate): Node
    {
        $copy = clone $node;
        foreach ($node->nodes as $name => $child) {
            if ($child instanceof Node) {
                $copy->nodes[$name] = self::nullableMeanClamps($child, $evaluate);
            }
        }
        if (!self::isMeanClamp($node)) {
            return $copy;
        }

        return new class ($copy->nodes['arguments'], $evaluate) extends Node {
            /** @param Closure(?float, float, float): ?float $evaluate */
            public function __construct(Node $arguments, private readonly Closure $evaluate)
            {
                parent::__construct(['arguments' => $arguments]);
            }

            /**
             * @param array<mixed> $functions
             * @param array<mixed> $values
             */
            public function evaluate(array $functions, array $values): mixed
            {
                $arguments = [];
                foreach (array_values($this->nodes['arguments']->nodes) as $argument) {
                    $arguments[] = $argument->evaluate($functions, $values);
                }

                return ($this->evaluate)(...$arguments);
            }
        };
    }

    private static function isMeanClamp(Node $node): bool
    {
        if (!$node instanceof FunctionNode || $node->attributes['name'] !== 'clamp') {
            return false;
        }
        $arguments = array_values($node->nodes['arguments']->nodes);

        return \count($arguments) === 3 && ComputedMetricReads::hasNullableValues($arguments[0]);
    }

    private static function operandId(Node $node, string|int $operand): string
    {
        return spl_object_id($node) . ':' . $operand;
    }

    /**
     * Built from clones: Expression Language may hand the same parsed nodes to
     * another evaluation. A node appearing twice gets a copy per position.
     *
     * @param ?Node $catcher the fallback or nullable mean receiving this node's `null`, if any
     */
    private function copyOf(Node $node, ?Node $catcher): Node
    {
        $copy = clone $node;
        $this->copyChildren($copy, $node, $catcher);

        if (ComputedMetricReads::keyOf($node) !== null) {
            $copy->nodes['node'] = $this->metricName($node, $catcher);
        }

        $conditional = ComputedMetricReads::conditionalOperands($node);
        if ($conditional !== []) {
            $this->wrapEnteredOperands($copy, $node, $conditional);
        } elseif (!$node instanceof GetAttrNode) {
            $this->wrapEagerChildren($copy, $node);
        }

        return $copy;
    }

    private function copyChildren(Node $copy, Node $node, ?Node $catcher): void
    {
        foreach ($node->nodes as $name => $child) {
            if (!$child instanceof Node) {
                continue;
            }

            $copy->nodes[$name] = ComputedMetricReads::hasNullableValues($node) && $name === 'arguments'
                ? $this->copyNullableArguments($child, $node)
                : $this->copyOf($child, self::childCatcher($node, $name, $catcher));
        }
    }

    /** @param list<string|int> $operands */
    private function wrapEnteredOperands(Node $copy, Node $node, array $operands): void
    {
        foreach ($operands as $name) {
            $copy->nodes[$name] = $this->entering($copy->nodes[$name], $node, $name);
        }
    }

    private function wrapEagerChildren(Node $copy, Node $node): void
    {
        $children = array_filter(
            $copy->nodes,
            static fn(mixed $child, string|int $name): bool => $child instanceof Node && $name !== 'arguments',
            \ARRAY_FILTER_USE_BOTH,
        );
        $this->wrapEagerOperands($copy, $node, $children);
    }

    /** @param array<string|int, Node> $operands */
    private function wrapEagerOperands(Node $copy, Node $node, array $operands): void
    {
        /** @var array<string, Node> $later */
        $later = [];
        foreach (array_reverse($operands, true) as $name => $operand) {
            $id = self::operandId($node, $name);
            $copy->nodes[$name] = $this->eager($operand, $id, $later);
            $later = [$id => $copy->nodes[$name], ...$later];
        }
    }

    private static function childCatcher(Node $node, string|int $name, ?Node $catcher): ?Node
    {
        if ($node instanceof NullCoalesceNode) {
            return $name === 'expr1' ? $node : $catcher;
        }
        if ($node instanceof ConditionalNode && $name !== 'expr1') {
            return $catcher;
        }

        return null;
    }

    private function copyNullableArguments(Node $arguments, Node $function): Node
    {
        $copy = clone $arguments;
        foreach (array_values($arguments->nodes) as $index => $argument) {
            $copy->nodes[$index] = $this->copyOf($argument, $index % 2 === 0 ? $function : null);
        }
        $this->wrapEagerOperands($copy, $arguments, $copy->nodes);

        return $copy;
    }

    private function metricName(Node $read, ?Node $catcher): NameNode
    {
        $metrics = $this->metrics;
        $reached = &$this->reached;
        $onRead = static function () use (&$reached, $read): void {
            $reached[spl_object_id($read)] = true;
        };
        $onAbsent = $this->onAbsent($catcher);

        return new class ($metrics, $onAbsent, $onRead) extends NameNode {
            /** @var ArrayAccess<mixed, mixed> */
            private readonly ArrayAccess $lookup;

            /**
             * @param Closure(): void $onAbsent
             * @param Closure(): void $onRead
             */
            public function __construct(
                MetricLookup $metrics,
                Closure $onAbsent,
                Closure $onRead,
            ) {
                parent::__construct(ComputedMetricReads::VARIABLE);
                $this->lookup = new class ($metrics, $onAbsent, $onRead) implements ArrayAccess {
                    /**
                     * @param Closure(): void $onAbsent
                     * @param Closure(): void $onRead
                     */
                    public function __construct(
                        private readonly MetricLookup $metrics,
                        private readonly Closure $onAbsent,
                        private readonly Closure $onRead,
                    ) {}

                    public function offsetExists(mixed $offset): bool
                    {
                        ($this->onRead)();
                        if (isset($this->metrics[$offset])) {
                            return true;
                        }
                        ($this->onAbsent)();

                        return false;
                    }

                    public function offsetGet(mixed $offset): int|float|null
                    {
                        ($this->onRead)();
                        $value = $this->metrics[$offset];
                        if ($value === null) {
                            ($this->onAbsent)();
                        }

                        return $value;
                    }

                    public function offsetSet(mixed $offset, mixed $value): void
                    {
                        throw new LogicException('Metrics are read-only inside a formula.');
                    }

                    public function offsetUnset(mixed $offset): void
                    {
                        throw new LogicException('Metrics are read-only inside a formula.');
                    }
                };
            }

            /**
             * @param array<mixed> $functions
             * @param array<mixed> $values
             */
            public function evaluate(array $functions, array $values): mixed
            {
                return $this->lookup;
            }
        };
    }

    /** @return Closure(): void */
    private function onAbsent(?Node $catcher): Closure
    {
        if ($catcher !== null) {
            return static function (): void {};
        }

        $missing = $this->missing;

        return static function () use ($missing): void {
            throw $missing;
        };
    }

    private function entering(Node $operand, Node $node, string|int $name): Node
    {
        $entered = &$this->entered;
        $onEnter = static function () use (&$entered, $node, $name): void {
            $entered[self::operandId($node, $name)] = true;
        };

        return new class ($operand, $onEnter) extends Node {
            /** @param Closure(): void $onEnter */
            public function __construct(Node $operand, private readonly Closure $onEnter)
            {
                parent::__construct(['operand' => $operand]);
            }

            /**
             * @param array<mixed> $functions
             * @param array<mixed> $values
             */
            public function evaluate(array $functions, array $values): mixed
            {
                ($this->onEnter)();

                return $this->nodes['operand']->evaluate($functions, $values);
            }
        };
    }

    /** @param array<string, Node> $later */
    private function eager(Node $operand, string $id, array $later): Node
    {
        $visited = &$this->visited;
        $missing = $this->missing;
        $visit = static function () use (&$visited, $id): void {
            $visited[$id] = true;
        };
        $recover = static function (array $functions, array $values) use (&$visited, $later, $missing): void {
            foreach ($later as $laterId => $laterOperand) {
                if (isset($visited[$laterId])) {
                    continue;
                }
                try {
                    $laterOperand->evaluate($functions, $values);
                } catch (Throwable $failure) {
                    if ($failure !== $missing) {
                        throw $failure;
                    }
                }
            }
        };
        $isMissing = static fn(Throwable $failure): bool => $failure === $missing;

        return new class ($operand, $visit, $recover, $isMissing) extends Node {
            /**
             * @param Closure(): void $visit
             * @param Closure(array<mixed>, array<mixed>): void $recover
             * @param Closure(Throwable): bool $isMissing
             */
            public function __construct(
                Node $operand,
                private readonly Closure $visit,
                private readonly Closure $recover,
                private readonly Closure $isMissing,
            ) {
                parent::__construct(['operand' => $operand]);
            }

            /**
             * @param array<mixed> $functions
             * @param array<mixed> $values
             */
            public function evaluate(array $functions, array $values): mixed
            {
                ($this->visit)();
                try {
                    return $this->nodes['operand']->evaluate($functions, $values);
                } catch (Throwable $failure) {
                    $isMissing = ($this->isMissing)($failure);
                    if (!$isMissing) {
                        throw $failure;
                    }
                    ($this->recover)($functions, $values);

                    throw $failure;
                }
            }
        };
    }
}
