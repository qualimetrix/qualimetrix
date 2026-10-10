<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Namespace_\ProjectNamespaceResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;

use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\ThresholdCrossing;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

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
 * Besides the published `coupling.distance` value, also reads
 * `coupling.abstractness` and `coupling.instability` to explain the finding
 * in its message/recommendation.
 *
 * Namespace filtering:
 * - By default, uses ProjectNamespaceResolver to auto-detect project namespaces from composer.json
 * - Use `includeNamespaces` option to override auto-detection
 * - Use `suppress_namespaces` (universal per-rule option) to exclude specific namespaces
 *
 * @qmx-threshold coupling.cbo 22 -- Raw CBO 21 includes the ThresholdCrossing wording contract
 *                and the per-rule channel, shape and judged-metric declarations every producer
 *                must name. Moving finding emission would only relocate these dependencies;
 *                the declarations state rule metadata rather than called collaborators.
 *                22 retains one-edge headroom.
 */
#[CliAlias('distance-warning', 'max_distance_warning')]
#[CliAlias('distance-error', 'max_distance_error')]
final class DistanceRule extends AbstractRule
{
    public const string NAME = 'coupling.distance';
    public const string DOCS_PAGE = 'rules/coupling.md';

    public const int REMEDIATION_MINUTES = 30;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;

    private readonly ?NamespaceMatcher $includeMatcher;

    public function __construct(
        RuleOptionsInterface $options,
        private readonly ?ProjectNamespaceResolverInterface $namespaceResolver = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct($options);
        $this->includeMatcher = $options instanceof DistanceOptions
            && $options->includeNamespaces !== null
            && $options->includeNamespaces !== []
                ? new NamespaceMatcher($options->includeNamespaces)
                : null;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks distance from main sequence at namespace level';
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof DistanceOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $totalNamespaces = 0;
        $analyzedNamespaces = 0;
        $declaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->all(SymbolLevel::Namespace_) as $nsInfo) {
            $result = $this->namespaceResult($nsInfo, $context, $declaration);
            $totalNamespaces += (int) $result['present'];
            $analyzedNamespaces += (int) $result['projectMatched'];
            if ($result['finding'] !== null) {
                $findings[] = $result['finding'];
            }
        }

        // Warn when namespaces exist but none matched project namespace filter
        if ($analyzedNamespaces === 0 && $totalNamespaces > 0) {
            $this->logger?->warning(
                'Distance rule: no project namespaces detected among {total} namespaces. '
                . "Use --rule-opt='coupling.distance:include-namespaces=subtree:App' to specify a namespace selector for vendor code analysis.",
                ['total' => $totalNamespaces],
            );
        }

        return $findings;
    }

    /**
     * @return array{present: bool, projectMatched: bool, finding: ?Finding}
     */
    private function namespaceResult(SymbolInfo $namespaceInfo, AnalysisContext $context, ChannelDeclaration $declaration): array
    {
        \assert($this->options instanceof DistanceOptions);

        $namespace = $namespaceInfo->symbolPath->namespace;
        $present = $namespace !== null;
        $matched = false;
        $admitted = $context->admit(
            self::NAME,
            new FindingChannel(self::NAME),
            SymbolLevel::Namespace_,
            PopulationIdentity::aggregate($namespaceInfo->symbolPath),
            $declaration,
            (function () use ($namespace, $present, $namespaceInfo, $context, &$matched): iterable {
                yield GateInput::context('namespaceCoordinateKnown', $present);
                \assert($namespace !== null);
                $matched = $this->shouldAnalyzeNamespace($namespace);
                yield GateInput::boundName('namespace-selected', $matched);
                $metrics = $context->metrics->get($namespaceInfo->symbolPath);
                \assert($this->options instanceof DistanceOptions);
                yield GateInput::metrics('own-types', $metrics, $this->options->minTypeCount);
                yield GateInput::metrics('own-distance', $metrics);
                yield GateInput::metrics('own-coupling', $metrics);
            })(),
        );
        return ['present' => $present, 'projectMatched' => $matched, 'finding' => $admitted ? $this->matchedNamespaceFinding($namespaceInfo, $context) : null];
    }

    private function matchedNamespaceFinding(SymbolInfo $info, AnalysisContext $context): ?Finding
    {
        \assert($this->options instanceof DistanceOptions);

        $metrics = $context->metrics->get($info->symbolPath);
        $distance = $metrics->get(MetricName::COUPLING_DISTANCE_OWN);
        $subject = $info->subject ?? MetricSubject::aggregate($info->symbolPath);
        $distanceValue = (float) $distance;
        /** @var DistanceOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
        $severity = $effectiveOptions->getSeverity($distanceValue);
        if ($severity === null) {
            return null;
        }

        $abstractness = (float) ($metrics->get(MetricName::COUPLING_ABSTRACTNESS_OWN) ?? 0.0);
        $instability = (float) ($metrics->get(MetricName::COUPLING_INSTABILITY_OWN) ?? 0.0);
        $threshold = $severity === Severity::Error ? $effectiveOptions->maxDistanceError : $effectiveOptions->maxDistanceWarning;

        return new Finding(
            location: new Location($info->file, $info->line),
            subject: $subject,
            symbolPath: $info->symbolPath,
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'Distance from main sequence is %.2f (A=%.2f, I=%.2f), ' . ThresholdCrossing::of($distanceValue, $threshold)->value . ' threshold of %.2f. Balance abstractness and stability',
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
     * Note: suppress_namespaces is handled at framework level by RuleExecution.
     */
    private function shouldAnalyzeNamespace(string $namespace): bool
    {
        \assert($this->options instanceof DistanceOptions);

        // If explicit includes are set, check against them
        if ($this->includeMatcher !== null) {
            return $this->includeMatcher->matches($namespace) !== null;
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
     * $this->maxDistanceError` / `$distance >=
     * $this->maxDistanceWarning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::COUPLING_DISTANCE_OWN],
                SymbolLevel::Namespace_,
            )->withGates(
                self::populationGate('namespace-known', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', self::contextGuard('namespaceCoordinateKnown'), 'The namespace coordinate is unknown.'),
                self::populationGate('namespace-selected', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', self::nameMatches('namespace-selected'), 'The namespace is outside the configured project selection.'),
                self::populationGate('own-types', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', self::keyThreshold('own-types', [MetricName::SIZE_CLASS_COUNT, MetricName::SIZE_TRAIT_COUNT, MetricName::SIZE_INTERFACE_COUNT, MetricName::SIZE_IMPLEMENTING_ENUM_COUNT], '>=', 'own-types', 'zero', true), 'The own type population is below its minimum.'),
                self::populationGate('own-distance', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', self::keyPresent('own-distance', [MetricName::COUPLING_DISTANCE_OWN]), 'Own distance was not published.'),
                self::populationGate('own-coupling', new FindingChannel(self::NAME), SymbolLevel::Namespace_, 'namespace', self::keyThreshold('own-coupling', [MetricName::COUPLING_CA_OWN, MetricName::COUPLING_CE_OWN], '>', 0, 'refuse', true), 'The own namespace has no coupling.'),
            ),
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
