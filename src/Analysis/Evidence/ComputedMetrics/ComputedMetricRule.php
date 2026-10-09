<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Finding\ComputedMetricFindingBuilder;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
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

    public static function getDescription(): string
    {
        return ComputedMetricChannelFamily::descriptionOf(self::NAME);
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

            $declaration = ComputedMetricChannelFamily::declarationForDefinition($definition);
            if ($declaration === null) {
                $profiler->stop($spanName);
                continue;
            }
            foreach ($definition->levels as $level) {
                $this->checkLevel($context, $definition, $level, $findings, $declaration);
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
        ChannelDeclaration $declaration,
    ): void {
        $symbols = $this->getSymbolsForLevel($context, $level);

        foreach ($symbols as [$subject, $symbolPath, $location]) {
            $identity = $level === SymbolLevel::Class_ ? PopulationIdentity::subject($subject) : PopulationIdentity::aggregate($symbolPath);
            $producer = $definition->producerRuleName();
            $channel = new FindingChannel($definition->name);
            if ($level === SymbolLevel::Class_ && $symbolPath->getType() !== SymbolType::Class_) {
                $context->admit($producer, $channel, $level, $identity, $declaration, (static function () use ($symbolPath): iterable {
                    yield GateInput::kind('class-coordinate', $symbolPath->getType());
                })());
                continue;
            }
            $metrics = $context->metrics->getSubject($subject);
            if (!$definition->getApplicabilityForLevel($level)->appliesTo($metrics->all())) {
                continue;
            }
            if (!$context->admit($producer, $channel, $level, $identity, $declaration, (static function () use ($level, $symbolPath, $metrics): iterable {
                if ($level === SymbolLevel::Class_) {
                    yield GateInput::kind('class-coordinate', $symbolPath->getType());
                }
                yield GateInput::metrics('published-value', $metrics);
            })())) {
                continue;
            }
            $value = $metrics->get($definition->name);

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
        foreach ($context->metrics->allClassDeclarations() as $declarationInfo) {
            $declaration = $declarationInfo->subject?->declarationPath();
            if ($declaration === null) {
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
