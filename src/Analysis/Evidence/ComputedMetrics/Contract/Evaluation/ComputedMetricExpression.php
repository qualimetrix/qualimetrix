<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\Node\ConstantNode;
use Symfony\Component\ExpressionLanguage\Node\GetAttrNode;
use Symfony\Component\ExpressionLanguage\Node\NameNode;
use Symfony\Component\ExpressionLanguage\Node\Node;
use Symfony\Component\ExpressionLanguage\Node\NullCoalesceNode;
use Symfony\Component\ExpressionLanguage\ParsedExpression;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * The one place that parses a computed metric's formula and reads what it names.
 *
 * A formula sees one variable, `m`, and reaches a metric by its published key:
 * `m["complexity.ccn.avg"]`. The encoding this replaced turned `a.b` into the
 * identifier `a__b` because Expression Language forbids a dot in a name; with
 * kebab keys it would have had to forbid the hyphen too, and the guard that kept
 * metric names free of `__` existed only to protect that encoding.
 *
 * **Everything here reads the parsed expression, not its text.** The first
 * version matched `m[` with a regular expression and compared counts, and review
 * found two ways past it in one sitting: `m .offsetGet("k")` is a method call on
 * a public `ArrayAccess`, and `m ["k"]` is the same index with a space in it.
 * Neither is exotic — the second is a typo an ordinary formula can carry — and
 * both left the key invisible to the dependency graph while the guard reported
 * nothing. A grammar defended by a pattern over text is defended against the
 * shapes its author thought of; the parser already knows all of them.
 *
 * Reading the tree also settles what a regular expression could only approximate:
 * whether a formula needs a key is a fact about where each read sits relative to
 * `??`, and about which other keys are present — never about the name alone.
 */
final class ComputedMetricExpression
{
    /** The single variable a formula sees. */
    private const string VARIABLE = 'm';

    private readonly ExpressionLanguage $expressionLanguage;

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();

        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('min'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('max'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('abs'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('sqrt'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('log'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('log10'));

        $this->expressionLanguage->addFunction(new ExpressionFunction(
            'clamp',
            static fn(string $value, string $min, string $max): string => \sprintf(
                'max(%s, min(%s, %s))',
                $min,
                $max,
                $value,
            ),
            static fn(array $arguments, float $value, float $min, float $max): float => max($min, min($max, $value)),
        ));
    }

    /**
     * @throws SyntaxError if the formula is not a valid expression
     */
    public function parse(string $formula): ParsedExpression
    {
        return $this->expressionLanguage->parse($formula, [self::VARIABLE]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function evaluate(string $formula, array $variables): mixed
    {
        return $this->expressionLanguage->evaluate($formula, $variables);
    }

    /**
     * Whether every access to `m` is a quoted index.
     *
     * The check the encoding gave away for free is restated here: under
     * `a__b`, a misspelled key was an unknown VARIABLE and the parser refused it
     * at no cost. One variable buys that for nothing, so an index that cannot be
     * read — a method call, a computed index, `m` handed to a function — has to
     * be caught by something.
     *
     * Answers rather than throws: what a violation means is a configuration
     * decision, and configuration is not this zone's subject.
     */
    public function everyAccessIsALiteralIndex(string $formula): bool
    {
        try {
            $nodes = $this->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return true; // Reported, with its own message, by the syntax validation.
        }

        foreach (self::walk($nodes) as [$node, $parent]) {
            if (!$node instanceof NameNode || $node->attributes['name'] !== self::VARIABLE) {
                continue;
            }

            if (self::keyReadFrom($parent) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The key a node reads out of `m`, or null if it is not such a read.
     *
     * The base is checked, not just the index. Asking only "is this a literal
     * index" made the guard and the reader answer about different nodes:
     * `m["complexity.ccn"]["health.overall"]` indexes the VALUE the first read
     * returned, so the guard saw a legal access to `m` and the reader collected
     * `health.overall` as a key of its own — a dependency edge to a metric the
     * formula never reads. One function answers for both, and it answers about
     * one node.
     */
    public static function keyReadFrom(?Node $node): ?string
    {
        if (!$node instanceof GetAttrNode || $node->attributes['type'] !== GetAttrNode::ARRAY_CALL) {
            return null;
        }

        $base = $node->nodes['node'] ?? null;

        if (!$base instanceof NameNode || $base->attributes['name'] !== self::VARIABLE) {
            return null;
        }

        $attribute = $node->nodes['attribute'] ?? null;

        return $attribute instanceof ConstantNode && \is_string($attribute->attributes['value'])
            ? $attribute->attributes['value']
            : null;
    }

    /** Whether a key names another computed metric rather than a measured one. */
    public static function isComputedReference(string $key): bool
    {
        return str_starts_with($key, 'health.') || str_starts_with($key, 'computed.');
    }

    /**
     * The metric keys a formula names, in order of first appearance.
     *
     * @return list<string>
     */
    public function keysOf(string $formula): array
    {
        $keys = [];

        foreach ($this->accesses($formula) as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * The absent keys this formula would read as `null` where it cannot use
     * one: in arithmetic, a function argument, or as the formula's own value.
     * Empty means the formula is computable from what is present.
     *
     * Not a fixed list of "required" keys: `m["a"] ?? m["b"]` needs one of the
     * two, and which one depends on what is present — `b` is read only where
     * `a` is absent. A flat list either demands `b` where `a` answers, or
     * demands neither and lets two absent keys reach the arithmetic as 0.
     *
     * @param callable(string): bool $isPresent
     *
     * @return list<string> in order of first appearance
     */
    public function missingKeysOf(string $formula, callable $isPresent): array
    {
        try {
            $root = $this->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return [];
        }

        [$consumed, $value] = self::absentReads($root, $isPresent);

        return array_values(array_unique([...$consumed, ...$value]));
    }

    /**
     * Absent reads under a node, split by where their `null` goes.
     *
     * The second list holds the reads whose `null` becomes the node's own
     * value, which an enclosing `??` can still catch; the first holds those
     * already consumed by an operator or a function, which nothing can.
     *
     * @param callable(string): bool $isPresent
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function absentReads(Node $node, callable $isPresent): array
    {
        $key = self::keyReadFrom($node);

        if ($key !== null) {
            return [[], $isPresent($key) ? [] : [$key]];
        }

        if ($node instanceof NullCoalesceNode) {
            return self::absentReadsOfFallback($node, $isPresent);
        }

        $consumed = [];

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                [$childConsumed, $childValue] = self::absentReads($child, $isPresent);
                $consumed = [...$consumed, ...$childConsumed, ...$childValue];
            }
        }

        return [$consumed, []];
    }

    /**
     * @param callable(string): bool $isPresent
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function absentReadsOfFallback(NullCoalesceNode $node, callable $isPresent): array
    {
        $left = $node->nodes['expr1'];
        [$consumed, $value] = self::absentReads($left, $isPresent);

        // Only a read or another `??` says here whether it is null. Any
        // other left side might be — a ternary, `max()` of two nulls — so
        // the right side is taken as read: it cannot be decided, and
        // demanding a key is the side that fabricates nothing.
        if ($value === [] && (self::keyReadFrom($left) !== null || $left instanceof NullCoalesceNode)) {
            return [$consumed, []];
        }

        [$rightConsumed, $rightValue] = self::absentReads($node->nodes['expr2'], $isPresent);

        // Null only when both sides are; then both sides' keys are why.
        return [[...$consumed, ...$rightConsumed], $rightValue === [] ? [] : [...$value, ...$rightValue]];
    }

    /**
     * The other computed metrics a formula reads.
     *
     * @return list<string>
     */
    public function computedReferencesOf(string $formula): array
    {
        return array_values(array_filter(
            $this->keysOf($formula),
            self::isComputedReference(...),
        ));
    }

    /**
     * Every `m["key"]` in the formula, in order.
     *
     * @return list<string>
     */
    private function accesses(string $formula): array
    {
        try {
            $nodes = $this->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return [];
        }

        $accesses = [];

        foreach (self::walk($nodes) as [$node]) {
            $key = self::keyReadFrom($node);

            if ($key !== null) {
                $accesses[] = $key;
            }
        }

        return $accesses;
    }

    /**
     * The tree, flattened into (node, its parent) pairs.
     *
     * The parent is what says whether a `m` is an index base, so it travels
     * with the node rather than being looked up.
     *
     * @return list<array{0: Node, 1: ?Node}>
     */
    private static function walk(Node $node, ?Node $parent = null): array
    {
        $flattened = [[$node, $parent]];

        foreach ($node->nodes as $child) {
            if ($child instanceof Node) {
                $flattened = [...$flattened, ...self::walk($child, $node)];
            }
        }

        return $flattened;
    }
}
