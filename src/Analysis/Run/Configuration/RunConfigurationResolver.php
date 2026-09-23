<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
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
        $autoloadDev = self::autoloadDevPolicy($document->contributions(ConfigSchema::INCLUDE_AUTOLOAD_DEV));
        $paths = self::analysedPaths(
            $document->contributions(ConfigSchema::PATHS),
            self::discoveredPaths($document, $autoloadDev),
        );
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
            coversProjectScope: $this->projectScopeCoverage->pathsCoverProjectScope($root, $pathList, $autoloadDev),
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
            autoloadDevPolicy: $autoloadDev,
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
     * The paths this run will analyse: the last contribution that named any;
     * when no source named one, the roots composer discovery found, or the
     * working directory when it found none.
     *
     * Two questions, and they are answered at different points on purpose.
     * **Form** — is this a list, and is every entry a path — is asked of every
     * contribution, because a malformed list is malformed whatever a later
     * source says about it. **Emptiness** is asked once, of the effective list
     * only: a document writing `paths: []` and a CLI invocation naming a
     * directory is a lawful override, and the run analyses the directory.
     *
     * Until this door closed, both answers were the same silent one. Entries
     * that were not strings — the dangling `-` that YAML reads as `null`, the
     * unquoted directory name `2024` that it reads as an integer — were
     * filtered out of the list and never mentioned; a list they emptied left
     * discovery with nothing to find, and the run reported success over zero
     * files. That is less analysis than the author asked for, reported as
     * more.
     *
     * @param list<mixed> $contributions
     * @param list<string> $discovered
     *
     * @return list<string>
     */
    private static function analysedPaths(array $contributions, array $discovered): array
    {
        $paths = $discovered !== [] ? $discovered : ['.'];
        foreach ($contributions as $candidate) {
            $paths = self::acceptedPathList($candidate);
        }

        if ($paths === []) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid value for "%s": the list is empty, so this run would analyse nothing. Name at least one'
                    . ' path, or omit the key to analyse the working directory.',
                    ConfigSchema::PATHS,
                ),
                ConfigSchema::PATHS,
            );
        }

        return $paths;
    }

    /**
     * One contribution's worth of paths, refused by form rather than filtered.
     *
     * @return list<string>
     */
    private static function acceptedPathList(mixed $candidate): array
    {
        if (!\is_array($candidate) || !array_is_list($candidate)) {
            throw ConfigurationRefusal::aboutResolvedInput(
                \sprintf(
                    'Invalid value for "%s": expected a list of paths, got %s.',
                    ConfigSchema::PATHS,
                    \is_array($candidate) ? 'a map' : ConfigSchema::scalarTypeName($candidate),
                ),
                ConfigSchema::PATHS,
            );
        }

        $paths = [];
        foreach ($candidate as $index => $entry) {
            if (!\is_string($entry)) {
                // The dangling dash is the likeliest way to arrive here and
                // the hardest to see, so it is named rather than left to the
                // generic advice about quoting.
                $hint = $entry === null
                    ? 'A list item with nothing after its dash reads as this value.'
                    : 'Quote a name that reads as a number or a keyword ("2024", "true").';

                throw ConfigurationRefusal::aboutResolvedInput(
                    \sprintf(
                        'Invalid entry %d in "%s": a path must be a string, got %s. %s Name a path or remove the entry.',
                        $index,
                        ConfigSchema::PATHS,
                        ConfigSchema::scalarTypeName($entry),
                        $hint,
                    ),
                    ConfigSchema::PATHS,
                );
            }

            $paths[] = $entry;
        }

        return $paths;
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

    /**
     * The targets composer discovery contributed, taken under the run's
     * policy through the same question {@see ProjectScopeCoverage} asks of
     * the denominator. They are only the default — a `paths` any source
     * wrote replaces them, flag or no flag.
     *
     * @return list<string>
     */
    private static function discoveredPaths(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        return $autoloadDev->projectTargets(
            self::lastStringList($document->contributions(ConfigSchema::DISCOVERED_AUTOLOAD_PATHS)),
            self::lastStringList($document->contributions(ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS)),
        ) ?? [];
    }

    /**
     * @param list<mixed> $contributions
     *
     * @return list<string>
     */
    private static function lastStringList(array $contributions): array
    {
        $last = $contributions === [] ? [] : $contributions[array_key_last($contributions)];

        return \is_array($last) ? array_values(array_filter($last, \is_string(...))) : [];
    }

    /** @param list<mixed> $contributions */
    private static function autoloadDevPolicy(array $contributions): AutoloadDevPolicy
    {
        $policy = AutoloadDevPolicy::Exclude;
        foreach ($contributions as $candidate) {
            if (\is_bool($candidate)) {
                $policy = $candidate ? AutoloadDevPolicy::Include : AutoloadDevPolicy::Exclude;
            }
        }

        return $policy;
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
