<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageObservation;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveLevels;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Decides whether the addressed producers and subjects were measured in this run. */
final readonly class DirectiveMeasurability
{
    public function __construct(
        private ChannelIdentityInterface $identity,
        private RuleConfigurationInterface $ruleConfiguration,
        private ChannelDeclarationRegistryInterface $declarations,
    ) {}

    /** @param non-empty-list<Suppression> $group */
    public function unmeasurableReason(
        string $file,
        array $group,
        LevelActivity $activity,
        SubjectCoverageFacts $subjectCoverage,
    ): ?DirectiveUnmeasurableReason {
        $suppression = $group[0];
        if ($suppression->target()->appliesToEveryChannel()) {
            return $this->runWideReason($subjectCoverage);
        }

        $coverage = $this->addressedCoverage($file, $group, $activity, $subjectCoverage);

        return match (true) {
            $coverage['uncovered'] => DirectiveUnmeasurableReason::ScopeUnmeasured,
            !$coverage['enabled'] => DirectiveUnmeasurableReason::ProducerDisabled,
            default => null,
        };
    }

    private function runWideReason(SubjectCoverageFacts $subjectCoverage): ?DirectiveUnmeasurableReason
    {
        return $subjectCoverage->covers(
            \Qualimetrix\Analysis\Finding\Contract\ValueReach::Run,
            SymbolLevel::Project,
            SubjectCoverageObservation::nonlocalRegion(),
        ) ? null : DirectiveUnmeasurableReason::ScopeUnmeasured;
    }

    /**
     * @param non-empty-list<Suppression> $group
     *
     * @return array{enabled: bool, uncovered: bool}
     */
    private function addressedCoverage(string $file, array $group, LevelActivity $activity, SubjectCoverageFacts $subjectCoverage): array
    {
        $enabled = false;
        $uncovered = false;
        foreach ($this->addressedCodes($group[0]) as $code) {
            $producer = $this->identity->producerOf($code);
            if ($producer === null) {
                // Unreachable through a directive: `addressedCodes()` expands
                // the selector over the same catalogue `producerOf()` reads, so
                // a code that came out of the expansion has a producer. Kept as
                // a type guard, not as a reason path — the answer below is the
                // same either way, so nothing hangs on which way this exits.
                continue;
            }

            if (!$this->producerRan($code, $producer, $group, $activity)) {
                continue;
            }
            $enabled = true;
            if (!$this->channelCovered($file, $code, $group, $subjectCoverage)) {
                $uncovered = true;
            }
        }

        return ['enabled' => $enabled, 'uncovered' => $uncovered];
    }

    /** @param non-empty-list<Suppression> $group */
    private function channelCovered(string $file, string $code, array $group, SubjectCoverageFacts $subjectCoverage): bool
    {
        $channel = new FindingChannel($code);
        $declaration = $this->declarations->declarationFor($channel);
        if ($declaration === null) {
            return false;
        }

        $covered = true;
        $levels = DirectiveLevels::ofGroup($group);
        foreach ($levels === [] ? $declaration->levels : $levels as $level) {
            if (!\in_array($level, $declaration->levels, true)) {
                continue;
            }
            $observation = $level === SymbolLevel::Namespace_ || $level === SymbolLevel::Project
                ? SubjectCoverageObservation::nonlocalRegion()
                : SubjectCoverageObservation::analyzedFile(RelativePath::fromString($file));
            if (!$subjectCoverage->covers($this->declarations->reachAt($channel, $level), $level, $observation)) {
                $covered = false;
            }
        }

        return $covered;
    }

    /** @param non-empty-list<Suppression> $group */
    private function producerRan(string $code, string $producer, array $group, LevelActivity $activity): bool
    {
        $enablement = $this->ruleConfiguration->enablement()
            ?? throw new LogicException('Rule enablement is unavailable before directive audit.');
        $levels = DirectiveLevels::ofGroup($group);
        foreach ($levels === [] ? [null] : $levels as $level) {
            if ($enablement->publishes(new FindingChannel($code), $level)
                && $activity->ranAtAnyOf($producer, $level === null ? [] : [$level])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The finding codes a target addresses.
     *
     * @return list<string>
     */
    private function addressedCodes(Suppression $suppression): array
    {
        $selector = $suppression->target()->selector();
        if ($selector === null) {
            return [];
        }

        $codes = [];
        foreach ($this->identity->expand($selector->channel()) as $channel) {
            $codes[] = $channel->code;
        }

        return $codes;
    }
}
