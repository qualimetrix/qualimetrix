<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use InvalidArgumentException;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationKey;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Owns mutable lexical, callable, and file-entry subject state for one file. */
final class VisitorFileEntryScope
{
    /** @var array<string, array{components: array<string, int|string>, ordinal: DeclarationOrdinal}> */
    private array $subjects = [];

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

    private ?string $namespace = null;

    private int $closureCounter = 0;

    private int $nextSubject = 0;

    private ?FileDeclarationIndex $declarationIndex = null;

    public function reset(): void
    {
        $this->subjects = [];
        $this->callableTraversalOrdinals = [];
        $this->callables = [];
        $this->callableSubjects = [];
        $this->classes = [];
        $this->namespaces = [];
        $this->properties = [];
        $this->namespace = null;
        $this->closureCounter = 0;
        $this->nextSubject = 0;
    }

    public function useDeclarationIndex(FileDeclarationIndex $index): void
    {
        $this->declarationIndex = $index;
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
            ? $this->index()->ordinalOf(DeclarationKey::forUnnamedClassLike(), $position)
            : $this->index()->ordinalOf(
                DeclarationKey::forLogical(SymbolPath::forClass($this->namespace ?? '', $name)),
                $position,
            );
        $class = $name ?? '{anonymous#' . $ordinal->value . '}';
        $subject = $anonymous
            ? null
            : $this->register(MetricSubjectCodec::encodeClass($this->namespace ?? '', $class), $ordinal);

        $this->classes[] = [
            'namespace' => $this->namespace,
            'class' => $class,
            'start' => $position,
            'ordinal' => $ordinal,
            'anonymous' => $anonymous,
            'subject' => $subject,
        ];
    }

    public function leaveClass(): void
    {
        array_pop($this->classes);
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
            $member = '{closure#' . ++$this->closureCounter . '}';
        } elseif ($member === null || $member === '') {
            throw new InvalidArgumentException('Named callable scope requires a non-empty member');
        }

        $classContext = $this->currentClass();
        $anonymousClassContext = $classContext['anonymous'] ?? false;
        $logicalOwner = $class === null ? null : $this->qualified($namespace, $class);
        $logicalFqn = $logicalOwner === null
            ? $this->qualified($namespace, $member)
            : $logicalOwner . '::' . $member;
        $base = $logicalFqn . '@' . $startFilePos;
        $traversalOrdinal = $this->callableTraversalOrdinals[$base] ?? 0;
        $this->callableTraversalOrdinals[$base] = $traversalOrdinal + 1;

        $scope = new VisitorCallableScope(
            $namespace,
            $class,
            $anonymousClassContext,
            $member,
            $logicalFqn,
            $base . '#' . $traversalOrdinal,
            $startFilePos,
            $sourceLine,
            $kind,
            $anonymousSyntax,
            $classStartFilePos,
            $this->callableOrdinal($namespace, $class, $member, $kind, $startFilePos),
            self::lexicalClassOrdinal($classContext, $classStartFilePos),
        );
        $this->callables[] = $scope;
        $this->callableSubjects[] = $this->callableSubject($scope);

        return $scope;
    }

    public function leaveCallable(): ?VisitorCallableScope
    {
        array_pop($this->callableSubjects);

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

        return $this->currentClass()['subject'] ?? 'file';
    }

    /** @return array<string, int|string> */
    public function subjectComponents(string $id): array
    {
        if ($id === 'file') {
            return MetricSubjectCodec::encodeFile();
        }
        $subject = $this->subjects[$id] ?? null;
        if ($subject === null) {
            throw new LogicException('Unknown file-entry subject reference');
        }
        $components = $subject['components'];
        if (!$subject['ordinal']->isFirst()) {
            $components['collisionOrdinal'] = $subject['ordinal']->value;
        }

        return $components;
    }

    /**
     * The wire subject of a callable declared inside an anonymous class stays
     * the file: the enclosing identity is positional, so pinning a member of it
     * would put a byte offset back into the key.
     */
    private function callableSubject(VisitorCallableScope $scope): string
    {
        if ($scope->anonymousClassContext) {
            return 'file';
        }
        $namespace = $scope->namespace ?? '';
        $components = $scope->class !== null && \in_array($scope->kind, [CallableKind::Method, CallableKind::PropertyHook], true)
            ? MetricSubjectCodec::encodeMethod($namespace, $scope->class, $scope->member)
            : MetricSubjectCodec::encodeFunction($namespace, $scope->member);

        return $this->register($components, $scope->ordinal);
    }

    private function callableOrdinal(?string $namespace, ?string $class, string $member, CallableKind $kind, int $startFilePos): DeclarationOrdinal
    {
        $logical = $class !== null && \in_array($kind, [CallableKind::Method, CallableKind::PropertyHook], true)
            ? SymbolPath::forMethod($namespace ?? '', $class, $member)
            : SymbolPath::forGlobalFunction($namespace ?? '', $member);

        return $this->index()->ordinalOf(DeclarationKey::forLogical($logical), $startFilePos);
    }

    /** @param ?array{namespace: ?string, class: string, start: int, ordinal: DeclarationOrdinal, anonymous: bool, subject: ?string} $classContext */
    private static function lexicalClassOrdinal(?array $classContext, ?int $classStartFilePos): ?DeclarationOrdinal
    {
        return $classStartFilePos === null ? null : ($classContext['ordinal'] ?? null);
    }

    private function index(): FileDeclarationIndex
    {
        return $this->declarationIndex
            ?? throw new LogicException('Declaration numbering requires the file declaration index of the current traversal');
    }

    private function qualified(?string $namespace, string $name): string
    {
        return $namespace === null || $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /** @param array<string, int|string> $components */
    private function register(array $components, DeclarationOrdinal $ordinal): string
    {
        $id = 'subject-' . $this->nextSubject++;
        $this->subjects[$id] = ['components' => $components, 'ordinal' => $ordinal];

        return $id;
    }
}
