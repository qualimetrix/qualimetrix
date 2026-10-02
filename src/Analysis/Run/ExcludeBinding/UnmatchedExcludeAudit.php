<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/** Publishes findings from ProjectWalk's captured selector verdicts. */
final readonly class UnmatchedExcludeAudit
{
    public function __construct(
        private RuleOptionsInterface $options,
    ) {}

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
