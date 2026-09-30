<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;

/** Publishes only source issues and root omissions already observed in this invocation. */
final readonly class ObservedProjectScopeReasons
{
    public function __construct(
        private ManifestSnapshotControlInterface $manifestSnapshot,
        private AnalysedInstallAnchorInterface $installAnchor,
    ) {}

    /** @return list<ProjectScopeReason> */
    public function forMainSource(string $source): array
    {
        $reasons = array_map(
            static fn($issue): ProjectScopeReason => $issue->source !== $source
                ? ProjectScopeReason::auxiliaryManifest($issue)
                : ProjectScopeReason::mainManifest($issue),
            $this->manifestSnapshot->observedIssues(),
        );
        foreach ($this->installAnchor->observedRootOmissions() as $omission) {
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::OmittedComposerRoot, [
                'cause' => $omission->cause, 'candidate' => $omission->candidate,
                'startDirectory' => $omission->startDirectory ?? '', 'lastDirectory' => $omission->lastDirectory ?? '',
                'visitedLevels' => $omission->visitedLevels,
            ]);
        }

        return $reasons;
    }
}
