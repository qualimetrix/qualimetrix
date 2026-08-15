<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\FileSetInspection;

use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use SplFileInfo;

final readonly class FileSetInspectionComposite
{
    /**
     * @param list<FileSetInspectionParticipantInterface> $participants
     */
    public function __construct(
        private array $participants,
        private RuleSelectorProducerGate $producerGate,
        private ProfilerInterface $profiler,
    ) {}

    /**
     * @param list<SplFileInfo> $eligibleFiles
     * @param list<string> $onlyRules
     * @param list<string> $disabledRules
     */
    public function inspect(array $eligibleFiles, AbsolutePath $projectRoot, array $onlyRules, array $disabledRules): void
    {
        foreach ($this->participants as $participant) {
            $participant->resetForRun();
        }

        foreach ($this->participants as $participant) {
            if (!$this->producerGate->isEnabled($participant::producerRuleName(), $onlyRules, $disabledRules)) {
                continue;
            }

            $span = 'file-set-inspection.' . $participant::participantId();
            $this->profiler->start($span, 'pipeline');
            try {
                $participant->inspect($eligibleFiles, $projectRoot);
            } finally {
                $this->profiler->stop($span);
            }
        }
    }
}
