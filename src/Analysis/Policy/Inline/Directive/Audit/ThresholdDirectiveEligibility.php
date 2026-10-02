<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Directive\RefusedDirectives;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/** Decides whether an authored threshold has an addressable, executed producer. */
final readonly class ThresholdDirectiveEligibility
{
    public function __construct(
        private RuleConfigurationInterface $ruleConfiguration,
        private RefusedDirectives $refused,
    ) {}

    public function refuses(AuthoredDirectiveGroup $group): bool
    {
        return $this->refused->threshold($group->file, $group->bindings[0]) !== null;
    }

    /**
     * A group can span levels; undeclared levels do not count as disablement.
     *
     * @param list<ThresholdOverride> $bindings
     */
    public function reason(array $bindings, LevelActivity $activity): ?DirectiveUnmeasurableReason
    {
        $override = $bindings[0];
        $enablement = $this->ruleConfiguration->enablement()
            ?? throw new LogicException('Rule enablement is unavailable before directive audit.');
        $levels = self::levelsOf($bindings);
        $declaredLevels = array_values(array_filter(
            $levels,
            static fn(SymbolLevel $level): bool => $activity->declares($override->rulePattern, $level),
        ));

        return $this->ranAtDeclaredLevel($override->rulePattern, $levels, $declaredLevels, $enablement, $activity)
            ? null
            : DirectiveUnmeasurableReason::ProducerDisabled;
    }

    /**
     * @param list<SymbolLevel> $levels
     * @param list<SymbolLevel> $declaredLevels
     */
    private function ranAtDeclaredLevel(
        string $producer,
        array $levels,
        array $declaredLevels,
        RuleEnablement $enablement,
        LevelActivity $activity,
    ): bool {
        foreach ($enablement->decisions() as $decision) {
            if ($decision->producer !== $producer || !$decision->live() || !$decision->direct) {
                continue;
            }
            if ($declaredLevels !== [] && !\in_array($decision->level, $declaredLevels, true)) {
                continue;
            }
            if ($activity->ranAtAnyOf($producer, $levels)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ThresholdOverride> $bindings
     *
     * @return list<SymbolLevel>
     */
    private static function levelsOf(array $bindings): array
    {
        $levels = [];
        foreach ($bindings as $binding) {
            $level = SymbolLevelProjection::ofDeclaration($binding->subject->toSymbolPath()->getType());
            $levels[$level->value] = $level;
        }

        return array_values($levels);
    }
}
