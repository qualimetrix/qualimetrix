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

    /**
     * A node is read when an author wrote a `@qmx-` tag on it.
     *
     * There is no list of node types here on purpose, and the list that used
     * to stand beside this condition is gone rather than extended. The
     * physical forms are bound to a line and to a file, not to a declaration,
     * so the grammar of PHP has no say in where they may be written — and a
     * list that answers as if it did is a copy of that grammar which falls
     * behind it in silence: `if`, `foreach`, `return`, `namespace` and `use`
     * were all missing from the one that stood here, and on each of them a
     * docblock directive did nothing while the same directive in a line
     * comment worked. A node carrying no tag yields nothing either way, so the
     * list bought nothing beyond the illusion of governing placement.
     */
    private static function canCarrySuppression(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (str_contains($comment->getText(), SuppressionExtractor::TAG_PREFIX)) {
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
                (string) ($suppression->binding->endLine ?? -1),
                $suppression->binding?->subject->toCanonical() ?? '',
                self::scopeKey($suppression->binding?->controlScope),
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
