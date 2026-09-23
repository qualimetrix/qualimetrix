<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * The container shape of every root of a configuration document: a section or
 * map root must not be a sequential list, a list root must not be a map.
 *
 * Shape is judged here rather than left to each root's owner because the owner
 * receives merged contributions and can name neither the document nor the
 * position, while a list under a section root used to reach the sub-key walk
 * and crash it with an integer sub-key.
 *
 * `[]` passes in both directions: an empty container is a legitimate way to
 * write "nothing here", and its author has not chosen a shape.
 *
 * The three questions below are asked of three disjoint sets of roots — the
 * ones that must be associative, the ones with a closed set of sub-keys, and
 * the ones that must be a sequence — and each names the mistake in the words
 * of its own set. They are separate methods for that reason, not for length:
 * a root is in exactly one of the three, so a single walk would have to
 * re-decide which sentence to print on every iteration.
 */
final class RootContainerShapes
{
    /**
     * @param array<string, mixed> $config the document after key normalization
     * @param array<string, string> $keyMap normalized root key => the spelling the author wrote
     *
     * @throws ConfigurationRefusal on the first root whose container is of the wrong shape
     */
    public static function refuseWrongContainer(array $config, string $path, array $keyMap): void
    {
        self::refuseNonAssociativeSection($config, $path, $keyMap);
        self::refuseListWhereNamedKeysBelong($config, $path, $keyMap);
        self::refuseNonListRoot($config, $path, $keyMap);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private static function refuseNonAssociativeSection(array $config, string $path, array $keyMap): void
    {
        foreach (ConfigSchema::associativeRootKeys() as $section) {
            if (!isset($config[$section]) || \is_array($config[$section])) {
                continue;
            }

            $originalSection = self::originalKey($section, $keyMap);

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::open([$originalSection], $originalSection),
                \sprintf('"%s" must be an associative array', $originalSection),
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private static function refuseListWhereNamedKeysBelong(array $config, string $path, array $keyMap): void
    {
        foreach (ConfigSchema::allowedSectionSubKeys() as $section => $allowedSubKeys) {
            $value = $config[$section] ?? null;

            if (!\is_array($value) || $value === [] || !array_is_list($value)) {
                continue;
            }

            $originalSection = self::originalKey($section, $keyMap);
            $originalSubKeys = array_map(
                static fn(string $subKey): string => ConfigKeySpelling::offerLike($subKey, $originalSection),
                $allowedSubKeys,
            );

            throw ConfigurationRefusal::atConfigFileKey(
                $path,
                RefusedPosition::closed([$originalSection], $originalSection, $originalSubKeys),
                \sprintf(
                    'Invalid value for "%s": expected a section of named keys (%s), got a list.',
                    $originalSection,
                    implode(', ', $originalSubKeys),
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $keyMap
     */
    private static function refuseNonListRoot(array $config, string $path, array $keyMap): void
    {
        foreach (ConfigSchema::listKeys() as $field) {
            if (!isset($config[$field])) {
                continue;
            }

            $originalField = self::originalKey($field, $keyMap);

            if (!\is_array($config[$field])) {
                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalField], $originalField),
                    \sprintf('"%s" must be a list', $originalField),
                );
            }

            if ($config[$field] !== [] && !array_is_list($config[$field])) {
                throw ConfigurationRefusal::atConfigFileKey(
                    $path,
                    RefusedPosition::open([$originalField], $originalField),
                    \sprintf('Invalid value for "%s": expected a list of entries, got a map.', $originalField),
                );
            }
        }
    }

    /** @param array<string, string> $keyMap */
    private static function originalKey(string $normalizedKey, array $keyMap): string
    {
        return $keyMap[$normalizedKey] ?? $normalizedKey;
    }
}
