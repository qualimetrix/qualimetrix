<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;

/** Current baseline comparison facts from one analysis invocation. */
final class RunCoverage
{
    private ?ProjectTreeSnapshot $snapshot = null;

    /** @var array<string, ProjectEntryPresence> */
    private array $filePresence = [];

    /** @var array<string, ProjectEntryPresence> */
    private array $directoryPresence = [];

    /** @param array<string, list<string>> $psr4Roots Both accepted Composer sections. */
    public function __construct(
        public readonly RunScope $scope,
        public readonly AnalysisCoverage $analysis,
        public readonly RecordedExclusions $exclusions,
        public readonly ProjectScopeUniverse $universe,
        public readonly array $psr4Roots,
        private readonly ProjectTreeQueryInterface $tree,
        public readonly SubjectCoverageFacts $subjectCoverage,
    ) {}

    public function hasFile(RelativePath $file): ProjectEntryPresence
    {
        return $this->filePresence[$file->value()] ??= $this->tree->hasFile($this->universe->projectRoot, $file);
    }

    public function hasDirectory(AbsolutePath $directory): ProjectEntryPresence
    {
        return $this->directoryPresence[$directory->value()] ??= $this->tree->hasDirectory($directory);
    }

    public function analyzed(RelativePath $file): bool
    {
        return $this->subjectCoverage->analyzed($file);
    }

    public function snapshot(): ProjectTreeSnapshot
    {
        return $this->snapshot ??= $this->tree->snapshot($this->universe);
    }
}
