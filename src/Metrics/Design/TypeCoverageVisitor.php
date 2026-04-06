<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Design;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\ClassWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Metrics\ResettableVisitorInterface;
use Qualimetrix\Metrics\VisitorMethodTrackingTrait;

/**
 * Visitor for collecting type coverage metrics per class.
 *
 * Tracks:
 * - Parameter type declarations (total and typed)
 * - Return type declarations (total and typed)
 * - Property type declarations (total and typed)
 *
 * Promoted constructor properties are counted as both parameters and properties.
 * Magic methods __construct, __destruct, __clone are excluded from return type counting.
 * Anonymous classes are skipped.
 */
final class TypeCoverageVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use VisitorMethodTrackingTrait;

    /** @var array<string, array{paramTotal: int, paramTyped: int, returnTotal: int, returnTyped: int, propertyTotal: int, propertyTyped: int}> */
    private array $classTypeInfo = [];

    /** @var array<string, array{namespace: ?string, class: string, line: int}> */
    private array $classInfos = [];

    private ?string $currentNamespace = null;
    private ?string $currentClass = null; // @phpstan-ignore property.unusedType (assigned via VisitorMethodTrackingTrait)
    private int $closureCounter = 0;

    /**
     * Magic methods excluded from return type counting.
     * These methods have implicit void return and PHP doesn't allow explicit return types on them.
     *
     * @var list<string>
     */
    private const RETURN_TYPE_EXCLUDED_METHODS = [
        '__construct',
        '__destruct',
        '__clone',
    ];

    public function reset(): void
    {
        $this->classTypeInfo = [];
        $this->classInfos = [];
        $this->currentNamespace = null;
        $this->currentClass = null;
        $this->closureCounter = 0;
    }

    /**
     * @return array<string, array{paramTotal: int, paramTyped: int, returnTotal: int, returnTyped: int, propertyTotal: int, propertyTyped: int}>
     */
    public function getClassTypeInfo(): array
    {
        return $this->classTypeInfo;
    }

    /**
     * @return array<string, array{namespace: ?string, class: string, line: int}>
     */
    public function getClassInfos(): array
    {
        return $this->classInfos;
    }

    /**
     * Returns structured class metrics for each analyzed class.
     *
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array
    {
        $result = [];

        foreach ($this->classInfos as $fqn => $info) {
            $typeInfo = $this->classTypeInfo[$fqn] ?? null;

            if ($typeInfo === null) {
                continue;
            }

            $bag = (new MetricBag())
                ->with('typeCoverage.paramTotal', $typeInfo['paramTotal'])
                ->with('typeCoverage.paramTyped', $typeInfo['paramTyped'])
                ->with('typeCoverage.returnTotal', $typeInfo['returnTotal'])
                ->with('typeCoverage.returnTyped', $typeInfo['returnTyped'])
                ->with('typeCoverage.propertyTotal', $typeInfo['propertyTotal'])
                ->with('typeCoverage.propertyTyped', $typeInfo['propertyTyped']);

            if ($typeInfo['paramTotal'] > 0) {
                $bag = $bag->with(
                    'typeCoverage.param',
                    round($typeInfo['paramTyped'] / $typeInfo['paramTotal'] * 100, 2),
                );
            }

            if ($typeInfo['returnTotal'] > 0) {
                $bag = $bag->with(
                    'typeCoverage.return',
                    round($typeInfo['returnTyped'] / $typeInfo['returnTotal'] * 100, 2),
                );
            }

            if ($typeInfo['propertyTotal'] > 0) {
                $bag = $bag->with(
                    'typeCoverage.property',
                    round($typeInfo['propertyTyped'] / $typeInfo['propertyTotal'] * 100, 2),
                );
            }

            $result[] = new ClassWithMetrics(
                namespace: $info['namespace'],
                class: $info['class'],
                line: $info['line'],
                metrics: $bag,
            );
        }

        return $result;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';
        }

        // Enter class-like node (Class_, Interface_, Trait_, Enum_)
        $className = $this->extractClassLikeName($node);
        if ($className !== null) {
            $fqn = $this->buildClassFqn($className);

            $info = $this->analyzeClassLike($node);
            $this->classTypeInfo[$fqn] = $info;
            $this->classInfos[$fqn] = [
                'namespace' => $this->currentNamespace,
                'class' => $className,
                'line' => $node->getStartLine(),
            ];
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    /**
     * @return array{paramTotal: int, paramTyped: int, returnTotal: int, returnTyped: int, propertyTotal: int, propertyTyped: int}
     */
    private function analyzeClassLike(Node $node): array
    {
        $info = [
            'paramTotal' => 0,
            'paramTyped' => 0,
            'returnTotal' => 0,
            'returnTyped' => 0,
            'propertyTotal' => 0,
            'propertyTyped' => 0,
        ];

        $stmts = $this->getClassStmts($node);

        foreach ($stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $this->analyzeMethod($stmt, $info);
            } elseif ($stmt instanceof Property) {
                $this->analyzeProperty($stmt, $info);
            }
        }

        return $info;
    }

    /**
     * @param array{paramTotal: int, paramTyped: int, returnTotal: int, returnTyped: int, propertyTotal: int, propertyTyped: int} $info
     */
    private function analyzeMethod(ClassMethod $method, array &$info): void
    {
        $methodName = $method->name->toString();

        // Count parameters
        foreach ($method->params as $param) {
            $info['paramTotal']++;
            if ($param->type !== null) {
                $info['paramTyped']++;
            }

            // Promoted properties count as properties too
            if ($this->isPromotedProperty($param)) {
                $info['propertyTotal']++;
                if ($param->type !== null) {
                    $info['propertyTyped']++;
                }
            }
        }

        // Count return types (skip magic methods that don't allow return types)
        if (!\in_array($methodName, self::RETURN_TYPE_EXCLUDED_METHODS, true)) {
            $info['returnTotal']++;
            if ($method->returnType !== null) {
                $info['returnTyped']++;
            }
        }
    }

    /**
     * @param array{paramTotal: int, paramTyped: int, returnTotal: int, returnTyped: int, propertyTotal: int, propertyTyped: int} $info
     */
    private function analyzeProperty(Property $property, array &$info): void
    {
        // Each Property node can declare multiple properties (e.g., public int $a, $b;)
        // but they all share the same type declaration
        $isTyped = $property->type !== null;

        foreach ($property->props as $_) {
            $info['propertyTotal']++;
            if ($isTyped) {
                $info['propertyTyped']++;
            }
        }
    }

    private function isPromotedProperty(Param $param): bool
    {
        // In php-parser v5, promoted properties have visibility flags.
        // MODIFIER_READONLY is included for completeness (readonly alone requires
        // visibility in PHP, but php-parser may set it independently).
        return ($param->flags & (Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE | Class_::MODIFIER_READONLY)) !== 0;
    }

    private function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    /**
     * @return array<Node\Stmt>
     */
    private function getClassStmts(Node $node): array
    {
        if ($node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_
        ) {
            return $node->stmts;
        }

        return [];
    }
}
