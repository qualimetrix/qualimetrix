<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Contract;

use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Core\Path\RelativePath;

/** Current baseline comparison facts from one analysis invocation. */
final class RunCoverage
{
    private ?ProjectTreeSnapshot $snapshot = null;

    /** @param array<string, list<string>> $psr4Roots Both accepted Composer sections. */
    public function __construct(
        public readonly RunScope $scope,
        public readonly AnalysisCoverage $analysis,
        public readonly RecordedExclusions $exclusions,
        public readonly ProjectScopeUniverse $universe,
        public readonly array $psr4Roots,
        private readonly ProjectTreeQueryInterface $tree,
    ) {}

    public function hasFile(RelativePath $file): ProjectEntryPresence
    {
        return $this->tree->hasFile($this->universe->projectRoot, $file);
    }

    public function analyzed(RelativePath $file): bool
    {
        foreach ($this->analysis->analyzedFiles as $analyzed) {
            if ($analyzed->value() === $file->value()) {
                return true;
            }
        }

        return false;
    }

    public function snapshot(): ProjectTreeSnapshot
    {
        return $this->snapshot ??= $this->tree->snapshot($this->universe);
    }
}
