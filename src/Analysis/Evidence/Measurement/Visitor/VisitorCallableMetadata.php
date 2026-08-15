<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\VisitorCallableScope;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Projects immutable callable metadata from typed traversal scopes. */
final class VisitorCallableMetadata
{
    public function create(VisitorCallableScope $scope, RelativePath $file, MetricBag $metrics, ?int $ordinal = null): CallableWithMetrics
    {
        $namespace = $scope->namespace ?? '';
        $logical = \in_array($scope->kind, [CallableKind::Method, CallableKind::PropertyHook], true) && $scope->class !== null
            ? SymbolPath::forMethod($namespace, $scope->class, $scope->member)
            : SymbolPath::forGlobalFunction($namespace, $scope->member);
        $lexical = $scope->class !== null && $scope->classStartFilePos !== null
            ? new DeclarationPath(SymbolPath::forClass($namespace, $scope->class), $file, $scope->classStartFilePos)
            : null;
        $owner = $lexical !== null
            && !$scope->anonymousClassContext
            && \in_array($scope->kind, [CallableKind::Method, CallableKind::PropertyHook], true)
                ? new LogicalClassPath($lexical->logical)
                : null;

        return new CallableWithMetrics(
            new DeclarationPath($logical, $file, $scope->startFilePos, $ordinal),
            $scope->kind,
            $scope->anonymousSyntax,
            $lexical,
            $owner,
            $metrics,
            $scope->sourceLine,
        );
    }

    /**
     * @param array<string, VisitorCallableScope> $scopes
     *
     * @return array<string, int|null>
     */
    public function collisionOrdinals(array $scopes): array
    {
        $groups = [];
        foreach ($scopes as $key => $scope) {
            $groups[implode("\0", [$scope->namespace ?? '', $scope->class ?? '', $scope->member, (string) $scope->startFilePos, $scope->kind->value])][] = $key;
        }
        $ordinals = array_fill_keys(array_keys($scopes), null);
        foreach ($groups as $keys) {
            if (\count($keys) > 1) {
                foreach ($keys as $ordinal => $key) {
                    $ordinals[$key] = $ordinal;
                }
            }
        }

        return $ordinals;
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, VisitorCallableScope> $scopes
     *
     * @return array<string, mixed>
     */
    public function projectLogicalMetricMap(array $metrics, array $scopes): array
    {
        $projected = [];
        foreach ($metrics as $key => $value) {
            $scope = $scopes[$key] ?? null;
            $logicalFqn = $scope === null ? $key : $scope->logicalFqn;
            if ($scope?->kind === CallableKind::AnonymousCallable && $scope->class === null) {
                $logicalFqn = ($scope->namespace ?? '') . '::' . $scope->member;
            }
            $projected[$logicalFqn] = $value;
        }

        return $projected;
    }
}
