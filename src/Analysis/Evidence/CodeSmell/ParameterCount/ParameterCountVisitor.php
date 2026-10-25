<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\ParameterCount;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorMethodTrackingTrait;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Visitor for counting method/function parameters.
 *
 * Counts the parameters of every callable scope the method context opens: methods, functions,
 * closures and arrow functions. Which of those a rule judges is the rule's decision.
 */
final class ParameterCountVisitor extends NodeVisitorAbstract implements DeclarationIndexAwareInterface, ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, int> Method/function FQN => parameter count */
    private array $parameterCounts = [];

    /** @var array<string, bool> Method/function FQN => is VO constructor */
    private array $voConstructors = [];

    /** @var array<string, VisitorCallableScope> */
    private array $scopes = [];

    /**
     * One frame per open class-like. The VO decision waits for the class to close because the getter that
     * exposes a private promoted property may follow the constructor.
     *
     * @var list<array{readonly: bool, candidate: ?array{key: string, exposedByVisibility: bool, promoted: array<string, true>}, returned: array<string, true>}>
     */
    private array $classFrames = [];

    public function reset(): void
    {
        $this->parameterCounts = [];
        $this->voConstructors = [];
        $this->scopes = [];
        $this->resetVisitorMethodContext();
        $this->classFrames = [];
    }

    /**
     * @return array<string, int>
     */
    public function getParameterCounts(): array
    {
        /** @var array<string, int> $projected */
        $projected = $this->projectLogicalMetricMap($this->parameterCounts, $this->scopes);

        return $projected;
    }

    /**
     * Returns FQNs of methods detected as VO constructors.
     *
     * A VO constructor is a __construct in a readonly class where all parameters
     * are promoted properties, the body is empty (no statements), and the class
     * exposes at least one of those properties: it is publicly readable, or a
     * method of the class returns it as `return $this->name;`. A readonly class
     * whose promoted state is all hidden is a service, not a data carrier.
     *
     * @return array<string, bool>
     */
    public function getVoConstructors(): array
    {
        /** @var array<string, bool> $projected */
        $projected = $this->projectLogicalMetricMap($this->voConstructors, $this->scopes);

        return $projected;
    }

    /**
     * @return array<string, VisitorCallableScope>
     */
    public function getMethodInfos(): array
    {
        return $this->scopes;
    }

    /**
     * Returns structured method metrics for each analyzed method.
     *
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array
    {
        $result = [];

        foreach ($this->scopes as $fqn => $scope) {
            $metrics = (new MetricBag())->with(MetricName::CODE_SMELL_PARAMETER_COUNT, $this->parameterCounts[$fqn] ?? 0);

            if (isset($this->voConstructors[$fqn])) {
                $metrics = $metrics->with(MetricName::CODE_SMELL_IS_VO_CONSTRUCTOR, 1);
            }

            $result[] = $this->createCallableWithMetrics($scope, $file, $metrics);
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        $this->trackClassFrame($node);
        $scope = $this->enterVisitorMethodContext($node);
        if ($scope === null) {
            return null;
        }
        if (!$node instanceof Node\FunctionLike) {
            return null;
        }

        $fqn = $scope->traversalKey;
        $this->scopes[$fqn] = $scope;
        $this->parameterCounts[$fqn] = \count($node->getParams());

        if ($node instanceof ClassMethod
            && strtolower($scope->member) === '__construct'
            && $this->isCurrentClassReadonly()
            && $this->isVoConstructor($node)
        ) {
            $this->recordVoCandidate($fqn, $node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        $this->leaveVisitorMethodContext($node);
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->closeClassFrame();
        }

        return null;
    }

    /**
     * Checks if the current class scope has the readonly modifier.
     */
    private function isCurrentClassReadonly(): bool
    {
        return $this->classFrames !== [] && $this->classFrames[array_key_last($this->classFrames)]['readonly'];
    }

    private function trackClassFrame(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classFrames[] = ['readonly' => $node instanceof Node\Stmt\Class_ && $node->isReadonly(), 'candidate' => null, 'returned' => []];
        } elseif ($node instanceof Return_) {
            $this->recordReturnedProperty($node);
        }
    }

    private function recordVoCandidate(string $fqn, ClassMethod $node): void
    {
        $exposedByVisibility = false;
        $promoted = [];
        foreach ($node->params as $param) {
            $exposedByVisibility = $exposedByVisibility || $this->isPubliclyReadable($param->flags);
            if ($param->var instanceof Variable && \is_string($param->var->name)) {
                $promoted[$param->var->name] = true;
            }
        }

        $top = array_key_last($this->classFrames);
        if ($top !== null) {
            $this->classFrames[$top]['candidate'] = ['key' => $fqn, 'exposedByVisibility' => $exposedByVisibility, 'promoted' => $promoted];
        }
    }

    /**
     * Read visibility only: `private(set)` and its siblings restrict writes, so `private(set) $x` reads publicly.
     */
    private function isPubliclyReadable(int $flags): bool
    {
        $visibility = $flags & Modifiers::VISIBILITY_MASK;

        return $visibility === 0 || ($visibility & Modifiers::PUBLIC) !== 0;
    }

    /**
     * Only the exact `return $this->name;` counts. A wider reading (any property inside the returned expression)
     * was measured to re-admit services that return `new Result($this->dependency)`.
     */
    private function recordReturnedProperty(Return_ $node): void
    {
        $expr = $node->expr;
        if ($this->classFrames === []
            || !$expr instanceof PropertyFetch
            || !$expr->var instanceof Variable
            || $expr->var->name !== 'this'
            || !$expr->name instanceof Identifier
        ) {
            return;
        }

        $this->classFrames[array_key_last($this->classFrames)]['returned'][$expr->name->name] = true;
    }

    private function closeClassFrame(): void
    {
        $frame = array_pop($this->classFrames);
        $candidate = $frame['candidate'] ?? null;
        if ($frame === null || $candidate === null) {
            return;
        }

        if ($candidate['exposedByVisibility'] || array_intersect_key($candidate['promoted'], $frame['returned']) !== []) {
            $this->voConstructors[$candidate['key']] = true;
        }
    }

    /**
     * Detects a VO constructor: all parameters must be promoted properties and body must be empty.
     *
     * Promoted parameters have a visibility modifier (public/protected/private).
     * Empty body means no statements (property promotion is not a statement).
     */
    private function isVoConstructor(ClassMethod $node): bool
    {
        // Must have at least one parameter to be a meaningful VO constructor
        if ($node->params === []) {
            return false;
        }

        // All parameters must be promoted (have visibility flags)
        foreach ($node->params as $param) {
            if ($param->flags === 0) {
                return false;
            }
        }

        // Body must be empty or absent (no statements)
        return $node->stmts === null || $node->stmts === [];
    }
}
