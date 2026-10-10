<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** One indexed answer to whether this run could have observed a subject. */
final readonly class SubjectCoverageFacts
{
    /** @param array<string, true> $analyzed
     * @param array<string, true> $failed
     */
    private function __construct(
        private ProjectScopeJudgement $scope,
        private array $analyzed,
        private array $failed,
    ) {}

    /** @param list<RelativePath> $analyzedFiles
     * @param list<RelativePath> $failedFiles
     */
    public static function fromMeasured(ProjectScopeJudgement $scope, array $analyzedFiles, array $failedFiles): self
    {
        $analyzed = [];
        $failed = [];
        foreach ($analyzedFiles as $file) {
            $analyzed[$file->value()] = true;
        }
        foreach ($failedFiles as $file) {
            $failed[$file->value()] = true;
        }

        return new self($scope, $analyzed, $failed);
    }

    public function covers(ValueReach $reach, SymbolLevel $level, SubjectCoverageObservation $observation): bool
    {
        $localLevel = $level !== SymbolLevel::Namespace_ && $level !== SymbolLevel::Project;
        if ($observation->kind === 'verified-absent-file') {
            return $localLevel && $observation->file !== null;
        }
        if ($reach === ValueReach::Members && $localLevel && $observation->kind === 'analyzed-file') {
            return $observation->file !== null && isset($this->analyzed[$observation->file->value()]);
        }

        $judged = $localLevel ? $this->scope->judgesSelectedUniverse() : $this->scope->judgesNamespaceClaims();

        return $judged && $this->failed === [];
    }

    public function analyzed(RelativePath $file): bool
    {
        return isset($this->analyzed[$file->value()]);
    }
}
