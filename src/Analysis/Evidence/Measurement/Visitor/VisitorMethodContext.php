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
 * @qmx-threshold coupling.cbo 22 -- Composition root of traversal identity: four collaborators plus the node types it routes; raw CBO 21 gets one-edge headroom.
 * @qmx-threshold coupling.instability warning=0.95 error=0.95 -- A composition root depends outward on everything it assembles and is depended on by one trait; instability near one is its shape, not its defect.
 *
 * @qmx-ignore health.cohesion -- Composition root: `subjects`, `numbering`, `lexicalScope`, and `callableMetadata` are four independent collaborators bundled here only so ~13 unrelated visitors (via VisitorMethodTrackingTrait) each hold one dependency instead of four; LCOM4 measures within-class property sharing, which a bundling facade lacks by design, not by defect. The `health.cohesion` producer has no threshold-override support, so `@qmx-threshold` cannot express this exception; `@qmx-ignore` is the only inline mechanism available.
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
        match (true) {
            $node instanceof Node\Stmt\Namespace_ => $this->lexicalScope->enterNamespace($node->name?->toString()),
            $node instanceof Node\Stmt\ClassLike => $this->lexicalScope->enterClass($node->name?->toString(), $node->getStartFilePos()),
            $node instanceof Node\Stmt\Property => $this->lexicalScope->enterProperty(\count($node->props) === 1 ? $node->props[0]->name->toString() : null),
            default => null,
        };

        return $this->enterCallable(
            $node,
            $this->lexicalScope->currentClass(),
            $this->lexicalScope->namespace(),
        );
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
            $node instanceof Node\PropertyHook => $this->enterPropertyHook($node, $class),
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

        match (true) {
            $node instanceof Node\Stmt\Property => $this->lexicalScope->leaveProperty(),
            $node instanceof Node\Stmt\ClassLike => $this->lexicalScope->leaveClass(),
            $node instanceof Node\Stmt\Namespace_ => $this->lexicalScope->leaveNamespace(),
            default => null,
        };

        return $scope;
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

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $class */
    private function enterPropertyHook(Node\PropertyHook $node, ?array $class): VisitorCallableScope
    {
        $property = $this->lexicalScope->currentProperty();
        $member = $property === null ? '' : $property . '::' . $node->name->toString();

        return $this->enterMember($member, $node, CallableKind::PropertyHook, $class);
    }
}
