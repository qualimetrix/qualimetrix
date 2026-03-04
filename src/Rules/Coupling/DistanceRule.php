<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Namespace_\ProjectNamespaceResolverInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

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
    public const string NAME = 'distance';
    private const string METRIC_DISTANCE = 'distance';
    private const string METRIC_ABSTRACTNESS = 'abstractness';
    private const string METRIC_INSTABILITY = 'instability';

    private ?ProjectNamespaceResolverInterface $namespaceResolver;

    public function __construct(
        RuleOptionsInterface $options,
        ?ProjectNamespaceResolverInterface $namespaceResolver = null,
    ) {
        if (!$options instanceof DistanceOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', DistanceOptions::class, $options::class),
            );
        }
        parent::__construct($options);

        // Store namespace resolver (lazily initialized if not provided)
        $this->namespaceResolver = $namespaceResolver;
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
        return [self::METRIC_DISTANCE, self::METRIC_ABSTRACTNESS, self::METRIC_INSTABILITY];
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

        foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $namespace = $nsInfo->symbolPath->namespace;

            // Skip empty namespaces
            if ($namespace === null) {
                continue;
            }

            // Skip namespaces not belonging to the project
            if (!$this->shouldAnalyzeNamespace($namespace)) {
                continue;
            }

            $metrics = $context->metrics->get($nsInfo->symbolPath);
            $distance = $metrics->get(self::METRIC_DISTANCE);

            if ($distance === null) {
                continue;
            }

            $distanceValue = (float) $distance;
            $severity = $this->options->getSeverity($distanceValue);

            if ($severity !== null) {
                $abstractness = (float) ($metrics->get(self::METRIC_ABSTRACTNESS) ?? 0.0);
                $instability = (float) ($metrics->get(self::METRIC_INSTABILITY) ?? 0.0);

                $violations[] = new Violation(
                    location: new Location($nsInfo->file, $nsInfo->line),
                    symbolPath: $nsInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf(
                        'Distance from main sequence is %.2f (A=%.2f, I=%.2f), exceeds threshold of %.2f. Balance abstractness and stability',
                        $distanceValue,
                        $abstractness,
                        $instability,
                        $severity === Severity::Error ? $this->options->maxDistanceError : $this->options->maxDistanceWarning,
                    ),
                    severity: $severity,
                    metricValue: $distanceValue,
                );
            }
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
        foreach ($this->options->excludeNamespaces as $excludePrefix) {
            if ($this->namespaceMatchesPrefix($namespace, $excludePrefix)) {
                return false;
            }
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
