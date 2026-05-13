<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Architecture;

use Qualimetrix\Core\Architecture\ArchitectureConfiguration;
use Qualimetrix\Core\Architecture\CoverageMode;
use Qualimetrix\Core\Architecture\Layer\LayerCollisionException;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Rules\AbstractRule;

/**
 * Reports dependencies that violate the user-declared architecture policy.
 *
 * The rule reads {@see AnalysisContext::$architecture} for the layer registry
 * and allow-list, and {@see AnalysisContext::$dependencyGraph} for the set of
 * concrete dependency edges. Every edge whose source and target both fall into
 * declared layers and whose source→target pair is not in the policy's
 * allow-list produces one {@see Violation}.
 *
 * **Diagnostic violations produced under separate rule names:**
 * - `architecture.coverage` — when {@see ArchitectureConfiguration::coverage()}
 *   is not {@see CoverageMode::Ignore}, summarises out-of-layer edges as one
 *   aggregated {@see Severity::Info} or {@see Severity::Error} Violation.
 * - `architecture.layer-collision` — for every class FQN that matches two or
 *   more layer definitions with equal specificity (configuration error). One
 *   {@see Severity::Error} Violation per ambiguous class is emitted. These
 *   surface silently-dropped edges that the {@see LayerCollisionException}
 *   short-circuit would otherwise hide. Configuration validation in
 *   {@see \Qualimetrix\Configuration\Architecture\ArchitectureConfigurationFactory}
 *   catches many cases up front; these diagnostics catch the rest.
 */
final class LayerViolationRule extends AbstractRule
{
    public const string NAME = 'architecture.layer-violation';

    public const string COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage';

    public const string COLLISION_DIAGNOSTIC_NAME = 'architecture.layer-collision';

    private const int COVERAGE_SAMPLE_LIMIT = 10;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Detects dependencies between layers that are not explicitly allowed by the architecture policy.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Architecture;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return class-string<LayerViolationOptions>
     */
    public static function getOptionsClass(): string
    {
        return LayerViolationOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'layer-violation' => 'enabled',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof LayerViolationOptions);

        if (!$this->options->isEnabled()) {
            return [];
        }

        $architecture = $context->architecture;
        if ($architecture === null || $architecture->isEmpty()) {
            return [];
        }

        $graph = $context->dependencyGraph;
        if ($graph === null) {
            return [];
        }

        $violations = [];
        $unmatchedSourceEdges = 0;
        $unmatchedTargetEdges = 0;
        $unmatchedClasses = [];
        $collisions = [];

        foreach ($graph->getAllDependencies() as $dependency) {
            $resolution = $this->resolveEdge($dependency, $architecture, $collisions);
            if ($resolution === null) {
                continue;
            }

            [$fromLayer, $toLayer] = $resolution;

            if ($fromLayer === null) {
                $unmatchedSourceEdges++;
                $unmatchedClasses[$dependency->source->toCanonical()] = $dependency->source->toString();
            }

            if ($toLayer === null) {
                $unmatchedTargetEdges++;
                $unmatchedClasses[$dependency->target->toCanonical()] = $dependency->target->toString();
            }

            $violation = $this->buildViolation($dependency, $fromLayer, $toLayer, $architecture);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        $diagnostic = $this->buildCoverageDiagnostic(
            $architecture->coverage(),
            $unmatchedSourceEdges,
            $unmatchedTargetEdges,
            array_values($unmatchedClasses),
        );
        if ($diagnostic !== null) {
            $violations[] = $diagnostic;
        }

        foreach ($this->buildCollisionDiagnostics($collisions) as $collisionDiagnostic) {
            $violations[] = $collisionDiagnostic;
        }

        return $violations;
    }

    /**
     * Resolves both ends of an edge. On {@see LayerCollisionException} the edge
     * is dropped from the violation flow but the ambiguous class is recorded
     * in `$collisions` so a dedicated diagnostic Violation surfaces the
     * configuration error.
     *
     * Otherwise returns a [fromLayer, toLayer] tuple where each entry may be
     * null (out-of-layer end).
     *
     * @param array<string, LayerCollisionException> $collisions
     *
     * @param-out array<string, LayerCollisionException> $collisions
     *
     * @return array{0: ?string, 1: ?string}|null
     */
    private function resolveEdge(
        Dependency $dependency,
        ArchitectureConfiguration $architecture,
        array &$collisions,
    ): ?array {
        $registry = $architecture->registry();

        try {
            $fromLayer = $registry->resolveLayer($dependency->source);
        } catch (LayerCollisionException $e) {
            $collisions[$e->getFqn()] = $e;

            return null;
        }

        try {
            $toLayer = $registry->resolveLayer($dependency->target);
        } catch (LayerCollisionException $e) {
            $collisions[$e->getFqn()] = $e;

            return null;
        }

        return [$fromLayer, $toLayer];
    }

    private function buildViolation(
        Dependency $dependency,
        ?string $fromLayer,
        ?string $toLayer,
        ArchitectureConfiguration $architecture,
    ): ?Violation {
        if ($fromLayer === null || $toLayer === null) {
            return null;
        }

        if ($architecture->policy()->isAllowed($fromLayer, $toLayer)) {
            return null;
        }

        \assert($this->options instanceof LayerViolationOptions);

        return new Violation(
            location: $dependency->location,
            symbolPath: $dependency->source,
            ruleName: self::NAME,
            violationCode: self::NAME,
            message: \sprintf(
                'Layer "%s" must not depend on layer "%s" (%s → %s, %s)',
                $fromLayer,
                $toLayer,
                $dependency->source->toString(),
                $dependency->target->toString(),
                $dependency->type->description(),
            ),
            severity: $this->options->severity,
            recommendation: $this->buildRecommendation($dependency, $fromLayer, $toLayer, $architecture),
            dependencyTarget: $dependency->target,
            dependencyType: $dependency->type,
        );
    }

    private function buildRecommendation(
        Dependency $dependency,
        string $fromLayer,
        string $toLayer,
        ArchitectureConfiguration $architecture,
    ): string {
        $allowed = $architecture->policy()->allowedTargets($fromLayer);

        if ($allowed === []) {
            $line1 = \sprintf(
                'Layer "%s" is not allowed to depend on any other declared layer.',
                $fromLayer,
            );
        } else {
            $line1 = \sprintf(
                'Allowed targets for layer "%s": %s. Consider routing through one of them.',
                $fromLayer,
                implode(', ', $allowed),
            );
        }

        $payload = json_encode(
            [
                'fromLayer' => $fromLayer,
                'toLayer' => $toLayer,
                'source' => $dependency->source->toString(),
                'target' => $dependency->target->toString(),
                'type' => $dependency->type->value,
            ],
            \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        return $line1 . "\n" . 'Dep data: ' . $payload;
    }

    /**
     * Builds the coverage diagnostic Violation when {@see CoverageMode} is not
     * {@see CoverageMode::Ignore} and at least one out-of-layer end was seen.
     *
     * Diagnostic severity:
     * - {@see CoverageMode::Warn}  → {@see Severity::Info}  (does not fail the run by default)
     * - {@see CoverageMode::Error} → {@see Severity::Error}
     *
     * @param list<string> $unmatchedClasses Deduplicated class display-name FQNs seen at out-of-layer ends.
     */
    private function buildCoverageDiagnostic(
        CoverageMode $mode,
        int $unmatchedSourceEdges,
        int $unmatchedTargetEdges,
        array $unmatchedClasses,
    ): ?Violation {
        if ($mode === CoverageMode::Ignore) {
            return null;
        }

        $unmatchedEnds = $unmatchedSourceEdges + $unmatchedTargetEdges;
        if ($unmatchedEnds === 0) {
            return null;
        }

        $severity = $mode === CoverageMode::Error ? Severity::Error : Severity::Info;

        sort($unmatchedClasses);
        $sample = \array_slice($unmatchedClasses, 0, self::COVERAGE_SAMPLE_LIMIT);
        $remaining = \count($unmatchedClasses) - \count($sample);

        $sampleList = implode(', ', $sample);
        if ($remaining > 0) {
            $sampleList .= \sprintf(' (+%d more)', $remaining);
        }

        $message = \sprintf(
            'Architecture coverage: %d edge(s) with unmatched source layer, %d edge(s) with unmatched target layer, %d class(es) outside all declared layers.',
            $unmatchedSourceEdges,
            $unmatchedTargetEdges,
            \count($unmatchedClasses),
        );

        $recommendation = $sample === []
            ? 'Declare layers covering the remaining classes or accept the gap by leaving coverage on "ignore".'
            : 'Examples of unclassified classes: ' . $sampleList . '. Declare layers covering these classes or accept the gap by leaving coverage on "ignore".';

        return new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: self::COVERAGE_DIAGNOSTIC_NAME,
            violationCode: self::COVERAGE_DIAGNOSTIC_NAME,
            message: $message,
            severity: $severity,
            recommendation: $recommendation,
        );
    }

    /**
     * Emits one {@see Severity::Error} diagnostic Violation per ambiguous class.
     *
     * @param array<string, LayerCollisionException> $collisions
     *
     * @return list<Violation>
     */
    private function buildCollisionDiagnostics(array $collisions): array
    {
        if ($collisions === []) {
            return [];
        }

        ksort($collisions);

        $violations = [];
        foreach ($collisions as $fqn => $exception) {
            $candidates = [];
            foreach ($exception->getMatches() as [$layerName, $pattern]) {
                $candidates[] = \sprintf('%s (%s)', $layerName, $pattern);
            }

            $message = \sprintf(
                'Class "%s" matches multiple architecture layers with equal specificity: %s. This is a configuration error — every class must belong to at most one layer.',
                $fqn,
                implode(', ', $candidates),
            );

            $recommendation = \sprintf(
                'Tighten one of the colliding patterns so its literal prefix is more specific than the others, or move the class out of the overlap. Affected class: %s.',
                $fqn,
            );

            $violations[] = new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::fromClassFqn($fqn),
                ruleName: self::COLLISION_DIAGNOSTIC_NAME,
                violationCode: self::COLLISION_DIAGNOSTIC_NAME,
                message: $message,
                severity: Severity::Error,
                recommendation: $recommendation,
            );
        }

        return $violations;
    }
}
