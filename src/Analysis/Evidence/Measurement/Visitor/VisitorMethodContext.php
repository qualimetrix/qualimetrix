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

/** Composes lexical traversal scope and immutable callable projection. */
final class VisitorMethodContext
{
    private readonly VisitorFileEntryScope $fileEntryScope;

    private readonly VisitorCallableMetadata $callableMetadata;

    public function __construct()
    {
        $this->fileEntryScope = new VisitorFileEntryScope();
        $this->callableMetadata = new VisitorCallableMetadata();
    }

    public function reset(): void
    {
        $this->fileEntryScope->reset();
    }

    public function useDeclarationIndex(FileDeclarationIndex $index): void
    {
        $this->fileEntryScope->useDeclarationIndex($index);
    }

    public function enter(Node $node): ?VisitorCallableScope
    {
        match (true) {
            $node instanceof Node\Stmt\Namespace_ => $this->fileEntryScope->enterNamespace($node->name?->toString()),
            $node instanceof Node\Stmt\ClassLike => $this->fileEntryScope->enterClass($node->name?->toString(), $node->getStartFilePos()),
            $node instanceof Node\Stmt\Property => $this->fileEntryScope->enterProperty(\count($node->props) === 1 ? $node->props[0]->name->toString() : null),
            default => null,
        };

        return $this->enterCallable(
            $node,
            $this->fileEntryScope->currentClass(),
            $this->fileEntryScope->namespace(),
        );
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterCallable(Node $node, ?array $class, ?string $namespace): ?VisitorCallableScope
    {
        return match (true) {
            $node instanceof Node\Stmt\Function_ => $this->fileEntryScope->enterCallable(
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
            $node instanceof Node\PropertyHook => $this->enterPropertyHook($node, $class),
            default => null,
        };
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterAnonymousCallable(Node $node, ?array $class, ?string $namespace, string $syntax): VisitorCallableScope
    {
        return $this->fileEntryScope->enterCallable(
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
                ? $this->fileEntryScope->leaveCallable()
                : null;

        match (true) {
            $node instanceof Node\Stmt\Property => $this->fileEntryScope->leaveProperty(),
            $node instanceof Node\Stmt\ClassLike => $this->fileEntryScope->leaveClass(),
            $node instanceof Node\Stmt\Namespace_ => $this->fileEntryScope->leaveNamespace(),
            default => null,
        };

        return $scope;
    }

    public function currentFileEntrySubjectId(): string
    {
        return $this->fileEntryScope->currentSubjectId();
    }

    /** @return array<string, int|string> */
    public function fileEntrySubjectComponents(string $subjectId): array
    {
        return $this->fileEntryScope->subjectComponents($subjectId);
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
        return $this->fileEntryScope->enterCallable(
            $class['namespace'] ?? $this->fileEntryScope->namespace(),
            $class['class'] ?? null,
            $member,
            $node->getStartFilePos(),
            $node->getStartLine(),
            $kind,
            null,
            $class['start'] ?? null,
        );
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterPropertyHook(Node\PropertyHook $node, ?array $class): VisitorCallableScope
    {
        $property = $this->fileEntryScope->currentProperty();
        $member = $property === null ? '' : $property . '::' . $node->name->toString();

        return $this->enterMember($member, $node, CallableKind::PropertyHook, $class);
    }
}
