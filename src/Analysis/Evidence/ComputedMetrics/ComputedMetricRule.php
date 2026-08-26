<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class ComputedMetricRule extends AbstractRule
{
    public const string NAME = ComputedMetricChannelFamily::OPEN_PRODUCER_RULE_NAME;

    /**
     * Every one of the four facts below is the family's, not this class's: six
     * of the seven producers have no class to declare them on, so declaring
     * them here as literals would be one of two spellings of the same answer.
     * The constants stay because a rule class is read by reflection for them.
     */
    public const string DOCS_PAGE = ComputedMetricChannelFamily::DOCS_PAGE;

    public const int REMEDIATION_MINUTES = ComputedMetricChannelFamily::REMEDIATION_MINUTES;

    public const bool SUPPORTS_THRESHOLD_OVERRIDE = ComputedMetricChannelFamily::SUPPORTS_THRESHOLD_OVERRIDE;

    public const ChannelShape SHAPE = ComputedMetricChannelFamily::SHAPE;

    public function __construct(
        ComputedMetricRuleOptions $options,
        private readonly ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private readonly ComputedMetricFindingBuilder $findingBuilder,
        private readonly ProfilerInterface $profiler,
        private readonly ComputedMetricProducerOptions $producerOptions,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return ComputedMetricChannelFamily::descriptionOf(self::NAME);
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        $findings = [];
        $profiler = $this->profiler;

        foreach ($this->definitionCatalog->all() as $definition) {
            // One class runs seven producers, so `enabled` is asked of the
            // producer this definition belongs to. Asking once before the loop
            // would make `rules: { health.cohesion: { enabled: false } }` mean
            // either all seven or none.
            if (!$this->producerOptions->isEnabledFor($definition->name)) {
                continue;
            }

            // Skip definitions without thresholds
            if ($definition->warningThreshold === null && $definition->errorThreshold === null) {
                continue;
            }

            $producer = $definition->producerRuleName();
            $spanName = 'rule.' . $producer . '.' . $definition->name;
            $profiler->start($spanName, 'rule.' . $producer);

            foreach ($definition->levels as $level) {
                $this->checkLevel($context, $definition, $level, $findings);
            }

            $profiler->stop($spanName);
        }

        return $findings;
    }

    /**
     * @param list<Finding> $findings
     */
    private function checkLevel(
        AnalysisContext $context,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        array &$findings,
    ): void {
        $symbols = $this->getSymbolsForLevel($context, $level);

        foreach ($symbols as [$subject, $symbolPath, $location]) {
            $metrics = $context->metrics->get($symbolPath);
            $value = $metrics->get($definition->name);

            if ($value === null) {
                continue;
            }

            $finding = $this->findingBuilder->build(
                $definition,
                (float) $value,
                $subject,
                $symbolPath,
                $location,
                $definition->producerRuleName(),
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
    }

    /**
     * @return list<array{MetricSubject, SymbolPath, Location}>
     */
    private function getSymbolsForLevel(AnalysisContext $context, SymbolLevel $level): array
    {
        return match ($level) {
            SymbolLevel::Project => [[MetricSubject::aggregate(SymbolPath::forProject()), SymbolPath::forProject(), Location::none()]],
            SymbolLevel::Namespace_ => array_map(
                static fn(string $ns) => [MetricSubject::aggregate(SymbolPath::forNamespace($ns)), SymbolPath::forNamespace($ns), Location::none()],
                $context->metrics->getNamespaces(),
            ),
            SymbolLevel::Class_ => $this->getClassSymbolsWithPresentationLocations($context),
            SymbolLevel::Callable, SymbolLevel::File => [],
        };
    }

    /**
     * @return list<array{MetricSubject, SymbolPath, Location}>
     */
    private function getClassSymbolsWithPresentationLocations(AnalysisContext $context): array
    {
        $symbols = [];
        foreach ($context->metrics->allDeclarations() as $declarationInfo) {
            $declaration = $declarationInfo->subject?->declarationPath();
            if ($declaration?->logical->getType() !== SymbolType::Class_) {
                continue;
            }

            $symbols[] = [
                $declarationInfo->subject,
                $declaration->logical,
                new Location($declarationInfo->file ?? $declaration->file, $declarationInfo->line),
            ];
        }

        return $symbols;
    }

    /**
     * @return class-string<ComputedMetricRuleOptions>
     */
    public static function getOptionsClass(): string
    {
        return ComputedMetricRuleOptions::class;
    }
}
