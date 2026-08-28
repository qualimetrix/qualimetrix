<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

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
 * a key is required unless EVERY one of its occurrences sits under a `??`, and
 * that is a fact about occurrences, not about names.
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
     * Refuses a formula that reaches `m` by anything but a literal index.
     *
     * The check the encoding gave away for free is restated here: under
     * `a__b`, a misspelled key was an unknown VARIABLE and the parser refused it
     * at no cost. One variable buys that for nothing, so an index that cannot be
     * read — a method call, a computed index, `m` handed to a function — is
     * refused rather than validated as far as it can be.
     *
     * @throws ComputedMetricConfigurationException
     */
    public function assertEveryAccessIsALiteralIndex(string $formula, string $metricName): void
    {
        try {
            $nodes = $this->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return; // Reported, with its own message, by the syntax validation.
        }

        foreach (self::walk($nodes) as [$node, $parent]) {
            if (!$node instanceof NameNode || $node->attributes['name'] !== self::VARIABLE) {
                continue;
            }

            if (self::isLiteralIndexOf($parent)) {
                continue;
            }

            throw new ComputedMetricConfigurationException(\sprintf(
                'Computed metric "%s" reaches "%s" by something other than a quoted metric key, which makes the key'
                . ' unverifiable. Write every access as m["<metric key>"]. Formula: %s',
                $metricName,
                self::VARIABLE,
                $formula,
            ));
        }
    }

    /**
     * Whether a node is `<something>["a literal"]`.
     *
     * The one shape a formula may reach `m` through, and the one shape a key can
     * be read out of — asked in both directions from here, so the guard and the
     * reader cannot drift into disagreeing about what an access is.
     */
    private static function isLiteralIndexOf(?Node $node): bool
    {
        if (!$node instanceof GetAttrNode || $node->attributes['type'] !== GetAttrNode::ARRAY_CALL) {
            return false;
        }

        $attribute = $node->nodes['attribute'] ?? null;

        return $attribute instanceof ConstantNode && \is_string($attribute->attributes['value']);
    }

    /**
     * The metric keys a formula names, in order of first appearance.
     *
     * @return list<string>
     */
    public function keysOf(string $formula): array
    {
        $keys = [];

        foreach ($this->accesses($formula) as [$key, $guarded]) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * The keys a formula needs present: those with at least one occurrence not
     * guarded by `??`.
     *
     * @return list<string>
     */
    public function requiredKeysOf(string $formula): array
    {
        $required = [];

        foreach ($this->accesses($formula) as [$key, $guarded]) {
            if (!$guarded) {
                $required[$key] = true;
            }
        }

        return array_keys($required);
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
            static fn(string $key): bool => str_starts_with($key, 'health.') || str_starts_with($key, 'computed.'),
        ));
    }

    /**
     * Every `m["key"]` in the formula, with whether that occurrence is guarded.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function accesses(string $formula): array
    {
        try {
            $nodes = $this->parse($formula)->getNodes();
        } catch (SyntaxError) {
            return [];
        }

        $accesses = [];

        foreach (self::walk($nodes) as [$node, $parent]) {
            if (!self::isLiteralIndexOf($node)) {
                continue;
            }

            $attribute = $node->nodes['attribute'];
            \assert($attribute instanceof ConstantNode);
            \assert(\is_string($attribute->attributes['value']));

            $accesses[] = [$attribute->attributes['value'], $parent instanceof NullCoalesceNode];
        }

        return $accesses;
    }

    /**
     * The tree, flattened into (node, its parent) pairs.
     *
     * The parent is what says whether an access is guarded and whether a `m`
     * is an index base, so it travels with the node rather than being looked up.
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
