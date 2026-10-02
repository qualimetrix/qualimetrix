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
        $regularTargets = [];
        foreach ($walked->candidates as $file) {
            if (!$file->isLink() && $file->isFile()) {
                $target = realpath($file->getPathname());
                if ($target !== false) {
                    $regularTargets[$target] = true;
                }
            }
        }

        $files = [];
        $linkTargets = [];
        foreach ($walked->candidates as $file) {
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

        $eligible = [];
        $generated = [];
        $skipped = $walked->skipped;
        foreach ($files as $relative => $file) {
            if ($run->generatedFilePolicy === GeneratedFilePolicy::Include) {
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

    private static function relative(SplFileInfo $file, AbsolutePath $root): RelativePath
    {
        return PathFactory::published(AbsolutePath::fromString($file->getPathname()), $root);
    }
}
