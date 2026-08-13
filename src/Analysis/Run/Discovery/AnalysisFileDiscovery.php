<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/** Coordinates file discovery, path deduplication, and generated-file classification. */
final readonly class AnalysisFileDiscovery
{
    public function __construct(
        private FileDiscoveryInterface $defaultDiscovery,
        private GeneratedFileFilterInterface $generatedFileFilter,
    ) {}

    /** @param list<AbsolutePath> $paths */
    public function discover(
        array $paths,
        AbsolutePath $projectRoot,
        GeneratedFilePolicy $generatedFilePolicy,
        ?FileDiscoveryInterface $override = null,
    ): DiscoveredAnalysisFiles {
        $discovery = $override ?? $this->defaultDiscovery;
        // preserve_keys=false: discover() may yield AbsolutePath object keys.
        $discoveredFiles = iterator_to_array($discovery->discover($paths), false);

        /** @var array<string, SplFileInfo> $filesByPath */
        $filesByPath = [];
        foreach ($discoveredFiles as $file) {
            $relativePath = PathFactory::bestEffortRelative($file->getPathname(), $projectRoot);
            $filesByPath[$relativePath->value()] ??= $file;
        }

        if ($generatedFilePolicy === GeneratedFilePolicy::Include) {
            return DiscoveredAnalysisFiles::fromDiscovery(array_values($filesByPath), [], \count($filesByPath));
        }

        $eligibleFiles = $this->generatedFileFilter->filter(array_values($filesByPath));
        $eligibleByPath = [];
        foreach ($eligibleFiles as $file) {
            $eligibleByPath[PathFactory::bestEffortRelative($file->getPathname(), $projectRoot)->value()] = true;
        }

        $eligible = [];
        $excluded = [];
        foreach ($filesByPath as $relativePath => $file) {
            if (isset($eligibleByPath[$relativePath])) {
                $eligible[] = $file;
            } else {
                $excluded[] = RelativePath::fromString($relativePath);
            }
        }

        return DiscoveredAnalysisFiles::fromDiscovery($eligible, $excluded, \count($filesByPath));
    }
}
