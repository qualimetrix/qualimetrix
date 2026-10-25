<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;

/**
 * Decides, during one traversal, which constructors are value-object constructors.
 *
 * A VO constructor is a __construct in a readonly class where all parameters are promoted properties,
 * the body is empty, and the class exposes at least one of those properties: it is publicly readable,
 * or a function body of the class itself (a method, or a closure or arrow function inside one; not a
 * used trait) returns it as `return $this->name;`. A readonly class whose promoted state is all hidden
 * is a service, not a data carrier.
 */
final class ValueObjectClassification
{
    /**
     * One frame per open class-like. The decision waits for the class to close because the getter that
     * exposes a private promoted property may follow the constructor.
     *
     * @var list<array{readonly: bool, candidate: ?array{key: string, exposedByVisibility: bool, promoted: array<string, true>}, returned: array<string, true>}>
     */
    private array $frames = [];

    public function reset(): void
    {
        $this->frames = [];
    }

    public function enter(Node $node): void
    {
        if ($node instanceof ClassLike) {
            $this->frames[] = ['readonly' => $node instanceof Class_ && $node->isReadonly(), 'candidate' => null, 'returned' => []];
        } elseif ($node instanceof Return_) {
            $this->recordReturnedProperty($node->expr);
        } elseif ($node instanceof ArrowFunction) {
            $this->recordReturnedProperty($node->expr);
        }
    }

    /**
     * @param string $key traversal key the decision is reported under
     */
    public function proposeConstructor(string $key, ClassMethod $constructor): void
    {
        $top = array_key_last($this->frames);
        if ($top === null || !$this->frames[$top]['readonly'] || !self::hasValueObjectShape($constructor)) {
            return;
        }

        $exposedByVisibility = false;
        $promoted = [];
        foreach ($constructor->params as $param) {
            $exposedByVisibility = $exposedByVisibility || self::isPubliclyReadable($param->flags);
            if ($param->var instanceof Variable && \is_string($param->var->name)) {
                $promoted[$param->var->name] = true;
            }
        }

        $this->frames[$top]['candidate'] = ['key' => $key, 'exposedByVisibility' => $exposedByVisibility, 'promoted' => $promoted];
    }

    /**
     * Closes the innermost class-like.
     *
     * @return ?string the traversal key of its constructor when that constructor is a VO constructor
     */
    public function leave(Node $node): ?string
    {
        if (!$node instanceof ClassLike) {
            return null;
        }

        $frame = array_pop($this->frames);
        $candidate = $frame['candidate'] ?? null;
        if ($frame === null || $candidate === null) {
            return null;
        }

        return $candidate['exposedByVisibility'] || array_intersect_key($candidate['promoted'], $frame['returned']) !== []
            ? $candidate['key']
            : null;
    }

    /**
     * All parameters promoted and an empty body.
     *
     * A promoted parameter carries a promotion modifier: visibility, `readonly` or an asymmetric set visibility.
     * Empty body means no statements (property promotion is not a statement).
     */
    private static function hasValueObjectShape(ClassMethod $constructor): bool
    {
        if ($constructor->params === []) {
            return false;
        }

        foreach ($constructor->params as $param) {
            if ($param->flags === 0) {
                return false;
            }
        }

        return $constructor->stmts === null || $constructor->stmts === [];
    }

    /**
     * Read visibility only: `private(set)` and its siblings restrict writes, so `private(set) $x` reads publicly.
     */
    private static function isPubliclyReadable(int $flags): bool
    {
        $visibility = $flags & Modifiers::VISIBILITY_MASK;

        return $visibility === 0 || ($visibility & Modifiers::PUBLIC) !== 0;
    }

    /**
     * Only the exact `$this->name` as the returned expression counts. A wider reading (any property inside the
     * returned expression) was measured to re-admit services that return `new Result($this->dependency)`.
     */
    private function recordReturnedProperty(?Node\Expr $expr): void
    {
        $top = array_key_last($this->frames);
        if ($top === null
            || !$expr instanceof PropertyFetch
            || !$expr->var instanceof Variable
            || $expr->var->name !== 'this'
            || !$expr->name instanceof Identifier
        ) {
            return;
        }

        $this->frames[$top]['returned'][$expr->name->name] = true;
    }
}
