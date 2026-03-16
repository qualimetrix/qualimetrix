<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Namespace_\ProjectNamespaceResolverInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use Psr\Log\LoggerInterface;

/**
 * Rule that checks distance from main sequence at namespace level.
 *
 * Distance = |A + I - 1|, range [0, 1]
 * Where:
 * - A = Abstractness (ratio of abstract classes/interfaces)
 * - I = Instability (Ce / (Ca + Ce))
 *
 * The main sequence is the line where A + I = 1.
 * - Zone of Pain: high stability, low abstractness (bottom-left)
 * - Zone of Uselessness: low stability, high abstractness (top-right)
 *
 * Packages should ideally be close to the main sequence.
 *
 * Namespace filtering:
 * - By default, uses ProjectNamespaceResolver to auto-detect project namespaces from composer.json
 * - Use `includeNamespaces` option to override auto-detection
 * - Use `excludeNamespaces` option to exclude specific namespaces
 */
final class DistanceRule extends AbstractRule
{
    public const string NAME = 'coupling.distance';

    public function __construct(
        RuleOptionsInterface $options,
        private readonly ?ProjectNamespaceResolverInterface $namespaceResolver = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks distance from main sequence at namespace level';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Coupling;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [MetricName::COUPLING_DISTANCE, MetricName::COUPLING_ABSTRACTNESS, MetricName::COUPLING_INSTABILITY];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof DistanceOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $totalNamespaces = 0;
        $analyzedNamespaces = 0;

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $namespace = $nsInfo->symbolPath->namespace;

            // Skip empty namespaces
            if ($namespace === null) {
                continue;
            }

            ++$totalNamespaces;

            // Skip namespaces not belonging to the project
            if (!$this->shouldAnalyzeNamespace($namespace)) {
                continue;
            }

            ++$analyzedNamespaces;

            $metrics = $context->metrics->get($nsInfo->symbolPath);

            // Skip namespaces with too few classes for meaningful analysis
            $classCount = (int) ($metrics->get(MetricName::SIZE_CLASS_COUNT . '.sum') ?? 0);
            if ($classCount < $this->options->minClassCount) {
                continue;
            }

            $distance = $metrics->get(MetricName::COUPLING_DISTANCE);

            if ($distance === null) {
                continue;
            }

            $distanceValue = (float) $distance;
            $severity = $this->options->getSeverity($distanceValue);

            if ($severity !== null) {
                $abstractness = (float) ($metrics->get(MetricName::COUPLING_ABSTRACTNESS) ?? 0.0);
                $instability = (float) ($metrics->get(MetricName::COUPLING_INSTABILITY) ?? 0.0);

                $threshold = $severity === Severity::Error ? $this->options->maxDistanceError : $this->options->maxDistanceWarning;

                $violations[] = new Violation(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'Distance from main sequence is %.2f (A=%.2f, I=%.2f), exceeds threshold of %.2f. Balance abstractness and stability',
                        $distanceValue,
                        $abstractness,
                        $instability,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: $distanceValue,
                    recommendation: \sprintf('Distance: %.2f (threshold: %.2f) — poor balance of abstraction and stability', $distanceValue, $threshold),
                    threshold: $threshold,
                );
            }
        }

        // Warn when namespaces exist but none matched project namespace filter
        if ($analyzedNamespaces === 0 && $totalNamespaces > 0) {
            $this->logger?->warning(
                'Distance rule: no project namespaces detected among {total} namespaces. '
                . "Use --rule-opt='coupling.distance:include_namespaces=...' to specify namespaces for vendor code analysis.",
                ['total' => $totalNamespaces],
            );
        }

        return $violations;
    }

    /**
     * Determines if namespace should be analyzed.
     *
     * Logic:
     * 1. Check explicit excludeNamespaces list first
     * 2. If includeNamespaces is set, check against that list
     * 3. If ProjectNamespaceResolver is provided, use it
     * 4. Otherwise, include all namespaces
     */
    private function shouldAnalyzeNamespace(string $namespace): bool
    {
        \assert($this->options instanceof DistanceOptions);

        // Check explicit exclusions first
        if ($this->options->isNamespaceExcluded($namespace)) {
            return false;
        }

        // If explicit includes are set, check against them
        if ($this->options->includeNamespaces !== null && $this->options->includeNamespaces !== []) {
            foreach ($this->options->includeNamespaces as $includePrefix) {
                if ($this->namespaceMatchesPrefix($namespace, $includePrefix)) {
                    return true;
                }
            }
            return false;
        }

        // Use resolver if available
        if ($this->namespaceResolver !== null) {
            return $this->namespaceResolver->isProjectNamespace($namespace);
        }

        // Include all namespaces by default
        return true;
    }

    /**
     * Check if namespace matches a prefix (with proper boundary check).
     */
    private function namespaceMatchesPrefix(string $namespace, string $prefix): bool
    {
        $prefix = rtrim($prefix, '\\');

        if ($namespace === $prefix) {
            return true;
        }

        return str_starts_with($namespace, $prefix . '\\');
    }

    /**
     * @return class-string<DistanceOptions>
     */
    public static function getOptionsClass(): string
    {
        return DistanceOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'distance-warning' => 'max_distance_warning',
            'distance-error' => 'max_distance_error',
        ];
    }
}
