<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use InvalidArgumentException;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;

/** Owns mutable lexical, callable, collision, and file-entry subject state for one file. */
final class VisitorFileEntryScope
{
    /** @var array<string, array{components: array<string, int|string>, group: string, ordinal: int}> */
    private array $subjects = [];

    /** @var array<string, int> */
    private array $groupCounts = [];

    /** @var array<string, int> */
    private array $callableTraversalOrdinals = [];

    /** @var list<VisitorCallableScope> */
    private array $callables = [];

    /** @var list<string> */
    private array $callableSubjects = [];

    /** @var list<array{namespace: ?string, class: string, start: int, anonymous: bool, subject: ?string}> */
    private array $classes = [];

    /** @var list<?string> */
    private array $namespaces = [];

    /** @var list<?string> */
    private array $properties = [];

    private ?string $namespace = null;

    private int $closureCounter = 0;

    private int $nextSubject = 0;

    public function reset(): void
    {
        $this->subjects = [];
        $this->groupCounts = [];
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
        $class = $name ?? '{anonymous@' . $position . '}';
        $subject = $anonymous
            ? null
            : $this->register(
                MetricSubjectCodec::encodeClass($this->namespace ?? '', $class, $position),
                implode("\0", ['class', $this->namespace ?? '', $class, (string) $position]),
            );

        $this->classes[] = [
            'namespace' => $this->namespace,
            'class' => $class,
            'start' => $position,
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
        $ordinal = $this->callableTraversalOrdinals[$base] ?? 0;
        $this->callableTraversalOrdinals[$base] = $ordinal + 1;

        $scope = new VisitorCallableScope(
            $namespace,
            $class,
            $anonymousClassContext,
            $member,
            $logicalFqn,
            $base . '#' . $ordinal,
            $startFilePos,
            $sourceLine,
            $kind,
            $anonymousSyntax,
            $classStartFilePos,
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

    /** @return ?array{namespace: ?string, class: string, start: int, anonymous: bool, subject: ?string} */
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
        if (($this->groupCounts[$subject['group']] ?? 0) > 1) {
            $components['collisionOrdinal'] = $subject['ordinal'];
        }

        return $components;
    }

    private function callableSubject(VisitorCallableScope $scope): string
    {
        if ($scope->anonymousClassContext) {
            return 'file';
        }
        $namespace = $scope->namespace ?? '';
        $components = $scope->class !== null && \in_array($scope->kind, [CallableKind::Method, CallableKind::PropertyHook], true)
            ? MetricSubjectCodec::encodeMethod($namespace, $scope->class, $scope->member, $scope->startFilePos)
            : MetricSubjectCodec::encodeFunction($namespace, $scope->member, $scope->startFilePos);
        $group = implode("\0", [$scope->kind->value, $namespace, $scope->class ?? '', $scope->member, (string) $scope->startFilePos]);

        return $this->register($components, $group);
    }

    private function qualified(?string $namespace, string $name): string
    {
        return $namespace === null || $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /** @param array<string, int|string> $components */
    private function register(array $components, string $group): string
    {
        $ordinal = $this->groupCounts[$group] ?? 0;
        $this->groupCounts[$group] = $ordinal + 1;
        $id = 'subject-' . $this->nextSubject++;
        $this->subjects[$id] = ['components' => $components, 'group' => $group, 'ordinal' => $ordinal];

        return $id;
    }
}
