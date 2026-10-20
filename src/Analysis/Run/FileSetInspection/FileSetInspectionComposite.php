<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\FileSetInspection;

use LogicException;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionFailure;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
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
     * @param array<string, RelativePath> $publishedByInput selected lexical absolute input path to its published path
     *
     * @return list<AnalysisFailure>
     */
    public function inspect(
        array $eligibleFiles,
        AbsolutePath $projectRoot,
        array $publishedByInput,
    ): array {
        foreach ($this->participants as $participant) {
            $participant->resetForRun();
        }

        $failures = [];
        foreach ($this->participants as $participant) {
            if (!$this->producerGate->isEnabled($participant::producerRuleName())) {
                continue;
            }

            $span = 'file-set-inspection.' . $participant::participantId();
            $this->profiler->start($span, 'pipeline');
            try {
                $participant->inspect($eligibleFiles, $projectRoot);
            } catch (FileSetInspectionFailure $failure) {
                foreach ($failure->failures as $unreadable) {
                    $input = $unreadable['input'];
                    $published = $publishedByInput[$input->value()] ?? null;
                    if ($published === null) {
                        throw new LogicException(\sprintf(
                            'File-set inspection reported input "%s" outside the selected file set.',
                            $input->value(),
                        ), previous: $failure);
                    }

                    $failures[$published->value()] ??= new AnalysisFailure(
                        $published,
                        AnalysisFailureKind::UnreadableFile,
                        $unreadable['message'],
                    );
                }
            } finally {
                $this->profiler->stop($span);
            }
        }

        return array_values($failures);
    }
}
