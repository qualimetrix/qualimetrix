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

    /** @var ?array<string, true> */
    private ?array $completeFiles = null;

    private int $distinctFileQueries = 0;

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
        $key = $file->value();
        if (isset($this->filePresence[$key])) {
            return $this->filePresence[$key];
        }

        ++$this->distinctFileQueries;
        if ($this->distinctFileQueries > 1 && str_ends_with($key, '.php') && $this->inDenominator($file)) {
            if ($this->snapshot === null) {
                $this->snapshot();
            }
            if ($this->snapshot !== null && $this->snapshot->complete()) {
                return $this->filePresence[$key] = $this->completeSnapshotContains($file)
                    ? ProjectEntryPresence::Present
                    : ProjectEntryPresence::Absent;
            }
        }

        return $this->filePresence[$key] = $this->tree->hasFile($this->universe->projectRoot, $file);
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

    public function completeSnapshotContains(RelativePath $file): bool
    {
        $snapshot = $this->snapshot();
        if (!$snapshot->complete()) {
            return false;
        }

        $this->completeFiles ??= array_fill_keys(
            array_map(static fn(RelativePath $path): string => $path->value(), $snapshot->phpFiles),
            true,
        );

        return isset($this->completeFiles[$file->value()]);
    }

    private function inDenominator(RelativePath $file): bool
    {
        foreach ($file->segments() as $segment) {
            if (\in_array($segment, ['vendor', 'node_modules', '.git'], true)) {
                return false;
            }
        }

        $absolute = $this->universe->projectRoot->joinRelative($file);
        foreach ($this->universe->denominator as $target) {
            if ($absolute->equals($target['path']) || $absolute->tryRelativizeTo($target['path']) !== null) {
                return true;
            }
        }

        return false;
    }
}
