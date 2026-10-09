<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

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
 * nodes retain sole ownership of branch selection.
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
        $this->isPresent = fn(string $key): bool => isset($this->metrics[$key]);
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
        $conditional = ComputedMetricReads::conditionalOperands($node);

        foreach ($node->nodes as $name => $child) {
            if (!$child instanceof Node) {
                continue;
            }

            if (ComputedMetricReads::hasNullableValues($node) && $name === 'arguments') {
                $copy->nodes[$name] = $this->copyNullableArguments($child, $node);
                continue;
            }

            $childCatcher = self::childCatcher($node, $name, $catcher);
            $copy->nodes[$name] = $this->copyOf($child, $childCatcher);
        }

        if (ComputedMetricReads::keyOf($node) !== null) {
            $copy->nodes['node'] = $this->metricName($node, $catcher !== null);
        }

        foreach ($copy->nodes as $name => $child) {
            if (!$child instanceof Node) {
                continue;
            }

            if (\in_array($name, $conditional, true)) {
                $copy->nodes[$name] = $this->entering($child, $node, $name);
                continue;
            }

            if ($node instanceof GetAttrNode || $conditional !== [] || $name === 'arguments') {
                continue;
            }

            $copy->nodes[$name] = $this->eager($child, $node, $name, $copy);
        }

        return $copy;
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
        foreach ($copy->nodes as $index => $argument) {
            $copy->nodes[$index] = $this->eager($argument, $arguments, $index, $copy);
        }

        return $copy;
    }

    private function metricName(Node $read, bool $nullable): NameNode
    {
        $metrics = $this->metrics;
        $missing = $this->missing;
        $onRead = function () use ($read): void {
            $this->reached[spl_object_id($read)] = true;
        };

        return new class ($metrics, $missing, $nullable, $onRead) extends NameNode {
            /** @var ArrayAccess<mixed, mixed> */
            private readonly ArrayAccess $lookup;

            /** @param Closure(): void $onRead */
            public function __construct(
                MetricLookup $metrics,
                LogicException $missing,
                bool $nullable,
                Closure $onRead,
            ) {
                parent::__construct(ComputedMetricReads::VARIABLE);
                $this->lookup = new class ($metrics, $missing, $nullable, $onRead) implements ArrayAccess {
                    /** @param Closure(): void $onRead */
                    public function __construct(
                        private readonly MetricLookup $metrics,
                        private readonly LogicException $missing,
                        private readonly bool $nullable,
                        private readonly Closure $onRead,
                    ) {}

                    public function offsetExists(mixed $offset): bool
                    {
                        ($this->onRead)();
                        if (isset($this->metrics[$offset])) {
                            return true;
                        }
                        if (!$this->nullable) {
                            throw $this->missing;
                        }

                        return false;
                    }

                    public function offsetGet(mixed $offset): int|float|null
                    {
                        ($this->onRead)();
                        $value = $this->metrics[$offset];
                        if ($value === null && !$this->nullable) {
                            throw $this->missing;
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

    private function entering(Node $operand, Node $node, string|int $name): Node
    {
        $onEnter = function () use ($node, $name): void {
            $this->entered[self::operandId($node, $name)] = true;
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

    private function eager(Node $operand, Node $node, string|int $name, Node $parent): Node
    {
        $visit = function () use ($node, $name): void {
            $this->visited[self::operandId($node, $name)] = true;
        };
        $recover = function (array $functions, array $values) use ($node, $name, $parent): void {
            $positions = array_keys($parent->nodes);
            $position = array_search($name, $positions, true);
            if ($position === false) {
                return;
            }

            foreach (\array_slice($positions, $position + 1) as $later) {
                if (isset($this->visited[self::operandId($node, $later)])) {
                    continue;
                }
                try {
                    $parent->nodes[$later]->evaluate($functions, $values);
                } catch (Throwable $failure) {
                    if (!$this->isMissing($failure)) {
                        throw $failure;
                    }
                }
            }
        };
        $isMissing = $this->isMissing(...);

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
