<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

final class RunConfigurationResolver implements RunConfigurationResolverInterface
{
    /** The directories the product excludes whether or not the author says so. */
    private const array BUILT_IN_EXCLUDES = ['vendor', 'node_modules', '.git'];

    public function __construct(private readonly ProjectScopeCoverage $projectScopeCoverage) {}

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

        return new RunConfiguration(
            coversProjectScope: $this->projectScopeCoverage->pathsCoverProjectScope($root, $pathList),
            paths: $pathList,
            pathExcludes: self::accumulatedStrings($excludeContributions, self::BUILT_IN_EXCLUDES),
            // The same contributions without the built-in floor: what the
            // author actually asked to exclude, which is the only part of the
            // merged list a miss can be reported about.
            authoredPathExcludes: self::accumulatedStrings($excludeContributions, []),
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

    /**
     * @param list<mixed> $contributions
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function accumulatedStrings(array $contributions, array $default): array
    {
        $values = $default;
        foreach ($contributions as $candidate) {
            if (\is_array($candidate) && array_is_list($candidate)) {
                array_push($values, ...array_filter($candidate, is_string(...)));
            }
        }

        return array_values(array_unique($values));
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
