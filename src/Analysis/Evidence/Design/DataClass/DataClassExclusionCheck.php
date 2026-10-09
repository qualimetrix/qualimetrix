<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\DataClass;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\FlagExcludes;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyPresent;
use Qualimetrix\Analysis\Finding\Contract\Population\KeyThreshold;
use Qualimetrix\Analysis\Finding\Contract\Population\PopulationGate;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** The ordered class-shape exclusions used by DataClassRule. */
final class DataClassExclusionCheck
{
    /** @return list<PopulationGate> */
    public static function populationGates(): array
    {
        $channel = new FindingChannel(DataClassRule::NAME);
        $level = SymbolLevel::Class_;

        return [
            new PopulationGate('interface-class', $channel, $level, 'declaration', new FlagExcludes('interface-class', MetricName::DESIGN_IS_INTERFACE), 'Interfaces are outside the data-class population.'),
            new PopulationGate('abstract-class', $channel, $level, 'declaration', new FlagExcludes('abstract-class', MetricName::DESIGN_IS_ABSTRACT), 'Abstract classes are outside the data-class population.'),
            new PopulationGate('class-properties', $channel, $level, 'declaration', new KeyThreshold('class-properties', [MetricName::SIZE_PROPERTY_COUNT], '>', 0, missing: 'zero'), 'Data classes require properties.'),
            new PopulationGate('exception-classification', $channel, $level, 'declaration', new KeyPresent('excludeExceptions', [MetricName::DESIGN_IS_EXCEPTION], activeWhen: true), 'Exception classification is required when exceptions are excluded.'),
            new PopulationGate('exception-class', $channel, $level, 'declaration', new FlagExcludes('excludeExceptions', MetricName::DESIGN_IS_EXCEPTION, activeWhen: true, nonzero: true), 'Exception classes are excluded by configuration.'),
            new PopulationGate('readonly-class', $channel, $level, 'declaration', new FlagExcludes('excludeReadonly', MetricName::DESIGN_IS_READONLY, activeWhen: true), 'Readonly classes are excluded by configuration.'),
            new PopulationGate('promoted-only-class', $channel, $level, 'declaration', new FlagExcludes('excludePromotedOnly', MetricName::DESIGN_IS_PROMOTED_PROPERTIES_ONLY, activeWhen: true), 'Promoted-properties-only classes are excluded by configuration.'),
            new PopulationGate('member-floor', $channel, $level, 'declaration', new KeyThreshold('minMembers', [MetricName::SIZE_METHOD_COUNT_TOTAL, MetricName::SIZE_PROPERTY_COUNT], '>=', 'minMembers', missing: 'zero'), 'The class has too few members for data-class judgement.'),
        ];
    }

    /** @return iterable<GateInput> */
    public static function populationInputs(MetricBag $metrics, DataClassOptions $options): iterable
    {
        yield GateInput::metrics('interface-class', $metrics);
        yield GateInput::metrics('abstract-class', $metrics);
        yield GateInput::metrics('class-properties', $metrics);
        yield GateInput::metrics('excludeExceptions', $metrics, option: $options->excludeExceptions);
        yield GateInput::metrics('excludeExceptions', $metrics, option: $options->excludeExceptions);
        yield GateInput::metrics('excludeReadonly', $metrics, option: $options->excludeReadonly);
        yield GateInput::metrics('excludePromotedOnly', $metrics, option: $options->excludePromotedOnly);
        yield GateInput::metrics('minMembers', $metrics, $options->minMembers);
    }
}
