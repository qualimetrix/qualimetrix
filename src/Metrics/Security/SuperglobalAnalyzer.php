<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Shared utility for analyzing superglobal usage in AST expressions.
 *
 * Provides methods to detect dangerous superglobals ($_GET, $_POST, $_REQUEST, $_COOKIE),
 * check sanitization, and extract superglobal names from expression trees.
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
     * Check if an expression is a dangerous superglobal variable or array access.
     */
    public function isDangerousSuperglobal(Expr $expr): bool
    {
        // Direct: $_GET, $_POST, etc.
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return \in_array($expr->name, self::DANGEROUS_SUPERGLOBALS, true);
        }

        // Array access: $_GET['key']
        if ($expr instanceof ArrayDimFetch) {
            return $this->isDangerousSuperglobal($expr->var);
        }

        return false;
    }

    /**
     * Check if an expression tree contains a superglobal.
     */
    public function containsSuperglobal(Expr $expr): bool
    {
        if ($this->isDangerousSuperglobal($expr)) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->containsSuperglobal($expr->left) || $this->containsSuperglobal($expr->right);
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->isDangerousSuperglobal($expr->var);
        }

        return false;
    }

    /**
     * Get the superglobal variable name from an expression.
     */
    public function getSuperglobalName(Expr $expr): string
    {
        if ($expr instanceof Variable && \is_string($expr->name)) {
            return $expr->name;
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->getSuperglobalName($expr->var);
        }

        return 'unknown';
    }

    /**
     * Find a superglobal name in an expression tree (traverses Concat chains).
     */
    public function findSuperglobalName(Expr $expr): string
    {
        if ($this->isDangerousSuperglobal($expr)) {
            return $this->getSuperglobalName($expr);
        }

        if ($expr instanceof Concat) {
            if ($this->containsSuperglobal($expr->left)) {
                return $this->findSuperglobalName($expr->left);
            }

            return $this->findSuperglobalName($expr->right);
        }

        if ($expr instanceof ArrayDimFetch) {
            return $this->getSuperglobalName($expr);
        }

        return 'unknown';
    }

    /**
     * Check if an expression is an unsanitized superglobal.
     *
     * @param list<string> $sanitizers Function names considered safe wrappers
     */
    public function isUnsanitizedSuperglobal(Expr $expr, array $sanitizers): bool
    {
        // Check for sanitization wrapper: htmlspecialchars($_GET['x']), etc.
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $funcName = $expr->name->toLowerString();
            if (\in_array($funcName, $sanitizers, true)) {
                return false;
            }
        }

        // Check for int/float cast: (int)$_GET['x']
        if ($expr instanceof Cast\Int_ || $expr instanceof Cast\Double) {
            return false;
        }

        // Check for intval wrapper
        if ($expr instanceof FuncCall && $expr->name instanceof Name && $expr->name->toLowerString() === 'intval') {
            return false;
        }

        return $this->isDangerousSuperglobal($expr);
    }

    /**
     * Check if an expression tree contains an unsanitized superglobal.
     *
     * @param list<string> $sanitizers Function names considered safe wrappers
     */
    public function containsUnsanitizedSuperglobalInExpr(Expr $expr, array $sanitizers): bool
    {
        if ($this->isUnsanitizedSuperglobal($expr, $sanitizers)) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->containsUnsanitizedSuperglobalInExpr($expr->left, $sanitizers)
                || $this->containsUnsanitizedSuperglobalInExpr($expr->right, $sanitizers);
        }

        return false;
    }

    /**
     * Find the name of an unsanitized superglobal in expression tree.
     *
     * @param list<string> $sanitizers Function names considered safe wrappers
     */
    public function findUnsanitizedSuperglobalName(Expr $expr, array $sanitizers): ?string
    {
        if ($this->isUnsanitizedSuperglobal($expr, $sanitizers)) {
            return $this->getSuperglobalName($expr);
        }

        if ($expr instanceof Concat) {
            return $this->findUnsanitizedSuperglobalName($expr->left, $sanitizers)
                ?? $this->findUnsanitizedSuperglobalName($expr->right, $sanitizers);
        }

        return null;
    }

    /**
     * Find an unsanitized superglobal in an InterpolatedString node.
     */
    public function findSuperglobalInInterpolatedString(InterpolatedString $node): ?string
    {
        foreach ($node->parts as $part) {
            if ($part instanceof Expr && $this->isDangerousSuperglobal($part)) {
                return $this->getSuperglobalName($part);
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
}
