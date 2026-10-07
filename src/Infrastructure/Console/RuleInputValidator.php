<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnassignedClassLayerRequirementInterface;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/** Fail-closed validation for all rule selectors accepted by CLI adapters. */
final readonly class RuleInputValidator
{
    public function __construct(
        private RuleOptionDocumentFormsInterface $documentForms,
        private RuleRegistryInterface $ruleRegistry,
        private RuleChannelSnapshotFactoryInterface $ruleChannelSnapshotFactory,
        private RuleOptionsBuild $optionsBuild,
        private ComputedMetricConfiguratorInterface $computedMetricConfigurator,
        private RuleEnablementResolver $enablementResolver,
        private UnassignedClassLayerRequirementInterface $unassignedClassLayerRequirement,
    ) {}

    public function resolve(ConfigurationDocument $document, InputInterface $input): FindingConfiguration
    {
        $configuration = FindingConfiguration::fromDocument($document);
        $channels = $this->ruleChannelSnapshotFactory->snapshot($this->computedMetricConfigurator->resolve($document));
        $stated = $this->enablementResolver->decide($configuration->document, $channels);
        $configuration = $configuration->withChannelUniverse($channels)->withDiagnostics($stated->diagnostics());
        $options = $this->optionsBuild->build($configuration, $stated);
        $configuration = $configuration->withResolvedOptions($options)->withEnablement($this->enablementResolver->conclude($stated, $options));
        $this->unassignedClassLayerRequirement->assertSatisfied($configuration);
        return $configuration;
    }

    public function validate(
        InputInterface $input,
        FindingConfiguration $configuration,
        ResolvedComputedMetricDefinitions $definitions,
    ): RuleChannelRegistryInterface {
        $this->validateWorkers($input);
        $channels = $configuration->channels
            ?? throw new LogicException('Rule channels must be decided before validation.');

        return $channels;
    }

    /** @return list<string> */
    public function configureCheckCommand(Command $command): array
    {
        return CheckCommandDefinition::addOptions($this->documentForms, $command, $this->ruleRegistry);
    }

    private function validateWorkers(InputInterface $input): void
    {
        $workers = CommandLineSpelling::option($input, 'workers');
        if ($workers === null) {
            return;
        }

        if (filter_var($workers, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--workers',
                \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers),
            );
        }
    }
}
