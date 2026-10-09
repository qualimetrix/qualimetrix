<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Publishes findings from ProjectWalk's captured selector verdicts. */
final readonly class UnmatchedExcludeAudit
{
    public function __construct(
        private RuleOptionsInterface $options,
    ) {}

    public function population(ProjectScopeJudgement $scope, ChannelPublication $publication, FindingChannel $channel, ChannelDeclaration $declaration): JudgedPopulation
    {
        if (!$this->options->isEnabled()) {
            return JudgedPopulation::empty();
        }
        $members = (static function () use ($scope): iterable {
            foreach ($scope->excludeSelectors() as $verdict) {
                yield [
                    'identity' => PopulationIdentity::selector($verdict->display, 'configured-discovery-selector'),
                    'inputs' => [GateInput::context('excludeVerdictJudged', \in_array($verdict->outcome, [ExcludeSelectorOutcome::Removed, ExcludeSelectorOutcome::Unmatched, ExcludeSelectorOutcome::CoveredBySameSource], true))],
                ];
            }
        })();
        return JudgedPopulation::measure($publication, UnmatchedExcludeRule::NAME, $channel, SymbolLevel::Project, $declaration, $members);
    }

    /** @return list<Finding> */
    public function findings(ProjectScopeJudgement $scope, AbsolutePath $projectRoot): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        foreach ($scope->excludeSelectors() as $verdict) {
            if (!$verdict instanceof ExcludeSelectorVerdict) {
                throw new LogicException('Expected measured exclude selector verdict');
            }
            if ($verdict->outcome === ExcludeSelectorOutcome::Unmatched) {
                $findings[] = UnmatchedExcludeFinding::forPattern($verdict->display);
            } elseif ($verdict->outcome === ExcludeSelectorOutcome::CoveredBySameSource) {
                $findings[] = UnmatchedExcludeFinding::forPattern($verdict->display, $verdict->coveredBy);
            } elseif ($verdict->outcome === ExcludeSelectorOutcome::Unjudgeable) {
                $directory = $projectRoot->value() . '/' . $verdict->blockedAt;
                $findings[] = UnjudgedExcludeFinding::forPattern(
                    $verdict->display,
                    AbsolutePath::fromString($directory),
                    $projectRoot,
                );
            }
        }

        return $findings;
    }

    /** @return list<array{channel: string, option: string, pattern: string}> */
    public function unjudgedValues(ProjectScopeJudgement $scope): array
    {
        $values = [];
        foreach ($scope->excludeSelectors() as $verdict) {
            if ($verdict->outcome === ExcludeSelectorOutcome::NotJudged || $verdict->outcome === ExcludeSelectorOutcome::CoveredByOtherSource) {
                $values[] = ['channel' => 'discovery.unmatched-exclude', 'option' => 'exclude', 'pattern' => $verdict->display];
            }
        }

        return $values;
    }
}
