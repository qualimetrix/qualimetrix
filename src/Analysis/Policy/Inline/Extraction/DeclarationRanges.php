<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Core\Symbol\MetricSubject;

/** Measured declaration ranges and their innermost enclosing bindings. */
final readonly class DeclarationRanges
{
    /** @var list<string> */
    private const array CLASS_LIKE_TYPES = ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum'];

    /** @var list<string> */
    private const array CALLABLE_TYPES = ['Stmt_ClassMethod', 'Stmt_Function', 'Expr_Closure', 'Expr_ArrowFunction', 'PropertyHook'];

    /** @var list<string> */
    private const array CLASS_SUBJECT_TYPES = ['class'];

    /** @var list<string> */
    private const array CALLABLE_SUBJECT_TYPES = ['method', 'function'];

    /**
     * @param array{class: list<array{start: int, end: int, subject: MetricSubject, scope: ControlScope}>, callable: list<array{start: int, end: int, subject: MetricSubject, scope: ControlScope}>} $byKind
     */
    private function __construct(private array $byKind) {}

    /**
     * @param array<Node> $ast
     * @param array<int, list<MetricSubject>> $byStart
     */
    public static function from(array $ast, array $byStart): self
    {
        $finder = new NodeFinder();
        $classes = self::classRanges($finder, $ast, $byStart);
        $callables = self::callableRanges($finder, $ast, $byStart);

        return new self(['class' => $classes, 'callable' => $callables]);
    }

    /** @return list<array{subject: MetricSubject, scope: ControlScope}> */
    public function classAt(int $start): array
    {
        return $this->containing('class', $start);
    }

    /** @return list<array{subject: MetricSubject, scope: ControlScope}> */
    public function callableAt(int $start): array
    {
        return $this->containing('callable', $start);
    }

    /**
     * @param array<Node> $ast
     * @param array<int, list<MetricSubject>> $byStart
     *
     * @return list<array{start: int, end: int, subject: MetricSubject, scope: ControlScope}>
     */
    private static function classRanges(NodeFinder $finder, array $ast, array $byStart): array
    {
        $ranges = [];
        foreach ($finder->find($ast, static fn(Node $node): bool => \in_array($node->getType(), self::CLASS_LIKE_TYPES, true)) as $classLike) {
            $start = $classLike->getStartFilePos();
            $end = $classLike->getEndFilePos();
            if ($start >= 0 && $end >= $start) {
                foreach (self::subjectsAt($byStart, $start, ...self::CLASS_SUBJECT_TYPES) as $subject) {
                    $ranges[] = ['start' => $start, 'end' => $end, 'subject' => $subject, 'scope' => ControlScope::Class_];
                }
            }
        }

        return $ranges;
    }

    /**
     * @param array<Node> $ast
     * @param array<int, list<MetricSubject>> $byStart
     *
     * @return list<array{start: int, end: int, subject: MetricSubject, scope: ControlScope}>
     */
    private static function callableRanges(NodeFinder $finder, array $ast, array $byStart): array
    {
        $ranges = [];
        $callables = $finder->find($ast, static fn(Node $node): bool => \in_array($node->getType(), self::CALLABLE_TYPES, true));
        foreach ($callables as $callable) {
            $start = $callable->getStartFilePos();
            $end = $callable->getEndFilePos();
            if ($start >= 0 && $end >= $start) {
                foreach (self::subjectsAt($byStart, $start, ...self::CALLABLE_SUBJECT_TYPES) as $subject) {
                    $ranges[] = [
                        'start' => $start,
                        'end' => $end,
                        'subject' => $subject,
                        'scope' => $callable->getType() === 'PropertyHook' ? ControlScope::Hook : ControlScope::Callable,
                    ];
                }
            }
        }

        return $ranges;
    }

    /**
     * @param 'class'|'callable' $kind
     *
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    private function containing(string $kind, int $start): array
    {
        $bestSpan = null;
        $bindings = [];
        foreach ($this->byKind[$kind] as $range) {
            if ($start < $range['start'] || $start > $range['end']) {
                continue;
            }

            $span = $range['end'] - $range['start'];
            if ($bestSpan === null || $span < $bestSpan) {
                $bestSpan = $span;
                $bindings = [];
            }

            if ($span === $bestSpan) {
                $bindings[] = [
                    'subject' => $range['subject'],
                    'scope' => $range['scope'],
                ];
            }
        }

        return $bindings;
    }

    /**
     * @param array<int, list<MetricSubject>> $byStart
     *
     * @return list<MetricSubject>
     */
    private static function subjectsAt(array $byStart, int $start, string ...$types): array
    {
        return array_values(array_filter(
            $byStart[$start] ?? [],
            static fn(MetricSubject $subject): bool => $subject->declarationPath() !== null
                && \in_array($subject->declarationPath()->logical->getType()->value, $types, true),
        ));
    }

}
