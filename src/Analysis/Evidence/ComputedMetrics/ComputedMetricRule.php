<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class ComputedMetricRule extends AbstractRule
{
    public const string NAME = ComputedMetricChannelFamily::PRODUCER_RULE_NAME;

    public function __construct(
        ComputedMetricRuleOptions $options,
        private readonly ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private readonly ComputedMetricFindingBuilder $findingBuilder,
        private readonly ProfilerInterface $profiler,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks computed health metrics against thresholds';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Maintainability;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ComputedMetricRuleOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];
        $profiler = $this->profiler;

        foreach ($this->definitionCatalog->all() as $definition) {
            // Skip definitions without thresholds
            if ($definition->warningThreshold === null && $definition->errorThreshold === null) {
                continue;
            }

            $spanName = 'rule.' . self::NAME . '.' . $definition->name;
            $profiler->start($spanName, 'rule.' . self::NAME);

            foreach ($definition->levels as $level) {
                $this->checkLevel($context, $definition, $level, $violations);
            }

            $profiler->stop($spanName);
        }

        return $violations;
    }

    /**
     * @param list<Violation> $violations
     */
    private function checkLevel(
        AnalysisContext $context,
        ComputedMetricDefinition $definition,
        SymbolType $level,
        array &$violations,
    ): void {
        $symbols = $this->getSymbolsForLevel($context, $level);

        foreach ($symbols as [$subject, $symbolPath, $location]) {
            $metrics = $context->metrics->get($symbolPath);
            $value = $metrics->get($definition->name);

            if ($value === null) {
                continue;
            }

            $violation = $this->findingBuilder->build($definition, (float) $value, $subject, $symbolPath, $location, $this->getName());
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }
    }

    /**
     * @return list<array{MetricSubject, SymbolPath, Location}>
     */
    private function getSymbolsForLevel(AnalysisContext $context, SymbolType $level): array
    {
        return match ($level) {
            SymbolType::Project => [[MetricSubject::aggregate(SymbolPath::forProject()), SymbolPath::forProject(), Location::none()]],
            SymbolType::Namespace_ => array_map(
                static fn(string $ns) => [MetricSubject::aggregate(SymbolPath::forNamespace($ns)), SymbolPath::forNamespace($ns), Location::none()],
                $context->metrics->getNamespaces(),
            ),
            SymbolType::Class_ => $this->getClassSymbolsWithPresentationLocations($context),
            default => [],
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
