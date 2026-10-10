<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusalReason;
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
     */
    private function __construct(
        private MetricSubject $file,
        private array $byStart,
        private array $callableStarts,
        private DeclarationRanges $ranges,
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

        $ranges = DeclarationRanges::from($ast, $byStart);

        return new self(
            MetricSubject::aggregate(SymbolPath::forFile($file)),
            $byStart,
            $callableStarts,
            $ranges,
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
     * @return list<DeclarationBinding>
     */
    public function suppressionBindingsFor(Node $node): array
    {
        $standsOn = self::describe($node);

        if ($node->getType() === 'Stmt_Property') {
            return [
                ...self::wholeReach($this->propertyHookBindings($node), $node, $standsOn),
                ...self::lineReach($this->ranges->classAt($node->getStartFilePos()), $node, $standsOn),
            ];
        }

        if (self::isClassLike($node)) {
            return self::wholeReach($this->classBindings($node), $node, $standsOn);
        }

        if (\in_array($node->getType(), self::CALLABLE_TYPES, true)) {
            $own = self::wholeReach($this->callablesBeginningWith($node), $node, $standsOn);

            return $node->getType() === 'Stmt_ClassMethod'
                ? [
                    ...$own,
                    ...self::lineReach($this->ranges->classAt($node->getStartFilePos()), $node, $standsOn),
                ]
                : $own;
        }

        $start = $node->getStartFilePos();

        if ($node instanceof Node\Param) {
            $bindings = self::lineReach($this->ranges->callableAt($start), $node, $standsOn);
            if (!$node->isPromoted()) {
                return $bindings;
            }

            return [
                ...$bindings,
                ...self::lineReach($this->ranges->classAt($start), $node, $standsOn),
                ...self::wholeReach($this->propertyHookBindings($node), $node, $standsOn),
            ];
        }

        if ($node->getType() === 'Stmt_EnumCase' || $node->getType() === 'Stmt_ClassConst') {
            return self::lineReach($this->ranges->classAt($start), $node, $standsOn);
        }

        return self::wholeReach($this->directCallableBindings($node), $node, $standsOn);
    }

    /**
     * Thresholds bind only to the declarations they retune. They keep whole
     * declaration reach, and no containing class or callable is inferred.
     *
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    public function thresholdBindingsFor(Node $node): array
    {
        if ($node->getType() === 'Stmt_Property') {
            return $this->propertyHookBindings($node);
        }

        if (self::isClassLike($node)) {
            return $this->classBindings($node);
        }

        if (\in_array($node->getType(), self::CALLABLE_TYPES, true)) {
            return $this->callablesBeginningWith($node);
        }

        return $this->directCallableBindings($node);
    }

    public static function unboundReason(Node $node): DirectiveRefusalReason
    {
        $callable = (new NodeFinder())->findFirst(
            $node,
            static fn(Node $candidate): bool => $candidate instanceof Node\Expr\Closure
                || $candidate instanceof Node\Expr\ArrowFunction,
        );

        return $callable === null
            ? DirectiveRefusalReason::NoDeclarationToBind
            : DirectiveRefusalReason::ClosureNotDirectValue;
    }

    /** Human-readable source construct on which an authored directive stands. */
    public static function describe(Node $node): string
    {
        return DeclarationSource::describe($node);
    }

    /**
     * The measured callables that begin where the node begins.
     *
     * php-parser gives a comment to the outermost node that starts at the
     * next token, so the docblock of a closure passed as an argument, written
     * as an array element or as a statement of its own reaches the argument,
     * the item or the statement — never the closure. Beginning at the same
     * token is what makes that comment the closure's.
     *
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    public function callablesBeginningWith(Node $node): array
    {
        $start = $node->getStartFilePos();
        if ($start < 0) {
            return [];
        }

        return array_map(
            static fn(MetricSubject $subject): array => [
                'subject' => $subject,
                'scope' => $node->getType() === 'PropertyHook' ? ControlScope::Hook : ControlScope::Callable,
            ],
            $this->subjectsAtStart($start, ...self::CALLABLE_SUBJECT_TYPES),
        );
    }

    /** @return list<array{subject: MetricSubject, scope: ControlScope}> */
    private function directCallableBindings(Node $node): array
    {
        $callable = DeclarationSource::anonymousCallable($node);

        return $callable === null ? [] : $this->callablesBeginningWith($callable);
    }

    /**
     * @return non-empty-list<array{subject: MetricSubject, scope: ControlScope}>
     */
    public function fallbackBindingsForProperty(Node $property): array
    {
        $binding = $this->ranges->classAt($property->getStartFilePos());

        return $binding !== [] ? $binding : [['subject' => $this->file, 'scope' => ControlScope::Class_]];
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
     * @param list<array{subject: MetricSubject, scope: ControlScope}> $bindings
     *
     * @return list<DeclarationBinding>
     */
    private static function wholeReach(array $bindings, Node $node, string $standsOn): array
    {
        $reach = DeclarationReach::whole($node->getEndLine() > 0 ? $node->getEndLine() : null, $standsOn);

        return array_map(
            static fn(array $binding): DeclarationBinding => new DeclarationBinding(
                $binding['subject'],
                $binding['scope'],
                $reach,
            ),
            $bindings,
        );
    }

    /**
     * @param list<array{subject: MetricSubject, scope: ControlScope}> $bindings
     *
     * @return list<DeclarationBinding>
     */
    private static function lineReach(array $bindings, Node $node, string $standsOn): array
    {
        $reach = DeclarationReach::lines($node->getStartLine(), $node->getEndLine(), $standsOn);

        return array_map(
            static fn(array $binding): DeclarationBinding => new DeclarationBinding(
                $binding['subject'],
                $binding['scope'],
                $reach,
            ),
            $bindings,
        );
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
