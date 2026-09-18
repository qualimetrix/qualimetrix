<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use RuntimeException;

/**
 * The manifest this group's controls judge the tree against, read in one place.
 *
 * {@see ArchitectureInternalTopologyTest} and {@see DogfoodingTopologyTest}
 * assert different things — one that the Architecture leaf's internal imports
 * follow its zone DAG, the other that the generated qmx projection matches the
 * manifest's owners and seams — and each carried its own spelling of the path,
 * its own `realpath()` walk to the repository root and its own decode. Nothing
 * about *what* they assert was shared, which is why the reading is what moves
 * and the assertions stay where they are.
 *
 * The decode is cached per process: the file is a tracked artifact and cannot
 * change under a run.
 */
final class ModularArchitectureManifest
{
    public const string PATH = 'docs/internal/modular-architecture-manifest.json';

    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $absolute = self::repositoryRoot() . '/' . self::PATH;
        $contents = file_get_contents($absolute);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $absolute));
        }

        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($manifest)) {
            throw new RuntimeException(\sprintf('%s does not decode to an array.', $absolute));
        }

        /** @var array<string, mixed> $manifest */
        return $cache = $manifest;
    }

    public static function repositoryRoot(): string
    {
        $root = realpath(\dirname(__DIR__, 2));

        if ($root === false) {
            throw new RuntimeException('Could not resolve the repository root.');
        }

        return $root;
    }
}
