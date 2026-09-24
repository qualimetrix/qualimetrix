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
     * @return string|null name of the first superglobal found (without `$`), or null
     */
    public function findSuperglobal(Expr $expr): ?string
    {
        if ($expr instanceof Variable) {
            return \is_string($expr->name) && \in_array($expr->name, self::DANGEROUS_SUPERGLOBALS, true) ? $expr->name : null;
        }

        return $this->findSuperglobalInParts($this->valueOperands($expr));
    }

    /**
     * @param array<Expr|InterpolatedStringPart> $parts parts of an interpolated string or a backtick command
     */
    public function findSuperglobalInParts(array $parts): ?string
    {
        foreach ($parts as $part) {
            $name = $part instanceof Expr ? $this->findSuperglobal($part) : null;
            if ($name !== null) {
                return $name;
            }
        }

        return null;
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
