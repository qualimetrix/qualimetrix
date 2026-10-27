<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;

/**
 * Where the traversal stands inside one file.
 *
 * Namespaces, class-likes, properties and callables are entered and left in
 * step with the AST, and every visitor asks this one scope rather than keeping
 * its own stacks. The subject id of each frame is kept here too: which subject
 * is *current* follows from where the traversal is, while what that subject is
 * made of belongs to {@see FileEntrySubjectRegistry}.
 *
 * An anonymous callable is named after the nearest enclosing frame of either
 * kind — a callable or a class-like declaration — and counted inside that frame
 * only: `App\A::rows::{closure#1}`. Counting per frame is what keeps a closure's
 * name from moving when a closure is added anywhere else in the file. Outside
 * every frame the namespace is the parent: top-level closures count per
 * namespace of the file, across every block that declares it.
 */
final class VisitorLexicalScope
{
    /** @var array<string, int> */
    private array $callableTraversalOrdinals = [];

    /** @var list<VisitorCallableScope> */
    private array $callables = [];

    /** @var list<string> */
    private array $callableSubjects = [];

    /** @var list<array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string}> */
    private array $classes = [];

    /** @var list<?string> */
    private array $namespaces = [];

    /** @var list<?string> */
    private array $properties = [];

    /**
     * Callable and class-like frames in lexical order, each with the number of
     * anonymous callables minted directly inside it so far.
     *
     * @var list<array{name: string, closures: int}>
     */
    private array $frames = [];

    private ?string $namespace = null;

    /** @var array<string, int> */
    private array $topLevelClosures = [];

    public function __construct(
        private readonly FileEntrySubjectRegistry $subjects,
        private readonly DeclarationNumbering $numbering,
    ) {}

    public function reset(): void
    {
        $this->callableTraversalOrdinals = [];
        $this->callables = [];
        $this->callableSubjects = [];
        $this->classes = [];
        $this->namespaces = [];
        $this->properties = [];
        $this->frames = [];
        $this->namespace = null;
        $this->topLevelClosures = [];
        $this->subjects->reset();
    }

    public function enterNamespace(?string $namespace): void
    {
        $this->namespaces[] = $this->namespace;
        $this->namespace = $namespace;
    }

    public function leaveNamespace(): void
    {
        $this->namespace = array_pop($this->namespaces);
    }

    public function enterClass(?string $name, int $position): void
    {
        $parent = $this->currentClass();
        $anonymous = $name === null || ($parent['anonymous'] ?? false);
        $ordinal = $name === null
            ? $this->numbering->forUnnamedClassLike($position)
            : $this->numbering->forClass($this->namespace, $name, $position);
        $class = $name ?? '{anonymous#' . $ordinal->value . '}';

        $this->classes[] = [
            'namespace' => $this->namespace,
            'class' => $class,
            'start' => $position,
            'ordinal' => $ordinal,
            'anonymous' => $anonymous,
            'subject' => $anonymous
                ? null
                : $this->subjects->register(MetricSubjectCodec::encodeClass($this->namespace ?? '', $class), $ordinal),
        ];
        $this->frames[] = ['name' => $class, 'closures' => 0];
    }

    public function leaveClass(): void
    {
        array_pop($this->classes);
        array_pop($this->frames);
    }

    public function enterProperty(?string $name): void
    {
        $this->properties[] = $name;
    }

    public function leaveProperty(): void
    {
        array_pop($this->properties);
    }

    /**
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=9 -- Sole mutable traversal scope atomically validates and constructs callable identity from eight context inputs.
     */
    public function enterCallable(
        ?string $namespace,
        ?string $class,
        ?string $member,
        int $startFilePos,
        int $sourceLine,
        CallableKind $kind,
        ?string $anonymousSyntax,
        ?int $classStartFilePos,
    ): VisitorCallableScope {
        if ($kind === CallableKind::AnonymousCallable) {
            if ($member !== null) {
                throw new InvalidArgumentException('Anonymous callable scope must not supply a member');
            }
            $member = $this->mintAnonymousMember();
        } elseif ($member === null || $member === '') {
            throw new InvalidArgumentException('Named callable scope requires a non-empty member');
        }

        $classContext = $this->currentClass();
        $logical = DeclarationNumbering::callablePath($namespace, $class, $member, $kind);
        $logicalFqn = $logical->toString();
        $base = $logicalFqn . '@' . $startFilePos;
        $traversalOrdinal = $this->callableTraversalOrdinals[$base] ?? 0;
        $this->callableTraversalOrdinals[$base] = $traversalOrdinal + 1;

        $scope = new VisitorCallableScope(
            $namespace,
            $class,
            $classContext['anonymous'] ?? false,
            $member,
            $logicalFqn,
            $base . '#' . $traversalOrdinal,
            $startFilePos,
            $sourceLine,
            $kind,
            $anonymousSyntax,
            $classStartFilePos,
            $this->numbering->forCallable($namespace, $class, $member, $kind, $startFilePos),
            self::lexicalClassOrdinal($classContext, $classStartFilePos),
        );
        $this->callables[] = $scope;
        $this->callableSubjects[] = $this->callableSubject($scope);
        $this->frames[] = ['name' => $logical->getSymbolName() ?? $member, 'closures' => 0];

        return $scope;
    }

    public function leaveCallable(): ?VisitorCallableScope
    {
        array_pop($this->callableSubjects);
        array_pop($this->frames);

        return array_pop($this->callables);
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    /** @return ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} */
    public function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
    }

    public function currentProperty(): ?string
    {
        return $this->properties === [] ? null : $this->properties[array_key_last($this->properties)];
    }

    public function currentSubjectId(): string
    {
        if ($this->callableSubjects !== []) {
            return $this->callableSubjects[array_key_last($this->callableSubjects)];
        }

        return $this->currentClass()['subject'] ?? $this->subjects->fileSubjectId();
    }

    /**
     * The wire subject of a callable declared inside an anonymous class stays
     * the file: the enclosing class has no name of its own, only its order
     * among the unnamed declarations of the file, and pinning a member to that
     * would make the member move whenever another anonymous class appears
     * above it.
     */
    private function callableSubject(VisitorCallableScope $scope): string
    {
        if ($scope->anonymousClassContext) {
            return $this->subjects->fileSubjectId();
        }

        $namespace = $scope->namespace ?? '';
        $logical = DeclarationNumbering::callablePath($scope->namespace, $scope->class, $scope->member, $scope->kind);
        $components = $logical->type !== null && $logical->member !== null
            ? MetricSubjectCodec::encodeMethod($namespace, $logical->type, $logical->member)
            : MetricSubjectCodec::encodeFunction($namespace, $scope->member);

        return $this->subjects->register($components, $scope->ordinal);
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $classContext */
    private static function lexicalClassOrdinal(?array $classContext, ?int $classStartFilePos): ?DeclarationOrdinal
    {
        return $classStartFilePos === null ? null : ($classContext['ordinal'] ?? null);
    }

    private function mintAnonymousMember(): string
    {
        if ($this->frames === []) {
            $namespace = $this->namespace ?? '';
            $this->topLevelClosures[$namespace] = ($this->topLevelClosures[$namespace] ?? 0) + 1;

            return '{closure#' . $this->topLevelClosures[$namespace] . '}';
        }

        $parent = array_key_last($this->frames);

        return $this->frames[$parent]['name'] . '::{closure#' . ++$this->frames[$parent]['closures'] . '}';
    }
}
