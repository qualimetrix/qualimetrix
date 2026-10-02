<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * @qmx-threshold coupling.cbo warning=21 -- Raw CBO 20 (Ce=19, Ca=1). Initial
 * path pruning belongs to ProjectScopePaths; this resolver still needs
 * ProjectScopeCoverage for the measured verdict. Extracting the shared
 * operation adds one named dependency without adding a policy or read.
 * The inclusive warning bound reports the next distinct coupling; the
 * configured error bound remains unchanged.
 */
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
        $pathList = $writtenPaths === null
            ? $this->defaultPathList($document, $autoloadDev)
            : self::writtenPathList($root, $writtenPaths);

        $excludes = self::list($resolved->get(ConfigurationRoot::Exclude->value));
        $authoredExcludes = $excludes === null ? [] : $this->pathPatterns($excludes);

        $scope = $this->projectScopeCoverage->measure($root, $pathList, $autoloadDev, $writtenPaths !== null ? PathsAuthorship::Authored : PathsAuthorship::Inferred);

        return new RunConfiguration(
            projectScope: $scope,
            pathExcludes: [...self::builtInPatterns(), ...array_map(static fn(AuthoredExclude $selector): PathPattern => $selector->pattern, $authoredExcludes)],
            authoredPathExcludes: $authoredExcludes,
            projectRoot: $root,
            generatedFilePolicy: self::flag($resolved->get(ConfigurationRoot::IncludeGenerated->value))
                ? GeneratedFilePolicy::Include
                : GeneratedFilePolicy::Exclude,
            autoloadDevPolicy: $autoloadDev,
        );
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

    /** @return list<AbsolutePath> */
    private function defaultPathList(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        $root = $document->workingDirectory();
        try {
            return PathsNormalizer::normalize($root, self::defaultPaths($this->discoveredPaths($document, $autoloadDev)));
        } catch (InvalidArgumentException $error) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $root->value() . '/composer.json'),
                $error->getMessage(),
                $error,
            );
        }
    }

    /** @return list<AbsolutePath> */
    private static function writtenPathList(AbsolutePath $root, ResolvedListInterface $writtenPaths): array
    {
        PathsSection::read($writtenPaths);
        $paths = [];
        foreach ($writtenPaths->items() as $item) {
            try {
                $paths[] = PathsNormalizer::normalize($root, [$item->plain()])[0];
            } catch (InvalidArgumentException $error) {
                $item->refuse($error->getMessage());
            }
        }

        return $paths;
    }

    /**
     * Each selector decoded in the words of the layer that wrote it.
     *
     * @throws ConfigurationRefusal
     *
     * @return list<AuthoredExclude>
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

        if (!$excludes instanceof ResolvedWriteHistoryInterface) {
            throw new LogicException('Exclude list lost its authored write history');
        }

        $sources = [];
        foreach ($excludes->writes() as $write) {
            foreach ($write['value'] as $index => $raw) {
                $origin = $write['provenance']->origin;
                $path = $write['provenance']->path ?? [ConfigurationRoot::Exclude->value, (string) $index];
                $pattern = $this->selectorDecoder->decodePath($raw, $origin, $path);
                $display = $pattern->definition->display();
                if (!isset($patterns[$display])) {
                    continue;
                }
                $sources[$display][serialize($origin)] = $origin;
            }
        }

        $authored = [];
        foreach ($patterns as $display => $pattern) {
            $originList = array_values($sources[$display] ?? []);
            if ($originList === []) {
                throw new LogicException('Effective exclude has no authored source');
            }
            $authored[] = new AuthoredExclude($pattern, $originList);
        }

        return $authored;
    }

    /** @return list<PathPattern> */
    private static function builtInPatterns(): array
    {
        return array_map(
            static fn(string $name): PathPattern => new PathPattern(new SelectorDefinition(
                SelectorKind::Regex,
                '(?:[^/]+/)*' . preg_quote($name, '~'),
            )),
            ['vendor', 'node_modules', '.git'],
        );
    }

    /**
     * The targets composer discovery contributed, taken under the run's
     * policy and kept to the ones a walk of the project reaches, through the
     * same two questions {@see ProjectScopePaths} asks of the
     * denominator. They are only the default — a `paths` any source wrote
     * replaces them, flag or no flag, and is never pruned here.
     *
     * @return list<string>
     */
    private function discoveredPaths(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        return ProjectScopePaths::reachableTargets(
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
