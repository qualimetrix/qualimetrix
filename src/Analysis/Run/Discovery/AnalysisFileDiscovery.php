<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkippedEntry;
use Qualimetrix\Analysis\Run\Contract\Discovery\SkipReportingDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
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
 *
 * The unit-of-analysis invariant is asserted here as well as inside the
 * traversal, because it holds for every discovery this is given — including
 * the ones that came from somewhere other than a filesystem walk.
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
        $projectRoot = $configuration->projectRoot;
        $unmatchedExcludes = $this->unmatchedExcludeAudit->findings($configuration);

        $discovery = $override ?? $this->defaultDiscovery;
        // preserve_keys=false: discover() may yield AbsolutePath object keys.
        $discoveredFiles = iterator_to_array($discovery->discover($configuration->paths), false);

        /** @var array<string, SplFileInfo> $filesByPath */
        $filesByPath = [];
        /** @var array<string, SkippedEntry> $skipsByPath */
        $skipsByPath = [];
        foreach ($discoveredFiles as $file) {
            $skip = self::skipFor($file);

            if ($skip === null) {
                $filesByPath[PathFactory::bestEffortRelative($file->getPathname(), $projectRoot)->value()] ??= $file;

                continue;
            }

            // Keyed the way the pipeline will name it, so a link and its
            // target cannot both claim a terminal state.
            $skipsByPath[$skip->relativeTo($projectRoot)->value()] ??= $skip;
        }

        // Only what the traversal did not already name. The walk records what
        // it refuses without yielding it, so the two lists are disjoint by
        // construction; the merge keys on path anyway, because two terminal
        // states for one path is a hard error further down.
        if ($discovery instanceof SkipReportingDiscoveryInterface) {
            foreach ($discovery->skippedEntries() as $skip) {
                $skipsByPath[$skip->relativeTo($projectRoot)->value()] ??= $skip;
            }
        }

        [$eligible, $excluded] = $this->splitByGeneratedFilePolicy(
            $filesByPath,
            $configuration->generatedFilePolicy,
            $projectRoot,
        );

        return DiscoveredAnalysisFiles::fromDiscovery(
            $eligible,
            $excluded,
            \count($filesByPath),
            $unmatchedExcludes,
            array_values($skipsByPath),
        );
    }

    /**
     * Why this entry cannot be a unit of analysis, or null when it can.
     *
     * Judged only where there is something to judge: an entry that is present
     * but is not a regular file cannot be a unit of analysis, and reading it
     * would not fail loudly either — `file_get_contents()` on a directory
     * returns an empty string, which parses into an empty AST and reports as a
     * successfully analyzed file. An absent path is a different question, and
     * one the parser already refuses with a typed error that reaches coverage
     * on its own.
     */
    private static function skipFor(SplFileInfo $file): ?SkippedEntry
    {
        if (!file_exists($file->getPathname()) || $file->isFile()) {
            return null;
        }

        return new SkippedEntry(
            AbsolutePath::fromString($file->getPathname()),
            $file->isDir()
                ? AnalysisFailureKind::DirectorySymlink
                : AnalysisFailureKind::NotRegularFile,
            'Discovered entry is not a regular file',
        );
    }

    /**
     * The files an analysis will read, and the generated ones it will name as
     * excluded. Under {@see GeneratedFilePolicy::Include} nothing is generated
     * as far as the run is concerned, so there is nothing to name.
     *
     * @param array<string, SplFileInfo> $filesByPath
     *
     * @return array{list<SplFileInfo>, list<RelativePath>}
     */
    private function splitByGeneratedFilePolicy(
        array $filesByPath,
        GeneratedFilePolicy $policy,
        AbsolutePath $projectRoot,
    ): array {
        if ($policy === GeneratedFilePolicy::Include) {
            return [array_values($filesByPath), []];
        }

        $eligibleByPath = [];
        foreach ($this->generatedFileFilter->filter(array_values($filesByPath)) as $file) {
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

        return [$eligible, $excluded];
    }
}
