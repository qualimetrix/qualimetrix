<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Applies generated policy and candidate identity to one walked project. */
final readonly class ProjectFiles implements ProjectFilesInterface
{
    public function __construct(
        private ProjectWalk $walk,
        private GeneratedFileFilterInterface $generatedFilter,
    ) {}

    public function discover(RunConfiguration $run): DiscoveredProjectFiles
    {
        $walked = $this->walk->walk(new WalkRequest($run));
        $root = $run->projectScope->universe->projectRoot;
        $files = self::selectCandidates($walked->candidates, $root);
        [$eligible, $generated, $skipped, $generatedNamespaces] = $this->applyGeneratedPolicy($files, $walked->skipped, $run->generatedFilePolicy, $walked->removedCode, $walked->namespaceBudget);

        $facts = new ScopeFacts(
            $walked->facts->missingByPaths,
            $walked->facts->unlistableOutside,
            $walked->facts->hiddenOutsideDirectories,
            $walked->facts->namedFilesOnly,
            \count($generated),
            $walked->facts->unlistableOutsideRoot,
        );

        return new DiscoveredProjectFiles(
            $eligible,
            $generated,
            $walked->namedExcluded,
            $skipped,
            $walked->verdicts,
            $facts,
            \count($files),
            $generatedNamespaces,
        );
    }

    /**
     * @param list<SplFileInfo> $candidates
     *
     * @return array<string, SplFileInfo>
     */
    private static function selectCandidates(array $candidates, AbsolutePath $root): array
    {
        $regularTargets = self::regularTargets($candidates);
        $files = [];
        $linkTargets = [];
        foreach ($candidates as $file) {
            if ($file->isLink()) {
                $target = realpath($file->getPathname());
                if ($target !== false) {
                    if (isset($regularTargets[$target]) || isset($linkTargets[$target])) {
                        continue;
                    }
                    $linkTargets[$target] = true;
                }
            }
            $relative = self::relative($file, $root);
            $files[$relative->value()] ??= $file;
        }

        return $files;
    }

    /**
     * @param list<SplFileInfo> $candidates
     *
     * @return array<string, true>
     */
    private static function regularTargets(array $candidates): array
    {
        $targets = [];
        foreach ($candidates as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }
            $target = realpath($file->getPathname());
            if ($target !== false) {
                $targets[$target] = true;
            }
        }

        return $targets;
    }

    /**
     * @param array<string, SplFileInfo> $files
     * @param list<SkippedEntry> $skipped
     *
     * @return array{list<SplFileInfo>, list<RelativePath>, list<SkippedEntry>, RemovedCodeNamespaces}
     */
    private function applyGeneratedPolicy(array $files, array $skipped, GeneratedFilePolicy $policy, RemovedCodeNamespaces $namespaces, RemovedNamespaceBudget $budget): array
    {
        $eligible = [];
        $generated = [];
        foreach ($files as $relative => $file) {
            if ($policy === GeneratedFilePolicy::Include) {
                $eligible[] = $file;
                continue;
            }
            $classification = $this->generatedFilter->isGenerated($file);
            if ($classification === null) {
                $skipped[] = new SkippedEntry(
                    AbsolutePath::fromString($file->getPathname()),
                    AnalysisFailureKind::UnreadableFile,
                    'File header cannot be read',
                );
            } elseif ($classification) {
                $generated[] = RelativePath::fromString($relative);
                $namespaces = $namespaces->isKnown() ? $namespaces->merge($this->declaredByGenerated($file, $budget)) : $namespaces;
            } else {
                $eligible[] = $file;
            }
        }

        return [$eligible, $generated, $skipped, $namespaces];
    }

    /** The header filter sees 2 KB; the `namespace` statement may lie anywhere in the file. */
    private function declaredByGenerated(SplFileInfo $file, RemovedNamespaceBudget $budget): RemovedCodeNamespaces
    {
        if (!$budget->take()) {
            return RemovedCodeNamespaces::unknown();
        }
        $declared = RemovedNamespaceScan::declaredIn($file->getPathname());

        return $declared === null ? RemovedCodeNamespaces::unknown() : RemovedCodeNamespaces::known($declared);
    }

    private static function relative(SplFileInfo $file, AbsolutePath $root): RelativePath
    {
        return PathFactory::published(AbsolutePath::fromString($file->getPathname()), $root);
    }
}
