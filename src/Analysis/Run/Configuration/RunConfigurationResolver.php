<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Discovery\DirectoryPruner;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

final class RunConfigurationResolver implements RunConfigurationResolverInterface
{
    public function __construct(
        private readonly ProjectScopeCoverage $projectScopeCoverage,
        private readonly SelectorYamlDecoder $selectorDecoder = new SelectorYamlDecoder(),
    ) {}

    public function resolve(ConfigurationDocument $document): RunConfiguration
    {
        $root = $document->workingDirectory();
        $resolved = $document->resolved();
        $autoloadDev = self::flag($resolved->get(ConfigurationRoot::IncludeAutoloadDev->value))
            ? AutoloadDevPolicy::Include
            : AutoloadDevPolicy::Exclude;
        $writtenPaths = self::list($resolved->get(ConfigSchema::PATHS));
        $pathList = array_map(
            static fn(string $path): AbsolutePath => PathFactory::fromCliArgument($path, $root),
            $writtenPaths === null ? self::defaultPaths($this->discoveredPaths($document, $autoloadDev)) : PathsSection::read($writtenPaths),
        );

        $excludes = self::list($resolved->get(ConfigurationRoot::Exclude->value));
        $authoredExcludes = $excludes === null ? [] : $this->pathPatterns($excludes);

        if ($writtenPaths !== null) {
            self::refuseWrittenRootsExcluded($pathList, $root, new DirectoryPruner($root, $authoredExcludes), $writtenPaths, $excludes);
        }

        $scope = $this->projectScopeCoverage->measure($root, $pathList, $autoloadDev);

        return new RunConfiguration(
            coversProjectScope: $scope->state()->coversProjectScope(),
            paths: $pathList,
            pathExcludes: [...DirectoryPruner::builtInPatterns(), ...$authoredExcludes],
            // The same patterns without the built-in floor: what the author
            // actually asked to exclude, which is the only part of the merged
            // list a miss can be reported about.
            authoredPathExcludes: $authoredExcludes,
            projectRoot: $root,
            generatedFilePolicy: self::flag($resolved->get(ConfigurationRoot::IncludeGenerated->value))
                ? GeneratedFilePolicy::Include
                : GeneratedFilePolicy::Exclude,
            autoloadDevPolicy: $autoloadDev,
        );
    }

    /**
     * A directory the author named and their own `exclude:` removes is never
     * walked, and the run would report success over nothing there. Only a
     * written list is asked: a composer default the author excluded is an
     * exclusion working as intended. The predicate is the one discovery applies
     * to a root, so a directory below one excluded only `exact:` is still
     * walked and not refused. The built-in `vendor`, `node_modules` and `.git`
     * floor is not asked here; discovery refuses a root it removes.
     *
     * The refusal names the layer that wrote the paths and every layer that
     * wrote an exclude: the conflict is between them.
     *
     * @param list<AbsolutePath> $paths
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseWrittenRootsExcluded(
        array $paths,
        AbsolutePath $root,
        DirectoryPruner $authored,
        ResolvedListInterface $writtenPaths,
        ?ResolvedListInterface $excludes,
    ): void {
        $excluded = self::excludedDirectories($paths, $root, $authored);
        if ($excluded === []) {
            return;
        }

        $one = \count($excluded) === 1;

        throw Provenance::refusalOf(
            [...($excludes?->contributors() ?? []), ...$writtenPaths->contributors()],
            \sprintf(
                'Invalid value for "%s": %s %s by your own exclude, so analysis never enters %s and this run would'
                . ' analyse nothing there. Remove the exclude selector, or name a path it does not remove.',
                ConfigSchema::PATHS,
                implode(', ', $excluded),
                $one ? 'is removed' : 'are removed',
                $one ? 'it' : 'them',
            ),
        );
    }

    /**
     * @param list<AbsolutePath> $paths
     *
     * @return list<string> each excluded directory with the selector that removes it
     */
    private static function excludedDirectories(array $paths, AbsolutePath $root, DirectoryPruner $authored): array
    {
        $excluded = [];
        foreach ($paths as $path) {
            $match = $path->isDirectory() ? $authored->match($path) : null;
            if ($match !== null) {
                $excluded[] = \sprintf(
                    '"%s" (selector "%s")',
                    $path->tryRelativizeTo($root)?->value() ?? $path->value(),
                    $match->definition->display(),
                );
            }
        }

        return $excluded;
    }

    /**
     * When no layer wrote `paths`: the roots composer discovery found, or the
     * working directory when it found none.
     *
     * @param list<string> $discovered
     *
     * @return non-empty-list<string>
     */
    private static function defaultPaths(array $discovered): array
    {
        return $discovered !== [] ? $discovered : ['.'];
    }

    /**
     * Each selector decoded in the words of the layer that wrote it.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<PathPattern>
     */
    private function pathPatterns(ResolvedListInterface $excludes): array
    {
        $patterns = [];
        foreach ($excludes->items() as $index => $entry) {
            $writer = $entry->contributors()[0];
            $pattern = $this->selectorDecoder->decodePath(
                $entry->plain(),
                $writer->origin,
                $writer->path ?? [ConfigurationRoot::Exclude->value, (string) $index],
            );
            $patterns[$pattern->definition->display()] = $pattern;
        }

        if (\count($patterns) > SelectorDefinition::MAX_SELECTOR_COUNT) {
            $excludes->refuse(
                \sprintf('Option "%s" must not contain more than %d selectors.', ConfigurationRoot::Exclude->value, SelectorDefinition::MAX_SELECTOR_COUNT),
            );
        }

        return array_values($patterns);
    }

    /**
     * The targets composer discovery contributed, taken under the run's
     * policy and kept to the ones a walk of the project reaches, through the
     * same two questions {@see ProjectScopeCoverage} asks of the
     * denominator. They are only the default — a `paths` any source wrote
     * replaces them, flag or no flag, and is never pruned here.
     *
     * @return list<string>
     */
    private function discoveredPaths(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        return ProjectScopeCoverage::reachableTargets(
            $document->workingDirectory(),
            $autoloadDev->projectTargets(
                $document->discoveredProductionAutoloadTargets(),
                $document->discoveredDevelopmentAutoloadTargets(),
            ) ?? [],
        );
    }

    private static function flag(?ResolvedValueInterface $value): bool
    {
        return $value?->plain() === true;
    }

    private static function list(?ResolvedValueInterface $value): ?ResolvedListInterface
    {
        return $value === null ? null : ($value instanceof ResolvedListInterface ? $value : throw new LogicException(
            \sprintf('The document declares a list here, but resolved a %s.', $value::class),
        ));
    }
}
