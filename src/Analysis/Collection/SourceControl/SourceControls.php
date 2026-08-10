<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\SourceControl;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Collection\Declaration\DeclarationBindings;
use Qualimetrix\Baseline\Suppression\SuppressionExtractor;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Suppression\ControlScope;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\ThresholdDiagnostic;
use Qualimetrix\Core\Suppression\ThresholdOverride;

/** Immutable source-level suppression and threshold extraction result. */
final readonly class SourceControls
{
    /** @var array<string, true> */
    private const array SUPPRESSION_NODE_TYPES = [
        'Stmt_Class' => true,
        'Stmt_Interface' => true,
        'Stmt_Trait' => true,
        'Stmt_Enum' => true,
        'Stmt_ClassMethod' => true,
        'Stmt_Function' => true,
        'Stmt_Property' => true,
        'PropertyHook' => true,
        'Expr_Closure' => true,
        'Expr_ArrowFunction' => true,
        'Param' => true,
        'Stmt_EnumCase' => true,
        'Stmt_ClassConst' => true,
        'Stmt_Expression' => true,
    ];

    /** @var array<string, true> */
    private const array THRESHOLD_NODE_TYPES = [
        'Stmt_Class' => true,
        'Stmt_Interface' => true,
        'Stmt_Trait' => true,
        'Stmt_Enum' => true,
        'Stmt_ClassMethod' => true,
        'Stmt_Function' => true,
        'Stmt_Property' => true,
        'PropertyHook' => true,
        'Expr_Closure' => true,
        'Expr_ArrowFunction' => true,
    ];

    /**
     * @param list<Suppression> $suppressions
     * @param list<ThresholdOverride> $thresholdOverrides
     * @param list<ThresholdDiagnostic> $thresholdDiagnostics
     */
    private function __construct(
        public array $suppressions,
        public array $thresholdOverrides,
        public array $thresholdDiagnostics,
    ) {}

    /**
     * @param array<Node> $ast
     */
    public static function extract(
        array $ast,
        DeclarationBindings $bindings,
        SuppressionExtractor $suppressionExtractor,
        ThresholdOverrideExtractor $thresholdOverrideExtractor,
    ): self {
        return new self(
            self::extractSuppressions($ast, $bindings, $suppressionExtractor),
            ...self::extractThresholdOverrides($ast, $bindings, $thresholdOverrideExtractor),
        );
    }

    /**
     * @param array<Node> $ast
     *
     * @return list<Suppression>
     */
    private static function extractSuppressions(array $ast, DeclarationBindings $bindings, SuppressionExtractor $extractor): array
    {
        $suppressions = [];
        if ($ast !== []) {
            $suppressions = $extractor->extractFileLevelSuppressions($ast[0]);
        }

        $nodes = (new NodeFinder())->find($ast, self::canCarrySuppression(...));
        foreach ($nodes as $node) {
            $nodeBindings = $bindings->bindingsFor($node);
            if ($nodeBindings === []) {
                array_push($suppressions, ...$extractor->extractPhysical($node));
                continue;
            }

            foreach ($nodeBindings as $binding) {
                array_push($suppressions, ...$extractor->extract($node, $binding['subject'], $binding['scope']));
            }
        }

        return self::deduplicate($suppressions);
    }

    private static function canCarrySuppression(Node $node): bool
    {
        if (isset(self::SUPPRESSION_NODE_TYPES[$node->getType()])) {
            return true;
        }

        foreach ($node->getComments() as $comment) {
            if (!$comment instanceof \PhpParser\Comment\Doc && str_contains($comment->getText(), '@qmx-ignore')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<Node> $ast
     *
     * @return array{list<ThresholdOverride>, list<ThresholdDiagnostic>}
     */
    private static function extractThresholdOverrides(array $ast, DeclarationBindings $bindings, ThresholdOverrideExtractor $extractor): array
    {
        $overrides = [];
        $diagnostics = [];
        $nodes = (new NodeFinder())->find($ast, static fn(Node $node): bool => isset(self::THRESHOLD_NODE_TYPES[$node->getType()]));

        foreach ($nodes as $node) {
            $nodeBindings = $bindings->bindingsFor($node);
            if ($nodeBindings === [] && $node->getType() === 'Stmt_Property') {
                foreach ($bindings->fallbackBindingsForProperty($node) as $fallback) {
                    $result = $extractor->extractWithDiagnostics($node, $fallback['subject'], $fallback['scope']);
                    array_push($diagnostics, ...$result->diagnostics);
                }
                continue;
            }

            foreach ($nodeBindings as $binding) {
                $result = $extractor->extractWithDiagnostics($node, $binding['subject'], $binding['scope']);
                array_push($overrides, ...$result->overrides);
                array_push($diagnostics, ...$result->diagnostics);
            }
        }

        return [$overrides, $diagnostics];
    }

    /**
     * @param list<Suppression> $suppressions
     *
     * @return list<Suppression>
     */
    private static function deduplicate(array $suppressions): array
    {
        $unique = [];
        foreach ($suppressions as $suppression) {
            $unique[implode('|', [
                $suppression->type->value,
                $suppression->rule,
                $suppression->reason ?? '',
                (string) $suppression->line,
                (string) ($suppression->endLine ?? -1),
                $suppression->subject?->toCanonical() ?? '',
                self::scopeKey($suppression->controlScope),
            ])] = $suppression;
        }

        return array_values($unique);
    }

    private static function scopeKey(?ControlScope $scope): string
    {
        if ($scope === null) {
            return '';
        }

        return $scope->name;
    }
}
