<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\StatedEnablement;
use Qualimetrix\Analysis\Finding\RuleConfiguration\OptionActivityResolution;
use Qualimetrix\Analysis\Finding\RuleConfiguration\ProducerOptionsBuild;

/** Builds every producer's immutable options from the judged document. */
final readonly class RuleOptionsBuild
{
    private ProducerOptionsBuild $producerBuild;

    public function __construct(private RuleExecutionInterface $execution)
    {
        $this->producerBuild = new ProducerOptionsBuild();
    }

    public function build(FindingConfiguration $configuration, StatedEnablement $stated): ResolvedRuleOptions
    {
        $options = [];
        $suppressions = [];
        $activity = [];
        foreach ($this->execution->allRules() as $producer) {
            [$options[$producer->name], $suppressions[$producer->name]] = $this->producerBuild->build($configuration, $stated, $producer);
        }
        foreach ($stated->decisions() as $decision) {
            $option = $options[$decision->producer] ?? throw new LogicException('Every decided producer requires resolved options.');
            $activity[$decision->producer][$decision->level->value ?? ''] = OptionActivityResolution::forCell($configuration, $decision, $option);
        }
        return new ResolvedRuleOptions($options, $suppressions, $activity);
    }
}
