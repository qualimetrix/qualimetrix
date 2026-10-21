<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use PhpParser\Node;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;

/**
 * Composes the four things a traversal needs to speak about declarations:
 * where it stands, how declarations are numbered, which wire subjects have been
 * minted, and how a finished callable is projected.
 *
 * Fan-out is what a composition root is for, and it is the price of the parts
 * below it staying separable.
 *
 * @qmx-threshold coupling.cbo 24 -- This traversal composition root coordinates four identity collaborators and routes promoted property hooks through Param and Variable nodes; raw CBO 23 gets one-edge headroom.
 * @qmx-threshold coupling.instability 0.913045 -- This traversal entry point composes separate lexical, numbering, subject, and callable-metadata owners for a small incoming population. Another wrapper would move the same parser vocabulary; the ceiling keeps added dependencies visible.
 * @qmx-threshold health.cohesion 45.0 -- This composition root bundles independent traversal collaborators so visitors hold one dependency; the finite boundary keeps a further cohesion loss visible.
 */
final class VisitorMethodContext
{
    private readonly FileEntrySubjectRegistry $subjects;

    private readonly DeclarationNumbering $numbering;

    private readonly VisitorLexicalScope $lexicalScope;

    private readonly VisitorCallableMetadata $callableMetadata;

    public function __construct()
    {
        $this->subjects = new FileEntrySubjectRegistry();
        $this->numbering = new DeclarationNumbering();
        $this->lexicalScope = new VisitorLexicalScope($this->subjects, $this->numbering);
        $this->callableMetadata = new VisitorCallableMetadata();
    }

    public function reset(): void
    {
        $this->lexicalScope->reset();
    }

    public function useDeclarationIndex(FileDeclarationIndex $index): void
    {
        $this->numbering->useIndex($index);
    }

    public function enter(Node $node): ?VisitorCallableScope
    {
        $this->enterLexicalScope($node);

        return $this->enterCallable(
            $node,
            $this->lexicalScope->currentClass(),
            $this->lexicalScope->namespace(),
        );
    }

    private function enterLexicalScope(Node $node): void
    {
        match (true) {
            $node instanceof Node\Stmt\Namespace_ => $this->lexicalScope->enterNamespace($node->name?->toString()),
            $node instanceof Node\Stmt\ClassLike => $this->lexicalScope->enterClass($node->name?->toString(), $node->getStartFilePos()),
            $node instanceof Node\Stmt\Property => $this->lexicalScope->enterProperty(\count($node->props) === 1 ? $node->props[0]->name->toString() : null),
            $node instanceof Node\Param && $node->isPromoted() => $this->lexicalScope->enterProperty(self::promotedPropertyName($node)),
            default => null,
        };
    }

    private static function promotedPropertyName(Node\Param $param): ?string
    {
        return $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)
            ? $param->var->name
            : null;
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterCallable(Node $node, ?array $class, ?string $namespace): ?VisitorCallableScope
    {
        return match (true) {
            $node instanceof Node\Stmt\Function_ => $this->lexicalScope->enterCallable(
                $namespace,
                null,
                $node->name->toString(),
                $node->getStartFilePos(),
                $node->getStartLine(),
                CallableKind::Function,
                null,
                null,
            ),
            $node instanceof Node\Expr\Closure => $this->enterAnonymousCallable($node, $class, $namespace, 'closure'),
            $node instanceof Node\Expr\ArrowFunction => $this->enterAnonymousCallable($node, $class, $namespace, 'arrow'),
            $node instanceof Node\Stmt\ClassMethod => $this->enterMember($node->name->toString(), $node, CallableKind::Method, $class),
            $node instanceof Node\PropertyHook => $this->enterMember(
                $this->lexicalScope->currentProperty() === null ? '' : $this->lexicalScope->currentProperty() . '::' . $node->name->toString(),
                $node,
                CallableKind::PropertyHook,
                $class,
            ),
            default => null,
        };
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterAnonymousCallable(Node $node, ?array $class, ?string $namespace, string $syntax): VisitorCallableScope
    {
        return $this->lexicalScope->enterCallable(
            $namespace,
            $class['class'] ?? null,
            null,
            $node->getStartFilePos(),
            $node->getStartLine(),
            CallableKind::AnonymousCallable,
            $syntax,
            $class['start'] ?? null,
        );
    }

    public function leave(Node $node): ?VisitorCallableScope
    {
        $scope = $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\PropertyHook
            ? $this->lexicalScope->leaveCallable()
            : null;

        $this->leaveLexicalScope($node);

        return $scope;
    }

    private function leaveLexicalScope(Node $node): void
    {
        match (true) {
            $node instanceof Node\Stmt\Property => $this->lexicalScope->leaveProperty(),
            $node instanceof Node\Param && $node->isPromoted() => $this->lexicalScope->leaveProperty(),
            $node instanceof Node\Stmt\ClassLike => $this->lexicalScope->leaveClass(),
            $node instanceof Node\Stmt\Namespace_ => $this->lexicalScope->leaveNamespace(),
            default => null,
        };
    }

    public function currentFileEntrySubjectId(): string
    {
        return $this->lexicalScope->currentSubjectId();
    }

    /** @return array<string, int|string> */
    public function fileEntrySubjectComponents(string $subjectId): array
    {
        return $this->subjects->componentsFor($subjectId);
    }

    public function createCallableWithMetrics(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics): CallableWithMetrics
    {
        return $this->callableMetadata->create($scope, $file, $metrics);
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, VisitorCallableScope> $scopes
     *
     * @return array<string, mixed>
     */
    public function projectLogicalMetricMap(array $metrics, array $scopes): array
    {
        return $this->callableMetadata->projectLogicalMetricMap($metrics, $scopes);
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterMember(string $member, Node $node, CallableKind $kind, ?array $class): VisitorCallableScope
    {
        return $this->lexicalScope->enterCallable(
            $class['namespace'] ?? $this->lexicalScope->namespace(),
            $class['class'] ?? null,
            $member,
            $node->getStartFilePos(),
            $node->getStartLine(),
            $kind,
            null,
            $class['start'] ?? null,
        );
    }

}
