<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorOutcome;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/** Publishes findings from ProjectWalk's captured selector verdicts. */
final readonly class UnmatchedExcludeAudit
{
    public function __construct(
        private RuleOptionsInterface $options,
    ) {}

    /** @param list<ExcludeSelectorVerdict> $verdicts
     * @return list<Finding>
     */
    public function findings(array $verdicts, AbsolutePath $projectRoot): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        foreach ($verdicts as $verdict) {
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
}
