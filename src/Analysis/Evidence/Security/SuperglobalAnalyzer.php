<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Finds a dangerous superglobal ($_GET, $_POST, $_REQUEST, $_COOKIE) whose
 * value becomes the value of an expression, or part of it, within that one
 * expression.
 *
 * Only wrappers whose result is one of their operands, or a string built
 * from them, are looked through: concatenation, interpolation, `??`, the
 * branches of `?:` (and the condition of the short form, which is its
 * value), `match` arm results, `(string)`, `@` and assignment. Every other
 * node ends the search, so a function or method call — a sanitizer such as
 * `htmlspecialchars()` or `escapeshellarg()` as much as any other — an
 * `(int)`/`(float)` cast, an array key and a ternary condition never carry
 * the superglobal's value. Data flow through variables is not tracked.
 */
final readonly class SuperglobalAnalyzer
{
    /** @var list<string> Superglobals considered dangerous for user input */
    private const DANGEROUS_SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_COOKIE',
    ];

    /**
     * @param Expr|InterpolatedStringPart ...$parts an expression, or the parts of an interpolated string or a backtick command
     *
     * @return string|null name of the first superglobal found (without `$`), or null
     */
    public function findSuperglobal(Expr|InterpolatedStringPart ...$parts): ?string
    {
        $name = ($this->readsInParts($parts)[0] ?? null)?->name;

        return \is_string($name) ? $name : null;
    }

    /**
     * Every dangerous superglobal read whose value becomes (part of) the value
     * of one of $parts, in source order.
     *
     * @param array<Expr|InterpolatedStringPart> $parts
     *
     * @return list<Variable>
     */
    public function readsInParts(array $parts): array
    {
        $reads = [];
        foreach ($parts as $part) {
            if ($part instanceof Variable) {
                if (\is_string($part->name) && \in_array($part->name, self::DANGEROUS_SUPERGLOBALS, true)) {
                    $reads[] = $part;
                }
            } elseif ($part instanceof Expr) {
                array_push($reads, ...$this->readsInParts($this->valueOperands($part)));
            }
        }

        return $reads;
    }

    /**
     * Flatten a concatenation chain into individual parts.
     *
     * @return list<Expr>
     */
    public function flattenConcat(Concat $node): array
    {
        $parts = [];

        if ($node->left instanceof Concat) {
            $parts = [...$parts, ...$this->flattenConcat($node->left)];
        } else {
            $parts[] = $node->left;
        }

        if ($node->right instanceof Concat) {
            $parts = [...$parts, ...$this->flattenConcat($node->right)];
        } else {
            $parts[] = $node->right;
        }

        return $parts;
    }

    /**
     * The operands whose value becomes (part of) the value of $expr; empty for
     * every node the search does not look through.
     *
     * @return array<Expr|InterpolatedStringPart>
     */
    private function valueOperands(Expr $expr): array
    {
        return match (true) {
            $expr instanceof ArrayDimFetch => [$expr->var],
            $expr instanceof Concat, $expr instanceof Coalesce => [$expr->left, $expr->right],
            $expr instanceof Ternary => [$expr->if ?? $expr->cond, $expr->else],
            default => $this->wrappedOperands($expr),
        };
    }

    /**
     * @return array<Expr|InterpolatedStringPart>
     */
    private function wrappedOperands(Expr $expr): array
    {
        return match (true) {
            $expr instanceof Cast\String_, $expr instanceof ErrorSuppress, $expr instanceof Assign => [$expr->expr],
            $expr instanceof InterpolatedString => $expr->parts,
            $expr instanceof Match_ => array_map(static fn(MatchArm $arm): Expr => $arm->body, $expr->arms),
            default => [],
        };
    }
}
