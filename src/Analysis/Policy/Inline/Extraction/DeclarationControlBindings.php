<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Immutable declaration-to-source-control bindings for one parsed file. */
final readonly class DeclarationControlBindings
{
    /** @var list<string> */
    private const array CLASS_LIKE_TYPES = ['Stmt_Class', 'Stmt_Interface', 'Stmt_Trait', 'Stmt_Enum'];

    /** @var list<string> */
    private const array CALLABLE_TYPES = ['Stmt_ClassMethod', 'Stmt_Function', 'Expr_Closure', 'Expr_ArrowFunction', 'PropertyHook'];

    /** @var list<string> */
    private const array CALLABLE_SUBJECT_TYPES = ['method', 'function'];

    /** @var list<string> */
    private const array CLASS_SUBJECT_TYPES = ['class'];

    /** @var array<string, string> */
    private const array SOURCE_TYPE_BY_NODE_TYPE = [
        'Stmt_Class' => 'class',
        'Stmt_Interface' => 'class',
        'Stmt_Trait' => 'class',
        'Stmt_Enum' => 'class',
        'Stmt_ClassMethod' => 'method:',
        'PropertyHook' => 'property-hook:',
        'Stmt_Function' => 'function:',
        'Expr_Closure' => 'anonymous-callable:closure',
        'Expr_ArrowFunction' => 'anonymous-callable:arrow',
    ];

    /**
     * @param array<int, list<MetricSubject>> $byStart
     * @param list<array{start: int, subject: MetricSubject, lexicalClassContext: ?string}> $callableStarts
     * @param list<array{start: int, end: int, subject: MetricSubject}> $classRanges
     * @param list<array{start: int, end: int, subject: MetricSubject, scope: ControlScope}> $callableRanges
     */
    private function __construct(
        private MetricSubject $file,
        private array $byStart,
        private array $callableStarts,
        private array $classRanges,
        private array $callableRanges,
    ) {}

    /**
     * @param array<Node> $ast
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: MetricSubject, metrics: mixed, line: int, start: int}> $classMetrics
     */
    public static function from(array $ast, RelativePath $file, array $callableMetrics, array $classMetrics): self
    {
        self::assertCompatibleSourceMetadata($ast, $callableMetrics, $classMetrics);

        $byStart = [];
        $callableStarts = [];
        foreach ($callableMetrics as $callable) {
            $subject = MetricSubject::declaration($callable->declarationPath);
            $byStart[$callable->startFilePos][] = $subject;
            $callableStarts[] = [
                'start' => $callable->startFilePos,
                'subject' => $subject,
                'lexicalClassContext' => $callable->lexicalClassContext?->toCanonical(),
            ];
        }

        foreach ($classMetrics as $classMetric) {
            if ($classMetric['subject']->declarationPath() !== null) {
                $byStart[$classMetric['start']][] = $classMetric['subject'];
            }
        }

        $finder = new NodeFinder();
        $classRanges = self::classRanges($finder, $ast, $byStart);
        $callableRanges = self::callableRanges($finder, $ast, $byStart);

        return new self(
            MetricSubject::aggregate(SymbolPath::forFile($file)),
            $byStart,
            $callableStarts,
            $classRanges,
            $callableRanges,
        );
    }

    /**
     * Rejects metadata that cannot describe one concrete source declaration.
     *
     * The compared identity is the canonical declaration key, ordinal included,
     * so two producers that gave one declaration two different numbers are
     * rejected here: their keys differ while their position is the same.
     *
     * @param array<Node> $ast
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: MetricSubject, metrics: mixed, line: int, start: int}> $classMetrics
     */
    private static function assertCompatibleSourceMetadata(array $ast, array $callableMetrics, array $classMetrics): void
    {
        $metadata = [
            ...array_map(static fn(CallableWithMetrics $callable): array => [
                'start' => $callable->startFilePos,
                'identity' => $callable->declarationPath->toCanonical() . "\0" . $callable->kind->value . ':' . ($callable->anonymousSyntax ?? ''),
            ], $callableMetrics),
            ...array_map(static fn(array $classMetric): array => [
                'start' => $classMetric['start'],
                'identity' => ($classMetric['subject']->declarationPath() ?? throw new LogicException('Class metrics must have a declaration subject'))->toCanonical() . "\0class",
            ], $classMetrics),
        ];
        $metadataByStart = array_reduce($metadata, static function (array $groups, array $item): array {
            $groups[$item['start']][] = $item['identity'];

            return $groups;
        }, []);
        $sourceNodes = array_reduce(
            (new NodeFinder())->find($ast, static fn(Node $node): bool => isset(self::SOURCE_TYPE_BY_NODE_TYPE[$node->getType()])),
            static function (array $nodes, Node $node): array {
                $nodes[$node->getStartFilePos()] = self::SOURCE_TYPE_BY_NODE_TYPE[$node->getType()];

                return $nodes;
            },
            [],
        );
        $invalidStart = array_key_first(array_filter(
            $metadataByStart,
            static function (array $identities, int $start) use ($sourceNodes): bool {
                $distinct = array_unique($identities);

                return (int) (\count($distinct) !== 1)
                    + (int) (($sourceNodes[$start] ?? null) !== explode("\0", $distinct[0], 2)[1]) > 0;
            },
            \ARRAY_FILTER_USE_BOTH,
        ));
        $invalidStart === null || throw new LogicException(\sprintf('Incompatible declaration metadata at file position %d', $invalidStart));
    }

    /**
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    public function bindingsFor(Node $node): array
    {
        if ($node->getType() === 'Stmt_Property') {
            return $this->propertyHookBindings($node);
        }

        if (self::isClassLike($node)) {
            return $this->classBindings($node);
        }

        $start = $node->getStartFilePos();

        if ($node->getType() === 'Param') {
            return $this->containingBinding($this->callableRanges, $start);
        }

        if ($node->getType() === 'Stmt_EnumCase' || $node->getType() === 'Stmt_ClassConst') {
            return $this->containingBinding($this->classRanges, $start, ControlScope::Class_);
        }

        if ($start >= 0) {
            return array_map(
                static fn(MetricSubject $subject): array => [
                    'subject' => $subject,
                    'scope' => $node->getType() === 'PropertyHook' ? ControlScope::Hook : ControlScope::Callable,
                ],
                $this->subjectsAtStart($start, ...self::CALLABLE_SUBJECT_TYPES),
            );
        }

        return [];
    }

    /**
     * @return non-empty-list<array{subject: MetricSubject, scope: ControlScope}>
     */
    public function fallbackBindingsForProperty(Node $property): array
    {
        $binding = $this->containingBinding($this->classRanges, $property->getStartFilePos(), ControlScope::Class_);

        return $binding !== [] ? $binding : [['subject' => $this->file, 'scope' => ControlScope::Class_]];
    }

    /**
     * @param array<Node> $ast
     * @param array<int, list<MetricSubject>> $byStart
     *
     * @return list<array{start: int, end: int, subject: MetricSubject}>
     */
    private static function classRanges(NodeFinder $finder, array $ast, array $byStart): array
    {
        $ranges = [];
        foreach ($finder->find($ast, self::isClassLike(...)) as $classLike) {
            $start = $classLike->getStartFilePos();
            $end = $classLike->getEndFilePos();
            if ($start >= 0 && $end >= $start) {
                foreach (self::subjectsAt($byStart, $start, ...self::CLASS_SUBJECT_TYPES) as $subject) {
                    $ranges[] = ['start' => $start, 'end' => $end, 'subject' => $subject];
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
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    private function propertyHookBindings(Node $property): array
    {
        $bindings = [];
        foreach ((new NodeFinder())->find($property, static fn(Node $node): bool => $node->getType() === 'PropertyHook') as $hook) {
            $start = $hook->getStartFilePos();
            if ($start >= 0) {
                array_push($bindings, ...array_map(
                    static fn(MetricSubject $subject): array => [
                        'subject' => $subject,
                        'scope' => ControlScope::Property,
                    ],
                    $this->subjectsAtStart($start, ...self::CALLABLE_SUBJECT_TYPES),
                ));
            }
        }

        return $bindings;
    }

    /**
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    private function classBindings(Node $classLike): array
    {
        $start = $classLike->getStartFilePos();
        if ($start < 0) {
            return [];
        }

        $bindings = [];
        foreach ($this->subjectsAtStart($start, ...self::CLASS_SUBJECT_TYPES) as $subject) {
            $bindings[] = ['subject' => $subject, 'scope' => ControlScope::Class_];
            foreach ($this->callableStarts as $callable) {
                if ($callable['lexicalClassContext'] === $subject->toCanonical()) {
                    $bindings[] = ['subject' => $callable['subject'], 'scope' => ControlScope::Class_];
                }
            }
        }

        return $bindings;
    }

    /**
     * @param list<array{start: int, end: int, subject: MetricSubject, scope?: ControlScope}> $ranges
     *
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    private function containingBinding(array $ranges, int $start, ?ControlScope $defaultScope = null): array
    {
        $bestSpan = null;
        $bindings = [];
        foreach ($ranges as $range) {
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
                    'scope' => $range['scope'] ?? $defaultScope ?? ControlScope::Callable,
                ];
            }
        }

        return $bindings;
    }

    /**
     * @return list<MetricSubject>
     */
    private function subjectsAtStart(int $start, string ...$types): array
    {
        return self::subjectsAt($this->byStart, $start, ...$types);
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

    private static function isClassLike(Node $node): bool
    {
        return \in_array($node->getType(), self::CLASS_LIKE_TYPES, true);
    }

}
