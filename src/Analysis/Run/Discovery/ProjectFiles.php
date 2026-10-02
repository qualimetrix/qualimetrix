<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
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
        [$eligible, $generated, $skipped] = $this->applyGeneratedPolicy($files, $walked->skipped, $run->generatedFilePolicy);

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
     * @return array{list<SplFileInfo>, list<RelativePath>, list<SkippedEntry>}
     */
    private function applyGeneratedPolicy(array $files, array $skipped, GeneratedFilePolicy $policy): array
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
            } else {
                $eligible[] = $file;
            }
        }

        return [$eligible, $generated, $skipped];
    }

    private static function relative(SplFileInfo $file, AbsolutePath $root): RelativePath
    {
        return PathFactory::published(AbsolutePath::fromString($file->getPathname()), $root);
    }
}
