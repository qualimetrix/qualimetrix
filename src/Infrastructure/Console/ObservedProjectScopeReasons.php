<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;

/** Publishes only source issues and root omissions already observed in this invocation. */
final readonly class ObservedProjectScopeReasons
{
    public function __construct(
        private ManifestSnapshotControlInterface $manifestSnapshot,
        private AnalysedInstallAnchorInterface $installAnchor,
    ) {}

    /** @return list<ProjectScopeReason> */
    public function forMainSource(string $source, ManifestReadState $state, AbsolutePath $projectRoot): array
    {
        $reasons = array_map(
            static fn($issue): ProjectScopeReason => $issue->source !== $source
                ? ProjectScopeReason::auxiliaryManifest($issue)
                : ProjectScopeReason::mainManifest($issue),
            $this->manifestSnapshot->observedIssues(),
        );
        if ($state === ManifestReadState::Absent) {
            return $reasons;
        }
        foreach ($this->installAnchor->observedRootOmissions() as $omission) {
            $data = ['cause' => $omission->cause, 'visitedLevels' => $omission->visitedLevels];
            foreach (['candidate' => $omission->candidate, 'startDirectory' => $omission->startDirectory, 'lastDirectory' => $omission->lastDirectory] as $key => $path) {
                if ($path === null || !str_starts_with($path, '/')) {
                    continue;
                }
                $relative = AbsolutePath::fromString($path)->tryRelativizeTo($projectRoot);
                if ($relative !== null) {
                    $data[$key] = $relative->value();
                }
            }
            $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::OmittedComposerRoot, $data);
        }

        return $reasons;
    }
}
