<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

final class RunConfigurationResolver implements RunConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document): RunConfiguration
    {
        $root = $document->workingDirectory();
        $paths = self::lastStringList($document->contributions(ConfigSchema::PATHS), ['.']);
        $pathList = array_map(
            static fn(string $path): AbsolutePath => PathFactory::fromCliArgument($path, $root),
            $paths,
        );

        return new RunConfiguration(
            paths: $pathList,
            pathExcludes: self::accumulatedStrings(
                $document->contributions(ConfigSchema::EXCLUDES),
                ['vendor', 'node_modules', '.git'],
            ),
            projectRoot: $root,
            generatedFilePolicy: self::generatedFilePolicy(
                $document->contributions(ConfigSchema::INCLUDE_GENERATED),
            ),
        );
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
