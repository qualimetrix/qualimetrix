<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Psr\Log\LoggerInterface;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Namespace_\ProjectNamespaceResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Util\NamespaceMatcher;

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
 * - Use `exclude_namespaces` (universal per-rule option) to exclude specific namespaces
 */
#[CliAlias('distance-warning', 'max_distance_warning')]
#[CliAlias('distance-error', 'max_distance_error')]
final class DistanceRule extends AbstractRule
{
    public const string NAME = 'coupling.distance';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 30;
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
            $result = $this->namespaceResult($nsInfo, $context);
            $totalNamespaces += (int) $result['present'];
            $analyzedNamespaces += (int) $result['projectMatched'];
            if ($result['violation'] !== null) {
                $violations[] = $result['violation'];
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
     * @return array{present: bool, projectMatched: bool, violation: ?Violation}
     */
    private function namespaceResult(SymbolInfo $namespaceInfo, AnalysisContext $context): array
    {
        \assert($this->options instanceof DistanceOptions);

        $namespace = $namespaceInfo->symbolPath->namespace;
        if ($namespace === null) {
            return ['present' => false, 'projectMatched' => false, 'violation' => null];
        }

        if (!$this->shouldAnalyzeNamespace($namespace)) {
            return ['present' => true, 'projectMatched' => false, 'violation' => null];
        }

        return [
            'present' => true,
            'projectMatched' => true,
            'violation' => $this->matchedNamespaceViolation($namespaceInfo, $context),
        ];
    }

    private function matchedNamespaceViolation(SymbolInfo $info, AnalysisContext $context): ?Violation
    {
        \assert($this->options instanceof DistanceOptions);

        $metrics = $context->metrics->get($info->symbolPath);
        $classCount = (int) ($metrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 0);
        $distance = $metrics->get(MetricName::COUPLING_DISTANCE);
        if ($classCount < $this->options->minClassCount || $distance === null) {
            return null;
        }

        $subject = $info->subject ?? MetricSubject::aggregate($info->symbolPath);
        $distanceValue = (float) $distance;
        /** @var DistanceOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
        $severity = $effectiveOptions->getSeverity($distanceValue);
        if ($severity === null) {
            return null;
        }

        $abstractness = (float) ($metrics->get(MetricName::COUPLING_ABSTRACTNESS) ?? 0.0);
        $instability = (float) ($metrics->get(MetricName::COUPLING_INSTABILITY) ?? 0.0);
        $threshold = $severity === Severity::Error ? $effectiveOptions->maxDistanceError : $effectiveOptions->maxDistanceWarning;

        return new Violation(
            location: new Location($info->file, $info->line),
            subject: $subject,
            symbolPath: $info->symbolPath,
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

    /**
     * Determines if namespace should be analyzed.
     *
     * Logic:
     * 1. If includeNamespaces is set, check against that list
     * 2. If ProjectNamespaceResolver is provided, use it
     * 3. Otherwise, include all namespaces
     *
     * Note: exclude_namespaces is handled at framework level by RuleExecution.
     */
    private function shouldAnalyzeNamespace(string $namespace): bool
    {
        \assert($this->options instanceof DistanceOptions);

        // If explicit includes are set, check against them
        if ($this->options->includeNamespaces !== null && $this->options->includeNamespaces !== []) {
            foreach ($this->options->includeNamespaces as $includePrefix) {
                if (NamespaceMatcher::matchesSingle($includePrefix, $namespace)) {
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
     * @return class-string<DistanceOptions>
     */
    public static function getOptionsClass(): string
    {
        return DistanceOptions::class;
    }

    /**
     * `coupling.distance` reports the distance-from-main-sequence value
     * (`$distanceValue` — see the emission above) as `metricValue`, judged
     * worse the higher it goes:
     * {@see DistanceOptions::getSeverity()}'s `$distance >=
     * $this->maxDistanceError` (line 95) / `$distance >=
     * $this->maxDistanceWarning` (line 99).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
