<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Analysis\Run\Contract\Configuration\AuthoredExclude;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

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
        [$pathList, $pathsAuthorship] = RunPathSelection::select($document, $autoloadDev);

        $excludes = self::list($resolved->get(ConfigurationRoot::Exclude->value));
        $authoredExcludes = $excludes === null ? [] : $this->pathPatterns($excludes);

        $scope = $this->projectScopeCoverage->measure($root, $pathList, $autoloadDev, $pathsAuthorship);

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

        $sources = $this->selectorSources($excludes, $patterns);

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

    /**
     * @param array<string, PathPattern> $patterns
     *
     * @return array<string, array<string, ConfigurationOrigin>>
     */
    private function selectorSources(ResolvedWriteHistoryInterface $excludes, array $patterns): array
    {
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

        return $sources;
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
