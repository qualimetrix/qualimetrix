<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

/**
 * Coordinates file discovery, path deduplication, generated-file
 * classification, and the one statement a run can make about its own file
 * selection: which of the author's exclude patterns removed nothing.
 *
 * That last question belongs here and nowhere later. Directory pruning drops
 * matching subtrees before this method returns anything, so from the
 * result alone a pattern that worked and a pattern that matched nothing are
 * the same picture. {@see UnmatchedExcludeAudit} is asked while the answer
 * still exists, and its findings ride out with the files.
 */
final readonly class AnalysisFileDiscovery
{
    public function __construct(
        private FileDiscoveryInterface $defaultDiscovery,
        private GeneratedFileFilterInterface $generatedFileFilter,
        private UnmatchedExcludeAudit $unmatchedExcludeAudit,
    ) {}

    /**
     * The whole configuration rather than three of its fields: the discovery
     * this coordinates is defined by all of them, and passing them apart is
     * what let each new field be forgotten at the one call site.
     */
    public function discover(
        RunConfiguration $configuration,
        ?FileDiscoveryInterface $override = null,
    ): DiscoveredAnalysisFiles {
        $paths = $configuration->paths;
        $projectRoot = $configuration->projectRoot;
        $generatedFilePolicy = $configuration->generatedFilePolicy;
        $unmatchedExcludes = $this->unmatchedExcludeAudit->findings($configuration);

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
            return DiscoveredAnalysisFiles::fromDiscovery(
                array_values($filesByPath),
                [],
                \count($filesByPath),
                $unmatchedExcludes,
            );
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

        return DiscoveredAnalysisFiles::fromDiscovery($eligible, $excluded, \count($filesByPath), $unmatchedExcludes);
    }
}
