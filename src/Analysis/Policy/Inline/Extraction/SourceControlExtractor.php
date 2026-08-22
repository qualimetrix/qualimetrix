<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControlExtractorInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControls;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Extracts Inline-owned source controls and binds them to measured declarations.
 */
final readonly class SourceControlExtractor implements SourceControlExtractorInterface
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

    public function __construct(
        private SuppressionExtractor $suppressionExtractor = new SuppressionExtractor(),
        private ThresholdOverrideExtractor $thresholdOverrideExtractor = new ThresholdOverrideExtractor(),
    ) {}

    /**
     * @param array<Node> $ast
     * @param list<CallableWithMetrics> $callableMetrics
     * @param array<string, array{subject: MetricSubject, metrics: MetricBag, line: int, start: int}> $classMetrics
     */
    public function extract(
        array $ast,
        RelativePath $file,
        array $callableMetrics,
        array $classMetrics,
    ): SourceControls {
        $bindings = DeclarationControlBindings::from($ast, $file, $callableMetrics, $classMetrics);

        return new SourceControls(
            self::extractSuppressions($ast, $bindings, $this->suppressionExtractor),
            ...self::extractThresholdOverrides($ast, $bindings, $this->thresholdOverrideExtractor),
        );
    }

    /**
     * @param array<Node> $ast
     *
     * @return list<Suppression>
     */
    private static function extractSuppressions(array $ast, DeclarationControlBindings $bindings, SuppressionExtractor $extractor): array
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
    private static function extractThresholdOverrides(array $ast, DeclarationControlBindings $bindings, ThresholdOverrideExtractor $extractor): array
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
