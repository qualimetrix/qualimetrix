<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
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
        $paths = self::lastStringList($document->contributions(ConfigSchema::PATHS), ['.']);
        $pathList = array_map(
            static fn(string $path): AbsolutePath => PathFactory::fromCliArgument(
                self::acceptedPath($path),
                $root,
            ),
            $paths,
        );

        $excludeContributions = $document->contributions(ConfigSchema::EXCLUDES);
        $authoredExcludes = $this->accumulatedPathPatterns($excludeContributions);

        return new RunConfiguration(
            coversProjectScope: $this->projectScopeCoverage->pathsCoverProjectScope($root, $pathList),
            paths: $pathList,
            pathExcludes: [...DirectoryPruner::builtInPatterns(), ...$authoredExcludes],
            // The same contributions without the built-in floor: what the
            // author actually asked to exclude, which is the only part of the
            // merged list a miss can be reported about.
            authoredPathExcludes: $authoredExcludes,
            projectRoot: $root,
            generatedFilePolicy: self::generatedFilePolicy(
                $document->contributions(ConfigSchema::INCLUDE_GENERATED),
            ),
        );
    }

    /**
     * The empty path reached {@see PathFactory} unframed and was answered
     * there in the vocabulary of the CLI, which misnames the door whenever the
     * value came from `paths:` in a document.
     */
    private static function acceptedPath(string $path): string
    {
        if ($path === '') {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid entry in "%s": a path cannot be empty. Name a directory or a file, or omit the key to analyse the working directory.',
                    ConfigSchema::PATHS,
                ),
                ConfigSchema::PATHS,
            );
        }

        return $path;
    }

    /**
     * @param list<mixed> $contributions
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function lastStringList(array $contributions, array $default): array
    {
        $value = $default;
        foreach ($contributions as $candidate) {
            if (\is_array($candidate) && array_is_list($candidate)) {
                $value = array_values(array_filter($candidate, is_string(...)));
            }
        }

        return $value;
    }

    /** @param list<mixed> $contributions
     * @return list<PathPattern>
     */
    private function accumulatedPathPatterns(array $contributions): array
    {
        $patterns = [];
        foreach ($contributions as $candidate) {
            if (!\is_array($candidate) || !array_is_list($candidate)) {
                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf('Invalid value for "%s": expected a list of explicit selector mappings.', ConfigSchema::EXCLUDES),
                    ConfigSchema::EXCLUDES,
                );
            }

            foreach ($candidate as $index => $entry) {
                $pattern = $entry instanceof PathPattern
                    ? $entry
                    : $this->selectorDecoder->decodePath(
                        $entry,
                        ConfigurationOrigin::of(ConfigurationSource::Resolved, ConfigSchema::EXCLUDES),
                        [ConfigSchema::EXCLUDES, (string) $index],
                    );
                $patterns[$pattern->definition->display()] = $pattern;
            }
        }

        if (\count($patterns) > SelectorDefinition::MAX_SELECTOR_COUNT) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf('Option "%s" must not contain more than %d selectors.', ConfigSchema::EXCLUDES, SelectorDefinition::MAX_SELECTOR_COUNT),
                ConfigSchema::EXCLUDES,
            );
        }

        return array_values($patterns);
    }

    /** @param list<mixed> $contributions */
    private static function generatedFilePolicy(array $contributions): GeneratedFilePolicy
    {
        $policy = GeneratedFilePolicy::Exclude;
        foreach ($contributions as $candidate) {
            if (\is_bool($candidate)) {
                $policy = $candidate ? GeneratedFilePolicy::Include : GeneratedFilePolicy::Exclude;
            }
        }

        return $policy;
    }
}
