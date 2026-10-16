<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Extraction;

use Closure;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControlExtractorInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControls;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveLevels;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Extracts Inline-owned source controls and binds them to measured declarations.
 */
final readonly class SourceControlExtractor implements SourceControlExtractorInterface
{
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
        string $source,
        RelativePath $file,
        array $callableMetrics,
        array $classMetrics,
    ): SourceControls {
        $bindings = DeclarationControlBindings::from($ast, $file, $callableMetrics, $classMetrics);
        $unattached = UnattachedComments::find($ast, $source);
        [$overrides, $diagnostics, $carriedTags] = self::extractThresholdOverrides($ast, $bindings, $unattached, $this->thresholdOverrideExtractor);

        return new SourceControls(
            self::extractSuppressions($ast, $bindings, $unattached, $this->suppressionExtractor, self::thresholdRead($carriedTags)),
            $overrides,
            $diagnostics,
        );
    }

    /**
     * Whether the threshold reader answered for the tag at this offset of this
     * comment.
     *
     * Asked of what the reader actually did rather than of a second copy of
     * its rules — node types, bindings, grammar — so the two extractors cannot
     * disagree about a tag and leave it to neither. The identity is the comment
     * object itself: both passes walk one AST, so it is the same instance.
     *
     * @param list<array{Doc, int}> $carriedTags
     *
     * @return Closure(Comment, int): bool
     */
    private static function thresholdRead(array $carriedTags): Closure
    {
        $carried = [];
        foreach ($carriedTags as [$comment, $offset]) {
            $carried[spl_object_id($comment) . ':' . $offset] = true;
        }

        return static fn(Comment $comment, int $offset): bool => isset($carried[spl_object_id($comment) . ':' . $offset]);
    }

    /**
     * @param array<Node> $ast
     * @param Closure(Comment, int): bool $thresholdRead
     *
     * @return list<Suppression>
     */
    private static function extractSuppressions(
        array $ast,
        DeclarationControlBindings $bindings,
        UnattachedComments $unattached,
        SuppressionExtractor $extractor,
        Closure $thresholdRead,
    ): array {
        $suppressions = [];
        if ($ast !== []) {
            $suppressions = $extractor->extractFileLevelSuppressions($ast[0]);
        }

        $nodes = (new NodeFinder())->find(
            $ast,
            static fn(Node $node): bool => $unattached->owns($node) || self::canCarrySuppression($node),
        );
        foreach ($nodes as $found) {
            $node = $unattached->withOwnedComments($found);
            $nodeBindings = $bindings->suppressionBindingsFor($node);
            if ($nodeBindings === []) {
                array_push($suppressions, ...$extractor->extractPhysical(
                    $node,
                    $thresholdRead,
                    DeclarationControlBindings::unboundReason($node),
                ));
                continue;
            }

            $nodeSuppressions = [];
            foreach ($nodeBindings as $binding) {
                array_push($nodeSuppressions, ...$extractor->extract(
                    $node,
                    $binding->subject,
                    $binding->controlScope,
                    $binding->reach,
                    $thresholdRead,
                ));
            }
            array_push($suppressions, ...self::refuseUnreachableLevels($nodeSuppressions, $nodeBindings));
        }

        foreach ($unattached->unowned() as $comment) {
            $carrier = new Node\Stmt\Nop([
                'comments' => [$comment],
                'startLine' => $comment->getStartLine(),
                'endLine' => $comment->getEndLine(),
                'startFilePos' => $comment->getStartFilePos(),
                'endFilePos' => $comment->getEndFilePos(),
            ]);
            array_push($suppressions, ...$extractor->extractPhysical($carrier, $thresholdRead));
        }

        return self::deduplicate($suppressions);
    }

    /**
     * An authored level is valid only when at least one suppression binding of
     * this carrier reaches it. Bare channels keep their existing semantics,
     * and threshold bindings never enter this union.
     *
     * @param list<Suppression> $suppressions
     * @param non-empty-list<\Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding> $bindings
     *
     * @return list<Suppression>
     */
    private static function refuseUnreachableLevels(array $suppressions, array $bindings): array
    {
        $groups = [];
        foreach ($suppressions as $suppression) {
            $groups[$suppression->authoredSite()][] = $suppression;
        }

        $reachable = DirectiveLevels::reachableByBindings($bindings);
        $result = [];
        foreach ($groups as $group) {
            $directive = $group[0];
            $requested = $directive->target()->selector()?->level();
            if ($directive->type !== SuppressionType::Symbol
                || $directive->refusal !== null
                || $requested === null
                || \in_array($requested, $reachable, true)
            ) {
                array_push($result, ...$group);
                continue;
            }

            $result[] = new Suppression(
                rule: $directive->rule,
                reason: $directive->reason,
                line: $directive->line,
                type: $directive->type,
                position: $directive->position,
                refusal: DirectiveRefusal::levelNotReachableHere($bindings[0]->reach->standsOn),
            );
        }

        return $result;
    }

    /**
     * A node is read when its comments may carry a directive or prefix typo.
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
            if (SuppressionExtractor::mayCarryDirective($comment->getText())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The overrides and diagnostics the file carries, and the tags they came
     * from.
     *
     * A property without hooks is read for its diagnostics only: nothing
     * measures it, so an override written there has nothing to retune. Its
     * tag is therefore not among those carried, and the suppression sweep
     * refuses it rather than letting it vanish.
     *
     * @param array<Node> $ast
     *
     * @return array{list<ThresholdOverride>, list<ThresholdDiagnostic>, list<array{Doc, int}>}
     */
    private static function extractThresholdOverrides(
        array $ast,
        DeclarationControlBindings $bindings,
        UnattachedComments $unattached,
        ThresholdOverrideExtractor $extractor,
    ): array {
        $overrides = [];
        $diagnostics = [];
        $carriedTags = [];
        $nodes = (new NodeFinder())->find(
            $ast,
            static fn(Node $node): bool => $unattached->owns($node)
                || self::canCarrySuppression($node),
        );

        foreach ($nodes as $found) {
            $node = $unattached->withOwnedComments($found);
            $nodeBindings = self::thresholdBindingsFor($node, $bindings);
            if ($nodeBindings === [] && $node->getType() === 'Stmt_Property') {
                foreach ($bindings->fallbackBindingsForProperty($node) as $fallback) {
                    $result = $extractor->extractWithDiagnostics($node, $fallback['subject'], $fallback['scope']);
                    array_push($diagnostics, ...$result->diagnostics);
                    array_push($carriedTags, ...$result->diagnosticTags);
                }
                continue;
            }

            foreach ($nodeBindings as $binding) {
                $result = $extractor->extractWithDiagnostics($node, $binding['subject'], $binding['scope']);
                array_push($overrides, ...$result->overrides);
                array_push($diagnostics, ...$result->diagnostics);
                array_push($carriedTags, ...$result->overrideTags, ...$result->diagnosticTags);
            }
        }

        return [$overrides, $diagnostics, $carriedTags];
    }

    /**
     * The declarations a threshold written on this node retunes.
     *
     * A declaration node binds as it always did. Any other node binds only to
     * a callable beginning where it begins, whose docblock php-parser handed
     * to it; the containment bindings a suppression also has — a parameter to
     * its function, a constant to its class — are deliberately not followed,
     * because a threshold is written on the declaration it retunes.
     *
     * @return list<array{subject: MetricSubject, scope: ControlScope}>
     */
    private static function thresholdBindingsFor(Node $node, DeclarationControlBindings $bindings): array
    {
        return $bindings->thresholdBindingsFor($node);
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
            $unique[implode("\0", [
                $suppression->authoredSite(),
                $suppression->type->value,
                $suppression->reason ?? '',
                $suppression->binding?->reach->key() ?? '',
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
