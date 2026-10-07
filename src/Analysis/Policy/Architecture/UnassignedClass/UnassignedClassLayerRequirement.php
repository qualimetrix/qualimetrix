<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\UnassignedClass;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\Contract\UnassignedClassLayerRequirementInterface;

final readonly class UnassignedClassLayerRequirement implements UnassignedClassLayerRequirementInterface
{
    public function assertSatisfied(FindingConfiguration $configuration): void
    {
        $mode = $this->requiredMode($configuration);
        if ($mode === null) {
            return;
        }
        $layers = $configuration->document->get('architecture', 'layers');
        if ($layers !== null && $layers->plain() !== []) {
            return;
        }

        throw ConfigurationRefusal::acrossLayers(
            $this->origins($configuration, $layers),
            RefusedPosition::open(['architecture', 'layers'], 'layers'),
            \sprintf('Rule "architecture.unassigned-class" mode "%s" requires at least one declared architecture.layers entry. Declare layers or use mode "ignore" or disable the rule.', $mode->value),
        );
    }

    private function requiredMode(FindingConfiguration $configuration): ?UnassignedClassMode
    {
        $resolved = $configuration->resolvedOptions
            ?? throw new LogicException('The unassigned-class layer requirement needs resolved options.');
        $enablement = $configuration->enablement
            ?? throw new LogicException('The unassigned-class layer requirement needs final enablement.');
        $options = $resolved->for(ArchitectureChannels::UNASSIGNED_CLASS_DIAGNOSTIC_NAME);
        if (!$options instanceof UnassignedClassOptions) {
            throw new LogicException('The unassigned-class layer requirement needs its own options.');
        }
        if ($options->mode === UnassignedClassMode::Ignore
            || !$enablement->isEnabled(ArchitectureChannels::UNASSIGNED_CLASS_DIAGNOSTIC_NAME)) {
            return null;
        }

        return $options->mode;
    }

    /** @return non-empty-list<ConfigurationOrigin> */
    private function origins(FindingConfiguration $configuration, ?ResolvedValueInterface $layers): array
    {
        $mode = $configuration->document->get('rules', ArchitectureChannels::UNASSIGNED_CLASS_DIAGNOSTIC_NAME, 'mode')
            ?? throw new LogicException('An active unassigned-class mode must have a written source.');
        $writers = [...$mode->contributors(), ...($layers?->contributors() ?? [])];
        usort($writers, static fn($left, $right): int => $left->layerIndex <=> $right->layerIndex);
        $origins = [];
        foreach ($writers as $writer) {
            $origins[serialize($writer->origin)] = $writer->origin;
        }
        if ($layers === null) {
            $missing = ConfigurationOrigin::of(ConfigurationSource::Resolved, 'architecture.layers');
            $origins[serialize($missing)] = $missing;
        }
        return array_values($origins);
    }
}
