<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Closure;
use LogicException;
use Symfony\Component\ExpressionLanguage\Node\ConditionalNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;

/**
 * The ternary branches, and right sides of `and`/`or`, one evaluation of a
 * formula entered.
 *
 * Which of them runs is a fact about the symbol's values, so it is taken from
 * the evaluation itself rather than computed beside it: the formula runs as a
 * copy whose conditional operands report their entry. Entering one that would
 * read an absent metric where nothing catches its `null` stops the run there,
 * before the `null` reaches the arithmetic or a PHP function.
 */
final class ComputedMetricBranchTrace
{
    /** A copy of the formula whose conditional operands report their entry. */
    public readonly Node $traced;

    /** @var array<string, true> */
    private array $entered = [];

    /** @var Closure(string): bool */
    private Closure $isPresent;

    private function __construct(private readonly Node $root)
    {
        $this->isPresent = static fn(string $key): bool => false;
        $this->traced = $this->copyOf($root, null);
    }

    /**
     * A trace for one parsed formula, or null when every operand of it always
     * runs and {@see ComputedMetricReads::missingOf()} already answers exactly.
     */
    public static function of(Node $root): ?self
    {
        return self::hasConditionalOperand($root) ? new self($root) : null;
    }

    /**
     * Starts a run of {@see $traced} on one symbol.
     *
     * @param Closure(string): bool $isPresent
     */
    public function start(Closure $isPresent): void
    {
        $this->entered = [];
        $this->isPresent = $isPresent;
    }

    /**
     * The absent keys the last run read where it cannot use `null`.
     *
     * @return list<string>
     */
    public function missingInRun(): array
    {
        $entered = $this->entered;

        return ComputedMetricReads::missingOf(
            $this->root,
            $this->isPresent,
            static fn(Node $node, string $operand): bool => isset($entered[self::operandId($node, $operand)]),
        );
    }

    private static function hasConditionalOperand(Node $node): bool
    {
        if (ComputedMetricReads::conditionalOperands($node) !== []) {
            return true;
        }

        foreach ($node->nodes as $child) {
            if ($child instanceof Node && self::hasConditionalOperand($child)) {
                return true;
            }
        }

        return false;
    }

    private static function operandId(Node $node, string $operand): string
    {
        return spl_object_id($node) . ':' . $operand;
    }

    /**
     * Built from clones: Expression Language may hand the same parsed nodes to
     * another evaluation. A node appearing twice — `a ?: b` reuses `a` — gets
     * a copy per position, because its `null` goes somewhere else in each.
     *
     * @param ?NullCoalesceNode $catcher the `??` whose left side receives this node's `null`, if any
     */
    private function copyOf(Node $node, ?NullCoalesceNode $catcher): Node
    {
        $copy = clone $node;
        $conditional = ComputedMetricReads::conditionalOperands($node);

        foreach ($node->nodes as $name => $child) {
            if (!$child instanceof Node) {
                continue;
            }

            $childCatcher = match (true) {
                $node instanceof NullCoalesceNode => $name === 'expr1' ? $node : $catcher,
                $node instanceof ConditionalNode => $name === 'expr1' ? null : $catcher,
                default => null,
            };
            $childCopy = $this->copyOf($child, $childCatcher);

            if (\in_array($name, $conditional, true)) {
                $childCopy = self::entering($childCopy, fn() => $this->enter($node, $name, $childCatcher));
            }

            $copy->nodes[$name] = $childCopy;
        }

        return $copy;
    }

    /**
     * @throws LogicException to stop the run: {@see missingInRun()} names why
     */
    private function enter(Node $node, string $operand, ?NullCoalesceNode $catcher): void
    {
        $this->entered[self::operandId($node, $operand)] = true;

        [$consumed, $value] = ComputedMetricReads::absentReads($node->nodes[$operand], $this->isPresent, null);

        if ($consumed !== [] || ($catcher === null && $value !== [])) {
            throw new LogicException('The operand the evaluation entered reads an absent metric.');
        }
    }

    /** @param Closure(): void $onEnter */
    private static function entering(Node $operand, Closure $onEnter): Node
    {
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
}
